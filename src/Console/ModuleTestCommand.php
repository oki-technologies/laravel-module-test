<?php

namespace SolomonOchepa\ModuleTest\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use LogicException;
use Nwidart\Modules\Facades\Module;
use SolomonOchepa\ModuleTest\Support\ScopedCoverageReport;
use SolomonOchepa\ModuleTest\Support\ScoreboardExport;

class ModuleTestCommand extends Command
{
    /**
     * Every Pest CLI flag we accept and forward verbatim to the underlying test command,
     * keyed by flag name, mapped to whether it accepts a value (true) or is a boolean
     * flag (false). Source: `vendor/bin/pest --help` — Configuration, Selection,
     * Execution, Reporting, Logging, Coverage, and Profiling sections. The AI and
     * Mutation Testing sections are separate testing modes, out of scope here.
     *
     * @var array<string, bool>
     */
    private const array PEST_OPTIONS = [
        // Configuration
        'init' => false,
        'bootstrap' => true,
        'configuration' => true,
        'no-configuration' => false,
        'extension' => true,
        'no-extensions' => false,
        'include-path' => true,
        'cache-directory' => true,
        'generate-configuration' => false,
        'migrate-configuration' => false,
        'generate-baseline' => true,
        'use-baseline' => true,
        'ignore-baseline' => false,
        // Selection
        'bail' => false,
        'todos' => false,
        'notes' => false,
        'issue' => true,
        'pr' => true,
        'pull-request' => true,
        'retry' => false,
        'dirty' => false,
        'flaky' => false,
        'all' => false,
        'list-suites' => false,
        'testsuite' => true,
        'exclude-testsuite' => true,
        'list-groups' => false,
        'group' => true,
        'exclude-group' => true,
        'covers' => true,
        'uses' => true,
        'requires-php-extension' => true,
        'list-test-files' => false,
        'list-tests' => false,
        'list-tests-xml' => true,
        'filter' => true,
        'exclude-filter' => true,
        'test-suffix' => true,
        // Execution
        'parallel' => false,
        'update-snapshots' => false,
        'update-shards' => false,
        'globals-backup' => false,
        'static-backup' => false,
        'strict-coverage' => false,
        'strict-global-state' => false,
        'disallow-test-output' => false,
        'enforce-time-limit' => false,
        'default-time-limit' => true,
        'do-not-report-useless-tests' => false,
        'stop-on-defect' => false,
        'stop-on-error' => false,
        'stop-on-failure' => false,
        'stop-on-warning' => false,
        'stop-on-risky' => false,
        'stop-on-deprecation' => false,
        'stop-on-notice' => false,
        'stop-on-skipped' => false,
        'stop-on-incomplete' => false,
        'fail-on-empty-test-suite' => false,
        'fail-on-warning' => false,
        'fail-on-risky' => false,
        'fail-on-deprecation' => false,
        'fail-on-phpunit-deprecation' => false,
        'fail-on-phpunit-notice' => false,
        'fail-on-phpunit-warning' => false,
        'fail-on-notice' => false,
        'fail-on-skipped' => false,
        'fail-on-incomplete' => false,
        'fail-on-all-issues' => false,
        'do-not-fail-on-empty-test-suite' => false,
        'do-not-fail-on-warning' => false,
        'do-not-fail-on-risky' => false,
        'do-not-fail-on-deprecation' => false,
        'do-not-fail-on-phpunit-deprecation' => false,
        'do-not-fail-on-phpunit-notice' => false,
        'do-not-fail-on-phpunit-warning' => false,
        'do-not-fail-on-notice' => false,
        'do-not-fail-on-skipped' => false,
        'do-not-fail-on-incomplete' => false,
        'cache-result' => false,
        'do-not-cache-result' => false,
        'order-by' => true,
        'resolve-dependencies' => false,
        'ignore-dependencies' => false,
        'random-order' => false,
        'random-order-seed' => true,
        'reverse-order' => false,
        // Reporting
        'colors' => true,
        'columns' => true,
        'stderr' => false,
        'no-progress' => false,
        'no-results' => false,
        'no-output' => false,
        'display-incomplete' => false,
        'display-skipped' => false,
        'display-deprecations' => false,
        'display-phpunit-deprecations' => false,
        'display-phpunit-notices' => false,
        'display-errors' => false,
        'display-notices' => false,
        'display-warnings' => false,
        'display-all-issues' => false,
        'reverse-list' => false,
        'teamcity' => false,
        'testdox' => false,
        'testdox-summary' => false,
        'debug' => false,
        'with-telemetry' => false,
        'compact' => false,
        // Logging
        'log-junit' => true,
        'log-otr' => true,
        'include-git-information' => false,
        'log-teamcity' => true,
        'testdox-html' => true,
        'testdox-text' => true,
        'log-events-text' => true,
        'log-events-verbose-text' => true,
        'no-logging' => false,
        // Coverage (beyond this command's own --coverage/--min)
        'exactly' => true,
        'only-covered' => false,
        'coverage-clover' => true,
        'coverage-openclover' => true,
        'coverage-cobertura' => true,
        'coverage-crap4j' => true,
        'coverage-html' => true,
        'coverage-php' => true,
        'coverage-text' => true,
        'only-summary-for-coverage-text' => false,
        'show-uncovered-for-coverage-text' => false,
        'coverage-xml' => true,
        'exclude-source-from-xml-coverage' => false,
        'warm-coverage-cache' => false,
        'coverage-filter' => true,
        'path-coverage' => false,
        'disable-coverage-ignore' => false,
        'no-coverage' => false,
        // Profiling
        'profile' => false,
        // php artisan test-only (ignored unless `--artisan`, since pest itself has no equivalent)
        'without-tty' => false,
        'recreate-databases' => false,
        'drop-databases' => false,
        'without-databases' => false,
        'without-cache' => false,
    ];

