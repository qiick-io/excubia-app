<?php

declare(strict_types=1);

use App\Models\Setting;
use App\Services\SettingsRepositoryService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fleet core schema: settings are scoped; instance rows use scope_id='' so the
 * unique index refuses duplicates on PostgreSQL and MySQL (signaculum-core #205).
 */
test('settings table has the scoped shape', function (): void {
    expect(Schema::hasColumns('settings', ['scope', 'scope_id', 'group', 'key', 'value']))->toBeTrue();
});

test('settings repository stores instance keys under scope instance and empty scope_id', function (): void {
    $settings = app(SettingsRepositoryService::class);
    $settings->setMany(['app_name' => 'Scoped Instance', 'login_max_attempts' => 7]);

    expect($settings->all()['app_name'])->toBe('Scoped Instance')
        ->and($settings->all()['login_max_attempts'])->toBe(7);

    $row = Setting::query()
        ->where('scope', SettingsRepositoryService::SCOPE_INSTANCE)
        ->where('scope_id', SettingsRepositoryService::SCOPE_ID_INSTANCE)
        ->where('group', SettingsRepositoryService::GROUP_APP)
        ->where('key', 'app_name')
        ->first();

    expect($row)->not->toBeNull()
        ->and($row?->scope_id)->toBe('')
        ->and($row?->value)->toBe('Scoped Instance');
});

test('a duplicate instance key is refused on postgresql and mysql', function (): void {
    if (! in_array(DB::getDriverName(), ['pgsql', 'mysql'], true)) {
        $this->markTestSkipped('Duplicate NULL-scope_id behaviour is engine-specific; empty-string uniqueness is checked on PostgreSQL and MySQL.');
    }

    Setting::query()->create([
        'scope' => SettingsRepositoryService::SCOPE_INSTANCE,
        'scope_id' => SettingsRepositoryService::SCOPE_ID_INSTANCE,
        'group' => SettingsRepositoryService::GROUP_APP,
        'key' => 'app_name',
        'value' => 'first',
    ]);

    expect(fn () => Setting::query()->create([
        'scope' => SettingsRepositoryService::SCOPE_INSTANCE,
        'scope_id' => SettingsRepositoryService::SCOPE_ID_INSTANCE,
        'group' => SettingsRepositoryService::GROUP_APP,
        'key' => 'app_name',
        'value' => 'second',
    ]))->toThrow(QueryException::class);
});
