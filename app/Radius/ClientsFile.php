<?php

namespace App\Radius;

use App\Models\NasClient;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Renders the FreeRADIUS clients file from the routers served here (CONCEPT.md §2.2, §10.4).
 * A watcher service in the container restarts FreeRADIUS when the file changes.
 */
class ClientsFile
{
    /** RADIUS secret: printable ASCII without whitespace and without characters of the file syntax. */
    private const SECRET_PATTERN = '/^[\x21-\x7E]{1,128}$/D';

    private const SECRET_FORBIDDEN = '{}#"\\';

    public function render(): string
    {
        $out = "# Managed by the portal. Do not edit.\n";

        // Only routers of sites served by this instance (CONCEPT.md §10.4); the replicated others are
        // kept for recovery. NAS IPs are IPv4 only for now (IPv6 later). Rows arrive by replication
        // without the admin form's validation: invalid values are never rendered.
        foreach (NasClient::query()->with('site')->orderBy('id')->get()->filter->isServedHere() as $nas) {
            if (($problem = self::problem($nas)) !== null) {
                Log::error('Router left out of the FreeRADIUS clients file', ['nas' => mb_substr((string) $nas->id, 0, 40),
                    'reason' => $problem]);

                continue;
            }
            $out .= sprintf(
                "\nclient %s {\n    ipaddr = %s\n    secret = \"%s\"\n    shortname = %s\n}\n",
                $nas->shortname(),
                $nas->nas_ip,
                $nas->radius_secret,
                $nas->shortname(),
            );
        }

        return $out;
    }

    /** Why a router cannot be rendered into the clients file, null: valid. */
    public static function problem(NasClient $nas): ?string
    {
        return self::rowProblem((string) $nas->getKey(), (string) $nas->nas_ip)
            ?? (self::validSecret($nas->radius_secret) ? null : 'RADIUS secret contains characters not allowed in the clients file');
    }

    /** Checks of the raw row values (also used by the federation apply engine). */
    public static function rowProblem(string $id, string $nasIp): ?string
    {
        return match (true) {
            preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/iD', $id) !== 1 => 'id is not a ULID',
            ! self::validAddress($nasIp) => 'NAS IP is not an IP address or network',
            default => null,
        };
    }

    /** A single IPv4/IPv6 address or a network in CIDR notation (not /0). */
    public static function validAddress(string $value): bool
    {
        [$ip, $prefix] = array_pad(explode('/', $value, 2), 2, null);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return $prefix === null || (preg_match('/^[0-9]{1,3}$/D', $prefix) === 1
            && (int) $prefix >= 1 && (int) $prefix <= (str_contains($ip, ':') ? 128 : 32));
    }

    public static function validSecret(mixed $secret): bool
    {
        return is_string($secret) && preg_match(self::SECRET_PATTERN, $secret) === 1 && strpbrk($secret, self::SECRET_FORBIDDEN) === false;
    }

    /** Write the file atomically if its content changed. Returns true when written. */
    public function write(): bool
    {
        $path = config('portal.radius.clients_file');
        $content = $this->render();

        if (File::exists($path) && File::get($path) === $content) {
            return false;
        }

        File::ensureDirectoryExists(dirname($path));
        // Contains secrets: create with restrictive permissions, then rename atomically.
        $tmp = $path.'.tmp';
        $umask = umask(0027);
        try {
            @unlink($tmp);
            File::put($tmp, $content);
            chmod($tmp, 0640);
        } finally {
            umask($umask);
        }
        File::move($tmp, $path);

        return true;
    }
}
