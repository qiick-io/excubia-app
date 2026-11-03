<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\HealthReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Summary of a stored report: when it arrived, its worst severity and the count per severity
 * of the findings that warn or fail. The report body is sent separately, on the project page.
 *
 * @mixin HealthReport
 */
class HealthReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var HealthReport $report */
        $report = $this->resource;

        return [
            'id' => $report->id,
            'received_at' => $report->received_at->toIso8601String(),
            'generated_at' => $report->generated_at->toIso8601String(),
            'worst_severity' => $report->worst_severity?->value,
            'counts' => [
                'critical' => $report->critical_count,
                'high' => $report->high_count,
                'medium' => $report->medium_count,
                'low' => $report->low_count,
                'info' => $report->info_count,
            ],
        ];
    }
}
