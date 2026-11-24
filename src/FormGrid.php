<?php

declare(strict_types=1);

namespace PdfApi;

/**
 * A fixed point grid for a template. places.json lists boxes in PDF points
 * from the bottom-left of the page. An optional underlay PDF is a file in
 * the same template directory.
 */
final class FormGrid
{
    /**
     * @return array{underlay: ?string, width: float, height: float, pages: list<array{items: list<array<string, mixed>>}>}|null
     */
    public static function fromDirectory(string $dir): ?array
    {
        $realDir = realpath($dir);
        if ($realDir === false || !is_dir($realDir)) {
            return null;
        }
        $path = $realDir . '/places.json';
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if (!is_string($raw)) {
            throw new HttpException(500, 'invalid_template', 'Field grid could not be read');
        }
        try {
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpException(500, 'invalid_template', 'Field grid is malformed');
        }
        if (!is_array($decoded)) {
            throw new HttpException(500, 'invalid_template', 'Field grid is malformed');
        }

        return self::normalize($realDir, $decoded);
    }

    /**
     * PNG or JPEG bytes from a data URI. Anything else is not an ink image.
     *
     * @return array{bytes: string, type: string}|null
     */
    public static function image(string $value): ?array
    {
        if (preg_match('#\Adata:image/(png|jpeg|jpg);base64,([A-Za-z0-9+/=\s]+)\z#', $value) !== 1) {
            return null;
        }
        $comma = strpos($value, ',');
        if ($comma === false) {
            return null;
        }
        $payload = preg_replace('/\s+/', '', substr($value, $comma + 1));
        if (!is_string($payload) || $payload === '') {
            return null;
        }
        $bytes = base64_decode($payload, true);
        if (!is_string($bytes) || strlen($bytes) < 32 || strlen($bytes) > 200000) {
            return null;
        }
        $type = str_starts_with($value, 'data:image/png;') ? 'png' : 'jpeg';
        if ($type === 'png' && !str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return null;
        }
        if ($type === 'jpeg' && !str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return null;
        }

        return [
            'bytes' => $bytes,
            'type' => $type,
        ];
    }

