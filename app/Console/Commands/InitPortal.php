<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use App\Support\LocalInstance;
use Illuminate\Console\Command;

class InitPortal extends Command
{
    protected $signature = 'portal:init';

    protected $description = 'Ensure the local instance identity exists (run at container start)';

    public function handle(LocalInstance $instance): int
    {
        $this->line('Instance: '.$instance->id());

        if (! User::query()->where('role', Role::Admin)->exists()) {
            $this->warn('No admin user yet. Create one with: artisan portal:admin');
        }

        return self::SUCCESS;
    }
}
