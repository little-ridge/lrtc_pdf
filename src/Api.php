<?php

declare(strict_types=1);

namespace PdfApi;

final class Api
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

    public function __construct(
        private readonly Tokens $tokens,
        private readonly RateLimiter $rates,
        private readonly TemplateLibrary $templates,
    ) {
    }

    public static function fromEnvironment(): self
    {
        $config = Config::fromEnvironment();

        return new self(
            new Tokens(new TokenFile($config->tokensFile)),
            new RateLimiter($config->dataDir . '/rates'),
            new TemplateLibrary($config->dataDir),
        );
    }

    public function dispatch(string $method, string $path, ?string $rawBody, ?string $authorization): ApiResponse
    {
        if ($path === '/v1/whoami' && $method === 'GET') {
            $auth = $this->tokens->authenticate($authorization);

            return new ApiResponse(200, $auth['token']->whoami());
        }
        if ($path === '/v1/capabilities' && $method === 'GET') {
            $this->tokens->authenticate($authorization);
            $presets = Presets::all();

            return new ApiResponse(200, [
                'mpdf_version' => $presets['mpdf_version'],
                'max_html_bytes' => $presets['max_html_bytes'],
                'max_body_bytes' => $presets['max_body_bytes'],
                'fonts' => $presets['fonts'],
                'papers' => $presets['papers'],
            ]);
        }
        if (($path === '/v1/pdf' || $path === '/v1/expand') && $method === 'POST') {
            $auth = $this->requireScope($authorization, 'render');
            $this->rates->enforce($auth['hash'], $auth['token']->rateLimit, $auth['token']->windowSeconds);

            return $this->render($this->jsonObject($rawBody), $auth['token'], $path === '/v1/expand');
        }
        if ($path === '/v1/templates' && $method === 'GET') {
            $auth = $this->requireAny($authorization, ['render', 'templates:write']);

            return new ApiResponse(200, $this->templates->list($auth['token']->owner));
        }
        if (preg_match('#^/v1/templates/([a-z0-9][a-z0-9-]{0,63})$#', $path, $matches) === 1
            && ($method === 'GET' || $method === 'PUT' || $method === 'DELETE')) {
            $id = $matches[1];
            if ($method === 'GET') {
                $auth = $this->requireAny($authorization, ['render', 'templates:write']);

                return new ApiResponse(200, $this->templates->get($auth['token']->owner, $id));
            }
            $auth = $this->requireScope($authorization, 'templates:write');
            $this->rates->enforce($auth['hash'], $auth['token']->rateLimit, $auth['token']->windowSeconds);
            if ($method === 'DELETE') {
                return new ApiResponse(200, $this->templates->delete($auth['token']->owner, $id));
            }

            return new ApiResponse(200, $this->templates->put($auth['token']->owner, $id, $this->jsonObject($rawBody)));
        }

        throw new HttpException(404, 'not_found', 'Not found');
    }

    /**
     * @return array{token: Token, hash: string}
     */
    private function requireScope(?string $authorization, string $scope): array
    {
        $auth = $this->tokens->authenticate($authorization);
        if (!$auth['token']->allows($scope)) {
            throw new HttpException(403, 'forbidden', 'Token is missing the required scope', [
                'scope' => $scope,
            ]);
        }

        return $auth;
    }

    /**
     * @param list<string> $scopes
     * @return array{token: Token, hash: string}
     */
    private function requireAny(?string $authorization, array $scopes): array
    {
        $auth = $this->tokens->authenticate($authorization);
        if (!$auth['token']->allowsAny($scopes)) {
            throw new HttpException(403, 'forbidden', 'Token is missing the required scope', [
                'scope' => $scopes,
            ]);
        }

        return $auth;
    }

    private function jsonObject(?string $raw): \stdClass
    {
        if ($raw === null || trim($raw) === '') {
            throw new HttpException(400, 'invalid_json', 'Request body must be JSON');
        }
        if (strlen($raw) > Presets::maxBodyBytes()) {
            throw new HttpException(413, 'payload_too_large', 'Request body exceeds the size limit', [
                'max_bytes' => Presets::maxBodyBytes(),
            ]);
        }
        try {
            $parsed = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpException(400, 'invalid_json', 'Request body must be JSON');
        }
        if (!$parsed instanceof \stdClass) {
            throw new HttpException(400, 'invalid_json', 'Request body must be a JSON object');
        }

        return $parsed;
    }

    private function render(\stdClass $body, Token $token, bool $expand): ApiResponse
    {
        $hasTemplate = isset($body->template) && is_string($body->template);
        $hasHtml = isset($body->html) && is_string($body->html);
        if ($hasTemplate === $hasHtml || ($hasHtml && $body->html === '')) {
            throw new HttpException(400, 'invalid_request', 'Provide exactly one of template or html');
        }
        if (isset($body->template) && !is_string($body->template)) {
            throw new HttpException(400, 'invalid_request', 'Provide exactly one of template or html');
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
        $options = new \stdClass();
        if (isset($body->options)) {
            if (!$body->options instanceof \stdClass) {
                throw new HttpException(400, 'invalid_request', 'options must be an object');
            }
            foreach (array_keys(get_object_vars($body->options)) as $key) {
                if (!in_array($key, self::OPTION_KEYS, true)) {
                    throw new HttpException(400, 'invalid_request', 'Unknown option', [
                        'option' => $key,
                    ]);
                }
            }
            $options = $body->options;
        }

        $html = $hasHtml ? $body->html : '';
        $header = null;
        $footer = null;
        $paper = 'letter';
        $font = 'dejavusans';
        $fields = null;
        if ($hasTemplate) {
            Seeds::assertId($body->template);
            $document = $this->templates->load($token->owner, $body->template);
            if ($document === null) {
                throw new HttpException(404, 'not_found', 'Template not found');
            }
            if (!is_string($document['html']) || !is_string($document['paper']) || !is_string($document['default_font'])) {
                throw new HttpException(500, 'internal', 'Template could not be read');
            }
            $html = $document['html'];
            $header = is_string($document['header_html']) ? $document['header_html'] : null;
            $footer = is_string($document['footer_html']) ? $document['footer_html'] : null;
            $paper = $document['paper'];
            $font = $document['default_font'];
            $fields = is_array($document['fields']) ? $document['fields'] : null;
        }
        if (property_exists($body, 'header_html')) {
            $header = self::overrideString($body->header_html, 'header_html');
        }
        if (property_exists($body, 'footer_html')) {
            $footer = self::overrideString($body->footer_html, 'footer_html');
        }
        if (isset($options->paper)) {
            if (!is_string($options->paper)) {
                throw new HttpException(400, 'invalid_request', 'options.paper must be a string');
            }
            $paper = $options->paper;
        }
        Presets::paper($paper);
        if (isset($options->default_font)) {
            if (!is_string($options->default_font)) {
                throw new HttpException(400, 'invalid_request', 'options.default_font must be a string');
            }
            $font = $options->default_font;
        }
        Presets::assertFont($font);
        foreach (['title', 'author', 'subject', 'keywords'] as $key) {
            if (!isset($options->{$key}) || $options->{$key} === null) {
                continue;
            }
            if (!is_string($options->{$key}) || strlen($options->{$key}) > 200) {
                throw new HttpException(400, 'invalid_request', 'options.' . $key . ' must be a string');
            }
        }
        $disposition = $options->disposition ?? 'inline';
        if ($disposition !== 'inline' && $disposition !== 'attachment') {
            throw new HttpException(400, 'invalid_request', 'options.disposition must be inline or attachment');
        }
        $filename = $options->filename ?? 'document.pdf';
        if (!is_string($filename) || strlen($filename) > 120 || preg_match('/[\r\n"]/', $filename) === 1) {
            throw new HttpException(400, 'invalid_request', 'options.filename must be a string');
        }
        self::assertHtmlSize('html', $html);
        if ($header !== null) {
            self::assertHtmlSize('header_html', $header);
        }
        if ($footer !== null) {
            self::assertHtmlSize('footer_html', $footer);
        }
        /** @var array<string, mixed>|null $fields */
        Fields::assertValid($fields, $data);
        $request = new DocumentRequest(
            $html,
            $header,
            $footer,
            $data,
            $fields,
            $hasTemplate || $data !== [],
            $paper,
            $font,
            self::meta($options, 'title'),
            self::meta($options, 'author'),
            self::meta($options, 'subject'),
            self::meta($options, 'keywords'),
            [],
            true,
        );
        if ($expand) {
            return new ApiResponse(200, $request->expand());
        }
        $rendered = Renderer::render($request);

        return new ApiResponse(200, null, $rendered['pdf'], [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => self::contentDisposition($disposition, $filename),
            'X-Pdf-Pages' => (string) $rendered['pages'],
            'X-Pdf-Bytes' => (string) $rendered['bytes'],
            'Cache-Control' => 'no-store',
        ]);
    }

    private static function overrideString(mixed $value, string $key): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new HttpException(400, 'invalid_request', $key . ' must be a string');
        }

        return $value;
    }

    private static function meta(\stdClass $options, string $key): string
    {
        if (!isset($options->{$key}) || !is_string($options->{$key})) {
            return '';
        }

        return $options->{$key};
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

    private static function contentDisposition(string $disposition, string $filename): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?? '';
        $safe = substr($safe, 0, 120);
        if ($safe === '') {
            $safe = 'document.pdf';
        }
        if (!str_ends_with(strtolower($safe), '.pdf')) {
            $safe .= '.pdf';
        }

        return $disposition . '; filename="' . $safe . '"';
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
