<?php

declare(strict_types=1);

namespace PdfApi;

use Mpdf\Mpdf;
use Mpdf\Output\Destination;

final class Renderer
{
    /**
     * @return array{pdf: string, pages: int, bytes: int}
     */
    public static function render(DocumentRequest $request): array
    {
        try {
            $mpdf = $request->grid !== null
                ? self::openGrid($request)
                : self::openHtml($request);
            $pdf = $mpdf->Output('', Destination::STRING_RETURN);
            $pages = max(1, (int) $mpdf->page);
        } catch (HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            error_log('pdf render failed: ' . $e->getMessage());
            throw new HttpException(500, 'render_failed', 'PDF render failed');
        }

        return [
            'pdf' => $pdf,
            'pages' => $pages,
            'bytes' => strlen($pdf),
        ];
    }

    private static function openHtml(DocumentRequest $request): Mpdf
    {
        $expanded = $request->expand();
        $mpdf = new Mpdf(self::config($request->paper, $request->defaultFont, $request->margins));
        self::describe($mpdf, $request);
        if ($expanded['header_html'] !== null && $expanded['header_html'] !== '') {
            $mpdf->SetHTMLHeader($expanded['header_html']);
        }
        if ($expanded['footer_html'] !== null && $expanded['footer_html'] !== '') {
            $mpdf->SetHTMLFooter($expanded['footer_html']);
        }
        $mpdf->WriteHTML($expanded['html']);

        return $mpdf;
    }

    /**
     * The underlay is the page. Caller margins are ignored so a point on the
     * grid stays on the same point of the imported page.
     */
    private static function openGrid(DocumentRequest $request): Mpdf
    {
        $grid = $request->grid ?? [];
        $width = (float) ($grid['width'] ?? 612);
        $height = (float) ($grid['height'] ?? 792);
        $config = self::config($request->paper, $request->defaultFont, [
            'margin_left' => 0.0,
            'margin_right' => 0.0,
            'margin_top' => 0.0,
            'margin_bottom' => 0.0,
        ]);
        $config['margin_header'] = 0;
        $config['margin_footer'] = 0;
        $mpdf = new Mpdf($config);
        self::describe($mpdf, $request);
        $mpdf->SetAutoPageBreak(false);
        $pages = is_array($grid['pages'] ?? null) ? $grid['pages'] : [];
        $underlay = is_string($grid['underlay'] ?? null) ? $grid['underlay'] : '';
        $sourcePages = $underlay !== '' ? $mpdf->setSourceFile($underlay) : 0;
        $count = max(count($pages), $sourcePages, 1);
        $widthMm = $width * 25.4 / 72;
        $heightMm = $height * 25.4 / 72;
        for ($index = 0; $index < $count; $index++) {
            if ($index > 0) {
                $mpdf->AddPage();
            }
            if ($index < $sourcePages) {
                $mpdf->useTemplate($mpdf->importPage($index + 1), 0, 0, $widthMm, $heightMm);
            }
            $items = $pages[$index]['items'] ?? [];
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                if (is_array($item)) {
                    self::paint($mpdf, $height, $item, $request->data);
                }
            }
        }

