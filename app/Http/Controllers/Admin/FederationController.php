<?php

namespace App\Http\Controllers\Admin;

use App\Auth\Oidc\OidcSettings;
use App\Federation\Applier;
use App\Federation\FederatedSecrets;
use App\Federation\Outbox;
use App\Federation\Pairing;
use App\Federation\PairingException;
use App\Federation\PeerCertificate;
use App\Federation\Topology;
use App\Http\Controllers\Controller;
use App\Models\CertificateSource;
use App\Models\Channel;
use App\Models\FederationPeer;
use App\Models\Instance;
use App\Models\LocalSetting;
use App\Models\NasClient;
use App\Models\Setting;
use App\Support\Audit;
use App\Support\LocalInstance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Federation page (admin only, CONCEPT.md §10): this instance's identity, hub and offline setting,
 * join tokens, peers with heartbeat, lag, clock skew and pause state, removing a peer.
 */
class FederationController extends Controller
{
    public function show(LocalInstance $local, Topology $topology): View
    {
        $self = Instance::query()->findOrFail($local->id());
        $peerStates = FederationPeer::query()->get()->keyBy('instance_id');
        $cursors = DB::table('peer_cursors')->get()->keyBy('origin');
        $ownSeq = (int) DB::table(Outbox::TABLE)->where('origin', $local->id())->max('seq');
        $direct = $topology->directPeers()->pluck('id')->all();

        $peers = $topology->others()->map(function (Instance $instance) use ($peerStates, $cursors, $ownSeq, $direct, $topology, $local) {
            $state = $peerStates->get($instance->id);
            $cursor = $cursors->get($instance->id);
            $incoming = null;
            if ($state?->origins !== null) {
                $incoming = 0;
                foreach ($state->origins as $origin => $seq) {
                    if ($origin !== $local->id() && (! $topology->isHub() || $origin === $instance->id)) {
                        $incoming += max(0, (int) $seq - (int) ($cursors->get($origin)->last_seq ?? 0));
                    }
                }
            }
            $ack = $state?->ack($local->id());

            return (object) [
                'instance' => $instance,
                'state' => $state,
                'direct' => in_array($instance->id, $direct, true),
                'isHub' => $instance->id === $topology->hubId(),
                'incoming' => $incoming,
                'outgoing' => $ack === null ? null : max(0, $ownSeq - $ack),
                'applied' => (int) ($cursor->last_seq ?? 0),
                'applyPause' => $cursor?->paused_at !== null ? (string) $cursor->pause_reason : null,
                'skewWarning' => $state?->clock_skew_ms !== null && abs($state->clock_skew_ms) > (int) config('portal.federation.skew_warn_ms'),
            ];
        });

        return view('admin.federation', [
            'self' => $self,
            'localPin' => PeerCertificate::localPin(),
            'isHub' => $topology->isHub(),
            'hubId' => $topology->hubId(),
            'instances' => Instance::query()->orderBy('name')->get(),
            'peers' => $peers,
            'offlineSmsOnly' => $topology->offlineSmsOnly(),
            'limitedMode' => $topology->limitedMode(),
            'joinedAt' => LocalSetting::get(Pairing::JOINED_SETTING),
            'removed' => LocalSetting::get(Pairing::REMOVED_SETTING) !== null,
        ]);
    }

    /**
     * Join page of a new instance (CONCEPT.md §10.2): opened by the local bootstrap admin (created with
     * `artisan portal:admin`); hidden once joined.
     */
    public function joinForm(Pairing $pairing, LocalInstance $local): View
    {
        abort_if(LocalSetting::get(Pairing::JOINED_SETTING) !== null, 404);

        return view('admin.federation-join', ['self' => Instance::query()->findOrFail($local->id()),
            'notFresh' => $pairing->isFresh(), 'localPin' => PeerCertificate::localPin()]);
    }

