<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\CreateUserAction;
use App\Enums\RoleEnum;
use App\Models\User;
use App\Services\InitialSuperAdminPasswordService;
use Closure;
use Database\Seeders\PermissionSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Creates a dashboard user from the CLI.
 *
 * This is the way in when mail is not configured: there is no public sign-up. The email is
 * marked as verified because the operator created the account on the server. The first user
 * becomes super-admin by default, later users reader.
 */
class CreateUserCommand extends Command
{
    protected $signature = 'excubia:create-user
        {--first-name= : First name}
        {--last-name= : Last name (optional)}
        {--email= : Login email}
        {--role= : Role name (default: super-admin for the first user, reader afterwards)}';

    protected $description = 'Create a dashboard user (the password is prompted)';

    public function handle(CreateUserAction $createUser, InitialSuperAdminPasswordService $initialSuperAdminPassword): int
    {
        // Built-in roles must exist even when `db:seed` was never run.
        $this->callSilently('db:seed', ['--class' => PermissionSeeder::class, '--force' => true]);

        $defaultRole = User::query()->exists() ? RoleEnum::Reader->value : RoleEnum::SuperAdmin->value;
        $roles = Role::query()->orderBy('name')->pluck('name')->all();

        $input = [
            'first_name' => $this->option('first-name') ?? text('First name', required: true),
            'last_name' => $this->option('last-name') ?? text('Last name'),
            'email' => Str::lower((string) ($this->option('email') ?? text('Email', required: true))),
            'password' => password('Password', required: true),
            'role' => $this->option('role') ?? select('Role', $roles, default: $defaultRole),
        ];

        $validator = Validator::make($input, [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => [
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail) use ($initialSuperAdminPassword): void {
                    foreach ($initialSuperAdminPassword->rejectionReasons(is_string($value) ? $value : null) as $reason) {
                        $fail($reason);
                    }
                },
            ],
            'role' => ['required', 'string', Rule::in($roles)],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $validated = $validator->validated();

        $user = $createUser->execute(null, $validated['first_name'], ($validated['last_name'] ?? '') !== '' ? $validated['last_name'] : null, $validated['email'], null, $validated['password'], [$validated['role']]);

        $this->info("Created user {$user->email} with role {$validated['role']}.");

        return self::SUCCESS;
    }
}
