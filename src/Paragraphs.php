<?php

declare(strict_types=1);

namespace PdfApi;

final class Paragraphs
{
    public static function toRows(string $text, string $tdStyle = ''): string
    {
        $attribute = '';
        if ($tdStyle !== '') {
            if (!preg_match('/^style="[a-zA-Z0-9:;%\s.,#-]*"$/', $tdStyle)) {
                throw new HttpException(400, 'invalid_fields', 'td_style must be a simple style attribute');
            }
            $attribute = ' ' . $tdStyle;
        }

        $rows = '';
        $lines = preg_split('/\r\n|\r|\n/', trim($text));
        if ($lines === false) {
            return '';
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $escaped = htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $rows .= '<tr style="page-break-inside:avoid; page-break-after:auto;"><td'
                . $attribute
                . '><p>'
                . $escaped
                . '</p></td></tr>';
        }

        return $rows;
    }
}
