<?php
declare(strict_types=1);

namespace App\Services\Documents;

/**
 * A very small PDF writer (§4: "generated digital prescription/PDF").
 *
 * Why hand-written: §24 fixes the stack as Core PHP with no Composer, so
 * dompdf and friends are out. What a clinic document actually needs is text
 * in columns, a few rules and a table — well inside what the PDF format can
 * be driven to do directly, using the base-14 fonts every reader has built in
 * so nothing has to be embedded.
 *
 * What it deliberately does NOT do: images, wrapping beyond a simple width
 * split, unicode beyond Latin-1. A prescription in Urdu script would need an
 * embedded font, and half-rendering one is worse than saying so — callers get
 * a transliterated fallback and the clinic can print from the screen instead.
 *
 * Coordinates are PDF points from the BOTTOM-left, which is what the format
 * uses; the helpers here take a top-down `y` and flip it, because every
 * document is written from the top down.
 */
final class Pdf
{
    public const A4_WIDTH  = 595.0;
    public const A4_HEIGHT = 842.0;

    private const FONTS = [
        'regular' => '/F1',
        'bold'    => '/F2',
        'mono'    => '/F3',
    ];

    /** @var list<string> finished page content streams */
    private array $pages = [];

    private string $stream = '';

    public function __construct(
        private readonly float $width = self::A4_WIDTH,
        private readonly float $height = self::A4_HEIGHT,
    ) {
    }

    // ---------------------------------------------------------------
    // Drawing
    // ---------------------------------------------------------------

    /** Write one line of text. `$y` is measured DOWN from the top edge. */
    public function text(
        string $value,
        float $x,
        float $y,
        float $size = 10.0,
        string $font = 'regular',
        ?array $rgb = null,
    ): void {
        $this->stream .= "BT\n";
        if ($rgb !== null) {
            $this->stream .= sprintf("%.3f %.3f %.3f rg\n", $rgb[0], $rgb[1], $rgb[2]);
        }
        $this->stream .= sprintf(
            "%s %.2f Tf\n%.2f %.2f Td\n(%s) Tj\nET\n",
            self::FONTS[$font] ?? self::FONTS['regular'],
            $size,
            $x,
            $this->height - $y,
            $this->escape($value),
        );
        if ($rgb !== null) {
            $this->stream .= "0 0 0 rg\n";
        }
    }

    /** Right-align text so a column of money lines up on its decimal point. */
    public function textRight(
        string $value,
        float $right,
        float $y,
        float $size = 10.0,
        string $font = 'regular',
    ): void {
        $this->text($value, $right - $this->widthOf($value, $size, $font), $y, $size, $font);
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $thickness = 0.6, ?array $rgb = null): void
    {
        if ($rgb !== null) {
            $this->stream .= sprintf("%.3f %.3f %.3f RG\n", $rgb[0], $rgb[1], $rgb[2]);
        }
        $this->stream .= sprintf(
            "%.2f w\n%.2f %.2f m\n%.2f %.2f l\nS\n",
            $thickness,
            $x1, $this->height - $y1,
            $x2, $this->height - $y2,
        );
        if ($rgb !== null) {
            $this->stream .= "0 0 0 RG\n";
        }
    }

    /** A filled band — used for table headers and the total row. */
    public function fill(float $x, float $y, float $w, float $h, array $rgb): void
    {
        $this->stream .= sprintf(
            "%.3f %.3f %.3f rg\n%.2f %.2f %.2f %.2f re\nf\n0 0 0 rg\n",
            $rgb[0], $rgb[1], $rgb[2],
            $x, $this->height - $y - $h, $w, $h,
        );
    }

    /**
     * Text that runs onto further lines when it will not fit.
     *
     * @return float the y after the last line written
     */
    public function paragraph(
        string $value,
        float $x,
        float $y,
        float $maxWidth,
        float $size = 10.0,
        string $font = 'regular',
        float $leading = 13.0,
    ): float {
        $words = preg_split('/\s+/', trim($value)) ?: [];
        $line  = '';

        foreach ($words as $word) {
            $candidate = $line === '' ? $word : "$line $word";
            if ($this->widthOf($candidate, $size, $font) > $maxWidth && $line !== '') {
                $this->text($line, $x, $y, $size, $font);
                $y   += $leading;
                $line = $word;
            } else {
                $line = $candidate;
            }
        }

        if ($line !== '') {
            $this->text($line, $x, $y, $size, $font);
            $y += $leading;
        }

        return $y;
    }

