<?php

namespace App\Console\Commands;

use App\Radius\ClientsFile;
use Illuminate\Console\Command;

class RenderRadiusClients extends Command
{
    protected $signature = 'portal:radius-clients';

    protected $description = 'Render the FreeRADIUS clients file from the configured routers';

    public function handle(ClientsFile $file): int
    {
        $this->line($file->write() ? 'Clients file written.' : 'Clients file unchanged.');

        return self::SUCCESS;
    }
}
