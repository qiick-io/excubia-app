<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Creates a dashboard user from the CLI.
 *
 * This is the way in when mail is not configured: there is no public sign-up. The email is
 * marked as verified because the operator created the account on the server.
 */
class CreateUserCommand extends Command
{
    protected $signature = 'excubia:create-user
        {--name= : Display name}
        {--email= : Login email}';

    protected $description = 'Create a dashboard user (the password is prompted)';

    public function handle(): int
    {
        $input = [
            'name' => $this->option('name') ?? text('Name', required: true),
            'email' => Str::lower((string) ($this->option('email') ?? text('Email', required: true))),
            'password' => password('Password', required: true),
        ];

        $validator = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::default()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = (new User)->forceFill([...$validator->validated(), 'email_verified_at' => now()]);
        $user->save();

        $this->info("Created user {$user->email}.");

        return self::SUCCESS;
    }
}
