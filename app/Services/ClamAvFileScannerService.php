<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\FileScannerContract;
use App\Enums\FileScanResultEnum;
use Illuminate\Http\UploadedFile;

/** Sends validated image bytes to an operator-managed clamd using its INSTREAM protocol. */
final readonly class ClamAvFileScannerService implements FileScannerContract
{
    private const int TIMEOUT_SECONDS = 3;

    // The largest supported upload is a 1 MiB branding logo.
    private const int MAX_BYTES = 1024 * 1024;

    public function __construct(private string $endpoint) {}

    public function scan(UploadedFile $upload): FileScanResultEnum
    {
        $contents = @file_get_contents($upload->getPathname(), length: self::MAX_BYTES + 1);

        if ($contents === false || $contents === '' || strlen($contents) > self::MAX_BYTES) {
            return FileScanResultEnum::Unavailable;
        }

        $socket = @stream_socket_client($this->endpoint, timeout: self::TIMEOUT_SECONDS);

        if ($socket === false) {
            return FileScanResultEnum::Unavailable;
        }

        try {
            // clamd uses network byte order for lengths and a zero-length chunk to end the upload.
            $command = "nINSTREAM\n".pack('N', strlen($contents)).$contents.pack('N', 0);

            if (! stream_set_timeout($socket, self::TIMEOUT_SECONDS) || @fwrite($socket, $command) !== strlen($command)) {
                return FileScanResultEnum::Unavailable;
            }

            $reply = @fgets($socket, 4096);

            return match (true) {
                $reply === "stream: OK\n" => FileScanResultEnum::Clean,
                is_string($reply) && preg_match('/\Astream: [^\r\n]+ FOUND\n\z/', $reply) === 1 => FileScanResultEnum::Infected,
                default => FileScanResultEnum::Unavailable,
            };
        } finally {
            fclose($socket);
        }
    }
}
