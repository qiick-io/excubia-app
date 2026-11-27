<?php

declare(strict_types=1);

namespace App\Traits;

use App\Enums\RuntimeTypeEnum;
use Illuminate\Http\JsonResponse;

/**
 * The 2xx answer of `POST /api/runtime` and `POST /api/exceptions` (`docs/API.md`): `201` for a
 * new batch, `200` for a repeated `batchId`, both with the app's `capabilities` (#239,
 * excubia-core design 1.1 §6.4 and §10 A2). excubia-core 1.1 reads them to stop sending runtime
 * types this app drops and to negotiate the personal data level; core 1.0 ignores the key.
 */
trait AnswersPushedBatchTrait
{
    /**
     * Personal data level this app accepts. Always `aggregate` in 1.x until the levels exist
     * (design §6.1): core never sends personal fields to an app that advertises it.
     */
    private const PRIVACY_LEVEL = 'aggregate';

    /**
     * @param  int  $skipped  entries of the batch the app does not store; the same on a duplicate
     */
    private function pushedBatchResponse(bool $isNew, int $skipped = 0): JsonResponse
    {
        return response()->json([
            'status' => $isNew ? 'stored' : 'duplicate',
            'capabilities' => [
                'privacyLevel' => self::PRIVACY_LEVEL,
                'types' => RuntimeTypeEnum::values(),
                'skipped' => $skipped,
            ],
        ], $isNew ? 201 : 200);
    }
}