    public function newPage(): void
    {
        $this->pages[] = $this->stream;
        $this->stream  = '';
    }

    // ---------------------------------------------------------------
    // Output
    // ---------------------------------------------------------------

    public function render(): string
    {
        $pages = $this->pages;
        if ($this->stream !== '' || $pages === []) {
            $pages[] = $this->stream;
        }

        $objects   = [];
        $pageCount = count($pages);

        // 1 catalog, 2 pages tree, then per page: page object + content stream,
        // then the three fonts.
        $firstPageObj = 3;
        $fontBase     = $firstPageObj + ($pageCount * 2);

        $kids = [];
        for ($i = 0; $i < $pageCount; $i++) {
            $kids[] = ($firstPageObj + ($i * 2)) . ' 0 R';
        }

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $pageCount . ' >>';

        foreach ($pages as $i => $content) {
            $pageObj    = $firstPageObj + ($i * 2);
            $contentObj = $pageObj + 1;

            $objects[$pageObj] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] '
                . '/Resources << /Font << /F1 %d 0 R /F2 %d 0 R /F3 %d 0 R >> >> '
                . '/Contents %d 0 R >>',
                $this->width, $this->height,
                $fontBase, $fontBase + 1, $fontBase + 2,
                $contentObj,
            );

            $objects[$contentObj] = '<< /Length ' . strlen($content) . " >>\nstream\n"
                                  . $content . "endstream";
        }

        $objects[$fontBase]     = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[$fontBase + 1] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $objects[$fontBase + 2] = '<< /Type /Font /Subtype /Type1 /BaseFont /Courier /Encoding /WinAnsiEncoding >>';

        ksort($objects);

        $pdf     = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= "$number 0 obj\n$body\nendobj\n";
        }

        $xrefAt = strlen($pdf);
        $count  = count($objects) + 1;

        $pdf .= "xref\n0 $count\n0000000000 65535 f \n";
        for ($number = 1; $number < $count; $number++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$number] ?? 0);
        }

        $pdf .= "trailer\n<< /Size $count /Root 1 0 R >>\nstartxref\n$xrefAt\n%%EOF\n";

        return $pdf;
    }

    // ---------------------------------------------------------------

    /**
     * Approximate text width in points.
     *
     * Helvetica's real metrics are per-glyph; these averages are close enough
     * to align a money column and to decide where a line wraps, and being
     * wrong by a point does not change what the document says.
     */
    public function widthOf(string $value, float $size, string $font = 'regular'): float
    {
        $factor = match ($font) {
            'mono' => 0.60,
            'bold' => 0.55,
            default => 0.50,
        };
        return strlen($this->transliterate($value)) * $size * $factor;
    }

    /** Escape for a PDF string literal. */
    private function escape(string $value): string
    {
        return str_replace(
            ['\\', '(', ')', "\r", "\n"],
            ['\\\\', '\\(', '\\)', '', ' '],
            $this->transliterate($value),
        );
    }

    /**
     * The base-14 fonts are Latin-1 only. Anything outside it is transliterated
     * where there is an obvious equivalent and dropped where there is not —
     * never rendered as mojibake, which looks like corruption on a document a
     * patient keeps.
     */
    private function transliterate(string $value): string
    {
        $value = strtr($value, [
            '—' => '-', '–' => '-', '‑' => '-',
            '“' => '"', '”' => '"', '‘' => "'", '’' => "'",
            '…' => '...', '·' => '-', '×' => 'x', '✓' => 'v', '₨' => 'Rs',
        ]);

        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $value);

        return $converted === false ? preg_replace('/[^\x20-\x7E]/', '', $value) ?? '' : $converted;
    }
}
