<?php

declare(strict_types=1);

namespace App\Enums;

/** Verdicts the upload validator can act on without exposing scanner details. */
enum FileScanResultEnum
{
    case Clean;
    case Infected;
    case Unavailable;
}