        return $mpdf;
    }

    private static function describe(Mpdf $mpdf, DocumentRequest $request): void
    {
        if ($request->title !== '') {
            $mpdf->SetTitle($request->title);
        }
        if ($request->author !== '') {
            $mpdf->SetAuthor($request->author);
        }
        if ($request->subject !== '') {
            $mpdf->SetSubject($request->subject);
        }
        if ($request->keywords !== '') {
            $mpdf->SetKeywords($request->keywords);
        }
        if (property_exists($mpdf, 'curlFollowLocation')) {
            $mpdf->curlFollowLocation = false;
        }
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $data
     */
    private static function paint(Mpdf $mpdf, float $pageHeight, array $item, array $data): void
    {
        $kind = $item['kind'] ?? '';
        if ($kind === 'mark') {
            self::paintMark($mpdf, $pageHeight, $item, self::marked($data[$item['key']] ?? null));

            return;
        }
        if ($kind === 'signature') {
            self::paintSignature($mpdf, $pageHeight, $item, $data);

            return;
        }
        if ($kind === 'blank') {
            self::paintBlank($mpdf, $pageHeight, $item);

            return;
        }
        if ($kind === 'text') {
            $value = self::textValue($data, $item);
            if ($value === '') {
                return;
            }
            self::paintText($mpdf, $pageHeight, $item, $value, (string) ($item['font'] ?? 'arial'), (float) ($item['size'] ?? 10));
        }
    }

    /**
     * @param array<string, mixed> $item
     */
    private static function paintBlank(Mpdf $mpdf, float $pageHeight, array $item): void
    {
        [$x, $y, $w, $h] = self::box($pageHeight, $item);
        $mpdf->SetFillColor(255);
        $mpdf->Rect($x, $y, $w, $h, 'F');
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $data
     */
    private static function paintSignature(Mpdf $mpdf, float $pageHeight, array $item, array $data): void
    {
        $raw = $data[$item['key']] ?? '';
        $raw = is_string($raw) ? trim($raw) : '';
        $image = $raw !== '' ? FormGrid::image($raw) : null;
        if ($image !== null) {
            self::paintImage($mpdf, $pageHeight, $item, $image['bytes'], $image['type']);

            return;
        }
        if ($raw !== '' && !str_starts_with($raw, 'data:')) {
            self::paintText($mpdf, $pageHeight, $item, $raw, 'signature', self::scriptSize($item));

            return;
        }
        $fallback = $item['fallback'] ?? null;
        $typed = is_string($fallback) && isset($data[$fallback]) && is_string($data[$fallback]) ? trim($data[$fallback]) : '';
        if ($typed !== '' && !str_starts_with($typed, 'data:')) {
            self::paintText($mpdf, $pageHeight, $item, $typed, 'signature', self::scriptSize($item));
        }
    }

    /**
     * @param array<string, mixed> $item
     */
    private static function scriptSize(array $item): float
    {
        $height = (float) ($item['h'] ?? 16);

        return max(10.0, min(22.0, $height * 0.55));
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $data
     */
    private static function textValue(array $data, array $item): string
    {
        $value = $data[$item['key']] ?? '';
        if (!is_string($value) || trim($value) === '') {
            $fallback = $item['fallback'] ?? null;
            if (is_string($fallback) && isset($data[$fallback]) && is_string($data[$fallback])) {
                $value = $data[$fallback];
            }
        }
        if (!is_string($value)) {
            return '';
        }
        $value = trim($value);
        if (str_starts_with($value, 'data:')) {
            return '';
        }
        $max = $item['max'] ?? null;
        if (is_int($max)) {
            $value = mb_substr($value, 0, $max);
        }

        return $value;
    }

    private static function marked(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        return in_array(trim($value), ['●', 'X', 'x', 'yes', '1'], true);
    }

    /**
     * @param array<string, mixed> $item
     */
    private static function paintText(Mpdf $mpdf, float $pageHeight, array $item, string $value, string $font, float $size): void
    {
        [$x, $y, $w, $h] = self::box($pageHeight, $item);
        $heightPt = (float) $item['h'];
        $pad = max(0.0, ($heightPt - $size) / 2);
        $align = ($item['align'] ?? 'left') === 'center' ? 'center' : 'left';
        $html = '<div style="font-family:' . $font . ';font-size:' . $size . 'pt;line-height:' . $size
            . 'pt;margin:0;padding:' . $pad . 'pt 1.5pt 0;text-align:' . $align . ';">'
            . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
        $mpdf->WriteFixedPosHTML($html, $x, $y, $w, $h, 'hidden');
    }

    /**
     * @param array<string, mixed> $item
     */
    private static function paintMark(Mpdf $mpdf, float $pageHeight, array $item, bool $selected): void
    {
        [$x, $y, $w, $h] = self::box($pageHeight, $item);
        $mpdf->SetDrawColor(0);
        $mpdf->SetFillColor(0);
        if (($item['style'] ?? 'circle') === 'square') {
            $inset = 0.4;
            $mpdf->SetLineWidth(0.4);
            $mpdf->Rect($x + $inset, $y + $inset, max(0.2, $w - (2 * $inset)), max(0.2, $h - (2 * $inset)), 'D');
            if ($selected) {
                $mpdf->SetLineWidth(0.45);
                $pad = min($w, $h) * 0.22;
                $mpdf->Line($x + $pad, $y + $pad, $x + $w - $pad, $y + $h - $pad);
                $mpdf->Line($x + $w - $pad, $y + $pad, $x + $pad, $y + $h - $pad);
            }

            return;
        }
        $mpdf->SetLineWidth(0.25);
        $radius = min($w, $h) * 0.36;
        $cx = $x + ($w / 2);
        $cy = $y + ($h / 2);
        $mpdf->Circle($cx, $cy, $radius, 'D');
        if ($selected) {
            $mpdf->Circle($cx, $cy, $radius * 0.5, 'F');
        }
    }

    /**
     * @param array<string, mixed> $item
     */
    private static function paintImage(Mpdf $mpdf, float $pageHeight, array $item, string $bytes, string $type): void
    {
        [$x, $y, $w, $h] = self::box($pageHeight, $item);
        $tmp = tempnam(sys_get_temp_dir(), 'pdfsig');
        if ($tmp === false) {
            return;
        }
        $path = $tmp . ($type === 'png' ? '.png' : '.jpg');
        rename($tmp, $path);
        try {
            if (file_put_contents($path, $bytes) === false) {
                return;
            }
            $mpdf->Image($path, $x, $y, $w, $h, $type, '', true, true);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * @param array<string, mixed> $item
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private static function box(float $pageHeight, array $item): array
    {
        $scale = 25.4 / 72;
        $x = (float) $item['x'];
        $y = (float) $item['y'];
        $w = (float) $item['w'];
        $h = (float) $item['h'];

        return [
            $x * $scale,
            ($pageHeight - ($y + $h)) * $scale,
            $w * $scale,
            $h * $scale,
        ];
    }

    /**
     * @param array{margin_left: float, margin_right: float, margin_top: float, margin_bottom: float}|null $margins
     * @return array<string, mixed>
     */
    public static function config(string $paper, string $defaultFont, ?array $margins = null): array
    {
        Presets::assertFont($defaultFont);
        $defaultConfig = (new \Mpdf\Config\ConfigVariables())->getDefaults();
        $fontDirs = $defaultConfig['fontDir'];
        $fontData = (new \Mpdf\Config\FontVariables())->getDefaults()['fontdata'];
        $emojiPath = Presets::emojiPath();
        if (is_file($emojiPath)) {
            $fontData['emoji'] = [
                'R' => 'NotoEmoji-VariableFont_wght.ttf',
            ];
        }
        $fontDir = Presets::fontDirectory();
        if (is_file($fontDir . '/LiberationSans-Regular.ttf')) {
            $fontData['arial'] = [
                'R' => 'LiberationSans-Regular.ttf',
                'B' => 'LiberationSans-Bold.ttf',
                'I' => 'LiberationSans-Italic.ttf',
                'BI' => 'LiberationSans-BoldItalic.ttf',
            ];
        }
        if (is_file($fontDir . '/Allura-Regular.ttf')) {
            $fontData['signature'] = [
                'R' => 'Allura-Regular.ttf',
            ];
        }

        $config = array_merge([
            'orientation' => 'P',
            'tempDir' => sys_get_temp_dir(),
            'fontDir' => array_merge($fontDirs, [Presets::fontDirectory()]),
            'fontdata' => $fontData,
            'default_font' => $defaultFont,
        ], Presets::paper($paper));
        if ($margins !== null) {
            foreach (['margin_left', 'margin_right', 'margin_top', 'margin_bottom'] as $key) {
                $config[$key] = $margins[$key];
            }
        }

        return $config;
    }
}
