<?php

declare(strict_types=1);

namespace PdfApi;

final class Tokens
{
    public function __construct(private readonly TokenFile $file)
    {
    }

    /**
     * @return array{token: Token, hash: string}
     */
    public function authenticate(?string $header): array
    {
        $header ??= '';
        if (preg_match('/^Bearer\s+(\S+)$/i', $header, $matches) !== 1) {
            throw new HttpException(401, 'unauthorized', 'Bearer token required');
        }
        $hash = hash('sha256', $matches[1]);
        $record = $this->file->find($hash);
        if ($record === null) {
            throw new HttpException(401, 'unauthorized', 'Unknown token');
        }
        $token = Token::parse(json_encode($record, JSON_THROW_ON_ERROR));
        if ($token->disabled) {
            throw new HttpException(401, 'unauthorized', 'Token is disabled');
        }

        return ['token' => $token, 'hash' => $hash];
    }
}
