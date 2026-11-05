<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AlertEventEnum;
use App\Models\Project;
use App\Services\AlertDispatcherService;
use Illuminate\Support\Str;

/**
 * Issues a new push token for a project and replaces the old one. Only the SHA-256 hash is
 * stored (a fast hash is enough for a 40-character random token and lets the push endpoint
 * look the project up by hash). The plain token is returned once and never logged.
 */
class RotateProjectTokenAction
{
    public const TOKEN_PREFIX = 'excubia_';

    public function execute(Project $project): string
    {
        $token = self::TOKEN_PREFIX.Str::random(40);

        $project->forceFill(['push_token_hash' => hash('sha256', $token)])->save();

        activity()->performedOn($project)->event('token_rotated')->log('Push token rotated');
        app(AlertDispatcherService::class)->dispatch(AlertEventEnum::ProjectTokenChanged, $project, 'push token rotated', ['change' => 'rotated']);

        return $token;
    }
}