    /**
     * Short flag aliases for a handful of the options above, matching Pest's own CLI.
     *
     * @var array<string, string>
     */
    private const PEST_OPTION_ALIASES = [
        'configuration' => 'c',
        'parallel' => 'p',
    ];

    /**
     * The names the cap on a run answers to, the first of them the one the
     * documentation leads with. They are one option: given more than once, the
     * values have to agree.
     *
     * @var array<int, string>
     */
    private const array LIMIT_OPTIONS = ['limit', 'take', 'pick'];

    /**
     * Where the root suite's tests are, and the tree they cover.
     *
     * A module holds both under one directory, so one path answers for the
     * tests to run and the code to measure. The root's two halves are two
     * trees, so they are named separately.
     */
    private const string ROOT_TESTS = 'tests';

    private const string ROOT_SOURCE = 'app';

    /** What the root's row is called, and the target that names it. */
    private const string ROOT_LABEL = 'Root';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run tests, optionally with code coverage, for one or more modules';

    /**
     * The name and signature of the console command.
     */
    public function __construct()
    {
        $this->signature = $this->buildSignature();

        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $sort = $this->text('sort') ?? '';

        if (! in_array($sort, ['asc', 'desc', 'none'], true)) {
            $this->components->error("Invalid --sort value [{$sort}]. Expected: asc, desc, none.");

            return self::FAILURE;
        }

        // Written as `--export` on its own, the option arrives as null — the
        // same as not written at all — so what the caller typed is the only
        // thing that tells the two apart.
        $export = $this->input->hasParameterOption('--export')
            ? ScoreboardExport::resolve($this->text('export'))
            : null;

        if ($export !== null && ! ScoreboardExport::writes($export)) {
            $this->components->error(
                "Cannot write [{$export}]. Name the format in the extension: ".implode(', ', ScoreboardExport::formats()).'.'
            );

            return self::FAILURE;
        }

        $passthrough = $this->pestOptionArgs();
        // A scoreboard, not a test run: the same coverage is collected, and
        // everything the child says on the way is dropped. It is what somebody
        // asking "where are we" wants, and what a per-file report buries.
        $scoreboard = (bool) ($this->option('totals') || $export);

        // Every module, disabled ones included: a module is switched off in
        // `modules.json` for what it does at runtime, which has nothing to do
        // with whether its tests should still pass.
        $registered = collect(Module::all())->map(fn ($module): string => $module->getName());

        /** @var Collection<array-key, string> $known */
        $known = $registered->map(fn (string $module): string => Str::lower($module));

        $root = $this->rootLabel();

        // The root answers to its label the way a module answers to its name:
        // it can be named as a target, and it can be given a floor.
        if ($root !== null) {
            $known->push(Str::lower($root));
        }

        /** @var mixed $targets */
        $targets = $this->argument('modules');
        $asked = collect(is_array($targets) ? $targets : [])->map(fn (mixed $target): string => is_scalar($target) ? (string) $target : '');

        $named = $this->floorsFor($known);
        $written = $this->floorsOn($asked, $known);

        if ($named === null || $written === null) {
            return self::FAILURE;
        }

        // A floor written on the target itself is the nearer of the two to
        // what is being run, so it is the one that holds.
        $floors = [...$named, ...$written];

        $collectingCoverage = (bool) ($this->option('coverage') || $this->option('min') || $floors !== [] || $scoreboard);

        $modules = $asked->map(fn (string $target): string => $this->splitFloor($target)[0]);

        if ($modules->isEmpty()) {
            $modules = $registered->values();
        }

        // A target naming nothing that is here is dropped, quietly: the `100`
        // left behind by a `--min 100` written without its `=`, or a module
        // that has been renamed since somebody wrote the command down. It is
        // only worth saying anything when nothing is left, because then
        // nothing ran.
        $given = $modules->count();

        $modules = $modules->filter(fn (string $module): bool => $this->isHere($module, $known))->values();

        // `--root` adds the root suite to whatever else is running; named as a
        // target as well, it is already here.
        if ($root !== null && ! $modules->contains(fn (string $module): bool => $this->isRoot($module))) {
            $modules->push($root);
        }

        // A floor named for a module the run was never going to reach is a
        // floor that cannot apply. Naming one in --mins is asking for that
        // module, so it joins the targets rather than being left out of a run
        // that then says nothing about it. Nothing to add where the caller
        // named no targets: the run is already every module.
        if ($asked->isNotEmpty()) {
            $running = $modules->map(fn (string $module): string => Str::lower($module));

            foreach (array_keys($floors) as $floor) {
                if ($running->contains($floor)) {
                    continue;
                }

                // The registry's own spelling, not the caller's: every target
                // below is resolved to a path from it.
                $modules->push($registered->first(
                    fn (string $module): bool => Str::lower($module) === $floor,
                ) ?? throw new LogicException("[{$floor}] was accepted as a floor but is in no registry."));
            }
        }

        if ($modules->isEmpty() && $given > 0) {
            $this->components->error('Nothing to test.');

            return self::FAILURE;
        }

        $targets = match (true) {
            // Nothing named and nothing registered: one target that names no
            // module, which is the whole tree.
            $modules->isEmpty() => collect(['']),
            $sort === 'asc' => $modules->sortBy(fn (string $module) => $this->resolveTarget($module))->values(),
            $sort === 'desc' => $modules->sortByDesc(fn (string $module) => $this->resolveTarget($module))->values(),
            default => $modules,
        };

        $targets = $this->slice($targets);

        if ($targets === null) {
            return self::FAILURE;
        }

        $begun = microtime(true);
        $exitCode = self::SUCCESS;

        // Statement counts across every module in the run, so a combined total
        // can be weighed the same way each module's own is.
        $combined = ['statements' => 0, 'covered' => 0];

        // The same rows, kept rather than drawn, for --export.
        $exported = new ScoreboardExport($this->option('min') === null ? null : (float) $this->option('min'));

        // What went wrong, so the end of the run can name it. A run over every
        // module is hundreds of lines, and a reader who has scrolled past the
        // module that failed has no way back to it but to scroll.
        $brokeDown = [];
        $fellShort = [];
        $accounts = [];

        foreach ($targets as $index => $module) {
            if ($index > 0 && ! $scoreboard) {
                $this->newLine();
            }

            $isRoot = $this->isRoot($module);
            $path = $module ? $this->resolveTarget($module) : 'modules';
            $label = match (true) {
                $isRoot => (string) $root,
                ! $module => 'Modules',
                str_contains($module, '/') => $path,
                default => Str::studly($module),
            };

            // A module's tests and the code they cover share one directory, so
            // the path answers for both. The root's do not: its tests are in
            // `tests/` and everything they cover is in `app/`, and measuring
            // the former would report on a tree coverage excludes.
            $scope = $isRoot ? self::ROOT_SOURCE : $path;

            // ParaTest (used by --parallel) parses its own InputDefinition and has no
            // equivalent to PHPUnit/Pest's positional "path" argument — passing one
            // fails with "Too many arguments, expected arguments 'path'". When both a
            // specific module and --parallel are requested, translate the path into a
            // --filter regex matching that module's namespace instead of appending it
            // positionally. A --filter the caller already passed takes precedence.
            $usingParallel = (bool) $this->option('parallel');
            $modulePassthrough = $passthrough;

            if ($usingParallel && $module && ! $this->option('filter')) {
                $modulePassthrough[] = '--filter='.$this->parallelFilterFor($path);
            }

            // ParaTest shards every test file across its workers and applies the
            // filter inside each one, so a worker whose shard holds nothing for
            // this module executes no tests — which PHPUnit reports as a failed
            // run, and ParaTest returns as the exit code for the whole module.
            // The module then lands in "tests failed in ..." with every one of
            // its own tests green. Which workers come up empty depends on how
            // the shards happen to fall, so the same module passes on one run
            // and fails on the next. Only the sharding is being excused here: a
            // real failure still fails, and a module with no tests of its own
            // still reports 0 % against the coverage floor.
            if ($usingParallel && $module && ! in_array('--do-not-fail-on-empty-test-suite', $modulePassthrough, true)) {
                $modulePassthrough[] = '--do-not-fail-on-empty-test-suite';
            }

            // A scoreboard keeps a module's failures and drops everything else
            // the child said, so there is no reason for it to draw a line per
            // test on the way to them.
            if ($scoreboard && ! in_array('--compact', $modulePassthrough, true)) {
                $modulePassthrough[] = '--compact';
            }

            $scopeCoverageToModule = $module && $collectingCoverage;

            // Scoped runs ask for Clover instead of Pest's own table. The
            // child then prints nothing about coverage — so its progress can
            // stream as it happens rather than being held back until the end —
            // and the report below is built from real statement counts rather
            // than from what the child drew. See ScopedCoverageReport.
            $clover = $scopeCoverageToModule ? ScopedCoverageReport::fileFor($label) : null;

            // PHPUnit/Pest's own `--min` is checked against the whole
            // `<source><include>` tree (see ScopedCoverageReport's docblock),
            // not the module being tested — passing it through here would fail
            // (or silently pass) on coverage this module never touched. Enforce
            // it ourselves, below, against the module-scoped total instead.
            $min = $floors[Str::lower((string) $module)] ?? $this->text('min');
            $forwardMin = $min && ! $scopeCoverageToModule;

            $command = array_values(array_filter([
                PHP_BINARY,
                ...($collectingCoverage ? ['-d', 'pcov.directory='.base_path()] : []),
                ...($this->option('artisan') ? [base_path('artisan'), 'test'] : [base_path('vendor/bin/pest')]),
                ...match (true) {
                    $clover !== null => ['--coverage-clover='.$clover],
                    $collectingCoverage => ['--coverage'],
                    default => [],
                },
                $forwardMin ? '--min='.$min : null,
                ...$modulePassthrough,
                ...($usingParallel && $module ? [] : [$path]),
            ]));

            $counted = $targets->count() > 1 ? ($index + 1).'/'.$targets->count() : null;

            $run = function () use ($command, $clover, $min, $scope, $label, $scoreboard, $counted, $exported, &$exitCode, &$combined, &$brokeDown, &$fellShort, &$accounts) {
                $started = microtime(true);

                // Last run's report, so a run that dies before writing one is
                // not reported against the one before it.
                if ($clover !== null && is_file($clover)) {
                    unlink($clover);
                }

                // The row goes up the moment the module is picked up, and is
                // redrawn in place as it goes. A run over every module is an
                // hour of silence otherwise, and the reader wants to know
                // which one is being read now, not only which are finished.
                $drawn = false;
                $ticked = 0.0;

                if ($scoreboard && $this->redraws()) {
                    $this->output->write(ScopedCoverageReport::renderPending($label, $counted));
                    $drawn = true;
                }

                $spoke = '';

                $process = Process::forever()
                    ->env($this->testEnvironmentOverrides())
                    ->run($command, function (string $type, string $output) use ($scoreboard, $label, $counted, $started, &$ticked, &$drawn, &$spoke): void {
                        if (! $scoreboard) {
                            $this->output->write($output);

                            return;
                        }

                        // Held rather than printed: a scoreboard is one row per
                        // module, and the child's own account is only worth the
                        // room if this module turns out to have failed.
                        $spoke .= $output;

                        // The child's own output is dropped; that it arrived
                        // at all is the clock ticking. Once a second, and only
                        // where the line can be taken back.
                        $now = microtime(true);

                        if (! $this->redraws() || $now - $ticked < 1) {
                            return;
                        }

                        $ticked = $now;
                        $drawn = $this->redraw(ScopedCoverageReport::renderPending(
                            $label,
                            $counted,
                            ScopedCoverageReport::took($now - $started),
                        ), $drawn);
                    });

                $passedCoverage = true;

                if ($clover !== null) {
                    $report = new ScopedCoverageReport($scope, $label);

                    if ($scoreboard) {
                        $drawn = $this->redraw(ScopedCoverageReport::renderScore(
                            $label,
                            $report->total($clover),
                            $min === null ? null : (float) $min,
                            $counted,
                            ScopedCoverageReport::took(microtime(true) - $started),
                        ), $drawn);
                    } else {
                        $this->newLine();
                        $this->output->write($report->render($clover, ScopedCoverageReport::took(microtime(true) - $started)));
                    }

                    $counts = $report->counts($clover);
                    $combined['statements'] += $counts['statements'];
                    $combined['covered'] += $counts['covered'];
                    $exported->add($label, $report->total($clover), $counts, microtime(true) - $started, $counted, $min === null ? null : (float) $min);

                    if ($min !== null) {
                        $total = $report->total($clover);
                        $passedCoverage = ScopedCoverageReport::scoreOf($total) >= ScopedCoverageReport::floorOf((float) $min);

                        if (! $passedCoverage) {
                            $fellShort[$label] = [$total, (float) $min];

                            // What it is over what it has to be, in that
                            // order: the number that has to move comes first.
                            $said = sprintf(
                                '  <fg=white;bg=red;options=bold> FAIL </> <fg=cyan;options=bold>%s</> code coverage is below expectation <fg=red;options=bold>%s %%</> / <fg=white;options=bold>%s %%</>',
                                $label,
                                ScopedCoverageReport::reached($total),
                                ScopedCoverageReport::asked((float) $min),
                            );

                            // Not on a scoreboard: the row already carries the
                            // floor beside the score, in red, and a FAIL line
                            // under it would be the same fact again a line
                            // further from the number it is about.
                            if (! $scoreboard) {
                                $this->output->writeln($said);
                            }
                        }
                    }

                    if (is_file($clover)) {
                        unlink($clover);
                    }
                }

                if (! $process->successful()) {
                    $brokeDown[] = $label;

                    if ($scoreboard && trim($spoke) !== '') {
                        $accounts[$label] = $spoke;
                    }
                }

                if (! $process->successful() || ! $passedCoverage) {
                    $exitCode = ! $process->successful() ? ($process->exitCode() ?? self::FAILURE) : self::FAILURE;
                }

                return $process->successful() && $passedCoverage;
            };

            // Each row is written as its module lands — see `$run`.
            if ($scoreboard) {
                if ($index === 0) {
                    $this->newLine();
                }

                $run();

                continue;
            }

            // The child's own output streams straight through, and it ends by
            // saying what passed and how long it took. A task component's
            // trailing "…… DONE" would therefore land pages below the label it
            // belongs to, restating a duration the child has just given — and
            // under `--parallel` it lands on top of it, because Pest's closing
            // "Parallel: N processes" line is drawn with a cursor-up. Name the
            // module and let the child speak for itself.
            $this->newLine();
            $this->output->writeln("  Testing <fg=cyan;options=bold>{$label}</>");
            $run();
        }

        if ($targets->count() > 1 && $combined['statements'] > 0) {
            $this->newLine();
            $this->output->write(ScopedCoverageReport::renderCombinedTotal(
                $combined,
                $targets->count(),
                ScopedCoverageReport::took(microtime(true) - $begun),
                $root === null ? 'modules' : 'targets',
            ));
        }

        // A single module says what went wrong where it went wrong, right under
        // its own report. Past that, the reason the run failed has to be
        // repeated at the end, named module by module, or the exit code is the
        // only thing left saying anything.
        if (($scoreboard || $targets->count() > 1) && ($brokeDown !== [] || $fellShort !== [])) {
            $this->newLine();

            if ($brokeDown !== []) {
                $this->output->writeln(sprintf(
                    '  <fg=white;bg=red;options=bold> FAIL </> tests failed in %s',
                    $this->few(array_map(fn (string $broken): string => "<fg=cyan;options=bold>{$broken}</>", $brokeDown)),
                ));
            }

            if ($fellShort !== []) {
                // Worst first, so the five that are named are the five worth
                // reading. The rows above hold the rest, in run order.
                uasort($fellShort, fn (array $one, array $other): int => $one[0] <=> $other[0]);

                $named = [];

                foreach ($fellShort as $short => [$total, $floor]) {
                    // Where the modules were held to different floors, a score
                    // on its own says nothing: 60 % is a pass against one of
                    // them and a failure against the next.
                    $named[] = $floors === []
                        ? sprintf('<fg=cyan;options=bold>%s</> <fg=red;options=bold>%s %%</>', $short, ScopedCoverageReport::reached($total))
                        : sprintf(
                            '<fg=cyan;options=bold>%s</> <fg=red;options=bold>%s %%</> / <fg=white;options=bold>%s %%</>',
                            $short,
                            ScopedCoverageReport::reached($total),
                            ScopedCoverageReport::asked($floor),
                        );
                }

                $this->output->writeln(sprintf(
                    '  <fg=white;bg=red;options=bold> FAIL </> %s: %s',
                    $floors === []
                        ? sprintf('below the <fg=white;options=bold>%s %%</> floor', ScopedCoverageReport::asked((float) $this->option('min')))
                        : 'below the floor',
                    $this->few($named),
                ));
            }

            // A scoreboard drops the child's output while the run is going, so
            // the line above is otherwise the only thing said about a module
            // that failed. Each one's own account goes here, under the result,
            // where it is read after the scores rather than instead of them.
            foreach ($accounts as $broken => $spoke) {
                $this->newLine();
                $this->output->writeln("  <fg=cyan;options=bold>{$broken}</>");
                $this->output->writeln($this->failuresIn($spoke));
            }
        }

        if ($export !== null && ! $exported->isEmpty()) {
            $exported->write($export, microtime(true) - $begun);

            $this->newLine();
            $this->components->info("Scoreboard written to <fg=cyan;options=bold>{$export}</>");
        }

        return $exitCode;
    }

