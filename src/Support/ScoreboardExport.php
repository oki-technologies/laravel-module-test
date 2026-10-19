<?php

declare(strict_types=1);

namespace Oki\ModuleTest\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;
use SplTempFileObject;

/**
 * The `--totals` scoreboard, kept as data and written out as a file.
 *
 * The terminal is where a score is read once; a file is how it is kept, sent
 * on, or put beside the last run to see what moved. The rows are collected as
 * the run goes — the same numbers the scoreboard draws, not a second
 * measurement — and written in whichever format the filename asks for.
 *
 * **The file says what the screen said.** A module keeps its counter, its
 * dot leader, its floor and what it cost, in the order the run put them in, so
 * a reader who knows the scoreboard does not have to learn a second layout.
 * The page-shaped formats are cut to the text: one page, sized to the rows,
 * with a margin and no more — a scoreboard padded out to A4 is mostly
 * whitespace, and the part worth reading ends up in a corner of it.
 */
class ScoreboardExport
{
    /** What a scoreboard is called when the caller did not name it. */
    private const string DEFAULT_NAME = 'module-test';

    /** And what it is written as when the name says nothing about format. */
    private const string DEFAULT_FORMAT = 'json';

    /** Extensions this writes, mapped to the method that writes them. */
    private const array FORMATS = [
        'txt' => 'text',
        'md' => 'markdown',
        'json' => 'json',
        'csv' => 'csv',
        'pdf' => 'pdf',
        'png' => 'png',
        'jpg' => 'jpeg',
        'jpeg' => 'jpeg',
    ];

    /** Courier's advance width at 1pt, used to size the PDF page to its text. */
    private const float COURIER_WIDTH = 0.6;

    private const int POINT_SIZE = 10;

    private const int LEADING = 14;

    private const int MARGIN = 12;

    /** The colours the scoreboard wears, as RGB. */
    private const array PALETTE = [
        'green' => [0x3C, 0xB3, 0x71],
        'yellow' => [0xD7, 0xA5, 0x21],
        'red' => [0xE0, 0x4F, 0x5F],
        'gray' => [0x80, 0x80, 0x80],
        'white' => [0xE6, 0xE6, 0xE6],
    ];

    private const array BACKGROUND = [0x17, 0x17, 0x17];

    /**
     * One row per module, in the order they ran.
     *
     * @var array<int, array{name: string, counter: ?string, coverage: float, statements: int, covered: int, seconds: float, minimum: ?float}>
     */
    private array $modules = [];

    public function __construct(private readonly ?float $minimum = null) {}

    /**
     * The file a `--export` value asks for.
     *
     * The option is there to be reached for in a hurry, so every half of the
     * answer has a whole one behind it: nothing at all is `module-test.json`,
     * a bare extension keeps that name and changes the format, and a name
     * with no extension on it is written as JSON and says so. A file called
     * `export`, with nothing to say what is in it, is what the caller has to
     * open to find out.
     *
     * JSON is the default because an unnamed export is the one nobody chose a
     * format for: it is read back by whatever asked for it — a pipeline step,
     * a diff against the last run — where the drawn formats are chosen on
     * purpose, by somebody who means to look at them.
     */
    public static function resolve(?string $file): string
    {
        $file = trim((string) $file);

        if ($file === '') {
            return self::DEFAULT_NAME.'.'.self::DEFAULT_FORMAT;
        }

        $directory = str_contains($file, '/') ? dirname($file).'/' : '';
        $name = pathinfo($file, PATHINFO_FILENAME);
        $extension = pathinfo($file, PATHINFO_EXTENSION);

        return $directory
            .($name === '' ? self::DEFAULT_NAME : $name)
            .'.'.($extension === '' ? self::DEFAULT_FORMAT : $extension);
    }

    /**
     * Whether a filename names a format this can write.
     */
    public static function writes(string $file): bool
    {
        return self::formatOf($file) !== null;
    }

