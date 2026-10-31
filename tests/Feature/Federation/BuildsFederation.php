<?php

namespace Tests\Feature\Federation;

use App\Federation\Pairing;
use Illuminate\Support\Facades\Artisan;

/**
 * Hub plus spokes joined over the simulated federation API (SimulatesInstances, FederatedHttp).
 */
trait BuildsFederation
{
    /** @param  list<string>  $spokes */
    protected function buildFederation(array $spokes = ['a', 'b']): void
    {
        $this->makeInstances('hub', ...$spokes);
        $pin = $this->giveCertificate('hub');
        foreach ($spokes as $spoke) {
            $this->giveCertificate($spoke);
        }
        $this->routeFederation(['hub', ...$spokes]);
        foreach ($spokes as $spoke) {
            $token = $this->on('hub', fn () => app(Pairing::class)->createToken(null)['token']);
            $code = $this->on($spoke, fn () => Artisan::call('portal:federation:join', ['url' => 'https://hub.example.com:9443',
                'token' => $token, 'fingerprint' => $pin]));
            $this->assertSame(0, $code, Artisan::output());
        }
        $this->syncRound([...$spokes]);
    }

    /** Two sync runs on every instance (hub first): pull, push and relay settle. */
    protected function syncRound(array $spokes = ['a', 'b']): void
    {
        foreach ([0, 1] as $round) {
            foreach (['hub', ...$spokes] as $name) {
                if (! in_array($name, $this->down, true)) {
                    $this->on($name, fn () => Artisan::call('portal:federation:sync'));
                }
            }
        }
    }
}
