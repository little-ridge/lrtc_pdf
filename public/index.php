<?php

declare(strict_types=1);

use PdfApi\DocumentRequest;
use PdfApi\HttpException;
use PdfApi\Presets;
use PdfApi\Renderer;
use PdfApi\Seeds;

require dirname(__DIR__) . '/vendor/autoload.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');

set_exception_handler(static function (Throwable $e): void {
    if ($e instanceof HttpException) {
        respondJson($e->toArray(), $e->status);
        return;
    }
    error_log($e::class . ': ' . $e->getMessage());
    respondJson([
        'error' => 'internal',
        'message' => 'Internal error',
        'details' => null,
    ], 500);
});

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (!is_string($path)) {
    $path = '/';
}

if ($method === 'GET' && $path === '/health') {
    respondJson(['ok' => true], 200);
}

if ($method === 'GET' && $path === '/seeds') {
    respondJson(['templates' => Seeds::summaries()], 200);
}

if ($method === 'GET' && preg_match('#^/seeds/([a-z0-9][a-z0-9-]{0,63})$#', $path, $matches) === 1) {
    respondJson(Seeds::document($matches[1]), 200);
}

if ($method === 'POST' && ($path === '/render' || $path === '/expand')) {
    $request = DocumentRequest::fromArray(readJsonBody());
    if ($path === '/expand') {
        respondJson($request->expand(), 200);
    }
    $rendered = Renderer::render($request);
    http_response_code(200);
    header('Content-Type: application/pdf');
    header('X-Pdf-Pages: ' . $rendered['pages']);
    header('X-Pdf-Bytes: ' . $rendered['bytes']);
    header('Cache-Control: no-store');
    echo $rendered['pdf'];
    exit;
}

respondJson([
    'error' => 'not_found',
    'message' => 'Not found',
    'details' => null,
], 404);

function readJsonBody(): mixed
{
    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > Presets::maxBodyBytes()) {
        throw new HttpException(413, 'payload_too_large', 'Request body exceeds the size limit', [
            'max_bytes' => Presets::maxBodyBytes(),
        ]);
    }
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > Presets::maxBodyBytes()) {
        throw new HttpException(413, 'payload_too_large', 'Request body exceeds the size limit', [
            'max_bytes' => Presets::maxBodyBytes(),
        ]);
    }
    if (trim($raw) === '') {
        throw new HttpException(400, 'invalid_json', 'Request body must be JSON');
    }
    try {
        return json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new HttpException(400, 'invalid_json', 'Request body must be JSON');
    }
}

/**
 * @param array<string, mixed> $payload
 */
function respondJson(array $payload, int $status): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}
