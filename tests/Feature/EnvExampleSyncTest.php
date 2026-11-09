<?php

declare(strict_types=1);

use Qiick\Excubia\Checks\EnvExampleSyncCheck;
use Qiick\Excubia\Configuration\ExcubiaConfigurationLoader;
use Qiick\Excubia\Services\ProjectSnapshotService;

/**
 * The system project checks this install's own code (#222): every key `config/*.php` reads is
 * listed in `.env.example`, and nothing listed there is unread, so a fresh install's first report
 * has no `env-example-sync` findings a self-hoster cannot act on. Runs excubia-core's own check
 * on the app, as RunSystemReportAction does; it reads key names only, never `.env`.
 */
it('keeps .env.example in sync with the keys config/*.php reads', function () {
    $configuration = (new ExcubiaConfigurationLoader)->load(null, base_path());
    $snapshot = (new ProjectSnapshotService)->snapshot(base_path(), $configuration);

    $result = (new EnvExampleSyncCheck)->run($snapshot, $configuration);

    expect(array_map(fn ($finding): string => $finding->message, $result->findings))->toBe([]);
});
