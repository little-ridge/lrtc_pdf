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

if ($failed > 0) {
    fwrite(STDERR, "{$failed} checks failed\n");
    exit(1);
}

fwrite(STDOUT, "all checks passed\n");
