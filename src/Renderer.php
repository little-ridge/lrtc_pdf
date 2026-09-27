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
        $expanded = $request->expand();
        try {
            $mpdf = new Mpdf(self::config($request->paper, $request->defaultFont));
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
            if ($expanded['header_html'] !== null && $expanded['header_html'] !== '') {
                $mpdf->SetHTMLHeader($expanded['header_html']);
            }
            if ($expanded['footer_html'] !== null && $expanded['footer_html'] !== '') {
                $mpdf->SetHTMLFooter($expanded['footer_html']);
            }
            if (property_exists($mpdf, 'curlFollowLocation')) {
                $mpdf->curlFollowLocation = false;
            }
            $mpdf->WriteHTML($expanded['html']);
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

    /**
     * @return array<string, mixed>
     */
    public static function config(string $paper, string $defaultFont): array
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

        return array_merge([
            'orientation' => 'P',
            'tempDir' => sys_get_temp_dir(),
            'fontDir' => array_merge($fontDirs, [Presets::fontDirectory()]),
            'fontdata' => $fontData,
            'default_font' => $defaultFont,
        ], Presets::paper($paper));
    }
}
