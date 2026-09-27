<?php

declare(strict_types=1);

namespace PdfApi;

final class Token
{
    private const KNOWN_SCOPES = ['render', 'templates:write'];

    /**
     * @param list<string> $scopes
     * @param list<string> $allowedHosts
     */
    public function __construct(
        public readonly string $name,
        public readonly string $owner,
        public readonly array $scopes,
        public readonly array $allowedHosts,
        public readonly int $rateLimit,
        public readonly int $windowSeconds,
        public readonly bool $disabled,
    ) {
    }

    public static function parse(string $json): self
    {
        try {
            $value = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpException(401, 'invalid_token', 'Token record is malformed');
        }
        if (!$value instanceof \stdClass) {
            throw new HttpException(401, 'invalid_token', 'Token record is malformed');
        }
        if (!isset($value->name) || !is_string($value->name) || trim($value->name) === '' || strlen($value->name) > 120) {
            throw new HttpException(401, 'invalid_token', 'Token record is malformed');
        }
        if (!isset($value->owner) || !is_string($value->owner) || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $value->owner) !== 1) {
            throw new HttpException(401, 'invalid_token', 'Token record is malformed');
        }
        if (!isset($value->scopes) || !is_array($value->scopes)) {
            throw new HttpException(401, 'invalid_token', 'Token record is malformed');
        }
        $scopes = [];
        foreach ($value->scopes as $scope) {
            if (is_string($scope) && in_array($scope, self::KNOWN_SCOPES, true)) {
                $scopes[] = $scope;
            }
        }
        if ($scopes === []) {
            throw new HttpException(401, 'invalid_token', 'Token record is malformed');
        }
        if (isset($value->disabled) && !is_bool($value->disabled)) {
            throw new HttpException(401, 'invalid_token', 'Token record is malformed');
        }

        $rate = self::rate($value->rate_limit ?? null);

        return new self(
            $value->name,
            $value->owner,
            $scopes,
            self::hosts($value->allowed_hosts ?? null),
            $rate['limit'],
            $rate['window_seconds'],
            ($value->disabled ?? false) === true,
        );
    }

    public function allows(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    /**
     * @param list<string> $scopes
     */
    public function allowsAny(array $scopes): bool
    {
        foreach ($scopes as $scope) {
            if ($this->allows($scope)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{name: string, owner: string, scopes: list<string>, allowed_hosts: list<string>, limits: array{max_html_bytes: int, max_body_bytes: int, rate_limit: int, window_seconds: int}}
     */
    public function whoami(): array
    {
        return [
            'name' => $this->name,
            'owner' => $this->owner,
            'scopes' => $this->scopes,
            'allowed_hosts' => $this->allowedHosts,
            'limits' => [
                'max_html_bytes' => Presets::maxHtmlBytes(),
                'max_body_bytes' => Presets::maxBodyBytes(),
                'rate_limit' => $this->rateLimit,
                'window_seconds' => $this->windowSeconds,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private static function hosts(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value)) {
            throw new HttpException(401, 'invalid_token', 'Token record is malformed');
        }
        $hosts = [];
        foreach ($value as $entry) {
            if (!is_string($entry)) {
                throw new HttpException(401, 'invalid_token', 'Token record is malformed');
            }
            $host = strtolower(rtrim(trim($entry), '.'));
            if ($host === '' || str_contains($host, '/') || str_contains($host, '*') || str_contains($host, ' ') || preg_match('/^[a-z0-9.-]+$/', $host) !== 1) {
                throw new HttpException(401, 'invalid_token', 'Token record is malformed');
            }
            $hosts[] = $host;
        }

        return $hosts;
    }

    /**
     * @return array{limit: int, window_seconds: int}
     */
    private static function rate(mixed $value): array
    {
        $limit = 30;
        $window = 60;
        if ($value === null) {
            return ['limit' => $limit, 'window_seconds' => $window];
        }
        if (!$value instanceof \stdClass) {
            throw new HttpException(401, 'invalid_token', 'Token record is malformed');
        }
        if (isset($value->limit)) {
            $limit = self::wholeNumber($value->limit);
        }
        if (isset($value->window_seconds)) {
            $window = self::wholeNumber($value->window_seconds);
        }
        if (!is_int($limit) || $limit < 1 || $limit > 10000 || !is_int($window) || $window < 60 || $window > 86400) {
            throw new HttpException(401, 'invalid_token', 'Token record is malformed');
        }

        return ['limit' => $limit, 'window_seconds' => $window];
    }

    private static function wholeNumber(mixed $value): mixed
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        return $value;
    }
}
