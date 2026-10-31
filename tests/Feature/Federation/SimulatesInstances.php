<?php

namespace Tests\Feature\Federation;

use App\Federation\Applier;
use App\Federation\ApplyResult;
use App\Federation\DataKey;
use App\Federation\Outbox;
use App\Federation\PeerCertificate;
use App\Support\Hlc;
use App\Support\LocalInstance;
use Closure;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Simulated federation members in one test process: each instance has its own in-memory SQLite
 * connection, instance id and HLC; on() switches the default connection and container bindings.
 */
trait SimulatesInstances
{
    /** @var array<string, array{instance: LocalInstance, hlc: Hlc}> */
    private array $sims = [];

    protected function makeInstances(string ...$names): void
    {
        foreach ($names as $name) {
            $connection = "sim_$name";
            config(["database.connections.$connection" => ['driver' => 'sqlite', 'database' => ':memory:',
                'prefix' => '', 'foreign_key_constraints' => true]]);
            DB::purge($connection);
            Artisan::call('migrate', ['--database' => $connection, '--force' => true]);
            $instance = new LocalInstance;
            $branding = sys_get_temp_dir().'/portal-test-'.getmypid().'/branding-'.$name.'-'.bin2hex(random_bytes(3));
            $this->sims[$name] = ['instance' => $instance, 'hlc' => new Hlc($instance), 'dir' => null, 'branding' => $branding];
            $this->on($name, fn () => app(LocalInstance::class)->id());
        }
    }

    /**
     * Own federation certificate for a simulated instance (config portal.federation.dir while active).
     *
     * @return string certificate pin
     */
    protected function giveCertificate(string $name): string
    {
        $dir = sys_get_temp_dir().'/portal-test-'.getmypid().'/fed-'.$name.'-'.bin2hex(random_bytes(3));
        mkdir($dir, 0700, true);
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $cert = openssl_csr_sign(openssl_csr_new(['commonName' => 'portal-federation'], $key), null, $key, 3650);
        openssl_x509_export_to_file($cert, "$dir/federation.crt");
        openssl_pkey_export_to_file($key, "$dir/federation.key");
        $this->sims[$name]['dir'] = $dir;

        return PeerCertificate::pin((string) file_get_contents("$dir/federation.crt"));
    }

    protected function certificateOf(string $name): string
    {
        return (string) file_get_contents($this->sims[$name]['dir'].'/federation.crt');
    }

    protected function on(string $name, Closure $callback): mixed
    {
        $previous = [DB::getDefaultConnection(), app(LocalInstance::class), app(Hlc::class), config('portal.federation.dir'),
            config('portal.branding.dir')];
        $this->bind("sim_$name", $this->sims[$name]['instance'], $this->sims[$name]['hlc'], $this->sims[$name]['dir'] ?? $previous[3],
            $this->sims[$name]['branding']);
        try {
            return $callback();
        } finally {
            $this->bind(...$previous);
        }
    }

    protected function idOf(string $name): string
    {
        return $this->on($name, fn () => app(LocalInstance::class)->id());
    }

    /**
     * Pull every event stored at $from (own and relayed) that $to has not applied yet.
     *
     * @return array<string, ApplyResult> per origin
     */
    protected function sync(string $from, string $to): array
    {
        $events = $this->on($from, fn () => DB::table('federation_events')->orderBy('seq')->get()->groupBy('origin'));
        $target = $this->idOf($to);

        return $this->on($to, function () use ($events, $target) {
            $results = [];
            foreach ($events as $origin => $list) {
                if ($origin !== $target) {
                    $results[$origin] = app(Applier::class)->apply($origin, $list->all());
                }
            }

            return $results;
        });
    }

    /** Sync until nothing changes (both directions, all pairs). */
    protected function syncAll(string ...$names): void
    {
        for ($round = 0; $round < 3; $round++) {
            foreach ($names as $from) {
                foreach ($names as $to) {
                    if ($from !== $to) {
                        $this->sync($from, $to);
                    }
                }
            }
        }
    }

    /** @return array<string, mixed>|null */
    protected function rowOn(string $name, string $table, string $id): ?array
    {
        $row = $this->on($name, fn () => DB::table($table)->where('id', $id)->first());

        return $row === null ? null : (array) $row;
    }

    private function bind(string $connection, LocalInstance $instance, Hlc $hlc, ?string $dir = null, ?string $branding = null): void
    {
        DB::setDefaultConnection($connection);
        config(['portal.federation.dir' => $dir, 'portal.branding.dir' => $branding]);
        app()->instance(LocalInstance::class, $instance);
        app()->instance(Hlc::class, $hlc);
        app()->forgetInstance(Outbox::class);
        app()->forgetInstance(DataKey::class);
    }
}
