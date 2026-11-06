<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\StorePushedRuntimeAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePushedRuntimeRequest;
use App\Models\Project;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/runtime`: receives the runtime aggregates pushed by `excubia:push-runtime`
 * (qiick/excubia-core). Answers 201 for a new batch and 200 for a repeated `batchId`.
 */
class PushedRuntimeController extends Controller
{
    public function store(StorePushedRuntimeRequest $request, StorePushedRuntimeAction $storeRuntime): JsonResponse
    {
        /** @var Project $project authorized by the request */
        $project = $request->project();

        /** @var list<array<string, mixed>> $aggregates */
        $aggregates = $request->json('runtime.aggregates');

        $release = $request->json('runtime.release');
        $isNew = $storeRuntime->execute($project, $request->string('batchId')->toString(), $aggregates, is_string($release) ? $release : null);

        return response()->json(['status' => $isNew ? 'stored' : 'duplicate'], $isNew ? 201 : 200);
    }
}
