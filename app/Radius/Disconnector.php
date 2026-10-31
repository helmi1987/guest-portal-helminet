<?php

namespace App\Radius;

use App\Models\RadiusAccounting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Sends a RADIUS Disconnect-Request (RFC 5176) to the router serving a session, using radclient.
 */
class Disconnector
{
    public function disconnect(RadiusAccounting $acct): bool
    {
        $nas = $acct->nasClient;
        if ($nas === null) {
            return false;
        }

        // Identifying attributes depend on the router (NAS driver); null: not addressable, not sent.
        $driver = $nas->site?->driver();
        $map = $driver?->disconnectAttributes($acct);
        if ($map === null || $map === []) {
            if ($driver === null) {
                Log::warning('RADIUS Disconnect-Request not sent: router has no site', ['nas' => $nas->nas_ip]);
            }

            return false;
        }
        $attributes = '';
        foreach ($map as $name => $value) {
            $attributes .= sprintf("%s = \"%s\"\n", $name, addslashes($value));
        }

        // Secret via file (-S), not on the command line.
        $secretFile = tempnam(sys_get_temp_dir(), 'radsec');
        file_put_contents($secretFile, $nas->radius_secret);
        chmod($secretFile, 0600);

        try {
            $result = Process::input($attributes)->timeout(30)->run([
                config('portal.radius.radclient'),
                '-x',
                '-t', (string) config('portal.radius.disconnect_timeout'),
                '-r', (string) config('portal.radius.disconnect_retries'),
                '-S', $secretFile,
                $nas->nas_ip.':'.$nas->coa_port,
                'disconnect',
            ]);
        } finally {
            @unlink($secretFile);
        }

        $ack = $result->successful() && str_contains($result->output(), 'Disconnect-ACK');
        Log::log($ack ? 'info' : 'warning', 'RADIUS Disconnect-Request', [
            'nas' => $nas->nas_ip,
            'acct_session_id' => $acct->acct_session_id,
            'attributes' => array_keys($map),
            'result' => $ack ? 'ack' : 'failed',
            'output' => $ack ? null : trim($result->errorOutput()."\n".$result->output()),
        ]);

        return $ack;
    }
}