    /**
     * The format a filename asks for, or null where its extension names one
     * this cannot write.
     */
    private static function formatOf(string $file): ?string
    {
        return self::FORMATS[Str::lower(pathinfo($file, PATHINFO_EXTENSION))] ?? null;
    }

    /**
     * The formats it writes, for a message that has to name them.
     *
     * @return array<int, string>
     */
    public static function formats(): array
    {
        return array_keys(self::FORMATS);
    }

    /**
     * Record a module's score as its run finishes.
     *
     * A module may be held to a floor of its own rather than the run's, so the
     * row carries the one it was actually measured against. Left out, it is
     * the run's.
     *
     * @param  array{statements: int, covered: int}  $counts
     */
    public function add(string $name, float $coverage, array $counts, float $seconds, ?string $counter = null, ?float $minimum = null): void
    {
        $this->modules[] = [
            'name' => $name,
            'counter' => $counter,
            'coverage' => $coverage,
            'statements' => $counts['statements'],
            'covered' => $counts['covered'],
            'seconds' => $seconds,
            'minimum' => $minimum ?? $this->minimum,
        ];
    }

    /**
     * Whether anything was recorded — a run that failed before any module
     * finished has no scoreboard to write.
     */
    public function isEmpty(): bool
    {
        return $this->modules === [];
    }

    /**
     * Write the scoreboard to `$file`, in the format its extension names.
     */
    public function write(string $file, float $seconds): void
    {
        $format = self::formatOf($file) ?? throw new InvalidArgumentException("No exporter for [{$file}].");

        $directory = dirname($file);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        match ($format) {
            'text' => file_put_contents($file, $this->text($seconds)),
            'markdown' => file_put_contents($file, $this->markdown($seconds)),
            'json' => file_put_contents($file, $this->json($seconds)),
            'csv' => file_put_contents($file, $this->csv($seconds)),
            'pdf' => file_put_contents($file, $this->pdf($seconds)),
            default => $this->image($file, $format, $seconds),
        };
    }

    /**
     * The run's own total: covered statements over executable ones, weighed
     * across every module rather than averaged across their percentages.
     */
    public function coverage(): float
    {
        $statements = (int) array_sum(array_column($this->modules, 'statements'));

        if ($statements === 0) {
            return 0.0;
        }

        return (int) array_sum(array_column($this->modules, 'covered')) / $statements * 100;
    }

