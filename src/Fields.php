<?php

declare(strict_types=1);

namespace PdfApi;

final class Fields
{
    /**
     * @param array<string, mixed>|null $manifest
     * @param array<string, mixed> $data
     */
    public static function assertValid(?array $manifest, array $data): void
    {
        if ($manifest === null) {
            return;
        }

        $missing = [];
        $invalid = [];
        foreach ($manifest as $name => $spec) {
            if (!is_string($name) || !is_array($spec)) {
                throw new HttpException(400, 'invalid_template', 'Field manifest is malformed');
            }
            $type = $spec['type'] ?? null;
            if (!is_string($type) || !in_array($type, ['string', 'html', 'paragraphs', 'list'], true)) {
                throw new HttpException(400, 'invalid_template', 'Field manifest is malformed', [
                    'field' => $name,
                ]);
            }
            $required = (bool) ($spec['required'] ?? false);
            $present = array_key_exists($name, $data) && $data[$name] !== null;
            if (!$present) {
                if ($required) {
                    $missing[] = $name;
                }
                continue;
            }
            $message = self::typeError($type, $data[$name]);
            if ($message !== null) {
                $invalid[] = ['field' => $name, 'message' => $message];
            }
        }

        if ($missing !== [] || $invalid !== []) {
            throw new HttpException(422, 'invalid_fields', 'The data does not match the template fields', [
                'missing' => $missing,
                'invalid' => $invalid,
            ]);
        }
    }

    public static function typeError(string $type, mixed $value): ?string
    {
        return match ($type) {
            'string', 'html' => is_string($value) ? null : 'expected a string',
            'paragraphs' => self::paragraphError($value),
            'list' => is_array($value) && array_is_list($value) ? null : 'expected a list',
            default => 'unknown field type',
        };
    }

    private static function paragraphError(mixed $value): ?string
    {
        if (is_string($value)) {
            return null;
        }
        if (!is_array($value) || array_is_list($value)) {
            return 'expected a string or a paragraphs object';
        }
        if (!isset($value['paragraphs']) || !is_string($value['paragraphs'])) {
            return 'paragraphs must be a string';
        }
        $extra = array_diff(array_keys($value), ['paragraphs', 'td_style']);
        if ($extra !== []) {
            return 'paragraphs objects only allow paragraphs and td_style';
        }
        if (isset($value['td_style']) && !is_string($value['td_style'])) {
            return 'td_style must be a string';
        }

        return null;
    }
}
