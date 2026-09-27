<?php

declare(strict_types=1);

namespace PdfApi;

final class UrlGuard
{
    /**
     * @param list<string> $allowedHosts
     */
    public static function assertSafe(string $html, array $allowedHosts, bool $allowAnyHost = false): void
    {
        $hosts = [];
        foreach ($allowedHosts as $host) {
            $normalized = self::normalizeHost($host);
            if ($normalized !== null) {
                $hosts[$normalized] = true;
            }
        }

        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $loaded = $dom->loadHTML(
            '<?xml encoding="utf-8"><html><body>' . $html . '</body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($loaded === false) {
            throw new HttpException(400, 'invalid_html', 'HTML could not be checked for remote URLs');
        }

        $blocked = [];
        foreach (['img', 'image', 'source'] as $tag) {
            foreach ($dom->getElementsByTagName($tag) as $element) {
                foreach (['src', 'href', 'poster'] as $attribute) {
                    $value = $element->getAttribute($attribute);
                    if ($value !== '') {
                        self::collect($value, $hosts, true, $allowAnyHost, $blocked);
                    }
                }
            }
        }
        foreach (['link', 'script'] as $tag) {
            foreach ($dom->getElementsByTagName($tag) as $element) {
                $value = $element->getAttribute('href');
                if ($value === '') {
                    $value = $element->getAttribute('src');
                }
                if ($value !== '') {
                    self::collect($value, $hosts, false, $allowAnyHost, $blocked);
                }
            }
        }
        if (preg_match_all('/url\(\s*([\'"]?)([^\'")]+)\1\s*\)|@import\s+([\'"])([^\'"]+)\3/i', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $url = ($match[2] ?? '') !== '' ? $match[2] : ($match[4] ?? '');
                $isImport = isset($match[4]) && $match[4] !== '';
                self::collect(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $hosts, !$isImport, $allowAnyHost, $blocked);
            }
        }

        if ($blocked !== []) {
            throw new HttpException(400, 'remote_url_blocked', $allowAnyHost
                ? 'Only http and https URLs can be fetched'
                : 'Remote URLs are limited to the token allowlist', [
                'urls' => array_values(array_unique($blocked)),
            ]);
        }
    }

    /**
     * @param array<string, true> $hosts
     * @param list<string> $blocked
     */
    private static function collect(string $url, array $hosts, bool $allowListedHttp, bool $allowAnyHost, array &$blocked): void
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, '#')) {
            return;
        }
        if (preg_match('#^data:image/(?:png|jpe?g|gif|webp);base64,#i', $url) === 1) {
            return;
        }
        $parts = parse_url($url);
        $scheme = is_array($parts) && isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
        $isHttp = ($scheme === 'http' || $scheme === 'https') && isset($parts['host']);
        if ($allowAnyHost) {
            if (!$isHttp) {
                $blocked[] = $url;
            }
            return;
        }
        if (!$allowListedHttp || !$isHttp) {
            $blocked[] = $url;
            return;
        }
        $host = strtolower($parts['host']);
        if (!isset($hosts[$host])) {
            $blocked[] = $url;
        }
    }

    private static function normalizeHost(string $host): ?string
    {
        $host = strtolower(trim($host));
        if ($host === '' || str_contains($host, '/') || str_contains($host, '*') || str_contains($host, ' ')) {
            return null;
        }
        $host = rtrim($host, '.');
        if (preg_match('/^[a-z0-9.-]+$/', $host) !== 1) {
            return null;
        }

        return $host;
    }
}
