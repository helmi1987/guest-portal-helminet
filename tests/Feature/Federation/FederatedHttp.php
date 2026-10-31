<?php

namespace Tests\Feature\Federation;

use App\Support\LocalInstance;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Routes federation HTTP calls between simulated instances (SimulatesInstances): a call to
 * https://<name>.example.com:9443/... is handled by the simulated instance <name> as a request on its
 * federation listener with the caller's client certificate. Instances in $down do not answer.
 */
trait FederatedHttp
{
    /** @var list<string> */
    protected array $down = [];

    /** @var list<string> hosts answered by other fakes registered before routeFederation() */
    protected array $fakedHosts = [];

    /** @var array<string, int> per sim: clock offset in seconds while it answers (clock skew) */
    protected array $clockOffset = [];

    /** @param  list<string>  $names */
    protected function routeFederation(array $names): void
    {
        foreach ($names as $name) {
            $this->on($name, fn () => app(LocalInstance::class)->refreshIdentity()
                ->update(['name' => ucfirst($name), 'federation_url' => "https://$name.example.com:9443"]));
        }
        Http::fake(function (Request $request) use ($names) {
            $host = (string) parse_url($request->url(), PHP_URL_HOST);
            if (in_array($host, $this->fakedHosts, true)) {
                return null; // every stub is evaluated: leave these to the other fakes
            }
            $target = explode('.', $host)[0];
            if (! in_array($target, $names, true) || in_array($target, $this->down, true)) {
                return Http::failedConnection('connection refused');
            }
            $caller = $this->simByInstanceId($request->header('X-Federation-Instance')[0] ?? '', $names);

            return $this->on($target, function () use ($request, $caller, $target) {
                $now = now();
                Carbon::setTestNow($now->copy()->addSeconds($this->clockOffset[$target] ?? 0));
                $method = $request->method();
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $server = ['PORTAL_ZONE' => 'federation', 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
                    'PORTAL_CLIENT_CERT' => $caller === null ? '' : rawurlencode($this->certificateOf($caller))];
                $response = $this->call($method, (string) parse_url($request->url(), PHP_URL_PATH), $method === 'GET' ? $query : [],
                    [], [], $server, $method === 'GET' ? null : $request->body());
                Carbon::setTestNow($now);

                return Http::response($response->getContent(), $response->getStatusCode(), ['Content-Type' => 'application/json']);
            });
        });
    }

    /** @param  list<string>  $names */
    private function simByInstanceId(string $id, array $names): ?string
    {
        foreach ($names as $name) {
            if ($this->idOf($name) === $id) {
                return $name;
            }
        }

        return null;
    }
}
