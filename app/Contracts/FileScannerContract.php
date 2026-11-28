<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Enums\FileScanResultEnum;
use Illuminate\Http\UploadedFile;

/** Checks a validated upload before the application stores it. */
interface FileScannerContract
{
    /** Reads the temporary upload and returns Unavailable if it cannot give a verdict. */
    public function scan(UploadedFile $upload): FileScanResultEnum;
}
