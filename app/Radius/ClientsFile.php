<?php

namespace App\Radius;

use App\Models\NasClient;
use Illuminate\Support\Facades\File;

/**
 * Renders the FreeRADIUS clients file from the routers (CONCEPT.md §2.2).
 * A watcher service in the container restarts FreeRADIUS when the file changes.
 */
class ClientsFile
{
    public function render(): string
    {
        $out = "# Managed by the portal. Do not edit.\n";

        foreach (NasClient::query()->orderBy('id')->get() as $nas) {
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
        $tmp = $path.'.tmp';
        File::put($tmp, $content);
        @chmod($tmp, 0640);
        File::move($tmp, $path);

        return true;
    }
}
