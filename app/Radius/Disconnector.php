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
    public const ACK = 'ack';

    /** The NAS answered Disconnect-NAK (e.g. no such session): retrying does not help (decision 2026-11-04, v0.28.0). */
    public const NAK = 'nak';

    /** No answer, or not sent (no router, not addressable safely): retried by the job. */
    public const FAILED = 'failed';

    public function disconnect(RadiusAccounting $acct): bool
    {
        return $this->send($acct) === self::ACK;
    }

    /** Sends the Disconnect-Request; ack, nak or failed. */
    public function send(RadiusAccounting $acct): string
    {
        $nas = $acct->nasClient;
        if ($nas === null) {
            return self::FAILED;
        }

        // Identifying attributes depend on the router (NAS driver); null: not addressable, not sent.
        $driver = $nas->site?->driver();
        $map = $driver?->disconnectAttributes($acct);
        if ($map === null || $map === []) {
            if ($driver === null) {
                Log::warning('RADIUS Disconnect-Request not sent: router has no site', ['nas' => $nas->nas_ip]);
            }

            return self::FAILED;
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

        $output = $result->output();
        $outcome = $result->successful() && str_contains($output, 'Disconnect-ACK') ? self::ACK
            : (str_contains($output, 'Disconnect-NAK') ? self::NAK : self::FAILED);
        Log::log($outcome === self::ACK ? 'info' : 'warning', 'RADIUS Disconnect-Request', [
            'nas' => $nas->nas_ip,
            'acct_session_id' => $acct->acct_session_id,
            'attributes' => array_keys($map),
            'result' => $outcome,
            'output' => $outcome === self::ACK ? null : trim($result->errorOutput()."\n".$output),
        ]);

        return $outcome;
    }
}