    /**
     * Narrow the run to the window `--from`/`--to` name, both ends included.
     *
     * Either end is a position in the run as it is about to go — 1 for the
     * first module — or the name of one of its modules. Answers null when an
     * end names nothing in the run, having said so.
     *
     * @param  Collection<int, string>  $targets
     * @return Collection<int, string>|null
     */
    private function slice(Collection $targets): ?Collection
    {
        $from = $this->text('from');
        $to = $this->text('to');
        $limit = $this->limit();

        if ($limit === false) {
            return null;
        }

        if ($limit !== null && (! ctype_digit($limit) || (int) $limit < 1)) {
            $this->components->error("A limit is a whole number of modules, 1 or more — [{$limit}] is not.");

            return null;
        }

        if ($from !== null || $to !== null) {
            $start = 0;
            $end = $targets->count() - 1;

            if ($from !== null) {
                $start = $this->offsetOf($from, $targets);

                if ($start === null) {
                    return null;
                }
            }

            if ($to !== null) {
                $end = $this->offsetOf($to, $targets);

                if ($end === null) {
                    return null;
                }
            }

            if ($end < $start) {
                $this->components->error('Nothing to test.');

                return null;
            }

            $targets = $targets->slice($start, $end - $start + 1)->values();
        }

        // The window first, then the count: `--from=9 --limit=5` is the five
        // modules starting at the ninth, and a limit longer than what the
        // window left simply takes what is there.
        return $limit === null ? $targets : $targets->take((int) $limit)->values();
    }

