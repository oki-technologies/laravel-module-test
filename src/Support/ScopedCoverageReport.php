<?php

declare(strict_types=1);

namespace SolomonOchepa\ModuleTest\Support;

use SimpleXMLElement;

use function Termwind\parse;
use function Termwind\terminal;

/**
 * A coverage report for one module, read from Clover and rendered the way Pest
 * renders its own.
 *
 * PHPUnit/Pest's `<source><include>` in phpunit.xml always covers the whole
 * app + modules tree, and `--coverage-filter` only adds to that scope rather
 * than narrowing it — so a single-module run would otherwise print every other
 * module's coverage too, and a total mostly made of files its tests never
 * touched. This keeps the rows and the total to what was actually asked for.
 *
 * **Read from Clover rather than from what Pest printed.** Two reasons. The
 * numbers are the real ones: a total is covered statements over executable
 * statements, which is Pest's own arithmetic, where averaging the per-file
 * percentages would let a three-line file weigh as much as a seven-hundred
 * line one and round a module with uncovered lines up to 100.0. And it cannot
 * be thrown by how the child chose to draw: under an AI agent `laravel/pao`
 * folds the whole table into one JSON line, and scraping that returned 0.0 %
 * for a fully covered module rather than saying anything was wrong.
 */
class ScopedCoverageReport
{
    public function __construct(
        private readonly string $path,
        private readonly string $label,
    ) {}

    /**
     * How long a run took, the way the task component says it: whole seconds,
     * with minutes in front once there are any.
     */
    public static function took(float $seconds): string
    {
        if ($seconds < 60) {
            return max(1, (int) round($seconds)).'s';
        }

        $minutes = (int) floor($seconds / 60);

        return $minutes.'m '.(int) round($seconds - $minutes * 60).'s';
    }

    /** Where the child writes the Clover report for a module, by its label. */
    public static function fileFor(string $label): string
    {
        $directory = storage_path('framework/testing');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        return $directory.'/coverage-'.md5($label).'.xml';
    }

    /**
     * Every row belonging to this module, then its total.
     *
     * `$took` is how long the run took, drawn on the total row. A scoped run
     * prints no `DONE` line of its own — the report *is* the end of the run,
     * and a second line underneath saying so again is one more line between
     * the reader and the next module.
     *
     * A missing or unreadable report is said out loud rather than rendered as
     * 0.0 %: no coverage and coverage nobody could read are different answers,
     * and only one of them is about the code.
     */
    public function render(string $clover, ?string $took = null): string
    {
        $files = $this->read($clover);

        if ($files === null) {
            return parse(<<<'HTML'
                <div class="mx-2">
                    <span class="text-red">No coverage report was written. The test run may have failed before it could.</span>
                </div>
            HTML)."\n\n";
        }

        $truncateAt = max(1, terminal()->width() - 12);
        $rows = array_map(
            fn (array $file): string => $this->renderRow($file, $truncateAt),
            $files,
        );

        return implode("\n", $rows)."\n".$this->renderTotal($this->totalOf($files), $took);
    }

    /**
     * The module's own coverage: covered statements over executable ones.
     *
     * Used to enforce `--min` against the module alone, since PHPUnit/Pest's
     * own `--min` runs against the whole `<source><include>` tree (see the
     * class docblock) and would otherwise pass or fail on coverage this module
     * never touched.
     */
    public function total(string $clover): float
    {
        return $this->totalOf($this->read($clover) ?? []);
    }

    /**
     * The raw statement counts behind that percentage.
     *
     * Several modules in one run are added up from these rather than from
     * their percentages — a module with four statements would otherwise weigh
     * as much as one with four hundred.
     *
     * @return array{statements: int, covered: int}
     */
    public function counts(string $clover): array
    {
        $files = $this->read($clover) ?? [];

        return [
            'statements' => (int) array_sum(array_column($files, 'statements')),
            'covered' => (int) array_sum(array_column($files, 'covered')),
        ];
    }

