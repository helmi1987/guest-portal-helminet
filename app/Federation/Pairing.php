<?php

namespace App\Federation;

use App\Enums\Role;
use App\Models\Instance;
use App\Models\LocalSetting;
use App\Models\MacWhitelist;
use App\Models\Setting;
use App\Models\User;
use App\Support\Audit;
use App\Support\LocalInstance;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Pairing (CONCEPT.md §10.2): an admin of a federation member creates a one-time join token; a fresh
 * instance presents it to POST /federation/join over mTLS with the member's pinned certificate. The
 * member registers the new instance (pin from the TLS handshake, signing key, URLs) and answers with
 * the data key ring and a snapshot of the replicated state; the registration replicates to the other
 * instances like any other change.
 */
class Pairing
{
    public const JOINED_SETTING = 'federation_joined_at';

    public function __construct(
        private LocalInstance $instance,
        private DataKey $keys,
        private Applier $applier,
        private Topology $topology,
        private PeerClient $client,
    ) {}

    /** @return array{token: string, expires_at: Carbon} clear token (shown once) */
    public function createToken(?User $by): array
    {
        $token = Str::random(40);
        $expires = now()->addMinutes((int) config('portal.federation.join_token_minutes', 15));
        DB::table('federation_join_tokens')->insert(['token_hash' => hash('sha256', $token), 'created_by' => $by?->id,
            'expires_at' => $expires, 'created_at' => now(), 'updated_at' => now()]);

        return ['token' => $token, 'expires_at' => $expires];
    }

    /**
     * Fresh instance (may join): never joined, no rows in any replicated table except its own instance
     * row and the local bootstrap admin (created with `artisan portal:admin` to open the join page)
     * with its audit entries, and no events of other origins.
     */
    public function isFresh(): ?string
    {
        $local = $this->instance->id();
        if (LocalSetting::get(self::JOINED_SETTING) !== null) {
            return 'already joined';
        }
        $users = DB::table('users')->get(['id', 'role', 'origin_instance_id']);
        if ($users->count() > 1) {
            return 'more than one user';
        }
        $bootstrap = $users->first();
        if ($bootstrap !== null && ($bootstrap->role !== Role::Admin->value || $bootstrap->origin_instance_id !== $local)) {
            return 'the user is not a local admin';
        }
        foreach (array_keys(Federation::TABLES) as $table) {
            $rows = match ($table) {
                'instances' => DB::table($table)->where('id', '!=', $local),
                'users' => null,
                // Audit entries of the bootstrap admin (and of login attempts) only.
                'audit_logs' => DB::table($table)->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', '!=', $bootstrap?->id ?? ''))
                    ->where(fn ($q) => $q->whereNull('subject_id')->orWhere('subject_type', '!=', 'user')->orWhere('subject_id', '!=', $bootstrap?->id ?? ''))
                    ->where('action', 'not like', 'auth.%'),
                default => DB::table($table),
            };
            if ($rows?->exists()) {
                return "table $table is not empty";
            }
        }
        if (DB::table(Outbox::TABLE)->where('origin', '!=', $local)->exists()) {
            return 'events of other instances present';
        }

        return null;
    }

    /**
     * Remove the local bootstrap admin before the federation's state arrives: users and audit rows are
     * deleted (hard, local, no event), their events leave the outbox and the remaining own events are
     * numbered again without gaps and signed again (nothing has been replicated yet).
     */
    private function dropBootstrap(): void
    {
        $local = $this->instance->id();
        $ids = DB::table('users')->pluck('id')->all();
        DB::table('users')->delete();
        DB::table('audit_logs')->delete();
        if ($ids !== [] && Schema::hasTable('sessions')) {
            DB::table('sessions')->whereIn('user_id', $ids)->delete();
        }
        DB::table('federation_join_tokens')->delete();
        DB::table(Outbox::TABLE)->where('origin', $local)->whereIn('table', ['users', 'audit_logs'])->delete();

        $signer = new SigningKey;
        $seq = 0;
        foreach (DB::table(Outbox::TABLE)->where('origin', $local)->orderBy('seq')->get() as $event) {
            $seq++;
            if ((int) $event->seq === $seq) {
                continue;
            }
            DB::table(Outbox::TABLE)->where('id', $event->id)->update(['seq' => $seq,
                'sig' => $signer->sign(EventCodec::message($local, $seq, $event->table, $event->record_id, $event->version,
                    (int) $event->proto, $event->payload))]);
        }
    }