    /**
     * The first few of a list, as a sentence — the rest counted rather than
     * named. A run over every module can fail in thirty of them, and thirty
     * names on one line is a line nobody reads; the rows above hold them all.
     *
     * @param  array<int, string>  $names
     */
    private function few(array $names, int $keep = 5): string
    {
        if (count($names) <= $keep) {
            return implode(', ', $names);
        }

        $rest = count($names) - $keep;

        return implode(', ', array_slice($names, 0, $keep))." <fg=gray>and {$rest} more</>";
    }

    /**
     * The limit the run was given under any of its names, or null where it was
     * given none. Answers false when two of them were given and disagree,
     * having said so — silently picking one of two numbers the caller typed is
     * worse than refusing.
     */
    private function limit(): string|false|null
    {
        $given = [];

        foreach (self::LIMIT_OPTIONS as $name) {
            $value = $this->text($name);

            if ($value !== null) {
                $given[$name] = $value;
            }
        }

        if (count(array_unique($given)) > 1) {
            $said = array_map(
                fn (string $name, string $value): string => "--{$name} is [{$value}]",
                array_keys($given),
                array_values($given),
            );

            $this->components->error(Arr::join($said, ', ', ' and ').'. They are one option, so give it once.');

            return false;
        }

        return $given === [] ? null : reset($given);
    }

