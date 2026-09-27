<?php

declare(strict_types=1);

use PdfApi\DocumentRequest;
use PdfApi\HttpException;
use PdfApi\Paragraphs;
use PdfApi\Presets;
use PdfApi\Renderer;
use PdfApi\Seeds;
use PdfApi\UrlGuard;

require dirname(__DIR__) . '/vendor/autoload.php';

$failed = 0;

function check(bool $ok, string $message): void
{
    global $failed;
    if ($ok) {
        fwrite(STDOUT, "ok  {$message}\n");
        return;
    }
    $failed++;
    fwrite(STDERR, "FAIL {$message}\n");
}

/**
 * @param array<string, array<string, mixed>> $records
 */
function writeTokens(string $path, array $records, int $mode = 0600): void
{
    $directory = dirname($path);
    if (!is_dir($directory)) {
        mkdir($directory, 0700, true);
    }
    file_put_contents($path, json_encode($records, JSON_THROW_ON_ERROR));
    chmod($path, $mode);
}

function removeTree(string $path): void
{
    if (!file_exists($path)) {
        return;
    }
    if (!is_dir($path)) {
        unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        removeTree($path . '/' . $entry);
    }
    rmdir($path);
}

$letter = Renderer::config('letter', 'dejavusans');
check($letter['format'] === 'Letter', 'letter format');
check($letter['margin_left'] === 15 && $letter['margin_right'] === 15, 'letter side margins');
check($letter['margin_top'] === 10 && $letter['margin_bottom'] === 10, 'letter vertical margins');
check($letter['orientation'] === 'P', 'portrait');
check($letter['default_font'] === 'dejavusans', 'default font');

$b5 = Renderer::config('custom-b5', 'dejavusans');
check($b5['format'] === [170, 240], 'custom-b5 uses format, not sheet-size');
check($b5['margin_left'] === 20 && $b5['margin_bottom'] === 20, 'custom-b5 margins');

$narrow = Presets::paper('letter-narrow');
check($narrow['margin_left'] === 10 && $narrow['margin_bottom'] === 30, 'letter-narrow margins');

$rows = Paragraphs::toRows("Alpha\n\n<script>\n\nBeta");
check(str_contains($rows, '<p>Alpha</p>'), 'paragraph row keeps text');
check(str_contains($rows, '&lt;script&gt;'), 'paragraph row escapes html');
check(substr_count($rows, '<tr') === 3, 'blank lines are dropped');

$seed = Seeds::document('greeting');
$body = json_decode(json_encode([
    'html' => $seed['html'],
    'header_html' => $seed['header_html'],
    'footer_html' => $seed['footer_html'],
    'data' => [
        'name' => '<Ada>',
        'title_text' => 'Greeting',
        'body' => "Line one\nLine two",
        'items' => [['name' => 'First']],
    ],
    'fields' => $seed['fields'],
    'fill' => true,
    'options' => ['paper' => 'letter', 'title' => 'Greeting'],
    'allowed_hosts' => [],
]));
$request = DocumentRequest::fromArray($body);
$expanded = $request->expand();
check(str_contains($expanded['html'], 'Hello &lt;Ada&gt;'), 'mustache escapes strings');
check(str_contains($expanded['html'], '<p>Line one</p>'), 'paragraph field becomes rows');
check(str_contains($expanded['html'], '<li>First</li>'), 'list section repeats');
check(str_contains($expanded['footer_html'] ?? '', '{PAGENO}'), 'footer keeps mPDF page token');

try {
    UrlGuard::assertSafe('<img src="https://evil.test/a.png">', []);
    check(false, 'blocks remote images');
} catch (HttpException $e) {
    check($e->error === 'remote_url_blocked', 'blocks remote images');
}

try {
    UrlGuard::assertSafe('<img src="https://cdn.example.com/a.png">', ['cdn.example.com']);
    check(true, 'allows listed image hosts');
} catch (HttpException) {
    check(false, 'allows listed image hosts');
}

