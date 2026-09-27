<?php

declare(strict_types=1);

namespace PdfApi;

final class TemplateLibrary
{
    public function __construct(private readonly string $root)
    {
    }

    /**
     * @return array{templates: list<array<string, mixed>>, cursor: null}
     */
    public function list(string $owner): array
    {
        $summaries = [];
        foreach (Seeds::summaries() as $summary) {
            $id = $summary['id'];
            $summaries[$id] = [
                'id' => $id,
                'paper' => $summary['paper'],
                'updated' => null,
                'fields' => $summary['fields'],
            ];
        }
        $ownerDir = $this->ownerDir($owner);
        if (is_dir($ownerDir)) {
            foreach (scandir($ownerDir) ?: [] as $id) {
                if ($id === '.' || $id === '..') {
                    continue;
                }
                $document = $this->read($owner, $id);
                if ($document !== null) {
                    $summaries[$id] = $this->summary($document);
                }
            }
        }

        return [
            'templates' => array_values($summaries),
            'cursor' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $owner, string $id): array
    {
        return $this->load($owner, $id) ?? throw new HttpException(404, 'not_found', 'Template not found');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function load(string $owner, string $id): ?array
    {
        Seeds::assertId($id);

        return $this->read($owner, $id) ?? (Seeds::exists($id) ? Seeds::document($id) : null);
    }

    /**
     * @return array<string, mixed>
     */
    public function put(string $owner, string $id, \stdClass $body): array
    {
        Seeds::assertId($id);
        if (!isset($body->html) || !is_string($body->html) || $body->html === '') {
            throw new HttpException(400, 'invalid_request', 'html is required');
        }
        self::assertHtmlSize('html', $body->html);
        $header = self::optionalHtml($body, 'header_html');
        $footer = self::optionalHtml($body, 'footer_html');
        if ($header !== null) {
            self::assertHtmlSize('header_html', $header);
        }
        if ($footer !== null) {
            self::assertHtmlSize('footer_html', $footer);
        }
        $paper = property_exists($body, 'paper') ? $body->paper : 'letter';
        if (!is_string($paper)) {
            throw new HttpException(400, 'invalid_paper', 'Unknown paper preset', [
                'allowed' => Presets::paperNames(),
            ]);
        }
        Presets::paper($paper);
        $font = property_exists($body, 'default_font') ? $body->default_font : 'dejavusans';
        if (!is_string($font)) {
            throw new HttpException(400, 'invalid_font', 'Unknown font', [
                'allowed' => Presets::fonts(),
            ]);
        }
        Presets::assertFont($font);
        $fields = Fields::manifest(isset($body->fields) ? self::toArray($body->fields) : null);
        $sample = self::sample($body->sample ?? null);
        $updated = gmdate('c');
        $dir = $this->templateDir($owner, $id);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new HttpException(500, 'storage_unavailable', 'PDF_DATA_DIR is not writable');
        }
        self::write($dir . '/body.html', $body->html);
        self::writeOptional($dir . '/header.html', $header);
        self::writeOptional($dir . '/footer.html', $footer);
        self::write($dir . '/meta.json', json_encode([
            'paper' => $paper,
            'default_font' => $font,
            'fields' => $fields,
            'sample' => $sample,
            'updated' => $updated,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return [
            'id' => $id,
            'html' => $body->html,
            'header_html' => $header,
            'footer_html' => $footer,
            'paper' => $paper,
            'default_font' => $font,
            'fields' => $fields,
            'sample' => $sample,
            'updated' => $updated,
        ];
    }

    /**
     * @return array{deleted: true, seed: bool}
     */
    public function delete(string $owner, string $id): array
    {
        Seeds::assertId($id);
        $dir = $this->templateDir($owner, $id);
        if (!is_dir($dir)) {
            throw new HttpException(404, 'not_found', 'Template not found');
        }
        foreach (['body.html', 'header.html', 'footer.html', 'meta.json'] as $name) {
            $path = $dir . '/' . $name;
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($dir);

        return [
            'deleted' => true,
            'seed' => Seeds::exists($id),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function read(string $owner, string $id): ?array
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $id) !== 1) {
            return null;
        }
        $dir = $this->templateDir($owner, $id);
        $bodyPath = $dir . '/body.html';
        if (!is_file($bodyPath)) {
            return null;
        }
        $html = file_get_contents($bodyPath);
        if (!is_string($html)) {
            throw new HttpException(500, 'internal', 'Template could not be read');
        }
        $paper = 'letter';
        $font = 'dejavusans';
        $fields = null;
        $sample = null;
        $updated = null;
        $metaPath = $dir . '/meta.json';
        if (is_file($metaPath)) {
            $raw = file_get_contents($metaPath);
            $meta = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($meta)) {
                if (isset($meta['paper']) && is_string($meta['paper']) && in_array($meta['paper'], Presets::paperNames(), true)) {
                    $paper = $meta['paper'];
                }
                if (isset($meta['default_font']) && is_string($meta['default_font']) && in_array($meta['default_font'], Presets::fonts(), true)) {
                    $font = $meta['default_font'];
                }
                try {
                    $fields = Fields::manifest(isset($meta['fields']) && is_array($meta['fields']) ? $meta['fields'] : null);
                } catch (HttpException) {
                    $fields = null;
                }
                if (isset($meta['sample']) && is_array($meta['sample'])) {
                    $sample = $meta['sample'];
                }
                if (isset($meta['updated']) && is_string($meta['updated'])) {
                    $updated = $meta['updated'];
                }
            }
        }

        return [
            'id' => $id,
            'html' => $html,
            'header_html' => self::optionalFile($dir . '/header.html'),
            'footer_html' => self::optionalFile($dir . '/footer.html'),
            'paper' => $paper,
            'default_font' => $font,
            'fields' => $fields,
            'sample' => $sample,
            'updated' => $updated,
        ];
    }

    /**
     * @param array<string, mixed> $document
     * @return array{id: string, paper: mixed, updated: mixed, fields: list<string>}
     */
    private function summary(array $document): array
    {
        $fields = $document['fields'] ?? null;

        return [
            'id' => $document['id'],
            'paper' => $document['paper'],
            'updated' => $document['updated'],
            'fields' => is_array($fields) ? array_keys($fields) : [],
        ];
    }

    private function ownerDir(string $owner): string
    {
        return $this->root . '/templates/' . $owner;
    }

    private function templateDir(string $owner, string $id): string
    {
        return $this->ownerDir($owner) . '/' . $id;
    }

    private static function optionalHtml(\stdClass $body, string $key): ?string
    {
        if (!property_exists($body, $key) || $body->{$key} === null) {
            return null;
        }
        if (!is_string($body->{$key})) {
            throw new HttpException(400, 'invalid_request', $key . ' must be a string');
        }

        return $body->{$key};
    }

    private static function assertHtmlSize(string $field, string $html): void
    {
        if (strlen($html) > Presets::maxHtmlBytes()) {
            throw new HttpException(413, 'payload_too_large', $field . ' exceeds the size limit', [
                'field' => $field,
                'max_bytes' => Presets::maxHtmlBytes(),
            ]);
        }
    }

    private static function sample(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        if (!$value instanceof \stdClass && !is_array($value)) {
            throw new HttpException(400, 'invalid_request', 'sample must be an object');
        }
        $sample = self::toArray($value);
        if (!is_array($sample)) {
            throw new HttpException(400, 'invalid_request', 'sample must be an object');
        }

        return $sample;
    }

    private static function write(string $path, string $contents): void
    {
        $temporary = $path . '.tmp';
        if (file_put_contents($temporary, $contents, LOCK_EX) === false || !rename($temporary, $path)) {
            throw new HttpException(500, 'storage_unavailable', 'PDF_DATA_DIR is not writable');
        }
    }

    private static function writeOptional(string $path, ?string $contents): void
    {
        if ($contents === null) {
            if (is_file($path)) {
                unlink($path);
            }

            return;
        }
        self::write($path, $contents);
    }

    private static function optionalFile(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);

        return is_string($raw) ? $raw : null;
    }

    private static function toArray(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $out = [];
            foreach (get_object_vars($value) as $key => $item) {
                $out[$key] = self::toArray($item);
            }

            return $out;
        }
        if (is_array($value)) {
            return array_map(self::toArray(...), $value);
        }

        return $value;
    }
}
