<?php

declare(strict_types=1);

namespace PdfApi;

final class TokenFile
{
    public function __construct(private readonly string $path)
    {
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $hash): ?array
    {
        $records = $this->load(true);
        if (!isset($records[$hash]) || !is_array($records[$hash])) {
            return null;
        }

        return $records[$hash];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function load(bool $required): array
    {
        if (!is_file($this->path)) {
            if ($required) {
                throw new HttpException(500, 'tokens_unconfigured', 'Token file is missing');
            }

            return [];
        }
        $this->assertPrivate();
        $handle = fopen($this->path, 'rb');
        if ($handle === false) {
            throw new HttpException(500, 'tokens_unconfigured', 'Token file could not be read');
        }
        try {
            if (!flock($handle, LOCK_SH)) {
                throw new HttpException(500, 'tokens_unconfigured', 'Token file could not be read');
            }
            $raw = stream_get_contents($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        if (!is_string($raw) || trim($raw) === '') {
            throw new HttpException(500, 'tokens_unconfigured', 'Token file is empty');
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpException(500, 'tokens_unconfigured', 'Token file is malformed');
        }
        if (!is_array($decoded)) {
            throw new HttpException(500, 'tokens_unconfigured', 'Token file is malformed');
        }
        $records = [];
        foreach ($decoded as $hash => $record) {
            if (!is_string($hash) || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1 || !is_array($record)) {
                throw new HttpException(500, 'tokens_unconfigured', 'Token file is malformed');
            }
            $records[$hash] = $record;
        }

        return $records;
    }

    /**
     * @param array<string, array<string, mixed>> $records
     */
    public function save(array $records): void
    {
        $this->assertOutsidePublic(dirname($this->path));
        $directory = dirname($this->path);
        $mode = $this->isRoot() ? 0750 : 0700;
        if (!is_dir($directory) && !@mkdir($directory, $mode, true) && !is_dir($directory)) {
            throw new \RuntimeException(
                'Could not create ' . $directory . '. Run this as root on the container, or pass --file to a private path you can write.',
            );
        }
        if (!$this->isRoot()) {
            chmod($directory, 0700);
        }
        $temporary = $this->path . '.tmp';
        $umask = umask(0077);
        $encoded = json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $written = file_put_contents($temporary, $encoded, LOCK_EX);
        umask($umask);
        if ($written === false) {
            throw new \RuntimeException('Token file could not be written');
        }
        chmod($temporary, 0600);
        if (!rename($temporary, $this->path)) {
            unlink($temporary);
            throw new \RuntimeException('Token file could not be written');
        }
        chmod($this->path, 0600);
        if ($this->isRoot() && $this->hasGroup('lrtc-pdf')) {
            if (!chgrp($this->path, 'lrtc-pdf')) {
                throw new \RuntimeException('Could not give the lrtc-pdf group access to the token file');
            }
            chmod($this->path, 0440);
        }
        $this->assertPrivate();
    }

    private function assertPrivate(): void
    {
        $perms = fileperms($this->path);
        if ($perms === false || ($perms & 0027) !== 0) {
            throw new HttpException(500, 'tokens_exposed', 'Token file must not be writable by the group or accessible to other users');
        }
        $real = realpath($this->path);
        if ($real === false) {
            throw new HttpException(500, 'tokens_unconfigured', 'Token file could not be read');
        }
        $this->assertOutsidePublic($real);
    }

    private function assertOutsidePublic(string $path): void
    {
        $public = realpath(dirname(__DIR__) . '/public');
        $candidate = realpath($path) ?: $path;
        if ($public !== false && ($candidate === $public || str_starts_with($candidate, $public . DIRECTORY_SEPARATOR))) {
            throw new HttpException(500, 'tokens_exposed', 'Token file must not live in the public directory');
        }
    }

    private function isRoot(): bool
    {
        $status = @file_get_contents('/proc/self/status');

        return is_string($status) && preg_match('/^Uid:\s+0\s/m', $status) === 1;
    }

    private function hasGroup(string $name): bool
    {
        $lines = @file('/etc/group', FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            return false;
        }
        $prefix = $name . ':';
        foreach ($lines as $line) {
            if (str_starts_with($line, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
