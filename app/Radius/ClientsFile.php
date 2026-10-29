<?php

namespace App\Radius;

use App\Models\NasClient;
use Illuminate\Support\Facades\File;

/**
 * Renders the FreeRADIUS clients file from the routers served here (CONCEPT.md §2.2, §10.4).
 * A watcher service in the container restarts FreeRADIUS when the file changes.
 */
class ClientsFile
{
    public function render(): string
    {
        $out = "# Managed by the portal. Do not edit.\n";

        // Only routers of sites served by this instance (CONCEPT.md §10.4); the replicated others are
        // kept for recovery. NAS IPs are IPv4 only for now (IPv6 later).
        foreach (NasClient::query()->with('site')->orderBy('id')->get()->filter->isServedHere() as $nas) {
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
