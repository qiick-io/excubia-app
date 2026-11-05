<?php

declare(strict_types=1);

namespace App\Actions;

use App\Services\SettingsRepositoryService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Stores the uploaded logo, dark logo and favicon on the local disk (`branding/`) and saves
 * their paths in settings. A removed or replaced file is deleted from the disk.
 */
class SaveBrandingFilesAction
{
    public function __construct(private SettingsRepositoryService $settings) {}

    /**
     * @param  array<string, UploadedFile>  $uploads  setting key (`project_logo`, …) → new file
     * @param  list<string>  $removals  setting keys to clear
     * @return array<string, string|null> the saved setting values, for the activity log
     */
    public function execute(array $uploads, array $removals): array
    {
        $current = $this->settings->all();
        $values = [];

        foreach (SettingsRepositoryService::BRANDING_ASSETS as $key) {
            $upload = $uploads[$key] ?? null;

            if ($upload === null && ! in_array($key, $removals, true)) {
                continue;
            }

            $this->delete($current[$key] ?? null);
            $values[$key] = $upload === null ? null : $this->store($upload);
        }

        if ($values !== []) {
            $this->settings->setMany($values);
        }

        return $values;
    }

    /**
     * @throws RuntimeException when the disk refuses the file
     */
    private function store(UploadedFile $upload): string
    {
        $path = $upload->storeAs('branding', Str::random(20).'.'.$upload->extension(), 'local');

        if ($path === false) {
            throw new RuntimeException('The branding file could not be stored.');
        }

        return $path;
    }

    private function delete(mixed $path): void
    {
        if (is_string($path) && $path !== '') {
            Storage::disk('local')->delete($path);
        }
    }
}
