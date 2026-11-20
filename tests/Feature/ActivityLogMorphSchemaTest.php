<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * Fleet core schema: activity morph ids are string(64) and every row may name an
 * organisation (nullable; Excubia has none yet).
 */
test('activity_log uses string morph ids and a nullable organisation_id', function (): void {
    expect(Schema::hasColumns('activity_log', [
        'subject_type',
        'subject_id',
        'causer_type',
        'causer_id',
        'organisation_id',
    ]))->toBeTrue();

    $user = User::factory()->create();

    activity()
        ->causedBy($user)
        ->performedOn($user)
        ->log('schema check');

    $row = Activity::query()->latest('id')->first();

    expect($row)->not->toBeNull()
        ->and($row?->subject_id)->toBe((string) $user->getKey())
        ->and($row?->causer_id)->toBe((string) $user->getKey())
        ->and($row?->organisation_id)->toBeNull();
});