    /**
     * One module's name and its score, as a row of a scoreboard.
     *
     * The same shape as a file's row — name, dot leader, percentage — because
     * it is the same question asked one level up, and a reader scanning for
     * the module that is behind should not have to learn a second layout.
     *
     * With a floor to meet, the row carries it too: `89.1% / 100.0%`. The
     * score keeps the colours every other percentage here wears, and the floor
     * is drawn apart from it — it is not a measurement, it is the line the
     * measurement is being held to, and colouring the two alike invites them
     * to be read as one number.
     *
     * `$of` counts the row out of the run, so a list that takes an hour says
     * how far through it is rather than only that it is still going, and
     * `$took` says what that row cost — which is how the module worth
     * splitting up is found.
     */
    public static function renderScore(
        string $label,
        float $total,
        ?float $min = null,
        ?string $of = null,
        ?string $took = null,
    ): string {
        $percentage = self::reached($total);
        $color = self::colorFor($percentage);
        $truncateAt = max(1, terminal()->width() - 12);

        $counter = $of === null ? '' : "<span class=\"text-gray mr-1\">{$of}</span>";

        $floor = $min === null
            ? ''
            : ' <span class="text-gray">/</span> <span class="text-white">'.self::asked($min).'%</span>';

        $clock = $took === null ? '' : " <span class=\"text-gray\">| {$took}</span>";

        return parse(<<<HTML
            <div class="flex mx-2">
                {$counter}<span class="truncate-{$truncateAt}">{$label}</span>
                <span class="flex-1 content-repeat-[.] text-gray mx-1"></span>
                <span><span class="text-{$color}">{$percentage}%</span>{$floor}{$clock}</span>
            </div>
        HTML)."\n";
    }

    /**
     * The same row, before there is a score to put in it.
     *
     * Written the moment a module is picked up rather than when it is done: a
     * run over every module is an hour of silence otherwise, and a reader
     * wants to know which one is being read now, not only which ones are
     * finished. Redrawn in place as it goes — see the command.
     */
    public static function renderPending(string $label, ?string $of = null, ?string $took = null): string
    {
        $truncateAt = max(1, terminal()->width() - 12);
        $counter = $of === null ? '' : "<span class=\"text-gray mr-1\">{$of}</span>";
        $said = $took === null ? 'running' : "running {$took}";

        return parse(<<<HTML
            <div class="flex mx-2">
                {$counter}<span class="truncate-{$truncateAt}">{$label}</span>
                <span class="flex-1 content-repeat-[.] text-gray mx-1"></span>
                <span class="text-gray">{$said}</span>
            </div>
        HTML)."\n";
    }

    /**
     * The combined total for a run covering more than one module, rendered as
     * the per-module totals are.
     *
     * `$noun` is what the rows are counted in. Modules, unless the run also
     * holds the root suite — which is one of its rows and is not one of them.
     *
     * @param  array{statements: int, covered: int}  $counts
     */
    public static function renderCombinedTotal(array $counts, int $rows, ?string $took = null, string $noun = 'modules'): string
    {
        $total = $counts['statements'] === 0 ? 0.0 : $counts['covered'] / $counts['statements'] * 100;

        return self::renderTotalFor($total, "{$rows} {$noun}", null, $took);
    }

    /**
     * The module's files, in path order, each with its statement counts and
     * the lines nothing ran. Null where there is no report to read.
     *
     * @return array<int, array{name: string, statements: int, covered: int, uncovered: array<int, int>}>|null
     */
    private function read(string $clover): ?array
    {
        if (! is_file($clover)) {
            return null;
        }

        $xml = @simplexml_load_file($clover);

        if ($xml === false) {
            return null;
        }

        $prefix = $this->path.'/';
        $root = base_path().'/';
        $files = [];

        /** @var SimpleXMLElement $file */
        foreach ($xml->xpath('//file') ?: [] as $file) {
            $name = str_replace($root, '', (string) $file['name']);

            if (! str_starts_with($name, $prefix)) {
                continue;
            }

            $metrics = $file->metrics;

            $files[$name] = [
                'name' => preg_replace('/\.php$/', '', $name) ?? $name,
                'statements' => (int) ($metrics['statements'] ?? 0),
                'covered' => (int) ($metrics['coveredstatements'] ?? 0),
                'uncovered' => $this->uncoveredIn($file),
            ];
        }

        ksort($files);

        return array_values($files);
    }

