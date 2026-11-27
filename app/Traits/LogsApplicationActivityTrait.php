<?php

declare(strict_types=1);

namespace App\Traits;

use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Default Spatie activity log configuration for application models (as in qiick-io/externa-core):
 * every changed attribute of a create, update or delete, logged with the acting user as causer.
 */
trait LogsApplicationActivityTrait
{
    use LogsActivity;

    /**
     * Configure Spatie activity-log options for the using model.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return $this->applicationActivityLogOptions();
    }

    /**
     * Default activity log options for models using this trait.
     */
    protected function applicationActivityLogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('default');
    }
}