try {
    UrlGuard::assertSafe('<link rel="stylesheet" href="https://cdn.example.com/a.css">', ['cdn.example.com']);
    check(false, 'blocks remote stylesheets without allow-any');
} catch (HttpException $e) {
    check($e->error === 'remote_url_blocked', 'blocks remote stylesheets without allow-any');
}

UrlGuard::assertSafe('<img src="https://evil.test/a.png"><link rel="stylesheet" href="https://cdn.example.com/a.css">', [], true);
check(true, 'authenticated renders allow any http host');

try {
    UrlGuard::assertSafe('<img src="file:///etc/passwd">', [], true);
    check(false, 'blocks local file urls');
} catch (HttpException $e) {
    check($e->error === 'remote_url_blocked', 'blocks local file urls');
}

UrlGuard::assertSafe('<img src="data:image/png;base64,aaaa">', []);
check(true, 'allows image data uris');

try {
    DocumentRequest::fromArray(json_decode('{"html":"<p>x</p>","options":{"tempDir":"/tmp"}}'));
    check(false, 'rejects unknown options');
} catch (HttpException $e) {
    check($e->status === 400, 'rejects unknown options');
}

try {
    $incomplete = DocumentRequest::fromArray(json_decode(json_encode([
        'html' => $seed['html'],
        'fields' => $seed['fields'],
        'data' => ['title_text' => 'Hi'],
        'fill' => true,
    ])));
    $incomplete->expand();
    check(false, 'rejects missing required fields');
} catch (HttpException $e) {
    check($e->status === 422, 'rejects missing required fields');
}

$rendered = Renderer::render($request);
check(str_starts_with($rendered['pdf'], '%PDF'), 'letter preset renders a pdf');
check($rendered['pages'] >= 1, 'page count is present');
check($rendered['bytes'] === strlen($rendered['pdf']), 'byte count matches');
check(is_file(Presets::emojiPath()), 'emoji font file is present');
check(isset($letter['fontdata']['emoji']), 'emoji family is registered');

