<?php

declare(strict_types=1);

use PdfApi\Config;
use PdfApi\TokenFile;

require dirname(__DIR__) . '/vendor/autoload.php';

$command = $argv[1] ?? '';
$options = options(array_slice($argv, 2));
$path = $options['file'] ?? Config::tokensPath();
$file = new TokenFile($path);

try {
    match ($command) {
        'add' => add($file, $options),
        'list' => listTokens($file),
        'disable', 'enable', 'remove' => change($file, $command, $options),
        default => usage(),
    };
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

/**
 * @param array<string, string> $options
 */
function add(TokenFile $file, array $options): void
{
    $name = required($options, 'name');
    $owner = required($options, 'owner');
    if (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $owner) !== 1) {
        throw new RuntimeException('owner must be lowercase letters, digits, underscores, or hyphens');
    }
    $scopes = scopes($options['scopes'] ?? 'render,templates:write');
    $from = $options['from'] ?? null;
    if ($from !== null) {
        $token = rawToken($from);
        $show = false;
    } else {
        $token = bin2hex(random_bytes(32));
        $show = true;
    }
    $records = $file->load(false);
    $record = [
        'name' => $name,
        'owner' => $owner,
        'scopes' => $scopes,
    ];
    if (isset($options['limit']) || isset($options['window'])) {
        $limit = (int) ($options['limit'] ?? '30');
        $window = (int) ($options['window'] ?? '60');
        if ($limit < 1 || $limit > 10000 || $window < 60 || $window > 86400) {
            throw new RuntimeException('rate limit must be 1-10000 requests per 60-86400 seconds');
        }
        $record['rate_limit'] = ['limit' => $limit, 'window_seconds' => $window];
    }
    $records[hash('sha256', $token)] = $record;
    $file->save($records);
    fwrite(STDERR, "Stored a hash for {$name} ({$owner}) in {$file->path()}\n");
    if ($show) {
        fwrite(STDOUT, $token . "\n");
    }
}

function listTokens(TokenFile $file): void
{
    foreach ($file->load(true) as $record) {
        $name = is_string($record['name'] ?? null) ? $record['name'] : '?';
        $owner = is_string($record['owner'] ?? null) ? $record['owner'] : '?';
        $scopes = isset($record['scopes']) && is_array($record['scopes']) ? implode(',', $record['scopes']) : '';
        $state = ($record['disabled'] ?? false) === true ? 'disabled' : 'active';
        fwrite(STDOUT, "{$name}\t{$owner}\t{$scopes}\t{$state}\n");
    }
}

/**
 * @param array<string, string> $options
 */
function change(TokenFile $file, string $command, array $options): void
{
    $name = required($options, 'name');
    $owner = $options['owner'] ?? null;
    $records = $file->load(true);
    $matches = [];
    foreach ($records as $hash => $record) {
        if (($record['name'] ?? null) !== $name) {
            continue;
        }
        if ($owner !== null && ($record['owner'] ?? null) !== $owner) {
            continue;
        }
        $matches[$hash] = $record;
    }
    if ($matches === []) {
        throw new RuntimeException('No matching token');
    }
    if (count($matches) > 1) {
        throw new RuntimeException('More than one token has that name; pass --owner');
    }
    $hash = array_key_first($matches);
    if ($command === 'remove') {
        unset($records[$hash]);
    } else {
        $records[$hash]['disabled'] = $command === 'disable';
    }
    $file->save($records);
    fwrite(STDERR, ucfirst($command) . "d {$name}\n");
}

function rawToken(string $path): string
{
    if (!is_file($path)) {
        throw new RuntimeException('Token source file was not found');
    }
    $raw = file_get_contents($path);
    if (!is_string($raw)) {
        throw new RuntimeException('Token source file could not be read');
    }
    $token = trim($raw);
    if ($token === '' || preg_match('/\s/', $token) === 1) {
        throw new RuntimeException('Token source file must contain one token');
    }

    return $token;
}

/**
 * @return list<string>
 */
function scopes(string $value): array
{
    $known = ['render', 'templates:write'];
    $scopes = [];
    foreach (explode(',', $value) as $scope) {
        $scope = trim($scope);
        if ($scope === '') {
            continue;
        }
        if (!in_array($scope, $known, true)) {
            throw new RuntimeException('Unknown scope: ' . $scope);
        }
        $scopes[] = $scope;
    }
    if ($scopes === []) {
        throw new RuntimeException('At least one scope is required');
    }

    return $scopes;
}

/**
 * @param array<string, string> $options
 */
function required(array $options, string $name): string
{
    $value = $options[$name] ?? '';
    if ($value === '') {
        throw new RuntimeException("--{$name} is required");
    }

    return $value;
}

/**
 * @param list<string> $argv
 * @return array<string, string>
 */
function options(array $argv): array
{
    $options = [];
    $count = count($argv);
    for ($i = 0; $i < $count; $i++) {
        $arg = $argv[$i];
        if (!str_starts_with($arg, '--') || strlen($arg) < 3) {
            throw new RuntimeException('Unexpected argument: ' . $arg);
        }
        $name = substr($arg, 2);
        $value = $argv[++$i] ?? '';
        if ($value === '' || str_starts_with($value, '--')) {
            throw new RuntimeException("--{$name} needs a value");
        }
        $options[$name] = $value;
    }

    return $options;
}

function usage(): never
{
    fwrite(STDERR, <<<'TXT'
Usage:
  token.php add --name NAME --owner OWNER [--scopes render,templates:write] [--from token.txt] [--file PATH]
  token.php list [--file PATH]
  token.php disable --name NAME [--owner OWNER] [--file PATH]
  token.php enable --name NAME [--owner OWNER] [--file PATH]
  token.php remove --name NAME [--owner OWNER] [--file PATH]

add prints a new bearer token once. --from stores a hash of an existing token file and does not print it.
The token file stores hashes only. Keep the raw token outside this container.

TXT);
    exit(1);
}
