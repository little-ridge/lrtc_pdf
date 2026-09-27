<?php

declare(strict_types=1);

namespace PdfApi;

final class Config
{
    public function __construct(
        public readonly string $dataDir,
        public readonly string $tokensFile,
    ) {
    }

    public static function fromEnvironment(): self
    {
        $dataDir = getenv('PDF_DATA_DIR');

        return new self(
            is_string($dataDir) && $dataDir !== '' ? $dataDir : '/var/lib/lrtc-pdf',
            self::tokensPath(),
        );
    }

    public static function tokensPath(): string
    {
        $tokensFile = getenv('TOKENS_FILE');
        if (is_string($tokensFile) && $tokensFile !== '') {
            return $tokensFile;
        }
        $status = @file_get_contents('/proc/self/status');
        if (is_string($status) && preg_match('/^Uid:\s+0\s/m', $status) === 1) {
            return '/etc/lrtc-pdf/tokens.json';
        }
        $configHome = getenv('XDG_CONFIG_HOME');
        if (is_string($configHome) && $configHome !== '') {
            return rtrim($configHome, '/') . '/lrtc-pdf/tokens.json';
        }
        $home = getenv('HOME');
        if (is_string($home) && $home !== '') {
            return rtrim($home, '/') . '/.config/lrtc-pdf/tokens.json';
        }

        return '/etc/lrtc-pdf/tokens.json';
    }
}