$dataDir = sys_get_temp_dir() . '/lrtc-pdf-fixture-' . bin2hex(random_bytes(4));
$token = 'test-token';
$tokenFile = $dataDir . '/tokens.json';
writeTokens($tokenFile, [
    hash('sha256', $token) => [
        'name' => 'Fixture',
        'owner' => 'fixture',
        'scopes' => ['render', 'templates:write'],
    ],
], 0644);
$exposed = new PdfApi\Api(
    new PdfApi\Tokens(new PdfApi\TokenFile($tokenFile)),
    new PdfApi\RateLimiter($dataDir . '/rates'),
    new PdfApi\TemplateLibrary($dataDir),
);
try {
    $exposed->dispatch('GET', '/v1/whoami', null, 'Bearer ' . $token);
    check(false, 'world-readable token file is refused');
} catch (HttpException $e) {
    check($e->status === 500 && $e->error === 'tokens_exposed', 'world-readable token file is refused');
}
chmod($tokenFile, 0600);
$api = new PdfApi\Api(
    new PdfApi\Tokens(new PdfApi\TokenFile($tokenFile)),
    new PdfApi\RateLimiter($dataDir . '/rates'),
    new PdfApi\TemplateLibrary($dataDir),
);
$auth = 'Bearer ' . $token;
$who = $api->dispatch('GET', '/v1/whoami', null, $auth);
check($who->status === 200 && ($who->json['owner'] ?? null) === 'fixture', 'whoami reads the token record');
try {
    $api->dispatch('GET', '/v1/whoami', null, null);
    check(false, 'missing bearer is unauthorized');
} catch (HttpException $e) {
    check($e->status === 401 && $e->error === 'unauthorized', 'missing bearer is unauthorized');
}
try {
    $api->dispatch('GET', '/v1/whoami', null, 'Bearer someone-else');
    check(false, 'unknown token is unauthorized');
} catch (HttpException $e) {
    check($e->status === 401 && $e->error === 'unauthorized', 'unknown token is unauthorized');
}
$expanded = $api->dispatch('POST', '/v1/expand', json_encode([
    'template' => 'greeting',
    'data' => [
        'name' => 'Ada',
        'title_text' => 'Hi',
        'body' => "One\nTwo",
    ],
], JSON_THROW_ON_ERROR), $auth);
check(
    is_array($expanded->json) && is_string($expanded->json['html'] ?? null) && str_contains($expanded->json['html'], 'Hello Ada'),
    'expand fills the seed template',
);
try {
    $api->dispatch('POST', '/v1/expand', json_encode([
        'html' => '<img src="file:///etc/passwd">',
    ], JSON_THROW_ON_ERROR), $auth);
    check(false, 'api blocks file urls');
} catch (HttpException $e) {
    check($e->error === 'remote_url_blocked', 'api blocks file urls');
}
$stored = $api->dispatch('PUT', '/v1/templates/note', json_encode([
    'html' => '<p>Hello {{name}}</p>',
    'fields' => ['name' => ['type' => 'string', 'required' => true]],
], JSON_THROW_ON_ERROR), $auth);
check(($stored->json['id'] ?? null) === 'note', 'stores a template on disk');
$loaded = $api->dispatch('GET', '/v1/templates/note', null, $auth);
check(($loaded->json['html'] ?? null) === '<p>Hello {{name}}</p>', 'reads a stored template');
$listed = $api->dispatch('GET', '/v1/templates', null, $auth);
$ids = array_column(is_array($listed->json['templates'] ?? null) ? $listed->json['templates'] : [], 'id');
check(in_array('greeting', $ids, true) && in_array('note', $ids, true), 'lists seeds and stored templates');
$deleted = $api->dispatch('DELETE', '/v1/templates/note', null, $auth);
check(($deleted->json['deleted'] ?? false) === true && ($deleted->json['seed'] ?? true) === false, 'deletes a stored template');
$pdf = $api->dispatch('POST', '/v1/pdf', json_encode([
    'html' => '<h1>Letter</h1>',
    'options' => ['paper' => 'letter', 'filename' => 'letter.pdf'],
], JSON_THROW_ON_ERROR), $auth);
check(is_string($pdf->pdf) && str_starts_with($pdf->pdf, '%PDF'), 'api render returns a pdf');
check(($pdf->headers['X-Pdf-Pages'] ?? '') !== '', 'api render reports a page count');

$limitedToken = 'limited-token';
$limitedFile = $dataDir . '/limited-tokens.json';
writeTokens($limitedFile, [
    hash('sha256', $limitedToken) => [
        'name' => 'Limited',
        'owner' => 'fixture',
        'scopes' => ['render'],
        'rate_limit' => ['limit' => 1, 'window_seconds' => 60],
    ],
]);
$limited = new PdfApi\Api(
    new PdfApi\Tokens(new PdfApi\TokenFile($limitedFile)),
    new PdfApi\RateLimiter($dataDir . '/rates'),
    new PdfApi\TemplateLibrary($dataDir),
);
$limitedAuth = 'Bearer ' . $limitedToken;
$limited->dispatch('POST', '/v1/expand', '{"html":"<p>One</p>"}', $limitedAuth);
try {
    $limited->dispatch('POST', '/v1/expand', '{"html":"<p>Two</p>"}', $limitedAuth);
    check(false, 'rate limit rejects the next call');
} catch (HttpException $e) {
    check($e->status === 429 && $e->error === 'rate_limited', 'rate limit rejects the next call');
}
removeTree($dataDir);

if ($failed > 0) {
    fwrite(STDERR, "{$failed} checks failed\n");
    exit(1);
}

fwrite(STDOUT, "all checks passed\n");