    /**
     * The scoreboard as it stood on the screen, with the colours dropped.
     *
     * The dot leaders and the column the scores line up in are the whole of
     * the layout, and both survive being pasted into a message or diffed
     * against last week's run.
     */
    private function text(float $seconds): string
    {
        $lines = array_map(
            fn (array $row): string => $this->flatten($row),
            $this->rows($seconds),
        );

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    /**
     * The same rows as a table, for a README, a pull request or a wiki.
     *
     * The total is the last row rather than a sentence under the table, so
     * the table is the whole answer and sorting it does not leave the total
     * behind.
     */
    private function markdown(float $seconds): string
    {
        $floor = $this->minimum !== null;

        $header = array_values(array_filter([
            'Module',
            'Coverage',
            $floor ? 'Minimum' : null,
            'Statements',
            'Covered',
            'Time',
        ]));

        $lines = [
            '| '.implode(' | ', $header).' |',
            '| '.implode(' | ', array_map(
                fn (int $index): string => $index === 0 ? ':---' : '---:',
                array_keys($header),
            )).' |',
        ];

        foreach ($this->modules as $module) {
            $lines[] = $this->markdownRow(
                $module['name'],
                $module['coverage'],
                $module['statements'],
                $module['covered'],
                $module['seconds'],
            );
        }

        $lines[] = $this->markdownRow(
            '**'.$this->label().'**',
            $this->coverage(),
            (int) array_sum(array_column($this->modules, 'statements')),
            (int) array_sum(array_column($this->modules, 'covered')),
            $seconds,
            bold: true,
        );

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    /**
     * One row of that table.
     */
    private function markdownRow(
        string $name,
        float $coverage,
        int $statements,
        int $covered,
        float $seconds,
        bool $bold = false,
    ): string {
        $score = $this->score($coverage);
        $emphasis = fn (string $cell): string => $bold ? "**{$cell}**" : $cell;

        $cells = array_values(array_filter([
            $name,
            $emphasis($score),
            $this->minimum === null ? null : $emphasis(ScopedCoverageReport::asked($this->minimum).'%'),
            $emphasis((string) $statements),
            $emphasis((string) $covered),
            $emphasis(ScopedCoverageReport::took($seconds)),
        ], fn (?string $cell): bool => $cell !== null));

        return '| '.implode(' | ', $cells).' |';
    }

    /**
     * The scoreboard as records, for a reader that is a program.
     */
    private function json(float $seconds): string
    {
        $payload = [
            'generated_at' => now()->toIso8601String(),
            'minimum' => $this->minimum,
            'modules' => array_map(fn (array $module): array => [
                'name' => $module['name'],
                'coverage' => (float) ScopedCoverageReport::reached($module['coverage']),
                'statements' => $module['statements'],
                'covered' => $module['covered'],
                'seconds' => round($module['seconds'], 1),
                'minimum' => $module['minimum'],
                'passed' => $this->passed($module['coverage'], $module['minimum']),
            ], $this->modules),
            'total' => [
                'modules' => count($this->modules),
                'coverage' => (float) ScopedCoverageReport::reached($this->coverage()),
                'statements' => (int) array_sum(array_column($this->modules, 'statements')),
                'covered' => (int) array_sum(array_column($this->modules, 'covered')),
                'seconds' => round($seconds, 1),
                'passed' => $this->passed($this->coverage(), $this->minimum),
            ],
        ];

        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION).PHP_EOL;
    }

    /**
     * The same records as a sheet: one header, one row per module, and the
     * run's own total as the last row — a spreadsheet sorts and sums from
     * there without being told which column is which.
     */
    private function csv(float $seconds): string
    {
        $sheet = new SplTempFileObject;

        $sheet->fputcsv(['module', 'coverage', 'minimum', 'statements', 'covered', 'seconds', 'passed'], escape: '');

        foreach ($this->modules as $module) {
            $sheet->fputcsv([
                $module['name'],
                ScopedCoverageReport::reached($module['coverage']),
                $module['minimum'] === null ? '' : ScopedCoverageReport::asked($module['minimum']),
                $module['statements'],
                $module['covered'],
                number_format($module['seconds'], 1, '.', ''),
                match ($this->passed($module['coverage'], $module['minimum'])) {
                    true => 'yes',
                    false => 'no',
                    null => '',
                },
            ], escape: '');
        }

        $sheet->fputcsv([
            $this->label(),
            ScopedCoverageReport::reached($this->coverage()),
            $this->minimum === null ? '' : ScopedCoverageReport::asked($this->minimum),
            (int) array_sum(array_column($this->modules, 'statements')),
            (int) array_sum(array_column($this->modules, 'covered')),
            number_format($seconds, 1, '.', ''),
            match ($this->passed($this->coverage(), $this->minimum)) {
                true => 'yes',
                false => 'no',
                null => '',
            },
        ], escape: '');

        $written = $sheet->ftell();
        $sheet->rewind();

        return $sheet->fread(max(1, $written)) ?: '';
    }

    /**
     * A single page, sized to the rows it holds.
     *
     * Written by hand rather than through a renderer: the document is a dozen
     * lines of monospaced text, and every library that would draw it also
     * brings a page size, a margin box and a page break with it — which is the
     * one thing this must not have.
     */
    private function pdf(float $seconds): string
    {
        $rows = $this->rows($seconds);
        $width = (int) ceil($this->widest($rows) * self::POINT_SIZE * self::COURIER_WIDTH) + self::MARGIN * 2;
        $height = count($rows) * self::LEADING + self::MARGIN * 2;

        // The same dark ground the terminal and the picture use: the colours
        // the scoreboard reads by are chosen against it, and on white the
        // lightest of them is not there at all.
        $content = sprintf(
            "%.3f %.3f %.3f rg 0 0 %d %d re f\n",
            ...[...array_map(fn (int $c): float => $c / 255, self::BACKGROUND), $width, $height],
        );

        $content .= 'BT /F1 '.self::POINT_SIZE.' Tf '.self::LEADING." TL\n";
        $content .= sprintf("%.3f %.3f %.3f rg\n", ...array_map(fn (int $c): float => $c / 255, self::PALETTE['white']));
        $content .= self::MARGIN.' '.($height - self::MARGIN - self::POINT_SIZE)." Td\n";

        foreach ($rows as $index => $row) {
            if ($index > 0) {
                $content .= "T*\n";
            }

            $drawn = 0;

            foreach ($row as [$text, $color]) {
                [$r, $g, $b] = self::PALETTE[$color];
                // Each segment is placed from the start of the line rather
                // than run on from the last one: Courier is monospaced, so the
                // offset is arithmetic, and a segment that has to be recoloured
                // later cannot then shift the rest of the row.
                $offset = $drawn * self::POINT_SIZE * self::COURIER_WIDTH;
                $content .= sprintf(
                    "%.3f %.3f %.3f rg %.2f 0 Td (%s) Tj %.2f 0 Td\n",
                    $r / 255,
                    $g / 255,
                    $b / 255,
                    $offset === 0.0 ? 0 : $offset,
                    $this->escape($text),
                    $offset === 0.0 ? 0 : -$offset,
                );
                $drawn += mb_strlen($text);
            }
        }

        $content .= 'ET';

        $objects = [
            '<</Type/Catalog/Pages 2 0 R>>',
            '<</Type/Pages/Kids[3 0 R]/Count 1>>',
            "<</Type/Page/Parent 2 0 R/MediaBox[0 0 {$width} {$height}]/Resources<</Font<</F1 5 0 R>>>>/Contents 4 0 R>>",
            '<</Length '.strlen($content).">>\nstream\n{$content}\nendstream",
            '<</Type/Font/Subtype/Type1/BaseFont/Courier>>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n{$object}\nendobj\n";
        }

        $startxref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<</Size ".(count($objects) + 1)."/Root 1 0 R>>\nstartxref\n{$startxref}\n%%EOF\n";
    }

    /**
     * The scoreboard as a picture of itself, cropped to the text.
     *
     * GD's built-in bitmap font, doubled: it needs no font file on the host,
     * and nearest-neighbour keeps the letters as sharp at twice the size as
     * they were at one.
     */
    private function image(string $file, string $format, float $seconds): void
    {
        $rows = $this->rows($seconds);
        $columns = $this->widest($rows);

        $font = 5;
        $cell = imagefontwidth($font);
        $line = imagefontheight($font) + 3;

        $width = max(1, $columns * $cell + self::MARGIN * 2);
        $height = max(1, count($rows) * $line + self::MARGIN * 2);

        $canvas = imagecreatetruecolor($width, $height);

        // A truecolor canvas takes the colour as the number it is, so nothing
        // has to be allocated into a palette first.
        $color = fn (array $rgb): int => $rgb[0] << 16 | $rgb[1] << 8 | $rgb[2];

        imagefilledrectangle($canvas, 0, 0, $width, $height, $color(self::BACKGROUND));

        $colors = array_map($color, self::PALETTE);

        foreach ($rows as $index => $row) {
            $drawn = 0;
            $y = self::MARGIN + $index * $line;

            foreach ($row as [$text, $color]) {
                imagestring($canvas, $font, self::MARGIN + $drawn * $cell, $y, $text, $colors[$color]);
                $drawn += mb_strlen($text);
            }
        }

        $scaled = imagescale($canvas, $width * 2, $height * 2, IMG_NEAREST_NEIGHBOUR) ?: $canvas;

        if ($format === 'jpeg') {
            imagejpeg($scaled, $file, 92);
        } else {
            imagepng($scaled, $file);
        }

        imagedestroy($canvas);
    }

    /**
     * Every row of the scoreboard as coloured segments — the counter, the
     * name, the dot leader, the score, the floor and what it cost — so the
     * page and the picture draw the same thing the terminal drew.
     *
     * @return array<int, array<int, array{0: string, 1: string}>>
     */
    private function rows(float $seconds): array
    {
        $left = [];
        $right = [];

        foreach ($this->modules as $module) {
            $left[] = ($module['counter'] === null ? '' : $module['counter'].' ').$module['name'];
            $right[] = $this->score($module['coverage']).$this->floor($module['minimum']).' | '.ScopedCoverageReport::took($module['seconds']);
        }

        $total = $this->label();
        $left[] = $total;
        $right[] = $this->score($this->coverage()).$this->floor($this->minimum).' | '.ScopedCoverageReport::took($seconds);

        $width = 0;

        foreach ($left as $index => $name) {
            $width = max($width, mb_strlen($name) + mb_strlen($right[$index]));
        }

        $width += 3;

        $rows = [];

        foreach ($this->modules as $index => $module) {
            $rows[] = [
                ...($module['counter'] === null ? [] : [[$module['counter'].' ', 'gray']]),
                [$module['name'], 'white'],
                [' '.str_repeat('.', $width - mb_strlen($left[$index]) - mb_strlen($right[$index]) - 2).' ', 'gray'],
                [$this->score($module['coverage']), self::colorFor($module['coverage'])],
                ...$this->tail($module['seconds']),
            ];
        }

        $last = array_key_last($left);

        $rows[] = [
            [$total, 'white'],
            [' '.str_repeat('.', $width - mb_strlen($left[$last]) - mb_strlen($right[$last]) - 2).' ', 'gray'],
            [$this->score($this->coverage()), self::colorFor($this->coverage())],
            ...$this->tail($seconds),
        ];

        return $rows;
    }

    /**
     * The end of a row: the floor it is held to, where there is one, and what
     * the run cost.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function tail(float $seconds): array
    {
        return [
            ...($this->minimum === null ? [] : [[$this->floor($this->minimum), 'gray']]),
            [' | '.ScopedCoverageReport::took($seconds), 'gray'],
        ];
    }

    /** The total row's name: how many modules it is the total of. */
    private function label(): string
    {
        return 'Total ('.count($this->modules).' '.Str::plural('module', count($this->modules)).')';
    }

    /** One score, as the scoreboard writes it. */
    private function score(float $coverage): string
    {
        return ScopedCoverageReport::reached($coverage).'%';
    }

    /** The floor beside a score, where there is one to show. */
    private function floor(?float $minimum): string
    {
        return $minimum === null ? '' : ' / '.ScopedCoverageReport::asked($minimum).'%';
    }

    /** Whether a score clears its floor. Null where there is no floor to clear. */
    private function passed(float $coverage, ?float $minimum): ?bool
    {
        return $minimum === null ? null : ScopedCoverageReport::scoreOf($coverage) >= ScopedCoverageReport::floorOf($minimum);
    }

    /**
     * The longest row, in characters — what the page and the picture are cut
     * to.
     *
     * @param  array<int, array<int, array{0: string, 1: string}>>  $rows
     */
    private function widest(array $rows): int
    {
        $widest = 0;

        foreach ($rows as $row) {
            $widest = max($widest, mb_strlen($this->flatten($row)));
        }

        return $widest;
    }

    /**
     * A row's text with its colours dropped, for measuring it.
     *
     * @param  array<int, array{0: string, 1: string}>  $row
     */
    private function flatten(array $row): string
    {
        return implode('', array_column($row, 0));
    }

    /** Parentheses and backslashes are the PDF string's own punctuation. */
    private function escape(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    /** The same thresholds the terminal scoreboard colours by. */
    private static function colorFor(float $coverage): string
    {
        return match (ScopedCoverageReport::reached($coverage)) {
            '100.00' => 'green',
            '0.00' => 'red',
            default => 'yellow',
        };
    }
}
