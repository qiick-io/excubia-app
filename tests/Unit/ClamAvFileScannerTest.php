<?php

declare(strict_types=1);

use App\Enums\FileScanResultEnum;
use App\Services\ClamAvFileScannerService;
use Illuminate\Http\UploadedFile;

it('frames the upload and requires a complete clean clamd reply', function (?string $reply, FileScanResultEnum $verdict): void {
    $contents = "image\0bytes\n";
    $upload = UploadedFile::fake()->createWithContent('image.png', $contents);
    $command = "nINSTREAM\n".pack('N', strlen($contents)).$contents.pack('N', 0);
    $socketPath = tempnam(sys_get_temp_dir(), 'clamd-');
    expect($socketPath)->toBeString();
    unlink($socketPath);
    $endpoint = 'unix://'.$socketPath;
    $server = stream_socket_server($endpoint);
    expect(is_resource($server))->toBeTrue();
    $child = pcntl_fork();

    if ($child === 0) {
        // A local child serves the Unix socket without an external daemon, network or command.
        $connection = stream_socket_accept($server, 4);

        if ($connection === false) {
            exit(1);
        }

        stream_set_timeout($connection, 4);
        $received = stream_get_contents($connection, strlen($command));

        if ($received !== $command) {
            exit(2);
        }

        if ($reply !== null && fwrite($connection, $reply) !== strlen($reply)) {
            exit(3);
        }

        fclose($connection);
        exit(0);
    }

    try {
        expect($child)->toBeGreaterThan(0);
        expect(new ClamAvFileScannerService($endpoint)->scan($upload))->toBe($verdict);
    } finally {
        fclose($server);
        unlink($socketPath);

        if ($child > 0) {
            pcntl_waitpid($child, $status);
        }
    }

    expect($status)->toBe(0);
})->with([
    'clean' => ["stream: OK\n", FileScanResultEnum::Clean],
    'detection' => ["stream: Benign.Test.Signature FOUND\n", FileScanResultEnum::Infected],
    'error' => ["stream: daemon error ERROR\n", FileScanResultEnum::Unavailable],
    'closed socket' => [null, FileScanResultEnum::Unavailable],
    'unterminated success' => ['stream: OK', FileScanResultEnum::Unavailable],
    'daemon size limit' => ["INSTREAM size limit exceeded. ERROR\n", FileScanResultEnum::Unavailable],
]);