    /**
     * Where in the run an `--from`/`--to` value sits: a 1-based position, or a
     * module name matched the way a target is. Answers null when it is neither,
     * having said so.
     *
     * @param  Collection<int, string>  $targets
     */
    private function offsetOf(string $value, Collection $targets): ?int
    {
        if (ctype_digit($value)) {
            $offset = (int) $value - 1;

            if ($offset >= 0 && $offset < $targets->count()) {
                return $offset;
            }

            $this->components->error("No module at position [{$value}]; the run has {$targets->count()}.");

            return null;
        }

        $wanted = $this->resolveTarget($value);

        foreach ($targets as $offset => $module) {
            if ($module !== '' && strcasecmp($this->resolveTarget($module), $wanted) === 0) {
                return $offset;
            }
        }

        $this->components->error("Module [{$value}] is not in this run.");

        return null;
    }

    /**
     * What the root suite's row is called, or null where the run was not
     * asked for it.
     *
     * A flag rather than a name to call the row, so that `--root Root` runs
     * the root suite and nothing else. An option that takes a value takes the
     * token after it whether or not there is an `=` between them, so a
     * nameable `--root` would have read `Root` as the name and run every
     * module under it — the opposite of what the line says.
     */
    private function rootLabel(): ?string
    {
        return $this->option('root') ? self::ROOT_LABEL : null;
    }