    /** Join: on success the bootstrap admin is gone, so the session ends and the hub's accounts apply. */
    public function join(Request $request, Pairing $pairing, LocalInstance $local): RedirectResponse
    {
        abort_if(LocalSetting::get(Pairing::JOINED_SETTING) !== null, 404);
        $data = $request->validate([
            'url' => ['required', 'url:https', 'max:255'],
            'token' => ['required', 'string', 'max:100'],
            'fingerprint' => ['required', 'string', 'regex:#^sha256//[A-Za-z0-9+/]{43}=$#'],
            'name' => ['required', 'string', 'max:100'],
            'federation_url' => ['required', 'url:https', 'max:255'],
        ]);
        Instance::query()->findOrFail($local->id())->update(['name' => $data['name'], 'federation_url' => rtrim($data['federation_url'], '/')]);
        $actor = (string) $request->user()->name;
        try {
            $pairing->join($data['url'], $data['token'], $data['fingerprint'], $actor);
        } catch (PairingException $e) {
            return back()->withInput($request->except('token'))->withErrors(['join' => $e->getMessage()]);
        }
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', __('portal.federation.joined_login'));
    }

    /**
     * Hub takeover (CONCEPT.md §10.1): this instance becomes the hub when the hub is lost for good.
     * Confirmed by typing this instance's name; the other instances follow when they re-pair (they get
     * the rotated data key with the re-pairing answer).
     */
    public function promote(Request $request, Pairing $pairing, LocalInstance $local, FederatedSecrets $secrets): RedirectResponse
    {
        $self = Instance::query()->findOrFail($local->id());
        $request->validate(['confirm_name' => ['required', 'string']]);
        if ($request->input('confirm_name') !== $self->name) {
            return back()->withErrors(['confirm_name' => __('portal.federation.promote_name_mismatch')]);
        }
        try {
            $pairing->promote();
        } catch (PairingException $e) {
            return back()->withErrors(['federation' => $e->getMessage()]);
        }
        // The old hub is removed: like removing a peer, a new data key and the secrets it knew to renew.
        $kid = $secrets->rotate();
        Audit::log('federation.data_key_rotated', null, ['data_key_id' => $kid, 'reason' => 'hub takeover']);

        return back()->with('status', __('portal.federation.promoted'))->with('federation_checklist', $this->checklist());
    }

    /** Re-pairing page: connect to a (new) hub, data stays. */
    public function repairForm(Topology $topology): View
    {
        return view('admin.federation-repair', ['removed' => LocalSetting::get(Pairing::REMOVED_SETTING) !== null,
            'hub' => $topology->hub()]);
    }

    public function repair(Request $request, Pairing $pairing): RedirectResponse
    {
        $data = $request->validate([
            'url' => ['required', 'url:https', 'max:255'],
            'token' => ['required', 'string', 'max:100'],
            'fingerprint' => ['required', 'string', 'regex:#^sha256//[A-Za-z0-9+/]{43}=$#'],
        ]);
        try {
            $pairing->repair($data['url'], $data['token'], $data['fingerprint'], (string) $request->user()->name);
        } catch (PairingException $e) {
            return back()->withInput($request->except('token'))->withErrors(['repair' => $e->getMessage()]);
        }

        return redirect()->route('admin.federation')->with('status', __('portal.federation.repaired'));
    }