    /**
     * The statement lines in one file that nothing ran.
     *
     * @return array<int, int>
     */
    private function uncoveredIn(SimpleXMLElement $file): array
    {
        $lines = [];

        foreach ($file->line as $line) {
            if ((string) $line['type'] === 'stmt' && (int) $line['count'] === 0) {
                $lines[] = (int) $line['num'];
            }
        }

        sort($lines);

        return $lines;
    }

    /**
     * How covered one file is. A file with nothing to run is fully covered,
     * which is how PHPUnit counts it too.
     *
     * @param  array{statements: int, covered: int}  $file
     */
    private function percentageOf(array $file): float
    {
        return $file['statements'] === 0 ? 100.0 : $file['covered'] / $file['statements'] * 100;
    }

    /**
     * The whole module, weighed by statements rather than by file: one
     * uncovered line in a long file is not worth the same as one in a short
     * one, and averaging the percentages would say it was.
     *
     * @param  array<int, array{statements: int, covered: int}>  $files
     */
    private function totalOf(array $files): float
    {
        $statements = array_sum(array_column($files, 'statements'));

        if ($statements === 0) {
            return 0.0;
        }

        return array_sum(array_column($files, 'covered')) / $statements * 100;
    }

    /**
     * Consecutive line numbers written the way Pest writes them: "12..18" for
     * a run, "30" on its own.
     *
     * @param  array<int, int>  $lines
     */
    private function rangesOf(array $lines): string
    {
        $ranges = [];

        foreach ($lines as $line) {
            $last = array_key_last($ranges);

            if ($last !== null && $ranges[$last][1] === $line - 1) {
                $ranges[$last][1] = $line;

                continue;
            }

            $ranges[] = [$line, $line];
        }

        return implode(', ', array_map(
            fn (array $range): string => $range[0] === $range[1] ? (string) $range[0] : "{$range[0]}..{$range[1]}",
            $ranges,
        ));
    }

    /**
     * One row, through the same Termwind template Pest uses per file:
     * truncated name, dot-leader filler, the lines nothing ran, and a
     * percentage colored by the thresholds Pest itself uses.
     *
     * @param  array{name: string, statements: int, covered: int, uncovered: array<int, int>}  $file
     */
    private function renderRow(array $file, int $truncateAt): string
    {
        $percentage = self::reached($this->percentageOf($file));
        $uncovered = $this->rangesOf($file['uncovered']);

        $uncoveredHtml = $uncovered === ''
            ? ''
            : "<span>{$uncovered}</span> <span class=\"text-gray\"> / </span>";

        $color = self::colorFor($percentage);
        $name = $file['name'];

        return parse(<<<HTML
            <div class="flex mx-2">
                <span class="truncate-{$truncateAt}">{$name}</span>
                <span class="flex-1 content-repeat-[.] text-gray mx-1"></span>
                <span class="text-{$color}">{$uncoveredHtml} {$percentage}%</span>
            </div>
        HTML);
    }

    /**
     * Render the scoped total: a divider, then how long the run took on the
     * left and the figure right-aligned, bold, and colored by the same
     * thresholds Pest's own coverage report uses for its "Total: X %" row.
     */
    private function renderTotal(float $total, ?string $took = null): string
    {
        return self::renderTotalFor($total, $this->label, $took);
    }