    /** Whether a target is the root suite, under whatever it is being called. */
    private function isRoot(string $module): bool
    {
        $root = $this->rootLabel();

        return $root !== null && strcasecmp($module, $root) === 0;
    }

    /**
     * Whether a target names something this checkout actually has.
     *
     * A registered module by name, a path, or anything else that resolves to
     * a file on disk — which covers a module whose directory is there but
     * whose entry in `modules.json` is not. Everything else is somebody's
     * typo or a value that slipped past its own option, and running it would
     * report 0 % for a module that was never tested.
     *
     * @param  Collection<int, string>  $known  Registered module names, lower-cased.
     */
    private function isHere(string $module, Collection $known): bool
    {
        // A path says where it is and answers for itself: Pest reports a
        // missing one far better than this could, and a caller who typed one
        // is not the caller this guard is for.
        if (str_contains($module, '/')) {
            return true;
        }

        return $known->contains(Str::lower($module))
            || file_exists(base_path($this->resolveTarget($module)));
    }

    /**
     * Whether a line already written can be taken back.
     *
     * Only on a terminal that understands the escape codes. Piped to a file, a
     * log or an agent, the cursor moves are so much rubbish in the middle of
     * the output — so there the row is written once, when it has something to
     * say.
     */
    private function redraws(): bool
    {
        return $this->output->isDecorated();
    }

    /**
     * Replaces the row last written, where it can be; writes a new one where
     * it cannot. Answers whether there is now a row to replace next time.
     */
    private function redraw(string $row, bool $drawn): bool
    {
        if ($drawn && $this->redraws()) {
            // Up one line, and clear it.
            $this->output->write("\033[1A\033[2K");
        }

        $this->output->write($row);

        return true;
    }

    /**
     * The floor each named module has to clear, where it is not the one
     * `--min` gives everything else.
     *
     * A run usually has one number to hold every module to, but a module that
     * has been raised is held to what it reached while the rest are still
     * climbing — so `--min=0 --mins=Auth,Activity:100` keeps what is done
     * from slipping without failing the run for what is not. The option may
     * be given more than once, once per floor.
     *
     * @param  Collection<array-key, string>  $known
     * @return array<string, float>|null Null where a value could not be read, having said so
     */
    private function floorsFor(Collection $known): ?array
    {
        $written = $this->minsGiven();

        if ($written === null) {
            return null;
        }

        $floors = [];
        $shape = 'Write it as Module[,Module]:percentage, as in Auth,Activity:100.';

        foreach ($written as $given) {
            if (! str_contains($given, ':')) {
                $this->components->error("Cannot read [{$given}]: it names no percentage. {$shape}");

                return null;
            }

            $percentage = trim(Str::afterLast($given, ':'));

            if (! is_numeric($percentage)) {
                $this->components->error("[{$percentage}] in [{$given}] is not a percentage. {$shape}");

                return null;
            }

            $names = collect(explode(',', Str::beforeLast($given, ':')))
                ->map(fn (string $name): string => trim($name))
                ->filter()
                ->values();

            if ($names->isEmpty()) {
                $this->components->error("Cannot read [{$given}]: it names no module. {$shape}");

                return null;
            }

            foreach ($names as $name) {
                if (! $known->contains(Str::lower($name))) {
                    $this->components->error("[{$name}] is not a module here, so nothing would hold it to {$percentage} %.");

                    return null;
                }

                $floors[Str::lower($name)] = (float) $percentage;
            }
        }

        return $floors;
    }

