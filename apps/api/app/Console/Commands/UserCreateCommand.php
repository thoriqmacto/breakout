<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Creates a user account from the CLI.
 *
 * The registration endpoint is the normal path, but it depends on the whole
 * chain being up -- nginx, PHP-FPM, CORS, and the frontend pointing at the
 * right API URL. This talks to the database directly, so a first account can
 * be created before any of that is working, and a broken sign-up form can be
 * told apart from a broken API.
 */
class UserCreateCommand extends Command
{
    protected $signature = 'user:create
        {--name= : Full name}
        {--email= : Email address, must not already exist}
        {--password= : Plain-text password; omit to be prompted without echo}';

    protected $description = 'Create a user account directly, without going through /api/auth/register.';

    public function handle(): int
    {
        $name = (string) ($this->option('name') ?: $this->ask('Full name'));
        $email = (string) ($this->option('email') ?: $this->ask('Email address'));

        // Passing --password puts the plain text in the shell history and the
        // process list, so prompting is the default and the value is hidden.
        $password = (string) $this->option('password');

        if ($password === '') {
            $password = (string) $this->secret('Password (min 8 characters)');
            $confirmation = (string) $this->secret('Confirm password');

            if ($password !== $confirmation) {
                $this->error('The passwords do not match.');

                return self::FAILURE;
            }
        }

        // Same rules the API enforces, so a CLI-created account cannot slip
        // past a constraint that registration would have applied.
        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')],
                'password' => ['required', Password::defaults()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        /** @var User $user */
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ]);

        $this->info("Created user #{$user->id} <{$user->email}>.");
        $this->line('Sign in with:');
        $this->line("  curl -X POST \"\${API_URL}/auth/login\" -H 'Accept: application/json' \\");
        $this->line("    -d 'email={$user->email}' -d 'password=...'");

        return self::SUCCESS;
    }
}
