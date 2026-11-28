<?php

declare(strict_types=1);

use App\Contracts\FileScannerContract;
use App\Providers\AppServiceProvider;
use App\Services\ClamAvFileScannerService;
use Tests\TestCase;

uses(TestCase::class);

it('registers the ClamAV driver when booting with supported socket configuration', function (string $endpoint): void {
    config(['excubia.upload_scanner' => 'clamav', 'excubia.clamav_endpoint' => $endpoint]);
    (new AppServiceProvider(app()))->register();

    expect(app(FileScannerContract::class))->toBeInstanceOf(ClamAvFileScannerService::class);
})->with(['unix:///run/clamav/clamd.ctl', 'tcp://scanner:3310']);

it('rejects invalid upload scanner settings at boot', function (string $driver, string $endpoint, string $invalidSetting): void {
    config(['excubia.upload_scanner' => $driver, 'excubia.clamav_endpoint' => $endpoint]);

    expect(fn () => (new AppServiceProvider(app()))->register())->toThrow(RuntimeException::class, $invalidSetting);
})->with([
    'unknown driver' => ['other', 'unix:///run/clamav/clamd.ctl', 'excubia.upload_scanner'],
    'unsupported socket scheme' => ['clamav', 'https://scanner', 'excubia.clamav_endpoint'],
]);
