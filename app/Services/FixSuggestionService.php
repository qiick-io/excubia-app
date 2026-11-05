<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ExceptionGroup;

/**
 * A ready fix for a finding (#78), built from the report alone with no AI call: a shell command
 * where one exists, and a prompt for the user's own coding assistant. The text holds only safe
 * report data (package names, versions, advisory ids, check names, the core's fix hint and
 * evidence URL): never paths, hostnames or user data.
 */
class FixSuggestionService
{
    /**
     * @param  array<string, mixed>  $finding  a report finding, optionally with `checkName`
     * @return array{command: string|null, prompt: string}
     */
    public function forFinding(array $finding): array
    {
        $checkId = (string) ($finding['checkId'] ?? '');
        $details = is_array($finding['details'] ?? null) ? $finding['details'] : [];
        $package = $this->text($details['package'] ?? null) ?? $this->text($finding['subject'] ?? null) ?? '';
        $locked = $this->version($details['lockedVersion'] ?? null);
        $fixed = $this->list($details['fixedVersions'] ?? null);
        $advisoryId = $this->text($finding['advisoryId'] ?? null);

        [$command, $task] = match ($checkId) {
            'composer-advisories' => [
                $this->shellSafe($package) ? "composer update {$package} --with-dependencies" : null,
                "Update the Composer package `{$package}`".($locked ? " (installed {$locked})" : '').' to a version that fixes the advisory'.($advisoryId ? " {$advisoryId}" : '').($fixed !== '' ? " (fixed in {$fixed})" : '').'. Widen its constraint in composer.json only if the fixed version needs it, and read the changelog for breaking changes before updating.',
            ],
            'npm-advisories' => [
                $this->shellSafe($package) ? ($fixed !== '' && $this->shellSafe($this->firstOf($details['fixedVersions'] ?? null)) ? "npm install {$package}@{$this->firstOf($details['fixedVersions'] ?? null)}" : "npm update {$package}") : null,
                "Update the npm package `{$package}`".($locked ? " (installed {$locked})" : '').' to a version that fixes the advisory'.($advisoryId ? " {$advisoryId}" : '').($fixed !== '' ? " (fixed in {$fixed})" : '').'. If it is a transitive dependency, update the direct dependency that pulls it in, or add an override.',
            ],
            'composer-abandoned' => [
                null,
                "The Composer package `{$package}`".($locked ? " ({$locked})" : '').' is abandoned.'.(($replacement = $this->text($details['replacement'] ?? null)) ? " Replace it with `{$replacement}`," : ' Find a maintained replacement,').' update the code that uses it, and remove the old package.',
            ],
            'composer-stale' => [
                null,
                "The Composer package `{$package}`".($locked ? " ({$locked})" : '').' has had no release for a long time. Check whether it is still maintained and compatible with the next PHP and Laravel versions; plan a replacement if it is not.',
            ],
            'laravel-next-blocker' => [
                null,
                "The Composer package `{$package}`".($locked ? " ({$locked})" : '').' blocks the upgrade to Laravel '.($this->text($details['targetMajor'] ?? null) ?? 'next major').': its latest release does not allow it. Check for a newer release, a fork or a replacement, so the Laravel upgrade can go ahead.',
            ],
            'php-eol', 'laravel-eol', 'node-eol' => [
                null,
                'Upgrade '.['php-eol' => 'PHP', 'laravel-eol' => 'Laravel', 'node-eol' => 'Node.js'][$checkId].(($cycle = $this->text($details['cycle'] ?? null)) ? " from {$cycle}" : '').' to a supported version'.(($ends = $this->text($details['securitySupportEndsOn'] ?? null)) ? " (security support ends on {$ends})" : '').'. Follow the official upgrade guide step by step and run the test suite after each step.',
            ],
            'laravel-debug-mode' => [
                null,
                'Debug mode is on outside local and testing. Set `APP_DEBUG=false` in the environment of this deployment, then clear the cached configuration (`php artisan config:cache`).',
            ],
            'laravel-production-caches' => [
                'php artisan optimize',
                'The production caches are missing ('.$this->subject($finding).'). Run `php artisan optimize` as part of the deployment so configuration, routes and events are cached.',
            ],
            'laravel-pending-migrations' => [
                'php artisan migrate --force',
                'There are pending migrations'.(isset($details['count']) ? ' ('.(int) $details['count'].')' : '').'. Review them, then run `php artisan migrate --force` as part of the deployment.',
            ],
            'laravel-failed-jobs' => [
                'php artisan queue:failed',
                'There are failed queue jobs'.(isset($details['count']) ? ' ('.(int) $details['count'].')' : '').'. List them with `php artisan queue:failed`, fix the cause, then retry them (`php artisan queue:retry all`) or forget them.',
            ],
            'laravel-scheduler-heartbeat' => [
                null,
                'The Laravel scheduler is not running'.(isset($details['minutesSinceLastRun']) ? ' (last run '.(int) $details['minutesSinceLastRun'].' minutes ago)' : '').'. Add the cron entry `* * * * * php artisan schedule:run` (or keep `php artisan schedule:work` running) on the server.',
            ],
            'laravel-maintenance-mode' => [
                'php artisan up',
                'The application is in maintenance mode. If that is not intended, bring it back with `php artisan up`.',
            ],
            default => [null, $this->text($finding['fixHint'] ?? null) ?? (string) ($finding['message'] ?? '')],
        };

        return [
            'command' => $command,
            'prompt' => $this->prompt($finding, $task),
        ];
    }

