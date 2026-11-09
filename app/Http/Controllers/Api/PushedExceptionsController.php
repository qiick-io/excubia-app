<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\StorePushedExceptionsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePushedExceptionsRequest;
use App\Models\Project;
use App\Traits\AnswersPushedBatchTrait;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/exceptions`: receives the masked exception groups pushed by `excubia:push-runtime`
 * (qiick/excubia-core). Answers 201 for a new batch and 200 for a repeated `batchId`, both with
 * the app's capabilities. A valid group is always stored, so nothing is skipped.
 */
class PushedExceptionsController extends Controller
{
    use AnswersPushedBatchTrait;

    public function store(StorePushedExceptionsRequest $request, StorePushedExceptionsAction $storeExceptions): JsonResponse
    {
        /** @var Project $project authorized by the request */
        $project = $request->project();

        /** @var list<array<string, mixed>> $groups */
        $groups = $request->json('exceptions.groups');

        $release = $request->json('exceptions.release');
        $isNew = $storeExceptions->execute($project, $request->string('batchId')->toString(), $groups, is_string($release) ? $release : null);

        return $this->pushedBatchResponse($isNew);
    }
}
