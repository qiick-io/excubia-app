<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Project;

/**
 * Sets or clears the project's monthly AI budget (#129): the estimated spend (USD) at whose 80 %
 * and 100 % it alerts. Null turns the budget alerts off. Logged like any project change.
 */
class UpdateAiBudgetAction
{
    public function execute(Project $project, ?float $budget): void
    {
        $project->forceFill(['ai_monthly_budget' => $budget])->save();
    }
}