    /**
     * A prompt to fix an exception group (#26): its class, masked message, where it ran, the
     * previous exceptions and the in-app frames (relative paths, as the core sends them).
     *
     * @return array{command: null, prompt: string}
     */
    public function forException(ExceptionGroup $group): array
    {
        $frames = array_values(array_filter($group->frames, fn (array $frame): bool => $frame['inApp']));
        $lines = [
            "In this Laravel project, fix this exception reported by Excubia ({$group->count} occurrences in {$group->environment}, {$group->context_kind} `{$group->context_name}`):",
            "{$group->class}: {$group->message}",
        ];

        if ($group->previous !== []) {
            $lines[] = 'Caused by: '.implode(' ← ', $group->previous);
        }

        if ($frames !== []) {
            $lines[] = '';
            $lines[] = 'Stack (project code, innermost first):';

            foreach (array_slice($frames, 0, 10) as $frame) {
                $lines[] = '- '.($frame['file'] ?? '?').($frame['line'] !== null ? ':'.$frame['line'] : '').($frame['function'] !== null ? ' in '.$frame['function'] : '');
            }
        }

        $lines[] = '';
        $lines[] = 'Find the cause, fix it with the smallest change, add a test that reproduces it, run the test suite, and list what you changed. Placeholders like [email] or [number] are masked values.';

        return ['command' => null, 'prompt' => implode("\n", $lines)];
    }

    /**
     * The prompt: context line, the task, the core's own hint and evidence, and how to finish.
     *
     * @param  array<string, mixed>  $finding
     */
    private function prompt(array $finding, string $task): string
    {
        $lines = ['In this Laravel project, fix this finding from an Excubia health report ('.($this->text($finding['checkName'] ?? null) ?? (string) ($finding['checkId'] ?? 'check')).', severity '.($this->text($finding['severity'] ?? null) ?? 'unknown').'):'];

        if (($message = $this->text($finding['message'] ?? null)) !== null) {
            $lines[] = $message;
        }

        $lines[] = '';
        $lines[] = $task;

        if (($hint = $this->text($finding['fixHint'] ?? null)) !== null) {
            $lines[] = "Excubia's hint: {$hint}";
        }

        if (($url = $this->text($finding['evidenceUrl'] ?? null)) !== null && str_starts_with($url, 'https://')) {
            $lines[] = "Details: {$url}";
        }

        $lines[] = '';
        $lines[] = 'Keep the change minimal, run the test suite, and list what you changed.';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $finding
     */
    private function subject(array $finding): string
    {
        return $this->text($finding['subject'] ?? null) ?? 'config, routes or events';
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function version(mixed $value): ?string
    {
        return ($text = $this->text($value)) === null ? null : ltrim($text, 'vV');
    }

    private function list(mixed $value): string
    {
        return is_array($value) ? implode(', ', array_filter($value, 'is_string')) : '';
    }

    private function firstOf(mixed $value): string
    {
        return is_array($value) && is_string($value[0] ?? null) ? $value[0] : '';
    }

    /**
     * A package name or version safe to put in a copyable command: no shell metacharacters.
     */
    private function shellSafe(string $value): bool
    {
        return $value !== '' && preg_match('/^[A-Za-z0-9@\/._\-]+$/', $value) === 1 && ! str_contains($value, '..');
    }
}
