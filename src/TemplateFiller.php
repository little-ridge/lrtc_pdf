<?php

declare(strict_types=1);

namespace PdfApi;

final class TemplateFiller
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $manifest
     * @return array{html: string, header_html: ?string, footer_html: ?string}
     */
    public static function fill(
        string $html,
        ?string $headerHtml,
        ?string $footerHtml,
        array $data,
        ?array $manifest,
        bool $fill,
    ): array {
        Fields::assertValid($manifest, $data);
        if ($fill) {
            $data = self::prepareData($data, $manifest);
            $html = self::render($html, $data);
            $headerHtml = $headerHtml === null ? null : self::render($headerHtml, $data);
            $footerHtml = $footerHtml === null ? null : self::render($footerHtml, $data);
        }

        return [
            'html' => $html,
            'header_html' => $headerHtml,
            'footer_html' => $footerHtml,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $manifest
     * @return array<string, mixed>
     */
    private static function prepareData(array $data, ?array $manifest): array
    {
        if ($manifest === null) {
            return self::walk($data);
        }

        foreach ($manifest as $name => $spec) {
            if (!is_string($name) || !is_array($spec) || !array_key_exists($name, $data)) {
                continue;
            }
            if (($spec['type'] ?? null) !== 'paragraphs' || $data[$name] === null) {
                continue;
            }
            $value = $data[$name];
            if (is_string($value)) {
                $data[$name] = Paragraphs::toRows($value);
                continue;
            }
            if (is_array($value) && isset($value['paragraphs']) && is_string($value['paragraphs'])) {
                $style = isset($value['td_style']) && is_string($value['td_style']) ? $value['td_style'] : '';
                $data[$name] = Paragraphs::toRows($value['paragraphs'], $style);
            }
        }

        return self::walk($data);
    }

    /**
     * @return mixed
     */
    private static function walk(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value) && self::isParagraphObject($value)) {
            $style = isset($value['td_style']) && is_string($value['td_style']) ? $value['td_style'] : '';

            return Paragraphs::toRows($value['paragraphs'], $style);
        }
        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = self::walk($item);
        }

        return $out;
    }

    /**
     * @param array<mixed> $value
     */
    private static function isParagraphObject(array $value): bool
    {
        if (!isset($value['paragraphs']) || !is_string($value['paragraphs'])) {
            return false;
        }

        return array_diff(array_keys($value), ['paragraphs', 'td_style']) === [];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function render(string $template, array $data): string
    {
        $engine = new \Mustache_Engine([
            'entity_flags' => ENT_QUOTES | ENT_SUBSTITUTE,
            'charset' => 'UTF-8',
        ]);
        try {
            return $engine->render($template, $data);
        } catch (\Throwable $e) {
            error_log('template render failed: ' . $e->getMessage());
            throw new HttpException(400, 'invalid_template', 'Template could not be rendered');
        }
    }
}
