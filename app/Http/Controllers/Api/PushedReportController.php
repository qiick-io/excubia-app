<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\StorePushedReportAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePushedReportRequest;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Date;

/**
 * `POST /api/reports`: receives a report pushed by `excubia push` (qiick/excubia-core).
 * Answers 201 for a new report and 200 for a repeated `batchId`, both with a JSON body.
 */
class PushedReportController extends Controller
{
    public function store(StorePushedReportRequest $request, StorePushedReportAction $storeReport): JsonResponse
    {
        /** @var Project $project authorized by the request */
        $project = $request->project();

        /** @var array<string, mixed> $report */
        $report = $request->json('report');

        $healthReport = $storeReport->execute(
            $project,
            $request->string('batchId')->toString(),
            Date::parse($request->string('sentAt')->toString()),
            $report,
        );

        return response()->json(
            ['status' => $healthReport->wasRecentlyCreated ? 'stored' : 'duplicate', 'reportId' => $healthReport->id],
            $healthReport->wasRecentlyCreated ? 201 : 200,
        );
    }
}