    /** Name and URLs of this instance (replicated: the other instances use them). */
    public function updateInstance(Request $request, LocalInstance $local): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'federation_url' => ['nullable', 'url:https', 'max:255'],
            'admin_url' => ['nullable', 'url:https,http', 'max:255'],
        ]);
        $self = Instance::query()->findOrFail($local->id());
        $self->update([
            'name' => $data['name'],
            'federation_url' => isset($data['federation_url']) ? rtrim($data['federation_url'], '/') : null,
            'admin_url' => isset($data['admin_url']) ? rtrim($data['admin_url'], '/') : null,
        ]);
        Audit::log('federation.instance_updated', $self, ['changed' => array_values(array_diff(array_keys($self->getChanges()), ['updated_at', 'version']))]);

        return back()->with('status', __('portal.saved'));
    }

    /** Hub instance and the offline rule (replicated settings). */
    public function updateSettings(Request $request, Topology $topology): RedirectResponse
    {
        $data = $request->validate([
            'hub' => ['required', Rule::exists('instances', 'id')->whereNull('deleted_at')],
            'offline_sms_only' => ['boolean'],
        ]);
        // Only the hub hands the role on (the others would reject the change, CONCEPT.md §10.6).
        if (! $topology->isHub() && $data['hub'] !== $topology->hubId()) {
            return back()->withErrors(['hub' => __('portal.federation.hub_only_on_hub')]);
        }
        Setting::put(Topology::HUB_SETTING, $data['hub']);
        Setting::put(Topology::OFFLINE_SMS_ONLY_SETTING, $request->boolean('offline_sms_only'));
        Audit::log('federation.settings_updated', null, ['hub' => $data['hub'], 'offline_sms_only' => $request->boolean('offline_sms_only')]);

        return back()->with('status', __('portal.saved'));
    }

    /** One-time join token, shown once with this instance's federation URL and certificate pin. */
    public function createToken(Request $request, Pairing $pairing, LocalInstance $local): RedirectResponse
    {
        $token = $pairing->createToken($request->user());
        Audit::log('federation.token_created', null, ['expires_at' => $token['expires_at']->toIso8601String()]);
        $self = Instance::query()->findOrFail($local->id());

        return back()->with('federation_join', [
            'token' => $token['token'],
            'expires_at' => $token['expires_at']->toIso8601String(),
            'url' => $self->federation_url,
            'pin' => PeerCertificate::localPin(),
        ]);
    }

    /** Resume replication with a peer paused for clock skew, protocol or an event that could not be applied. */
    public function unpause(Instance $instance, Applier $applier): RedirectResponse
    {
        FederationPeer::query()->whereKey($instance->id)->update(['paused_at' => null, 'pause_reason' => null]);
        $applier->unpause($instance->id);
        Audit::log('federation.peer_unpaused', $instance, ['name' => $instance->name]);

        return back()->with('status', __('portal.federation.unpaused'));
    }

    /**
     * Remove a peer: its certificate is no longer trusted (tombstone, replicated), the data key is
     * rotated and the secrets the peer knew are listed for renewal.
     */
    public function destroy(Instance $instance, LocalInstance $local, Topology $topology, FederatedSecrets $secrets): RedirectResponse
    {
        if ($instance->id === $local->id()) {
            return back()->withErrors(['federation' => __('portal.federation.cannot_remove_self')]);
        }
        if ($instance->id === $topology->hubId()) {
            return back()->withErrors(['federation' => __('portal.federation.cannot_remove_hub')]);
        }
        DB::transaction(function () use ($instance) {
            $instance->delete();
            FederationPeer::query()->whereKey($instance->id)->delete();
        });
        $kid = $secrets->rotate();
        Audit::log('federation.peer_removed', $instance, ['name' => $instance->name, 'data_key_id' => $kid]);

        return redirect()->route('admin.federation')->with('status', __('portal.federation.removed', ['name' => $instance->name]))
            ->with('federation_checklist', $this->checklist());
    }

    /** @return list<string> secrets the removed peer knew, to renew at their providers */
    private function checklist(): array
    {
        $items = [];
        foreach (Channel::query()->orderBy('kind')->orderBy('name')->get() as $channel) {
            $items[] = __('portal.federation.checklist_channel', ['name' => $channel->name, 'driver' => $channel->driver]);
        }
        if (NasClient::query()->exists()) {
            $items[] = __('portal.federation.checklist_radius');
        }
        if (CertificateSource::query()->whereNotNull('api_key')->exists()) {
            $items[] = __('portal.federation.checklist_certificates');
        }
        if (OidcSettings::hasClientSecret()) {
            $items[] = __('portal.federation.checklist_oidc');
        }

        return $items;
    }
}
