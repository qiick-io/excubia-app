<?php

declare(strict_types=1);

namespace App\Rules;

use App\Contracts\FileScannerContract;
use App\Enums\FileScanResultEnum;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Translation\PotentiallyTranslatedString;

/** Requires a clean verdict when the operator enabled upload scanning. */
final class ScannedUploadRule implements ValidationRule
{
    /** @param Closure(string, ?string=): PotentiallyTranslatedString $fail */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! app()->bound(FileScannerContract::class)) {
            return;
        }

        $verdict = app(FileScannerContract::class)->scan($value);

        if ($verdict === FileScanResultEnum::Infected) {
            Log::warning('Upload blocked by malware scanner.', ['event' => 'upload.malware_detected']);
            $fail('validation.upload_malware_detected')->translate();
        }

        if ($verdict === FileScanResultEnum::Unavailable) {
            Log::warning('Upload malware scanner unavailable.');
            $fail('validation.upload_scan_unavailable')->translate();
        }
    }
}
