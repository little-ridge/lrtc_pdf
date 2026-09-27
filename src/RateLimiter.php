<?php

declare(strict_types=1);

namespace PdfApi;

final class RateLimiter
{
    public function __construct(private readonly string $directory)
    {
    }

    public function enforce(string $hash, int $limit, int $windowSeconds): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new HttpException(500, 'storage_unavailable', 'PDF_DATA_DIR is not writable');
        }
        $windowStart = intdiv(time(), $windowSeconds);
        $path = $this->directory . '/' . $hash . '.' . $windowStart;
        $handle = fopen($path, 'c+');
        if ($handle === false) {
            throw new HttpException(500, 'storage_unavailable', 'PDF_DATA_DIR is not writable');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new HttpException(500, 'storage_unavailable', 'PDF_DATA_DIR is not writable');
            }
            $raw = stream_get_contents($handle);
            $current = is_string($raw) && $raw !== '' ? (int) $raw : 0;
            if ($current >= $limit) {
                throw new HttpException(429, 'rate_limited', 'Too many requests', [
                    'limit' => $limit,
                    'window_seconds' => $windowSeconds,
                ], [
                    'Retry-After' => (string) $windowSeconds,
                ]);
            }
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, (string) ($current + 1));
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        $this->forgetOlder($hash, $windowStart);
    }

    private function forgetOlder(string $hash, int $windowStart): void
    {
        foreach (glob($this->directory . '/' . $hash . '.*') ?: [] as $path) {
            $suffix = substr($path, strlen($this->directory . '/' . $hash . '.'));
            if ($suffix !== '' && ctype_digit($suffix) && (int) $suffix < $windowStart - 1) {
                unlink($path);
            }
        }
    }
}