    /**
     * Every floor the run was given, a line read in for each `@path` among
     * them.
     *
     * Thirty floors on one command line is a line nobody reads and nobody
     * can review — a diff over it says the whole thing changed. `@path` puts
     * them in a file instead, one floor to a line, blank lines and `#`
     * comments allowed, so the list sorts, annotates and diffs like the
     * record of debt it is.
     *
     * @return array<int, string>|null Null where a file could not be read, having said so
     */
    private function minsGiven(): ?array
    {
        $given = [];

        foreach ((array) $this->option('mins') as $one) {
            $one = trim((string) $one);

            if (! str_starts_with($one, '@')) {
                $given[] = $one;

                continue;
            }

            $named = substr($one, 1);
            $file = is_file($named) ? $named : base_path($named);

            if (! is_file($file)) {
                $this->components->error("No floors file at [{$named}].");

                return null;
            }

            foreach (preg_split('/\R/', (string) file_get_contents($file)) ?: [] as $line) {
                $line = trim(Str::before($line, '#'));

                if ($line !== '') {
                    $given[] = $line;
                }
            }
        }

        return $given;
    }

    /**
     * A target and the floor written on it, where it carries one.
     *
     * `Auth:100` is a module and the number it has to clear. The number has
     * to look like one for the target to be split at all, so a path keeps
     * whatever colons it has, and the stray `100` left behind by a `--min 100`
     * written without its `=` stays the stray target it has always been.
     *
     * @return array{0: string, 1: ?float}
     */
    private function splitFloor(string $target): array
    {
        if (preg_match('/^(?<name>.+):(?<percentage>\d+(?:\.\d+)?)$/', $target, $found) !== 1) {
            return [$target, null];
        }

        return [$found['name'], (float) $found['percentage']];
    }

    /**
     * The floor each target carries, written on the target as `Module:percentage`.
     *
     * The same refusals `--mins` makes, for the same reason: a floor that
     * names nothing holds nothing, and saying so beats a run that passes
     * because it never measured what it was asked to.
     *
     * @param  Collection<array-key, string>  $asked
     * @param  Collection<array-key, string>  $known
     * @return array<string, float>|null Null where one could not be read, having said so
     */
    private function floorsOn(Collection $asked, Collection $known): ?array
    {
        $floors = [];

        foreach ($asked as $target) {
            [$name, $percentage] = $this->splitFloor($target);

            if ($percentage === null) {
                continue;
            }

            if (str_contains($name, '/')) {
                $this->components->error("[{$target}] writes a floor on a path. A floor goes on a module's name, as in Auth:100.");

                return null;
            }

            if (! $known->contains(Str::lower($name))) {
                $this->components->error("[{$name}] is not a module here, so nothing would hold it to ".ScopedCoverageReport::asked($percentage).' %.');

                return null;
            }

            $floors[Str::lower($name)] = $percentage;
        }

        return $floors;
    }

    /**
     * The failures out of what a child said, and nothing else: from the first
     * one Pest reported to the line before its closing summary.
     *
     * A scoreboard is one row per module, and a row already carries the score
     * and what the module cost. Handing back the child's whole account would
     * put the progress dots and a second duration under a line that has just
     * given both — so only the part a reader came for is kept.
     *
     * Where no failure can be found — a child that died before reporting one,
     * or said nothing Pest wrote — the account is handed back whole, since
     * something unreadable beats nothing at all.
     */
    private function failuresIn(string $spoke): string
    {
        $lines = preg_split('/\R/', rtrim($spoke)) ?: [];
        $plain = array_map(
            fn (string $line): string => (string) preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $line),
            $lines,
        );

        $first = null;

        foreach ($plain as $index => $line) {
            if (str_contains($line, 'FAILED')) {
                $first = $index;

                break;
            }
        }

        if ($first === null) {
            return rtrim($spoke);
        }

        // Pest draws a rule above each failure. It belongs to the block.
        if ($first > 0 && str_contains($plain[$first - 1], '─')) {
            $first--;
        }

        $last = count($lines) - 1;

        // From the end: "Tests:" is the summary's first line, and the words
        // could as easily appear in a failure message above it.
        for ($index = $last; $index > $first; $index--) {
            if (preg_match('/^\s*Tests:\s/', $plain[$index]) === 1) {
                $last = $index - 1;

                break;
            }
        }

