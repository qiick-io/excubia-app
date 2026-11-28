<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Gives ingest validators a single JSON body and the project authenticated by the token middleware.
 */
abstract class IngestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->project() instanceof Project;
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->isJson() ? $this->json()->all() : [];
    }

    /** @return array{batchId: string, sentAt: string} */
    public function envelope(): array
    {
        /** @var array{batchId: string, sentAt: float|int|string} $validated */
        $validated = $this->validated();

        return [
            'batchId' => $validated['batchId'],
            'sentAt' => (string) $validated['sentAt'],
        ];
    }

    public function project(): ?Project
    {
        $project = $this->attributes->get('project');

        return $project instanceof Project ? $project : null;
    }
}
