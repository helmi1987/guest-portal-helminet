<?php

namespace App\Nas;

use App\Approval\ApprovalSettings;
use App\Models\Channel;
use App\Models\NasClient;
use App\Support\LocalTime;
use App\Support\Redact;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Notifies the admins over the enabled notification channels when a router becomes outdated or unreachable
 * (decision 2026-11-05, v0.30.0, CONCEPT.md §9). Only on the transition: nas_clients.freshness_notified keeps when
 * each was sent ("outdated", "unreachable"); a router up to date again loses the "outdated" mark, a reachable one the
 * "unreachable" mark, so the next transition is notified again. Called by the scheduled check for the routers this
 * instance serves, so only the site's home instance sends. Channels are global (like approval notifications).
 */
class RouterNotifier
{
    /**
     * Apply the transition rules for one checked router (result of RouterFreshness::check()); returns whether a
     * message was sent.
     */
    public function transition(NasClient $nas, array $result): bool
    {
        $notified = (array) $nas->freshness_notified;
        $before = $notified;
        $new = [];
        if ($result['outdated'] && ! isset($notified['outdated'])) {
            $new[] = 'outdated';
        } elseif ($result['upToDate']) {
            unset($notified['outdated']);
        }
        if ($result['unreachable'] && ! isset($notified['unreachable'])) {
            $new[] = 'unreachable';
        } elseif ((int) $nas->router_state_failures === 0) {
            unset($notified['unreachable']);
        }
        foreach ($new as $what) {
            $notified[$what] = now()->toIso8601String();
        }
        if ($notified !== $before) {
            $nas->storeFreshnessNotified($notified);
        }
        // One message per transition (webhook events router.outdated and router.unreachable)
        foreach ($new as $what) {
            $this->send($nas, $result, $what);
        }

        return $new !== [];
    }

    /**
     * One message for one transition of a router ("outdated": the outdated pages and blocks; "unreachable": since
     * when), with the link to its router state. Webhook event "router.<transition>", the data fields are the same.
     */
    public function send(NasClient $nas, array $result, string $transition): void
    {
        $locale = ApprovalSettings::locale();
        $site = $nas->site;
        $url = ApprovalSettings::adminUrl().route('admin.sites.setup', $site, false).'#router-update-'.$nas->id;
        $blockNames = array_map(fn ($b) => __('portal.router_api.blocks.'.$b, [], $locale), $result['blocks']);
        $lines = [__('portal.router_fresh.notify.router', ['router' => $nas->name, 'site' => $site->name], $locale)];
        if ($transition === 'outdated' && $result['pages'] !== []) {
            $lines[] = __('portal.router_fresh.notify.pages', ['pages' => self::files($result['pages'])], $locale);
        }
        if ($transition === 'outdated' && $result['blocks'] !== []) {
            $lines[] = __('portal.router_fresh.notify.blocks', ['blocks' => implode(', ', $blockNames)], $locale);
        }
        if ($transition === 'unreachable') {
            $lines[] = __('portal.router_fresh.notify.unreachable', ['since' => $result['unreachableSince'] !== null
                ? LocalTime::format($result['unreachableSince']) : '?'], $locale);
        }
        $lines[] = __('portal.router_fresh.notify.link', ['url' => $url], $locale);
        $title = __('portal.router_fresh.notify.title', [], $locale);
        $data = [
            'event' => 'router.'.$transition,
            'router' => ['id' => $nas->id, 'name' => $nas->name, 'nas_ip' => $nas->nas_ip],
            'site' => ['slug' => $site->slug, 'name' => $site->name],
            'pages' => $result['pages'],
            'blocks' => $result['blocks'],
            'unreachable' => $result['unreachable'],
            'unreachable_since' => $result['unreachableSince']?->toIso8601String(),
            'url' => $url,
        ];
        foreach (Channel::query()->activeNotification()->get() as $channel) {
            try {
                $channel->driver()->announce($channel, $title, implode("\n", $lines), $data);
            } catch (Throwable $e) {
                Log::warning('Router notification failed', ['nas' => $nas->id, 'channel' => $channel->id,
                    'driver' => $channel->driver, 'error' => Redact::secrets($e->getMessage())]);
            }
        }
        Log::info('Router notification', ['nas' => $nas->id, 'site' => $site->slug, 'event' => 'router.'.$transition, 'pages' => $result['pages'],
            'blocks' => $result['blocks'], 'unreachable' => $result['unreachable']]);
    }

    /** @param  list<string>  $pages */
    public static function files(array $pages): string
    {
        return implode(', ', array_map(fn ($p) => $p.'.html', $pages));
    }
}
