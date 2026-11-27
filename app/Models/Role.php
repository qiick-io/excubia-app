<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\LogsApplicationActivityTrait;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Spatie role with activity logging (`permission.models.role`), as in qiick-io/externa-core.
 */
class Role extends SpatieRole
{
    use LogsApplicationActivityTrait;
}