    /**
     * Render a total row under an arbitrary label — one module's, or the whole
     * run's.
     */
    private static function renderTotalFor(
        float $total,
        string $label,
        ?string $took = null,
        ?string $spent = null,
    ): string {
        $formatted = self::reached($total);
        $color = self::colorFor($formatted);
        $total = "Total ({$label}): {$formatted}%";

        // The whole run's cost, beside the whole run's figure — the same
        // `| 5s` each row above it carries. Kept out of `$total` so the
        // padding below measures the text and not the tags around it.
        $clock = $spent === null ? '' : " | {$spent}";

        // Termwind returns the rule with no trailing newline of its own, so the
        // row that follows has to start one — without it, "DONE" and the figure
        // are drawn onto the end of the rule and pushed off the right margin.
        $rule = parse(<<<'HTML'
            <div class="mx-2">
                <hr class="text-gray" />
            </div>
        HTML)."\n";

        if ($took === null) {
            $indent = str_repeat(' ', max(2, terminal()->width() - 2 - mb_strlen($total.$clock)));

            return $rule.$indent."<fg={$color};options=bold>{$total}</><fg=gray>{$clock}</>\n\n";
        }

        // How long it took at the left margin, the figure at the right, and
        // the gap between them measured rather than flexed: Termwind collapses
        // an empty spacer, and a wrapped line would leave "DONE" stranded a
        // row above the figure it belongs to. Two spaces at least, so they
        // stay two things on a narrow terminal.
        // Written with Symfony's own tags rather than Termwind's: the gap
        // between the two halves is measured here, and Termwind trims a
        // whitespace-only spacer away to nothing.
        $gap = str_repeat(' ', max(2, terminal()->width() - 4 - mb_strlen("DONE {$took}") - mb_strlen($total.$clock)));

        // "DONE" is the verdict and wears the success colour; the duration
        // beside it is faded, the way the task component fades its own — it
        // is there to be glanced at, not read.
        return $rule."  <fg=green;options=bold>DONE</> <fg=gray>{$took}</>{$gap}<fg={$color};options=bold>{$total}</><fg=gray>{$clock}</>\n\n";
    }

    /**
     * The same green/red/yellow thresholds Pest's own coverage report uses
     * (100%/0%/between) — see vendor/pestphp/pest/src/Support/Coverage.php.
     */
    /** Every percentage is written to at least this many decimals. */
    public const int DECIMALS = 2;

    /**
     * A score as it is compared: two decimals, and never rounded up.
     *
     * 99.996 % is not 100 %. Rounded to the nearest hundredth it would read
     * as `100.00%` beside a failure against a floor of 100 — a row that
     * contradicts itself. Rounding down understates by less than a hundredth
     * and never claims coverage that is not there.
     *
     * The multiplication is rounded before it is floored because 8.07 * 100
     * is 806.999… in binary, and flooring that loses a whole hundredth.
     */
    public static function scoreOf(float $coverage): float
    {
        $scale = 10 ** self::DECIMALS;

        return floor(round($coverage * $scale, 6)) / $scale;
    }

    /** That score, written. */
    public static function reached(float $coverage): string
    {
        return number_format(self::scoreOf($coverage), self::DECIMALS, '.', '');
    }

    /**
     * A floor as it is compared: two decimals, to the nearest.
     *
     * A floor is a number somebody typed rather than something counted, so it
     * is rounded the ordinary way — 28.284 is 28.28 and 28.285 is 28.29.
     *
     * Both halves are rounded to what the row shows before they are compared,
     * so the two figures on screen are the two figures being compared, and a
     * row can never disagree with the verdict beside it. Held to the raw
     * numbers instead, a module at 28.288 % passed against `Auth:28.285` and
     * failed against `Auth:28.289`, printing the identical `28.28% / 28.29%`
     * both times.
     */
    public static function floorOf(float $minimum): float
    {
        return round($minimum, self::DECIMALS);
    }

    /** That floor, written. */
    public static function asked(float $minimum): string
    {
        return number_format(self::floorOf($minimum), self::DECIMALS, '.', '');
    }

    private static function colorFor(string $percentage): string
    {
        return match ($percentage) {
            '100.00' => 'green',
            '0.00' => 'red',
            default => 'yellow',
        };
    }
}
