<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class CreateAdmin extends Command
{
    protected $signature = 'portal:admin
    {--email= : Email address used to log in}
    {--name= : Display name}
    {--role=admin : admin or manager}
    {--password= : Password (omit to be prompted, or to generate one with --no-interaction)}';

    protected $description = 'Create an admin UI user (e.g. the first admin)';

    public function handle(): int
    {
        $email = $this->option('email') ?: text('Email', required: true);
        $name = $this->option('name') ?: ($this->input->isInteractive() ? text('Name', default: 'Admin', required: true) : 'Admin');
        $role = Role::tryFrom((string) $this->option('role'));
        $password = $this->option('password');
        $generated = false;

        if ($password === null || $password === '') {
            if ($this->input->isInteractive()) {
                $password = password('Password (min. 12 characters)', required: true);
            } else {
                $password = Str::password(20, symbols: false);
                $generated = true;
            }
        }

        $validator = Validator::make(
            ['email' => $email, 'name' => $name, 'role' => $role, 'password' => $password],
            [
                'email' => ['required', 'email', 'unique:users,email'],
                'name' => ['required', 'string', 'max:255'],
                'role' => ['required'],
                'password' => ['required', 'string', 'min:12'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create(['email' => $email, 'name' => $name, 'role' => $role, 'password' => $password]);

        $this->info("User {$user->email} ({$role->value}) created.");

        if ($generated) {
            $this->line("Generated password: {$password}");
        }

        return self::SUCCESS;
    }
}