        return rtrim(implode(PHP_EOL, array_slice($lines, $first, $last - $first + 1)));
    }

    /**
     * Build the `{--flag}`/`{--flag=}` signature fragment for every option in
     * self::PEST_OPTIONS, on top of this command's own arguments/options.
     */
    private function buildSignature(): string
    {
        $lines = [
            'module:test',
            '{modules?* : One or more module names, paths, or test files to run, a name optionally carrying a floor of its own as Module:percentage (omit to test every module)}',
            '{--coverage : Collect and display code coverage}',
            '{--t|totals : List each module with its coverage score and nothing else (implies --coverage and --compact)}',
            '{--min= : Minimum coverage percentage to enforce (implies --coverage)}',
            '{--mins=* : A floor for named modules, as Module[,Module]:percentage — what --min gives everything else, raised or lowered for these. Give it more than once for more than one floor, or @path to read them a line at a time out of a file (implies --coverage)}',
            '{--root : Also run the root tests/ suite, measured against the root app/ — it is the one tree no module covers. Its row is Root, and naming Root as a target is what narrows the run to it}',
            '{--sort=asc : Order to test multiple modules in (asc, desc, none)}',
            '{--from= : Start at this module, given as its position in the run or its name}',
            '{--to= : Stop after this module, given as its position in the run or its name}',
            '{--limit= : Run at most this many modules, counted from the start of the run (after --from/--to)}',
            '{--take= : The same as --limit}',
            '{--pick= : The same as --limit}',
            '{--artisan : Run via php artisan test instead of the pest binary}',
            '{--export= : Write the scoreboard to a file; the extension picks the format ('.implode(', ', ScoreboardExport::formats()).'), and either half of the name may be left off — on its own it writes module-test.json (implies --totals)}',
        ];

        foreach (self::PEST_OPTIONS as $name => $acceptsValue) {
            $alias = isset(self::PEST_OPTION_ALIASES[$name]) ? self::PEST_OPTION_ALIASES[$name].'|' : '';
            $lines[] = "{--{$alias}{$name}".($acceptsValue ? '=' : '').'}';
        }

        return implode("\n        ", $lines);
    }

    /** An option's value as text, or null where it was not given one. */
    private function text(string $option): ?string
    {
        /** @var mixed $value */
        $value = $this->option($option);

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * Every recognized Pest option the user actually passed, formatted for the
     * underlying command line: "--flag" for booleans, "--flag=value" for value options.
     *
     * @return array<int, string>
     */
    private function pestOptionArgs(): array
    {
        $args = [];

        foreach (self::PEST_OPTIONS as $name => $acceptsValue) {
            $value = $this->option($name);

            if ($acceptsValue) {
                if (is_scalar($value)) {
                    $args[] = "--{$name}={$value}";
                }
            } elseif ($value) {
                $args[] = "--{$name}";
            }
        }

        return $args;
    }

    /**
     * Resolve a module name, module-relative path, or literal path/file into a
     * path Pest can run: "Person" -> "modules/Person", "Person/tests/Unit/FooTest.php"
     * -> "modules/Person/tests/Unit/FooTest.php". A value already rooted at "modules/"
     * or "tests/", or an existing file relative to the project, passes through unchanged.
     */
    private function resolveTarget(string $module): string
    {
        if ($this->isRoot($module)) {
            return self::ROOT_TESTS;
        }

        if (str_starts_with($module, 'modules/') || str_starts_with($module, 'tests/') || file_exists(base_path($module))) {
            return $module;
        }

        if (! str_contains($module, '/')) {
            return 'modules/'.$this->moduleDirectory($module);
        }

        [$moduleName, $rest] = explode('/', $module, 2);

        return 'modules/'.$this->moduleDirectory($moduleName).'/'.$rest;
    }

    /**
     * The module's directory as it is spelled on disk.
     *
     * `Str::studly()` alone turns "mailcode" into "Mailcode", which is a
     * different directory from "MailCode". A case-insensitive filesystem still
     * finds the files under that spelling, so the run does not fail outright —
     * instead the `uses(TestCase::class)` binding in tests/Pest.php, which is
     * scoped by module path, never matches the differently-cased path, so every
     * test runs without a
     * booted application and the run reports hundreds of "facade root has not
     * been set" failures and 0% coverage. Matching the real directory name
     * keeps a lowercase argument working and keeps the answer identical on a
     * case-sensitive filesystem.
     */
    private function moduleDirectory(string $module): string
    {
        // Scanned rather than tested with is_dir(): on a case-insensitive
        // filesystem is_dir('modules/Mailcode') answers true for the directory
        // actually named "MailCode", so the wrong spelling would pass straight
        // through. Only the name the scan reports is the one on disk.
        foreach ((glob(base_path('modules/*'), GLOB_ONLYDIR) ?: []) as $directory) {
            $name = basename($directory);

            if (strcasecmp($name, $module) === 0) {
                return $name;
            }
        }

        return Str::studly($module);
    }

    /**
     * Derive a PHPUnit/ParaTest --filter regex matching the fully qualified test
     * names Pest generates for a resolved path, e.g. "modules/Person" -> a pattern
     * matching "...Modules\Person\..." — used in place of a positional path
     * argument, which ParaTest's CLI does not accept.
     */
    private function parallelFilterFor(string $path): string
    {
        $root = $path === self::ROOT_TESTS || str_starts_with($path, self::ROOT_TESTS.'/');

        $namespaced = Str::of($path)
            ->replaceFirst($root ? self::ROOT_TESTS : 'modules', $root ? 'Tests' : 'Modules')
            ->when(str_ends_with($path, '.php'), fn ($str) => $str->substr(0, -4))
            ->replace('/', '\\');

        // A module's test classes are namespaced `Modules\{Module}\Tests\…`, so
        // an unanchored `Tests\` would pull in every module's tests alongside
        // the root's. The root's pattern is anchored; a module's needs not be.
        return ($root ? '/^' : '/').preg_quote((string) $namespaced, '/').'/';
    }

    /**
     * Unset the env vars phpunit.xml controls (APP_ENV, DB_CONNECTION, etc.) before spawning
     * the child test process. This command is itself a booted Laravel app that already loaded
     * .env into its own process environment; without this, the child inherits those values and
     * phpunit.xml's <env> block — which only applies when a var isn't already set — never wins.
     *
     * @return array<string, false>
     */
    private function testEnvironmentOverrides(): array
    {
        if (! file_exists($file = base_path('phpunit.xml'))) {
            return [];
        }

        $overrides = [];

        foreach (simplexml_load_file($file)->php->env ?? [] as $env) {
            $overrides[(string) $env['name']] = false;
        }

        return $overrides;
    }

    public function getInfo(): ?string
    {
        return 'Testing module(s)...';
    }
}