    /**
     * Member side of POST /federation/join.
     *
     * @param  array{token: string, protocol: int, instance: array<string, mixed>}  $data
     * @return array<string, mixed> response
     *
     * @throws PairingException
     */
    public function acceptJoin(array $data, string $pin): array
    {
        if ((int) $data['protocol'] !== Federation::FEDERATION_PROTOCOL) {
            throw new PairingException('protocol mismatch: '.$data['protocol'].' != '.Federation::FEDERATION_PROTOCOL, 409);
        }
        $new = $data['instance'];

        return DB::transaction(function () use ($data, $new, $pin) {
            $hash = hash('sha256', (string) $data['token']);
            $token = DB::table('federation_join_tokens')->where('token_hash', $hash)->first();
            if ($token === null || $token->used_at !== null || now()->gte($token->expires_at)) {
                throw new PairingException('invalid or expired join token', 403);
            }
            if ($new['id'] === $this->instance->id() || Instance::withTrashed()->whereKey($new['id'])->exists()) {
                throw new PairingException('instance id already registered', 409);
            }
            if (Instance::query()->where('cert_fingerprint', $pin)->exists()) {
                throw new PairingException('certificate already registered', 409);
            }
            // Consume atomically: only one join can use the token, even with parallel requests.
            $consumed = DB::table('federation_join_tokens')->where('id', $token->id)->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->update(['used_at' => now(), 'used_by_instance_id' => $new['id'], 'updated_at' => now()]);
            if ($consumed !== 1) {
                throw new PairingException('invalid or expired join token', 403);
            }

            // The first pairing makes this instance the hub (CONCEPT.md §10.1).
            if ($this->topology->hubId() === null) {
                Setting::put(Topology::HUB_SETTING, $this->instance->id());
            }
            $instance = new Instance([
                'name' => $new['name'],
                'federation_url' => rtrim($new['federation_url'], '/'),
                'admin_url' => isset($new['admin_url']) ? rtrim($new['admin_url'], '/') : null,
                'cert_fingerprint' => $pin,
                'signing_public_key' => $new['signing_public_key'],
            ]);
            $instance->forceFill(['id' => $new['id'], 'origin_instance_id' => $new['id']])->save();
            Audit::log('federation.peer_joined', $instance, ['name' => $instance->name, 'federation_url' => $instance->federation_url],
                actor: 'federation');

            return [
                'protocol' => Federation::FEDERATION_PROTOCOL,
                'instance_id' => $this->instance->id(),
                'data_keys' => $this->keys->export(),
                'snapshot' => $this->snapshot(),
            ];
        });
    }

    /**
     * Consistent state of all replicated tables (read inside the caller's transaction): rows in
     * parent-before-child order, stored events of the current versions of rows with uniqueness rules,
     * and the highest stored sequence number per origin.
     */
    public function snapshot(): array
    {
        $tables = [];
        $events = [];
        foreach (array_keys(Federation::TABLES) as $table) {
            $tables[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
            if (Constraints::has($table)) {
                foreach (array_chunk($tables[$table], 200) as $chunk) {
                    $versions = array_column($chunk, 'version');
                    foreach (DB::table(Outbox::TABLE)->where('table', $table)->whereIn('version', $versions)->get() as $event) {
                        $events[] = EventCodec::toWire($event);
                    }
                }
            }
        }

        return [
            'tables' => $tables,
            'events' => $events,
            'cursors' => DB::table(Outbox::TABLE)->groupBy('origin')->selectRaw('origin, max(seq) as seq')
                ->pluck('seq', 'origin')->map(fn ($seq) => (int) $seq)->all(),
        ];
    }

    /**
     * New-instance side: join the federation of the member at $url, whose certificate pin the admin
     * confirmed ($expectedPin, shown with the token).
     *
     * @return array{instance_id: string, rows: int}
     *
     * @throws PairingException
     */
    public function join(string $url, string $token, string $expectedPin, string $actor = 'cli'): array
    {
        if (($reason = $this->isFresh()) !== null) {
            throw new PairingException("This instance is not fresh ($reason). Only a new instance without data can join.");
        }
        $self = $this->instance->refreshIdentity();
        if (! PeerCertificate::isPin($self->cert_fingerprint)) {
            throw new PairingException('This instance has no federation certificate ('.PeerCertificate::certPath().').');
        }
        if (! $self->federation_url) {
            throw new PairingException('This instance has no federation URL (PORTAL_FEDERATION_URL or --federation-url).');
        }

        try {
            $response = $this->client->pinned($url, $expectedPin, 120)->post('/federation/join', [
                'token' => $token,
                'protocol' => Federation::FEDERATION_PROTOCOL,
                'instance' => [
                    'id' => $self->id,
                    'name' => $self->name,
                    'federation_url' => $self->federation_url,
                    'admin_url' => $self->admin_url,
                    'signing_public_key' => $self->signing_public_key,
                ],
            ]);
        } catch (ConnectionException $e) {
            throw new PairingException('Connection failed (URL, port or certificate pin wrong?): '.$e->getMessage());
        }
        if (! $response->successful()) {
            throw new PairingException('Join refused ('.$response->status().'): '.($response->json('error') ?? $response->json('message') ?? 'unknown'));
        }
        $data = $response->json();
        if (! is_array($data) || (int) ($data['protocol'] ?? 0) !== Federation::FEDERATION_PROTOCOL
            || ! is_string($data['instance_id'] ?? null) || ! is_array($data['snapshot'] ?? null) || ! is_array($data['data_keys'] ?? null)) {
            throw new PairingException('Invalid join response.');
        }

        $rows = DB::transaction(function () use ($data, $url, $actor) {
            $this->dropBootstrap();
            $this->keys->importRing($data['data_keys'], adoptCurrent: true);
            $rows = $this->applier->applySnapshot($data['snapshot']);
            LocalSetting::put(self::JOINED_SETTING, now()->toIso8601String());
            // A local write after the snapshot persists the clock position (versions after the hub's).
            Instance::query()->findOrFail($this->instance->id())->touch();
            // The acting bootstrap admin no longer exists.
            if (Auth::hasUser()) {
                Auth::forgetUser();
            }
            Audit::log('federation.joined', null, ['member' => $data['instance_id'], 'url' => $url], actor: $actor);

            return $rows;
        });
        MacWhitelist::flushCache();

        return ['instance_id' => $data['instance_id'], 'rows' => $rows];
    }
}
