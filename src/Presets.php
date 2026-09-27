<?php

declare(strict_types=1);

namespace PdfApi;

final class Presets
{
    /** @var array<string, mixed>|null */
    private static ?array $data = null;

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        if (self::$data === null) {
            $path = dirname(__DIR__) . '/presets.json';
            $raw = file_get_contents($path);
            if ($raw === false) {
                throw new HttpException(500, 'internal', 'Presets could not be loaded');
            }
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                throw new HttpException(500, 'internal', 'Presets could not be loaded');
            }
            self::$data = $decoded;
        }

        return self::$data;
    }

    public static function maxHtmlBytes(): int
    {
        return (int) self::all()['max_html_bytes'];
    }

    public static function maxBodyBytes(): int
    {
        return (int) self::all()['max_body_bytes'];
    }

    /**
     * @return list<string>
     */
    public static function fonts(): array
    {
        /** @var list<string> $fonts */
        $fonts = self::all()['fonts'];

        return $fonts;
    }

    /**
     * @return list<string>
     */
    public static function paperNames(): array
    {
        return array_keys(self::papers());
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function papers(): array
    {
        /** @var array<string, array<string, mixed>> $papers */
        $papers = self::all()['papers'];

        return $papers;
    }

    /**
     * @return array<string, mixed>
     */
    public static function paper(string $name): array
    {
        $papers = self::papers();
        if (!isset($papers[$name])) {
            throw new HttpException(400, 'invalid_paper', 'Unknown paper preset', [
                'paper' => $name,
                'allowed' => self::paperNames(),
            ]);
        }

        return $papers[$name];
    }

    public static function assertFont(string $font): void
    {
        if (!in_array($font, self::fonts(), true)) {
            throw new HttpException(400, 'invalid_font', 'Unknown font', [
                'font' => $font,
                'allowed' => self::fonts(),
            ]);
        }
        if ($font === 'emoji' && !is_file(self::emojiPath())) {
            throw new HttpException(400, 'invalid_font', 'The emoji font is not installed');
        }
    }

    public static function emojiPath(): string
    {
        return dirname(__DIR__) . '/fonts/NotoEmoji-VariableFont_wght.ttf';
    }

    public static function fontDirectory(): string
    {
        return dirname(__DIR__) . '/fonts';
    }

    public static function templateDirectory(): string
    {
        return dirname(__DIR__) . '/templates';
    }
}
