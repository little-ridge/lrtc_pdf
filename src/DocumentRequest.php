<?php

declare(strict_types=1);

namespace PdfApi;

final class DocumentRequest
{
    private const OPTION_KEYS = [
        'paper',
        'default_font',
        'title',
        'author',
        'subject',
        'keywords',
        'filename',
        'disposition',
    ];

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $fields
     * @param list<string> $allowedHosts
     */
    public function __construct(
        public readonly string $html,
        public readonly ?string $headerHtml,
        public readonly ?string $footerHtml,
        public readonly array $data,
        public readonly ?array $fields,
        public readonly bool $fill,
        public readonly string $paper,
        public readonly string $defaultFont,
        public readonly string $title,
        public readonly string $author,
        public readonly string $subject,
        public readonly string $keywords,
        public readonly array $allowedHosts,
        public readonly bool $allowAnyHost = false,
    ) {
    }

    public static function fromArray(mixed $body): self
    {
        if (!$body instanceof \stdClass) {
            throw new HttpException(400, 'invalid_json', 'Request body must be a JSON object');
        }
        $html = self::optionalString($body, 'html');
        if ($html === null || $html === '') {
            throw new HttpException(400, 'invalid_request', 'html is required');
        }
        self::assertHtmlSize('html', $html);
        $header = self::optionalString($body, 'header_html');
        $footer = self::optionalString($body, 'footer_html');
        if ($header !== null) {
            self::assertHtmlSize('header_html', $header);
        }
        if ($footer !== null) {
            self::assertHtmlSize('footer_html', $footer);
        }

        $data = [];
        if (isset($body->data)) {
            if (!$body->data instanceof \stdClass) {
                throw new HttpException(400, 'invalid_request', 'data must be an object');
            }
            $converted = self::toArray($body->data);
            if (!is_array($converted)) {
                throw new HttpException(400, 'invalid_request', 'data must be an object');
            }
            /** @var array<string, mixed> $data */
            $data = $converted;
        }

        $fields = null;
        if (isset($body->fields)) {
            if (!$body->fields instanceof \stdClass) {
                throw new HttpException(400, 'invalid_request', 'fields must be an object');
            }
            $convertedFields = self::toArray($body->fields);
            if (!is_array($convertedFields)) {
                throw new HttpException(400, 'invalid_request', 'fields must be an object');
            }
            /** @var array<string, mixed> $fields */
            $fields = $convertedFields;
        }

        $options = new \stdClass();
        if (isset($body->options)) {
            if (!$body->options instanceof \stdClass) {
                throw new HttpException(400, 'invalid_request', 'options must be an object');
            }
            foreach (get_object_vars($body->options) as $key => $value) {
                if (!in_array($key, self::OPTION_KEYS, true)) {
                    throw new HttpException(400, 'invalid_request', 'Unknown option', [
                        'option' => $key,
                    ]);
                }
            }
            $options = $body->options;
        }

        $paper = isset($options->paper) ? self::requireString($options->paper, 'options.paper') : 'letter';
        Presets::paper($paper);
        $font = isset($options->default_font) ? self::requireString($options->default_font, 'options.default_font') : 'dejavusans';
        Presets::assertFont($font);

        $allowed = [];
        if (isset($body->allowed_hosts)) {
            if (!is_array($body->allowed_hosts)) {
                throw new HttpException(400, 'invalid_request', 'allowed_hosts must be a list');
            }
            foreach ($body->allowed_hosts as $host) {
                if (!is_string($host)) {
                    throw new HttpException(400, 'invalid_request', 'allowed_hosts must be a list of strings');
                }
                $allowed[] = $host;
            }
        }

        $fill = !isset($body->fill) || $body->fill === true;
        if (isset($body->fill) && !is_bool($body->fill)) {
            throw new HttpException(400, 'invalid_request', 'fill must be a boolean');
        }
        $allowAnyHost = false;
        if (isset($body->allow_any_host)) {
            if (!is_bool($body->allow_any_host)) {
                throw new HttpException(400, 'invalid_request', 'allow_any_host must be a boolean');
            }
            $allowAnyHost = $body->allow_any_host;
        }

        return new self(
            $html,
            $header,
            $footer,
            $data,
            $fields,
            $fill,
            $paper,
            $font,
            self::meta($options, 'title'),
            self::meta($options, 'author'),
            self::meta($options, 'subject'),
            self::meta($options, 'keywords'),
            $allowed,
            $allowAnyHost,
        );
    }

    /**
     * @return array{html: string, header_html: ?string, footer_html: ?string, paper: string}
     */
    public function expand(): array
    {
        $filled = TemplateFiller::fill(
            $this->html,
            $this->headerHtml,
            $this->footerHtml,
            $this->data,
            $this->fields,
            $this->fill,
        );
        UrlGuard::assertSafe($filled['html'], $this->allowedHosts, $this->allowAnyHost);
        if ($filled['header_html'] !== null) {
            UrlGuard::assertSafe($filled['header_html'], $this->allowedHosts, $this->allowAnyHost);
        }
        if ($filled['footer_html'] !== null) {
            UrlGuard::assertSafe($filled['footer_html'], $this->allowedHosts, $this->allowAnyHost);
        }

        return [
            'html' => $filled['html'],
            'header_html' => $filled['header_html'],
            'footer_html' => $filled['footer_html'],
            'paper' => $this->paper,
        ];
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

    private static function optionalString(\stdClass $body, string $key): ?string
    {
        if (!isset($body->{$key}) || $body->{$key} === null) {
            return null;
        }
        if (!is_string($body->{$key})) {
            throw new HttpException(400, 'invalid_request', $key . ' must be a string');
        }

        return $body->{$key};
    }

    private static function requireString(mixed $value, string $label): string
    {
        if (!is_string($value) || $value === '') {
            throw new HttpException(400, 'invalid_request', $label . ' must be a string');
        }

        return $value;
    }

    private static function meta(\stdClass $options, string $key): string
    {
        if (!isset($options->{$key}) || $options->{$key} === null) {
            return '';
        }
        if (!is_string($options->{$key})) {
            throw new HttpException(400, 'invalid_request', 'options.' . $key . ' must be a string');
        }
        if (strlen($options->{$key}) > 200) {
            throw new HttpException(400, 'invalid_request', 'options.' . $key . ' is too long');
        }

        return $options->{$key};
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