    /**
     * @param array<string, mixed> $decoded
     * @return array{underlay: ?string, width: float, height: float, pages: list<array{items: list<array<string, mixed>>}>}
     */
    private static function normalize(string $dir, array $decoded): array
    {
        $width = self::number($decoded['width'] ?? null);
        $height = self::number($decoded['height'] ?? null);
        if ($width === null || $height === null || $width < 72 || $height < 72 || $width > 2000 || $height > 2000) {
            throw new HttpException(500, 'invalid_template', 'Field grid is malformed');
        }
        $pages = $decoded['pages'] ?? null;
        if (!is_array($pages) || !array_is_list($pages) || $pages === [] || count($pages) > 8) {
            throw new HttpException(500, 'invalid_template', 'Field grid is malformed');
        }
        $normalizedPages = [];
        foreach ($pages as $page) {
            if (!is_array($page) || !isset($page['items']) || !is_array($page['items']) || !array_is_list($page['items'])) {
                throw new HttpException(500, 'invalid_template', 'Field grid is malformed');
            }
            if (count($page['items']) > 80) {
                throw new HttpException(500, 'invalid_template', 'Field grid is malformed');
            }
            $items = [];
            foreach ($page['items'] as $item) {
                $items[] = self::item($item, $width, $height);
            }
            $normalizedPages[] = ['items' => $items];
        }

        return [
            'underlay' => self::underlay($dir, $decoded['underlay'] ?? null),
            'width' => $width,
            'height' => $height,
            'pages' => $normalizedPages,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function item(mixed $item, float $width, float $height): array
    {
        if (!is_array($item)) {
            throw new HttpException(500, 'invalid_template', 'Field grid is malformed');
        }
        $allowed = ['key', 'kind', 'x', 'y', 'w', 'h', 'size', 'font', 'align', 'fallback', 'max', 'style'];
        if (array_diff(array_keys($item), $allowed) !== []) {
            throw new HttpException(500, 'invalid_template', 'Field grid is malformed');
        }
        $key = $item['key'] ?? null;
        $kind = $item['kind'] ?? null;
        if (!is_string($key) || preg_match('/^[A-Za-z0-9_]{1,64}$/', $key) !== 1) {
            throw new HttpException(500, 'invalid_template', 'Field grid is malformed');
        }
        if (!is_string($kind) || !in_array($kind, ['text', 'mark', 'signature', 'blank'], true)) {
            throw new HttpException(500, 'invalid_template', 'Field grid is malformed');
        }
        $x = self::number($item['x'] ?? null);
        $y = self::number($item['y'] ?? null);
        $w = self::number($item['w'] ?? null);
        $h = self::number($item['h'] ?? null);
        if ($x === null || $y === null || $w === null || $h === null || $w <= 0 || $h <= 0) {
            throw new HttpException(500, 'invalid_template', 'Field grid is malformed');
        }
        if ($x < 0 || $y < 0 || $x + $w > $width + 1 || $y + $h > $height + 1) {
            throw new HttpException(500, 'invalid_template', 'Field grid is malformed');
        }
        $out = [
            'key' => $key,
            'kind' => $kind,
            'x' => $x,
            'y' => $y,
            'w' => $w,
            'h' => $h,
        ];
        if (isset($item['size'])) {
            $size = self::number($item['size']);
            if ($size === null || $size < 6 || $size > 36) {
                throw new HttpException(500, 'invalid_template', 'Field grid is malformed');
            }
            $out['size'] = $size;
        }
        if (isset($item['font'])) {
            if (!is_string($item['font']) || !in_array($item['font'], Presets::fonts(), true)) {
                throw new HttpException(500, 'invalid_template', 'Field grid is malformed');
            }
            $out['font'] = $item['font'];
        }
        if (isset($item['align'])) {
            if ($item['align'] !== 'left' && $item['align'] !== 'center') {
                throw new HttpException(500, 'invalid_template', 'Field grid is malformed');
            }
            $out['align'] = $item['align'];
        }
        if (isset($item['style'])) {
            if ($item['style'] !== 'circle' && $item['style'] !== 'square') {
                throw new HttpException(500, 'invalid_template', 'Field grid is malformed');
            }
            $out['style'] = $item['style'];
        }
        if (isset($item['fallback'])) {
            if (!is_string($item['fallback']) || preg_match('/^[A-Za-z0-9_]{1,64}$/', $item['fallback']) !== 1) {
                throw new HttpException(500, 'invalid_template', 'Field grid is malformed');
            }
            $out['fallback'] = $item['fallback'];
        }
        if (isset($item['max'])) {
            if (!is_int($item['max']) || $item['max'] < 1 || $item['max'] > 500) {
                throw new HttpException(500, 'invalid_template', 'Field grid is malformed');
            }
            $out['max'] = $item['max'];
        }

        return $out;
    }

    private static function underlay(string $dir, mixed $name): ?string
    {
        if ($name === null || $name === '') {
            return null;
        }
        if (!is_string($name) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,80}\.pdf\z/', $name) !== 1) {
            throw new HttpException(500, 'invalid_template', 'Field grid is malformed');
        }
        $path = $dir . '/' . $name;
        $real = realpath($path);
        $prefix = $dir . DIRECTORY_SEPARATOR;
        if ($real === false || !is_file($real) || !str_starts_with($real, $prefix)) {
            throw new HttpException(500, 'invalid_template', 'Field grid is malformed');
        }

        return $real;
    }

    private static function number(mixed $value): ?float
    {
        if (!is_int($value) && !is_float($value)) {
            return null;
        }
        $number = (float) $value;
        if (!is_finite($number)) {
            return null;
        }

        return $number;
    }
}
