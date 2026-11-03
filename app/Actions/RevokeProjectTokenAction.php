<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Project;

/**
 * Revokes a project's push token: pushes are rejected until a new token is issued.
 */
class RevokeProjectTokenAction
{
    public function execute(Project $project): void
    {
        $project->forceFill(['push_token_hash' => null])->save();

        activity()->performedOn($project)->event('token_revoked')->log('Push token revoked');
    }
}
