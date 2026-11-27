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

    public function project(): ?Project
    {
        $project = $this->attributes->get('project');

        return $project instanceof Project ? $project : null;
    }
}
