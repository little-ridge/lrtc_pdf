<?php

declare(strict_types=1);

namespace PdfApi;

final class Seeds
{
    /**
     * @return list<array{id: string, paper: string, default_font: string, fields: array<string, mixed>|null, updated: null}>
     */
    public static function summaries(): array
    {
        $out = [];
        foreach (self::ids() as $id) {
            $meta = self::meta($id);
            $out[] = [
                'id' => $id,
                'paper' => is_string($meta['paper'] ?? null) ? $meta['paper'] : 'letter',
                'default_font' => is_string($meta['default_font'] ?? null) ? $meta['default_font'] : 'dejavusans',
                'fields' => isset($meta['fields']) && is_array($meta['fields']) ? array_keys($meta['fields']) : [],
                'updated' => null,
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public static function document(string $id): array
    {
        self::assertId($id);
        $dir = Presets::templateDirectory() . '/' . $id;
        $bodyPath = $dir . '/body.html';
        if (!is_file($bodyPath)) {
            throw new HttpException(404, 'not_found', 'Template not found');
        }
        $body = file_get_contents($bodyPath);
        if ($body === false) {
            throw new HttpException(500, 'internal', 'Template could not be read');
        }
        $meta = self::meta($id);
        $header = self::optionalFile($dir . '/header.html');
        $footer = self::optionalFile($dir . '/footer.html');

        return [
            'id' => $id,
            'html' => $body,
            'header_html' => $header,
            'footer_html' => $footer,
            'paper' => is_string($meta['paper'] ?? null) ? $meta['paper'] : 'letter',
            'default_font' => is_string($meta['default_font'] ?? null) ? $meta['default_font'] : 'dejavusans',
            'fields' => $meta['fields'] ?? null,
            'sample' => $meta['sample'] ?? null,
            'updated' => null,
        ];
    }

    public static function exists(string $id): bool
    {
        self::assertId($id);

        return is_file(Presets::templateDirectory() . '/' . $id . '/body.html');
    }

    public static function assertId(string $id): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $id) !== 1) {
            throw new HttpException(400, 'invalid_template_id', 'Template id must be lowercase letters, digits, and hyphens');
        }
    }

    /**
     * @return list<string>
     */
    private static function ids(): array
    {
        $path = Presets::templateDirectory() . '/index.json';
        $raw = file_get_contents($path);
        if ($raw === false) {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $ids = [];
        foreach ($decoded as $id) {
            if (is_string($id)) {
                self::assertId($id);
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @return array<string, mixed>
     */
    private static function meta(string $id): array
    {
        $path = Presets::templateDirectory() . '/' . $id . '/meta.json';
        if (!is_file($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function optionalFile(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);

        return $raw === false ? null : $raw;
    }
}
