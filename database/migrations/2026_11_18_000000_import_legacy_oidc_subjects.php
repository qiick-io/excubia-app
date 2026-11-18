<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Moves SSO users onto qiick/laravel-oidc (#356) on every upgrade path, `excubia:upgrade` and
 * the Docker entrypoint alike (both only run `migrate`), so no SSO user is locked out between
 * the upgrade and a manual step:
 *
 * 1. `users.oidc_sub` is copied into `user_identities` by the package's own
 *    `oidc:import-legacy-subjects`, with the issuer exactly as saved in Settings → Application
 *    (the string the package compares with discovery and stores on new links). It is
 *    idempotent and never moves an identity that is already linked to another user: such a
 *    conflict is printed and skipped, and the upgrade goes on (re-run the command after fixing).
 * 2. Installs that had configured SSO keep linking existing users by verified email, as the
 *    local client always did: `oidc_auto_link` is stored as true unless it is already set. New
 *    installs get the package default (off).
 *
 * Without a saved issuer there is nothing to import. `users.oidc_sub` stays; a later
 * forward-only migration drops it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $issuer = $this->savedIssuer();

        if ($issuer === null) {
            return;
        }

        if (! DB::table('settings')->where('key', 'oidc_auto_link')->exists()) {
            DB::table('settings')->insert(['key' => 'oidc_auto_link', 'value' => 'true', 'created_at' => now(), 'updated_at' => now()]);
        }

        $output = app()->runningUnitTests() ? new BufferedOutput : new ConsoleOutput;
        $exitCode = Artisan::call('oidc:import-legacy-subjects', ['--issuer' => $issuer], $output);

        if ($exitCode !== 0) {
            $output->writeln('<comment>Some SSO identities were not imported (see above). Fix them, then run: php artisan oidc:import-legacy-subjects --issuer='.escapeshellarg($issuer).'</comment>');
        }
    }

    /**
     * The `oidc_issuer` setting as SettingsRepositoryService reads it (JSON, trimmed), or null.
     */
    private function savedIssuer(): ?string
    {
        $storedValue = DB::table('settings')->where('key', 'oidc_issuer')->value('value');
        $issuer = is_string($storedValue) ? json_decode($storedValue) : null;

        return is_string($issuer) && trim($issuer) !== '' ? trim($issuer) : null;
    }
};
