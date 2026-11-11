<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Project;
use App\Models\User;
use App\Services\SidebarProjectsService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Pins or unpins a project on the sidebar (#273): only a project the user may view, and at most
 * eight pins, the number the sidebar shows.
 */
class PinProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && (bool) $this->user()?->can('view', $project);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $user = $this->user();
                $project = $this->route('project');

                if (! $this->isMethod('POST') || ! $user instanceof User || ! $project instanceof Project) {
                    return;
                }

                $pins = $user->pinnedProjects();

                if (! (clone $pins)->whereKey($project->id)->exists() && $pins->count() >= SidebarProjectsService::PINNED_LIMIT) {
                    $validator->errors()->add('project', __('Unpin a project first: the sidebar keeps :count.', ['count' => SidebarProjectsService::PINNED_LIMIT]));
                }
            },
        ];
    }
}
