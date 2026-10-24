<?php

use Illuminate\Process\FakeProcessResult;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Nwidart\Modules\Facades\Module;
use Nwidart\Modules\Laravel\Module as NwidartModule;
use Oki\ModuleTest\Console\ModuleTestCommand;
use Oki\ModuleTest\Support\ScopedCoverageReport;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/*
| The command reads whatever is registered, so every test below narrows the
| registry to modules that exist for this file only — otherwise the assertions
| would move with every module the service adds or renames. `$this->registry`
| is read at the moment the command asks, so a test wanting a different one
| rewrites it rather than mocking the facade a second time.
*/
beforeEach(function () {
    $this->registry = new ArrayObject(['Example', 'Sample', 'Contact']);

    $registry = $this->registry;

    Module::partialMock()->shouldReceive('all')->andReturnUsing(fn (): array => collect($registry->getArrayCopy())
        ->mapWithKeys(fn (string $name): array => [
            $name => new NwidartModule(app(), $name, base_path("modules/{$name}")),
        ])
        ->all());
});

/**
 * A module directory whose name is not the studly form of its lowercase
 * spelling, made for the test and removed with it.
 *
 * The resolution under test scans the filesystem rather than the registry, and
 * no installed module is spelled with an inner capital — asserting against one
 * that happened to be installed would move the moment it was renamed.
 */
function module_directory_named(string $name): void
{
    $path = base_path("modules/{$name}");

    if (File::isDirectory($path)) {
        return;
    }

    File::makeDirectory($path);

    test()->beforeApplicationDestroyed(fn () => File::deleteDirectory($path));
}

/**
 * A Clover report for one file of one module.
 *
 * @param  array<int, int>  $lines  Line number => times run.
 */
function clover_xml(string $module, array $lines): string
{
    return clover_xml_for(base_path("modules/{$module}/app/Foo.php"), $lines);
}

/**
 * The same, for a file named outright — the root `app/` tree has no module to
 * name it by.
 *
 * @param  array<int, int>  $lines  Line number => times run.
 */
function clover_xml_for(string $file, array $lines): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8"?><coverage><project><file name="'.$file.'">';

    foreach ($lines as $number => $count) {
        $xml .= '<line num="'.$number.'" type="stmt" count="'.$count.'"/>';
    }

    return $xml
        .'<metrics statements="'.count($lines).'" coveredstatements="'.count(array_filter($lines)).'"/>'
        .'</file></project></coverage>';
}

/**
 * Stands in for the child process, which writes a Clover report rather than
 * printing a coverage table. A faked process runs nothing, so the file it
 * would have left behind is written here instead.
 *
 * @param  array<int, int>  $lines  Line number => times run.
 */
function child_writes_coverage(string $label, array $lines): void
{
    child_writes_coverage_for([$label => $lines]);
}

/**
 * The same, for a run covering several modules: each child is recognised by
 * the report path it was told to write, so every module gets its own numbers.
 *
 * @param  array<string, array<int, int>>  $modules  Module label => (line number => times run).
 */
function child_writes_coverage_for(array $modules): void
{
    $reports = [];

    foreach ($modules as $label => $lines) {
        $reports[ScopedCoverageReport::fileFor($label)] = clover_xml($label, $lines);
    }

    Process::fake(function ($process) use ($reports) {
        foreach ($reports as $file => $xml) {
            if (in_array('--coverage-clover='.$file, $process->command, true)) {
                file_put_contents($file, $xml);
            }
        }

        return Process::result();
    });
}

/**
 * A child process that says something on its way, so the output callback the
 * command hands `Process::run()` is actually called — a synchronous fake never
 * calls it.
 *
 * @param  array<int, string>  $lines
 */
function child_says(array $lines, ?string $clover = null, string $xml = '', int $exitCode = 0): void
{
    $pending = new class($lines, $clover, $xml, $exitCode)
    {
        /** @param  array<int, string>  $lines */
        public function __construct(
            private readonly array $lines,
            private readonly ?string $clover,
            private readonly string $xml,
            private readonly int $exitCode,
        ) {}

        /** @param  array<string, mixed>  $environment */
        public function env(array $environment): static
        {
            return $this;
        }

        /** @param  array<int, string>  $command */
        public function run(array $command, ?callable $output = null): FakeProcessResult
        {
            if ($this->clover !== null) {
                file_put_contents($this->clover, $this->xml);
            }

            foreach ($this->lines as $line) {
                $output('out', $line);
            }

            return new FakeProcessResult(output: implode('', $this->lines), exitCode: $this->exitCode);
        }
    };

    Process::partialMock()->shouldReceive('forever')->andReturn($pending);
}

/**
 * Runs the command against a terminal that understands escape codes, which is
 * the only place the scoreboard takes a line back and redraws it.
 *
 * @param  array<string, mixed>  $parameters
 */
function run_decorated(array $parameters): string
{
    $buffer = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);

    Artisan::call('module:test', $parameters, $buffer);

    return $buffer->fetch();
}

// ---------------------------------------------------------------------------
// --sort validation
// ---------------------------------------------------------------------------

describe('--sort validation', function () {
    it('returns FAILURE and runs nothing for an invalid --sort value', function () {
        Process::fake();

        $this->artisan('module:test', ['modules' => ['Example'], '--sort' => 'bogus'])
            ->assertFailed();

        Process::assertNothingRan();
    });
});

// ---------------------------------------------------------------------------
// Target resolution
// ---------------------------------------------------------------------------

describe('target resolution', function () {
    it('defaults to testing every enabled module individually when none are given', function () {
        Process::fake();

        $this->registry->exchangeArray(['Example']);

        $this->artisan('module:test')->assertSuccessful();

        Process::assertRanTimes(fn () => true, 1);
        Process::assertRan(fn ($process) => end($process->command) === 'modules/Example');
    });

    it('resolves a bare module name to its modules/ directory, studly-cased', function () {
        Process::fake();

        $this->artisan('module:test', ['modules' => ['example']])->assertSuccessful();

        Process::assertRan(fn ($process) => end($process->command) === 'modules/Example');
    });

    it('resolves a module whose directory is not the studly form of its name', function () {
        // Str::studly('mailcode') is "Mailcode", a different directory from
        // "MailCode". A case-insensitive filesystem finds the files either way,
        // so the wrong spelling does not fail — it runs every test without a
        // booted application and reports 0% coverage.
        Process::fake();

        module_directory_named('MailCode');

        $this->artisan('module:test', ['modules' => ['mailcode']])->assertSuccessful();

        Process::assertRan(fn ($process) => end($process->command) === 'modules/MailCode');
    });

    it('resolves the directory casing for a path inside a module too', function () {
        Process::fake();

        module_directory_named('MailCode');

        $this->artisan('module:test', ['modules' => ['mailcode/tests/Unit']])->assertSuccessful();

        Process::assertRan(fn ($process) => end($process->command) === 'modules/MailCode/tests/Unit');
    });

    it('falls back to the studly form when no module directory matches its name', function () {
        // Registered, but with no directory of its own: the scan finds
        // nothing to match, so the studly spelling is what is run.
        Process::fake();

        $this->registry->exchangeArray(['no-such-module']);

        $this->artisan('module:test', ['modules' => ['no-such-module']])->assertSuccessful();

        Process::assertRan(fn ($process) => end($process->command) === 'modules/NoSuchModule');
    });

    it('drops a target naming nothing this checkout has', function () {
        // The `100` left behind by a `--min 100` written without its `=`, or
        // a module renamed since somebody wrote the command down.
        Process::fake();

        $this->artisan('module:test', ['modules' => ['Example', '100']])->assertSuccessful();

        Process::assertRanTimes(fn () => true, 1);
        Process::assertRan(fn ($process) => end($process->command) === 'modules/Example');
    });

    it('fails without running anything when every target named nothing', function () {
        Process::fake();

        $this->artisan('module:test', ['modules' => ['100']])
            ->expectsOutputToContain('Nothing to test.')
            ->assertFailed();

        Process::assertNothingRan();
    });

    it('passes through a path already rooted at modules/', function () {
        Process::fake();

        $target = 'modules/Example/tests/Unit/ExampleQueryTest.php';

        $this->artisan('module:test', ['modules' => [$target]])->assertSuccessful();

        Process::assertRan(fn ($process) => end($process->command) === $target);
    });

    it('passes through a path already rooted at tests/', function () {
        Process::fake();

        $target = 'tests/Unit/Helpers/TextAndNumberHelperTest.php';

        $this->artisan('module:test', ['modules' => [$target]])->assertSuccessful();

        Process::assertRan(fn ($process) => end($process->command) === $target);
    });

    it('passes through an existing file that has neither prefix', function () {
        Process::fake();

        $this->artisan('module:test', ['modules' => ['composer.json']])->assertSuccessful();

        Process::assertRan(fn ($process) => end($process->command) === 'composer.json');
    });

    it('resolves a module-relative subpath by studly-casing only the first segment', function () {
        Process::fake();

        $this->artisan('module:test', ['modules' => ['example/tests/Unit/ExampleQueryTest.php']])
            ->assertSuccessful();

        Process::assertRan(fn ($process) => end($process->command) === 'modules/Example/tests/Unit/ExampleQueryTest.php');
    });

    it('runs a single null target when neither modules are given nor any are enabled', function () {
        Process::fake();

        $this->registry->exchangeArray([]);

        $this->artisan('module:test')->assertSuccessful();

        Process::assertRanTimes(fn () => true, 1);
        Process::assertRan(fn ($process) => end($process->command) === 'modules');
    });
});

// ---------------------------------------------------------------------------
// Labels
// ---------------------------------------------------------------------------

describe('task labels', function () {
    it('labels each module individually on a no-argument run, not a single "Modules" block', function () {
        Process::fake();

        $this->registry->exchangeArray(['Example']);

        $this->artisan('module:test')
            ->assertSuccessful()
            ->expectsOutputToContain('Testing Example')
            ->doesntExpectOutputToContain('Testing Modules');
    });

    it('labels a bare module by its studly name', function () {
        Process::fake();

        $this->artisan('module:test', ['modules' => ['example']])
            ->assertSuccessful()
            ->expectsOutputToContain('Testing Example');
    });

    it('labels a path/file target by its resolved path', function () {
        Process::fake();

        $this->artisan('module:test', ['modules' => ['example/tests/Unit/ExampleQueryTest.php']])
            ->assertSuccessful()
            ->expectsOutputToContain('Testing modules/Example/tests/Unit/ExampleQueryTest.php');
    });

    it('labels a null target as "Modules" when nothing is enabled', function () {
        Process::fake();

        $this->registry->exchangeArray([]);

        $this->artisan('module:test')
            ->assertSuccessful()
            ->expectsOutputToContain('Testing Modules');
    });
});

// ---------------------------------------------------------------------------
// Coverage flags
// ---------------------------------------------------------------------------

describe('coverage flags', function () {
    it('omits coverage flags when neither --coverage nor --min is passed', function () {
        Process::fake();

        $this->artisan('module:test', ['modules' => ['Example']])->assertSuccessful();

        Process::assertRan(fn ($process) => ! in_array('--coverage', $process->command, true)
            && ! in_array('-d', $process->command, true));
    });

    it('adds -d pcov.directory and --coverage when --coverage is passed', function () {
        Process::fake();

        $this->artisan('module:test', ['modules' => ['Example'], '--coverage' => true])->assertSuccessful();

        Process::assertRan(fn ($process) => in_array('-d', $process->command, true)
            && in_array('pcov.directory='.base_path(), $process->command, true)
            && in_array('--coverage-clover='.ScopedCoverageReport::fileFor('Example'), $process->command, true));
    });

    it('implies --coverage when --min is passed', function () {
        // The command enforces --min itself against the module's own scoped
        // total (see the next test), so the child must leave behind a report
        // meeting it for this run to succeed.
        child_writes_coverage('Example', [1 => 1]);

        $this->artisan('module:test', ['modules' => ['Example'], '--min' => '80'])->assertSuccessful();

        Process::assertRan(fn ($process) => in_array('--coverage-clover='.ScopedCoverageReport::fileFor('Example'), $process->command, true));
    });

    it('does not forward --min to the child process for a module target', function () {
        // PHPUnit/Pest's own --min is checked against the whole <source><include>
        // tree, not the module under test (see ScopedCoverageReport's docblock) —
        // forwarding it here would fail/pass on coverage this module never
        // touched. The command enforces --min itself against the module's own
        // scoped total instead.
        child_writes_coverage('Example', [1 => 1]);

        $this->artisan('module:test', ['modules' => ['Example'], '--min' => '80'])->assertSuccessful();

        Process::assertRan(fn ($process) => ! Arr::first($process->command, fn ($token) => str_starts_with((string) $token, '--min=')));
    });

    it('fails when the module\'s own scoped total falls short of --min', function () {
        child_writes_coverage('Example', [1 => 1, 2 => 0]);

        $this->artisan('module:test', ['modules' => ['Example'], '--min' => '80'])
            ->expectsOutputToContain('code coverage is below expectation')
            ->assertFailed();
    });

    it('adds up a combined total across every module in the run', function () {
        // Weighed by statements, not by module: 1/1 and 1/3 is 2/4, not the
        // 66.70 % averaging the two percentages would claim.
        child_writes_coverage_for([
            'Example' => [1 => 1],
            'Sample' => [1 => 1, 2 => 0, 3 => 0],
        ]);

        $this->artisan('module:test', ['modules' => ['Example', 'Sample'], '--coverage' => true])
            ->expectsOutputToContain('Total (2 modules): 50.00%')
            ->assertSuccessful();
    });

    it('omits the combined total for a single module', function () {
        child_writes_coverage('Example', [1 => 1]);

        $this->artisan('module:test', ['modules' => ['Example'], '--coverage' => true])
            ->doesntExpectOutputToContain('1 modules')
            ->assertSuccessful();
    });

    it('omits the combined total when no module left a report behind', function () {
        Process::fake();

        $this->artisan('module:test', ['modules' => ['Example', 'Sample'], '--coverage' => true])
            ->doesntExpectOutputToContain('2 modules');
    });

    it('says so when the run left no coverage report behind', function () {
        Process::fake();

        $this->artisan('module:test', ['modules' => ['Example'], '--coverage' => true])
            ->expectsOutputToContain('No coverage report was written');
    });

    it('does not report a module against the report of the run before it', function () {
        file_put_contents(ScopedCoverageReport::fileFor('Example'), 'stale');

        Process::fake();

        $this->artisan('module:test', ['modules' => ['Example'], '--coverage' => true])
            ->expectsOutputToContain('No coverage report was written');
    });

    it('clears the report away once it has been read', function () {
        child_writes_coverage('Example', [1 => 1]);

        $this->artisan('module:test', ['modules' => ['Example'], '--coverage' => true])->assertSuccessful();

        expect(ScopedCoverageReport::fileFor('Example'))->not->toBeFile();
    });

    it('still forwards --min to the child process for a whole-suite (null target) run', function () {
        // There is no module to scope a total to here, so PHPUnit/Pest's own
        // whole-project --min check is exactly what is wanted.
        Process::fake();

        $this->registry->exchangeArray([]);

        $this->artisan('module:test', ['--min' => '80'])->assertSuccessful();

        Process::assertRan(fn ($process) => in_array('--min=80', $process->command, true));
    });
});

// ---------------------------------------------------------------------------
// --artisan
// ---------------------------------------------------------------------------

describe('--artisan', function () {
    it('runs via the pest binary by default', function () {
        Process::fake();

        $this->artisan('module:test', ['modules' => ['Example']])->assertSuccessful();

        Process::assertRan(fn ($process) => in_array(base_path('vendor/bin/pest'), $process->command, true)
            && ! in_array(base_path('artisan'), $process->command, true)
            && ! in_array('test', $process->command, true));
    });

    it('runs via php artisan test when --artisan is passed', function () {
        Process::fake();

        $this->artisan('module:test', ['modules' => ['Example'], '--artisan' => true])->assertSuccessful();

        Process::assertRan(fn ($process) => in_array(base_path('artisan'), $process->command, true)
            && in_array('test', $process->command, true)
            && ! in_array(base_path('vendor/bin/pest'), $process->command, true));
    });
});

// ---------------------------------------------------------------------------
// Pest option passthrough
// ---------------------------------------------------------------------------

describe('pest option passthrough', function () {
    it('forwards a recognized boolean pest flag when passed', function () {
        Process::fake();

        $this->artisan('module:test', ['modules' => ['Example'], '--bail' => true])->assertSuccessful();

        Process::assertRan(fn ($process) => in_array('--bail', $process->command, true));
    });

    it('forwards a recognized value-accepting pest option when passed', function () {
        Process::fake();

        $this->artisan('module:test', ['modules' => ['Example'], '--filter' => 'Foo'])->assertSuccessful();

        Process::assertRan(fn ($process) => in_array('--filter=Foo', $process->command, true));
    });

    it('omits every pest option that was not passed', function () {
        Process::fake();

        $this->artisan('module:test', ['modules' => ['Example']])->assertSuccessful();

        Process::assertRan(fn ($process) => ! in_array('--bail', $process->command, true)
            && ! Arr::first($process->command, fn ($token) => str_starts_with((string) $token, '--filter')));
    });
});

// ---------------------------------------------------------------------------
// Multiple targets: sequencing, ordering, exit code aggregation
// ---------------------------------------------------------------------------

describe('multiple targets', function () {
    it('--sort=asc runs targets in ascending alphabetical order', function () {
        $order = [];

        Process::fake(function ($process) use (&$order) {
            $order[] = end($process->command);

            return Process::result();
        });

        $this->artisan('module:test', ['modules' => ['Example', 'Contact'], '--sort' => 'asc'])
            ->assertSuccessful();

        expect($order)->toBe(['modules/Contact', 'modules/Example']);
    });

    it('--sort=desc runs targets in descending alphabetical order', function () {
        $order = [];

        Process::fake(function ($process) use (&$order) {
            $order[] = end($process->command);

            return Process::result();
        });

        $this->artisan('module:test', ['modules' => ['Contact', 'Example'], '--sort' => 'desc'])
            ->assertSuccessful();

        expect($order)->toBe(['modules/Example', 'modules/Contact']);
    });

    it('--sort=none preserves the given order', function () {
        $order = [];

        Process::fake(function ($process) use (&$order) {
            $order[] = end($process->command);

            return Process::result();
        });

        $this->artisan('module:test', ['modules' => ['Example', 'Contact'], '--sort' => 'none'])
            ->assertSuccessful();

        expect($order)->toBe(['modules/Example', 'modules/Contact']);
    });

    it('names the module whose tests failed, once the run is past the one that said so', function () {
        $calls = 0;

        Process::fake(function () use (&$calls) {
            $calls++;

            return Process::result(exitCode: $calls === 1 ? 1 : 0);
        });

        $this->artisan('module:test', ['modules' => ['Contact', 'Example'], '--sort' => 'none'])
            ->assertFailed()
            ->expectsOutputToContain('tests failed in Contact');
    });

    it('names every module that fell short of the floor, and the floor', function () {
        Process::fake();

        $this->artisan('module:test', ['modules' => ['Contact', 'Example'], '--totals' => true, '--min' => '50'])
            ->assertFailed()
            ->expectsOutputToContain('below the 50.00 % floor: Contact 0.00 %, Example 0.00 %');
    });

    it('names the worst few and counts the rest, rather than every module on one line', function () {
        $this->registry->exchangeArray(['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo', 'Foxtrot', 'Golf']);

        Process::fake();

        $this->artisan('module:test', ['--totals' => true, '--min' => '50'])
            ->assertFailed()
            ->expectsOutputToContain('and 2 more');
    });

    it('says nothing at the end about a single target, which has already said it', function () {
        Process::fake(fn () => Process::result(exitCode: 1));

        $this->artisan('module:test', ['modules' => ['Contact']])
            ->assertFailed()
            ->doesntExpectOutputToContain('tests failed in');
    });

    it('aggregates a failing exit code across multiple targets', function () {
        $calls = 0;

        Process::fake(function () use (&$calls) {
            $calls++;

            return Process::result(exitCode: $calls === 1 ? 1 : 0);
        });

        $exitCode = $this->artisan('module:test', ['modules' => ['Contact', 'Example']])->run();

        Process::assertRanTimes(fn () => true, 2);
        expect($exitCode)->toBe(1);
    });
});

// ---------------------------------------------------------------------------
// --from/--to: a window over the run
// ---------------------------------------------------------------------------

describe('--from/--to', function () {
    // Sorted ascending, the default registry runs Contact, Example, Sample.
    beforeEach(function () {
        $this->order = [];

        $order = &$this->order;

        Process::fake(function ($process) use (&$order) {
            $order[] = end($process->command);

            return Process::result();
        });
    });

    it('starts at the module a position names', function () {
        $this->artisan('module:test', ['--from' => '2'])->assertSuccessful();

        expect($this->order)->toBe(['modules/Example', 'modules/Sample']);
    });

    it('stops after the module a name names', function () {
        $this->artisan('module:test', ['--to' => 'Example'])->assertSuccessful();

        expect($this->order)->toBe(['modules/Contact', 'modules/Example']);
    });

    it('runs the one module both ends name', function () {
        $this->artisan('module:test', ['--from' => 'example', '--to' => '2'])->assertSuccessful();

        expect($this->order)->toBe(['modules/Example']);
    });

    it('fails when a position names no module in the run', function () {
        $this->artisan('module:test', ['--from' => '9'])
            ->expectsOutputToContain('No module at position [9]; the run has 3.')
            ->assertFailed();

        expect($this->order)->toBe([]);
    });

    it('fails when a name is not in the run', function () {
        $this->artisan('module:test', ['--to' => 'Workspace'])
            ->expectsOutputToContain('Module [Workspace] is not in this run.')
            ->assertFailed();

        expect($this->order)->toBe([]);
    });

    it('fails when the window closes before it opens', function () {
        $this->artisan('module:test', ['--from' => '3', '--to' => '1'])
            ->expectsOutputToContain('Nothing to test.')
            ->assertFailed();

        expect($this->order)->toBe([]);
    });

    it('fails when a name is asked of a run that names no module at all', function () {
        $this->registry->exchangeArray([]);

        $this->artisan('module:test', ['--from' => 'Example'])
            ->expectsOutputToContain('Module [Example] is not in this run.')
            ->assertFailed();

        expect($this->order)->toBe([]);
    });

    it('takes only the first few when a limit is given', function (string $option) {
        $this->artisan('module:test', [$option => '2'])->assertSuccessful();

        expect($this->order)->toBe(['modules/Contact', 'modules/Example']);
    })->with([
        'under its own name' => ['--limit'],
        'under --take' => ['--take'],
        'under --pick' => ['--pick'],
    ]);

    it('accepts every name for the limit where they agree', function () {
        $this->artisan('module:test', ['--limit' => '1', '--take' => '1', '--pick' => '1'])->assertSuccessful();

        expect($this->order)->toBe(['modules/Contact']);
    });

    it('refuses to choose between limits that disagree', function (array $options, string $message) {
        $this->artisan('module:test', $options)
            ->expectsOutputToContain($message)
            ->assertFailed();

        expect($this->order)->toBe([]);
    })->with([
        'two of them' => [
            ['--limit' => '2', '--take' => '3'],
            '--limit is [2] and --take is [3]. They are one option, so give it once.',
        ],
        'the newest pair' => [
            ['--take' => '2', '--pick' => '3'],
            '--take is [2] and --pick is [3]. They are one option, so give it once.',
        ],
        'all three' => [
            ['--limit' => '2', '--take' => '3', '--pick' => '4'],
            '--limit is [2], --take is [3] and --pick is [4]. They are one option, so give it once.',
        ],
    ]);

    it('counts the limit from where the window opens, not from the whole run', function () {
        $this->artisan('module:test', ['--from' => '2', '--limit' => '1'])->assertSuccessful();

        expect($this->order)->toBe(['modules/Example']);
    });

    it('takes what the window left when the limit asks for more', function () {
        $this->artisan('module:test', ['--to' => '2', '--limit' => '9'])->assertSuccessful();

        expect($this->order)->toBe(['modules/Contact', 'modules/Example']);
    });

    it('refuses a limit that is not a whole number of modules', function (string $limit) {
        $this->artisan('module:test', ['--limit' => $limit])
            ->expectsOutputToContain("A limit is a whole number of modules, 1 or more — [{$limit}] is not.")
            ->assertFailed();

        expect($this->order)->toBe([]);
    })->with([
        'zero' => ['0'],
        'negative' => ['-1'],
        'a word' => ['two'],
        'a fraction' => ['1.5'],
    ]);

    it('refuses the same under the other name', function () {
        $this->artisan('module:test', ['--take' => '0'])
            ->expectsOutputToContain('A limit is a whole number of modules, 1 or more — [0] is not.')
            ->assertFailed();

        expect($this->order)->toBe([]);
    });
});

// ---------------------------------------------------------------------------
// Test environment overrides (phpunit.xml <env> clearing)
// ---------------------------------------------------------------------------

describe('test environment overrides', function () {
    it('unsets every env var declared in phpunit.xml before spawning the child process', function () {
        Process::fake();

        $this->artisan('module:test', ['modules' => ['Example']])->assertSuccessful();

        Process::assertRan(function ($process) {
            $phpunit = simplexml_load_file(base_path('phpunit.xml'));

            // The file is this suite's own, so it is there and it parses. A
            // broken one fails the assertion rather than passing quietly.
            if ($phpunit === false) {
                return false;
            }

            foreach ($phpunit->php->env as $env) {
                if (($process->environment[(string) $env['name']] ?? null) !== false) {
                    return false;
                }
            }

            return true;
        });
    });

    it('falls back to no overrides when phpunit.xml is missing', function () {
        $phpunitPath = base_path('phpunit.xml');
        $backupPath = base_path('phpunit.xml.testing-backup');

        rename($phpunitPath, $backupPath);

        try {
            Process::fake();

            $this->artisan('module:test', ['modules' => ['Example']])->assertSuccessful();

            Process::assertRan(fn ($process) => $process->environment === []);
        } finally {
            rename($backupPath, $phpunitPath);
        }
    });
});

// ---------------------------------------------------------------------------
// --parallel + a specific module target (ParaTest has no positional "path"
// argument, unlike PHPUnit/Pest, so a module target must become --filter).
// ---------------------------------------------------------------------------

describe('--parallel with a specific module', function () {
    it('translates the module into a --filter regex instead of a positional path', function () {
        Process::fake();

        $this->artisan('module:test', ['modules' => ['Example'], '--parallel' => true])->assertSuccessful();

        Process::assertRan(function ($process) {
            $filter = Arr::first($process->command, fn ($token) => str_starts_with((string) $token, '--filter='));

            return $filter !== null
                && str_contains($filter, 'Modules')
                && str_contains($filter, 'Example')
                && ! in_array('modules/Example', $process->command, true);
        });
    });

    it('respects an explicit --filter instead of overriding it', function () {
        Process::fake();

        $this->artisan('module:test', ['modules' => ['Example'], '--parallel' => true, '--filter' => 'Custom'])
            ->assertSuccessful();

        Process::assertRan(fn ($process) => in_array('--filter=Custom', $process->command, true));
    });

    it('adds a per-module filter for every auto-discovered module when none are given', function () {
        Process::fake();

        $this->artisan('module:test', ['--parallel' => true])->assertSuccessful();

        Process::assertRan(function ($process) {
            $filter = Arr::first($process->command, fn ($token) => str_starts_with((string) $token, '--filter='));

            return $filter !== null && ! in_array('modules/Example', $process->command, true);
        });
    });

    it('does not add a --filter for a null target run via --parallel', function () {
        Process::fake();

        $this->registry->exchangeArray([]);

        $this->artisan('module:test', ['--parallel' => true])->assertSuccessful();

        Process::assertRan(fn ($process) => ! Arr::first($process->command, fn ($token) => str_starts_with((string) $token, '--filter='))
            && end($process->command) === 'modules');
    });

    it('lets a worker whose shard holds none of the module report no tests', function () {
        Process::fake();

        $this->artisan('module:test', ['modules' => ['Example'], '--parallel' => true])->assertSuccessful();

        Process::assertRan(fn ($process) => in_array('--do-not-fail-on-empty-test-suite', $process->command, true));
    });

    it('excuses an empty shard under an explicit --filter too', function () {
        Process::fake();

        $this->artisan('module:test', ['modules' => ['Example'], '--parallel' => true, '--filter' => 'Custom'])
            ->assertSuccessful();

        Process::assertRan(fn ($process) => in_array('--do-not-fail-on-empty-test-suite', $process->command, true));
    });

    it('does not excuse an empty suite when the run is not sharded', function () {
        Process::fake();

        $this->artisan('module:test', ['modules' => ['Example']])->assertSuccessful();

        Process::assertRan(fn ($process) => ! in_array('--do-not-fail-on-empty-test-suite', $process->command, true));
    });

    it('still appends the positional path when --parallel is not used', function () {
        Process::fake();

        $this->artisan('module:test', ['modules' => ['Example']])->assertSuccessful();

        Process::assertRan(fn ($process) => end($process->command) === 'modules/Example');
    });
});

// ---------------------------------------------------------------------------
// --totals: a scoreboard rather than a test run
// ---------------------------------------------------------------------------

describe('--totals', function () {
    it('collects coverage without being asked to', function () {
        child_writes_coverage('Example', [1 => 1]);

        $this->artisan('module:test', ['modules' => ['Example'], '--totals' => true])->assertSuccessful();

        Process::assertRan(fn ($process) => in_array('--coverage-clover='.ScopedCoverageReport::fileFor('Example'), $process->command, true));
    });

    it('draws one row per module and none of the per-file report', function () {
        child_writes_coverage_for([
            'Example' => [1 => 1],
            'Sample' => [1 => 1, 2 => 0],
        ]);

        $this->artisan('module:test', ['modules' => ['Example', 'Sample'], '--totals' => true])
            ->expectsOutputToContain('Example')
            ->expectsOutputToContain('Sample')
            // 2 of 3 statements is 66.666…, which is not 66.67: a score is
            // never rounded up.
            ->expectsOutputToContain('Total (2 modules): 66.66%')
            ->doesntExpectOutputToContain('Testing Example')
            ->assertSuccessful();
    });

    it('carries the floor beside the score and fails quietly beneath it', function () {
        child_writes_coverage('Example', [1 => 1, 2 => 0]);

        $this->artisan('module:test', ['modules' => ['Example'], '--totals' => true, '--min' => '80'])
            ->expectsOutputToContain('80.00%')
            ->doesntExpectOutputToContain('below expectation')
            ->assertFailed();
    });

    it('says a module is running before it has a score, where the line can be taken back', function () {
        child_writes_coverage('Example', [1 => 1]);

        expect(run_decorated(['modules' => ['Example'], '--totals' => true]))
            ->toContain('running')
            ->toContain('100.00%');
    });

    it('says a module is running as it starts, where the line cannot be taken back', function () {
        child_writes_coverage('Example', [1 => 1]);

        $buffer = new BufferedOutput;
        Artisan::call('module:test', ['modules' => ['Example'], '--totals' => true], $buffer);
        $said = $buffer->fetch();

        expect($said)->not->toContain("\033[1A")
            ->and(strpos($said, 'running'))->toBeLessThan(strpos($said, '100.00%'));
    });
});

// ---------------------------------------------------------------------------
// --shard: one part of the run, for one of several machines
// ---------------------------------------------------------------------------

/**
 * A test file holding the given number of tests, made for the test and
 * removed with it.
 */
function tests_on_disk(string $file, int $count): void
{
    $path = base_path($file);

    File::ensureDirectoryExists(dirname($path));
    File::put($path, "<?php\n".str_repeat("it('passes', fn () => true);\n", $count));

    test()->beforeApplicationDestroyed(fn () => File::delete($path));
}

describe('--shard', function () {
    // Sorted ascending, the default registry runs Contact, Example, Sample.
    beforeEach(function () {
        $this->order = [];

        $order = &$this->order;

        Process::fake(function ($process) use (&$order) {
            $order[] = end($process->command);

            return Process::result();
        });
    });

    it('cuts the run into parts that together hold every module once', function () {
        $this->artisan('module:test', ['--shard' => '1/2'])->assertSuccessful();
        $this->artisan('module:test', ['--shard' => '2/2'])->assertSuccessful();

        expect($this->order)->toBe(['modules/Contact', 'modules/Sample', 'modules/Example']);
    });

    it('weighs a module by the tests it holds', function () {
        tests_on_disk('modules/Sample/tests/Unit/HeavyTest.php', 5);

        $this->artisan('module:test', ['--shard' => '1/2'])->assertSuccessful();

        expect($this->order)->toBe(['modules/Sample']);
    });

    it('weighs a target named as a file by the tests in it', function () {
        tests_on_disk('modules/Sample/tests/Unit/HeavyTest.php', 5);

        $this->artisan('module:test', [
            'modules' => ['Contact', 'Example', 'modules/Sample/tests/Unit/HeavyTest.php'],
            '--shard' => '1/2',
        ])->assertSuccessful();

        expect($this->order)->toBe(['modules/Sample/tests/Unit/HeavyTest.php']);
    });

    it('weighs a module by the seconds a scoreboard recorded for it', function () {
        tests_on_disk('modules/Sample/tests/Unit/HeavyTest.php', 5);
        tests_on_disk('modules/Example/tests/Unit/LightTest.php', 1);

        $scoreboard = base_path('durations-test.json');
        File::put($scoreboard, json_encode(['modules' => [
            ['name' => 'Example', 'seconds' => 600.0],
            ['name' => 'Sample', 'seconds' => 10.0],
            ['name' => 'Contact', 'seconds' => 'unknown'],
        ]]));
        test()->beforeApplicationDestroyed(fn () => File::delete($scoreboard));

        $this->artisan('module:test', ['--shard' => '1/2', '--durations' => 'durations-test.json'])->assertSuccessful();

        expect($this->order)->toBe(['modules/Example']);
    });

    it('takes its part of the window --limit leaves', function () {
        $this->artisan('module:test', ['--limit' => '2', '--shard' => '2/2'])->assertSuccessful();

        expect($this->order)->toBe(['modules/Example']);
    });

    it('says so and passes when nothing falls to its part', function () {
        $this->artisan('module:test', ['--shard' => '4/4'])
            ->expectsOutputToContain('Nothing falls to shard 4/4.')
            ->assertSuccessful();

        expect($this->order)->toBe([]);
    });

    it('refuses a shard it cannot read', function (string $shard) {
        $this->artisan('module:test', ['--shard' => $shard])
            ->expectsOutputToContain("A shard is written as index/total, as in 2/4 — [{$shard}] is not.")
            ->assertFailed();

        expect($this->order)->toBe([]);
    })->with([
        'no total' => ['2'],
        'a part before the first' => ['0/2'],
        'a part past the last' => ['3/2'],
    ]);

    it('refuses durations that are not a scoreboard', function () {
        $this->artisan('module:test', ['--shard' => '1/2', '--durations' => 'nowhere.json'])
            ->expectsOutputToContain('No scoreboard at [nowhere.json].')
            ->assertFailed();

        expect($this->order)->toBe([]);
    });
});
// ---------------------------------------------------------------------------
// --mins: a floor of a module's own, over the one --min gives the rest
// ---------------------------------------------------------------------------

describe('--mins', function () {
    it('holds a named module to its own floor and the rest to --min', function () {
        $example = ScopedCoverageReport::fileFor('Example');
        $contact = ScopedCoverageReport::fileFor('Contact');

        child_says([], $example, clover_xml('Example', [1 => 1, 2 => 0]));
        child_says([], $contact, clover_xml('Contact', [1 => 1, 2 => 0]));

        $said = run_decorated([
            'modules' => ['Example'],
            '--totals' => true,
            '--min' => '10',
            '--mins' => ['Example:100'],
        ]);

        expect($said)->toContain('50.00%')->toContain('100.00%');
    });

    it('collects coverage without being asked to, having a floor to measure', function () {
        Process::fake();

        $this->artisan('module:test', ['modules' => ['Example'], '--mins' => ['Example:100']])
            ->assertFailed();

        Process::assertRan(fn ($process) => Arr::first(
            $process->command,
            fn ($token) => str_starts_with((string) $token, '--coverage'),
        ) !== null);
    });

    it('names each floor where the modules were not all held to one', function () {
        $clover = ScopedCoverageReport::fileFor('Example');

        child_says([], $clover, clover_xml('Example', [1 => 1, 2 => 0]));

        $said = run_decorated([
            'modules' => ['Example'],
            '--totals' => true,
            '--min' => '10',
            '--mins' => ['Example:100'],
        ]);

        // Each word of the line is coloured, so the sentence is never one run
        // of characters: the floor it names is what this is about.
        expect($said)
            ->toContain('below the floor:')
            ->toContain('50.00 %')
            ->toContain('100.00 %');

        expect($said)->not->toContain('below the 10.00 % floor');
    });

    it('leaves out a module named only in a floor, the floor waiting for a run that has it', function () {
        Process::fake();

        $this->artisan('module:test', ['modules' => ['Contact'], '--mins' => ['Example:100']])->run();

        Process::assertRanTimes(fn ($process) => in_array('modules/Contact', $process->command, true), 1);
        Process::assertRanTimes(fn ($process) => in_array('modules/Example', $process->command, true), 0);
    });

    it('does not run a module twice for being named both ways', function () {
        Process::fake();

        $this->artisan('module:test', ['modules' => ['Example'], '--mins' => ['Example:100']])->run();

        Process::assertRanTimes(fn () => true, 1);
    });

    it('adds nothing to a run that is already every module', function () {
        Process::fake();

        $this->artisan('module:test', ['--mins' => ['Example:100']])->run();

        Process::assertRanTimes(fn () => true, 3);
    });

    it('takes a floor written on the target itself', function () {
        $clover = ScopedCoverageReport::fileFor('Example');

        child_says([], $clover, clover_xml('Example', [1 => 1, 2 => 0]));

        $said = run_decorated(['modules' => ['Example:100'], '--totals' => true]);

        expect($said)->toContain('50.00%')->toContain('100.00%');
    });

    it('collects coverage for a floor written on the target, nothing else having asked', function () {
        Process::fake();

        $this->artisan('module:test', ['modules' => ['Example:100']])->run();

        Process::assertRan(fn ($process) => Arr::first(
            $process->command,
            fn ($token) => str_starts_with((string) $token, '--coverage'),
        ) !== null);
    });

    it('runs the module the floor was written on, not a target named after the pair', function () {
        Process::fake();

        $this->artisan('module:test', ['modules' => ['Example:100']])->run();

        Process::assertRan(fn ($process) => in_array('modules/Example', $process->command, true));
    });

    it('leaves a stray number where --min lost its equals sign', function () {
        Process::fake();

        $this->artisan('module:test', ['modules' => ['Example', '100']])->run();

        Process::assertRanTimes(fn () => true, 1);
    });

    it('refuses a floor written on a path, which names no module to hold', function () {
        Process::fake();

        $this->artisan('module:test', ['modules' => ['modules/Example:100']])
            ->assertFailed()
            ->expectsOutputToContain('writes a floor on a path');

        Process::assertNothingRan();
    });

    it('lets the floor on the target win over the one --mins gives the same module', function () {
        $clover = ScopedCoverageReport::fileFor('Example');

        child_says([], $clover, clover_xml('Example', [1 => 1, 2 => 0]));

        $said = run_decorated([
            'modules' => ['Example:70'],
            '--totals' => true,
            '--mins' => ['Example:90'],
        ]);

        expect($said)->toContain('70.00%');

        expect($said)->not->toContain('90.00%');
    });

    it('passes a score sitting exactly on its floor', function () {
        $clover = ScopedCoverageReport::fileFor('Example');

        child_says([], $clover, clover_xml('Example', [1 => 1, 2 => 0]));

        $said = run_decorated(['modules' => ['Example:50'], '--totals' => true]);

        expect($said)->toContain('50.00%');
        expect($said)->not->toContain('below the floor');
    });

    it('refuses a score a hundredth under its floor, and says so with the floor it was held to', function () {
        $clover = ScopedCoverageReport::fileFor('Example');

        child_says([], $clover, clover_xml('Example', [1 => 1, 2 => 0]));

        $said = run_decorated(['modules' => ['Example:50.01'], '--totals' => true]);

        expect($said)->toContain('50.00%')->toContain('50.01%')->toContain('below the floor');
    });

    it('settles a floor finer than the row on the hundredth the row shows', function (string $floor, string $shown, bool $clears) {
        $clover = ScopedCoverageReport::fileFor('Example');

        child_says([], $clover, clover_xml('Example', [1 => 1, 2 => 0]));

        $said = run_decorated(['modules' => ["Example:{$floor}"], '--totals' => true]);

        expect($said)->toContain('50.00%')->toContain($shown);

        // Whatever it settles on, the row and the verdict say the same thing.
        $clears
            ? expect($said)->not->toContain('below the floor')
            : expect($said)->toContain('below the floor');
    })->with([
        'below the halfway mark it rounds down to the score, and clears' => ['50.004', '50.00%', true],
        'at the halfway mark it rounds up, and does not' => ['50.005', '50.01%', false],
        'above it, the same' => ['50.009', '50.01%', false],
    ]);

    it('refuses a floor that names no percentage', function () {
        Process::fake();

        $this->artisan('module:test', ['--mins' => ['Example']])
            ->assertFailed()
            ->expectsOutputToContain('it names no percentage');

        Process::assertNothingRan();
    });

    it('refuses a percentage that is not one', function () {
        Process::fake();

        $this->artisan('module:test', ['--mins' => ['Example:soon']])
            ->assertFailed()
            ->expectsOutputToContain('is not a percentage');

        Process::assertNothingRan();
    });

    it('refuses a floor that names no module', function () {
        Process::fake();

        $this->artisan('module:test', ['--mins' => [':100']])
            ->assertFailed()
            ->expectsOutputToContain('it names no module');

        Process::assertNothingRan();
    });

    it('refuses a floor written on a target that is not a module here', function () {
        Process::fake();

        $this->artisan('module:test', ['modules' => ['Nowhere:100']])
            ->assertFailed()
            ->expectsOutputToContain('[Nowhere] is not a module here');

        Process::assertNothingRan();
    });

    it('refuses a floor on a module that is not here, rather than never applying it', function () {
        Process::fake();

        $this->artisan('module:test', ['--mins' => ['Nowhere:100']])
            ->assertFailed()
            ->expectsOutputToContain('[Nowhere] is not a module here');

        Process::assertNothingRan();
    });
});

// ---------------------------------------------------------------------------
// --mins from a file
// ---------------------------------------------------------------------------

describe('--mins from a file', function () {
    beforeEach(function () {
        $this->floors = base_path('coverage-floors-test.txt');
    });

    afterEach(function () {
        if (is_file($this->floors)) {
            unlink($this->floors);
        }
    });

    it('reads a floor a line at a time out of the file', function () {
        file_put_contents($this->floors, "Example:100\nContact:25\n");

        $clover = ScopedCoverageReport::fileFor('Example');

        child_says([], $clover, clover_xml('Example', [1 => 1, 2 => 0]));

        $said = run_decorated([
            'modules' => ['Example'],
            '--totals' => true,
            '--mins' => ['@coverage-floors-test.txt'],
        ]);

        expect($said)->toContain('50.00%')->toContain('100.00%');
    });

    it('skips what is there to be read rather than held to', function () {
        file_put_contents($this->floors, <<<'TEXT'
            # Everything not named here is held to --min.

            Example: 100   # aligned, annotated, and still a floor
            TEXT);

        $clover = ScopedCoverageReport::fileFor('Example');

        child_says([], $clover, clover_xml('Example', [1 => 1, 2 => 0]));

        $said = run_decorated([
            'modules' => ['Example'],
            '--totals' => true,
            '--mins' => ['@coverage-floors-test.txt'],
        ]);

        expect($said)->toContain('50.00%')->toContain('100.00%');
    });

    it('takes floors from a file and the command line together', function () {
        file_put_contents($this->floors, "Example:100\n");

        Process::fake();

        $this->artisan('module:test', [
            '--mins' => ['@coverage-floors-test.txt', 'Contact:25'],
            '--min' => '0',
        ])->run();

        Process::assertRanTimes(fn () => true, 3);
    });

    it('refuses a file that is not there, rather than a run held to nothing', function () {
        Process::fake();

        $this->artisan('module:test', ['--mins' => ['@nowhere/floors.txt']])
            ->expectsOutputToContain('No floors file at [nowhere/floors.txt].')
            ->assertFailed();
    });
});

// ---------------------------------------------------------------------------
// --root
// ---------------------------------------------------------------------------

describe('--root', function () {
    it('leaves the root suite out of a run that did not ask for it', function () {
        Process::fake();

        $this->artisan('module:test')->run();

        Process::assertNotRan(fn ($process) => in_array('tests', $process->command, true));
    });

    it('runs the root suite alongside every module', function () {
        Process::fake();

        $this->artisan('module:test', ['--root' => true])->run();

        Process::assertRanTimes(fn () => true, 4);
        Process::assertRanTimes(fn ($process) => in_array('tests', $process->command, true), 1);
    });

    it('runs it beside a named module, which is the whole of what was asked for', function () {
        Process::fake();

        $this->artisan('module:test', ['modules' => ['Example'], '--root' => true])->run();

        Process::assertRanTimes(fn () => true, 2);
    });

    it('does not run it twice for being named as a target as well', function () {
        Process::fake();

        $this->artisan('module:test', ['modules' => ['Root'], '--root' => true])->run();

        Process::assertRanTimes(fn () => true, 1);
    });

    it('measures it against the root app/, which is the tree no module covers', function () {
        $clover = ScopedCoverageReport::fileFor('Root');

        child_says([], $clover, clover_xml_for(base_path('app/Foo.php'), [1 => 1, 2 => 0]));

        expect(run_decorated(['modules' => ['Root'], '--totals' => true, '--root' => true]))
            ->toContain('Root')
            ->toContain('50.00%');
    });

    it('holds it to a floor of its own, named the way a module is', function () {
        $clover = ScopedCoverageReport::fileFor('Root');

        child_says([], $clover, clover_xml_for(base_path('app/Foo.php'), [1 => 1, 2 => 0]));

        $said = run_decorated([
            'modules' => ['Root'],
            '--totals' => true,
            '--root' => true,
            '--mins' => ['Root:100'],
        ]);

        expect($said)->toContain('50.00%')->toContain('100.00%');
    });

    it('counts the rows as targets, the root not being a module', function () {
        child_writes_coverage_for(['Example' => [1 => 1], 'Root' => [1 => 1]]);

        expect(run_decorated(['modules' => ['Example'], '--totals' => true, '--root' => true]))
            ->toContain('2 targets');
    });

    it('anchors its --parallel filter, which would otherwise take every module with it', function () {
        Process::fake();

        $this->artisan('module:test', ['modules' => ['Root'], '--root' => true, '--parallel' => true])->run();

        Process::assertRan(fn ($process) => in_array('--filter=/^Tests/', $process->command, true));
    });
});

// ---------------------------------------------------------------------------
// --export
// ---------------------------------------------------------------------------

describe('--export', function () {
    beforeEach(function () {
        $this->file = sys_get_temp_dir().'/module-test-export/run.csv';
    });

    afterEach(function () {
        foreach (glob(dirname($this->file).'/*') ?: [] as $written) {
            unlink($written);
        }

        if (is_dir(dirname($this->file))) {
            rmdir(dirname($this->file));
        }
    });

    it('writes the scoreboard to the file it was given, and says where', function () {
        child_writes_coverage('Example', [1 => 1, 2 => 0]);

        $this->artisan('module:test', ['modules' => ['Example'], '--export' => $this->file])
            ->expectsOutputToContain('Scoreboard written to')
            ->assertSuccessful();

        expect(file_get_contents($this->file))
            ->toContain('module,coverage')
            ->toContain('Example,50.0');
    });

    it('collects the scoreboard without being asked for it', function () {
        child_writes_coverage('Example', [1 => 1]);

        $this->artisan('module:test', ['modules' => ['Example'], '--export' => $this->file])
            ->doesntExpectOutputToContain('Testing Example')
            ->assertSuccessful();
    });

    it('writes JSON where the name says nothing about the format', function () {
        child_writes_coverage('Example', [1 => 1, 2 => 0]);

        $this->artisan('module:test', ['modules' => ['Example'], '--export' => dirname($this->file).'/run'])
            ->expectsOutputToContain('run.json')
            ->assertSuccessful();

        expect(json_decode(file_get_contents(dirname($this->file).'/run.json'), true))
            ->toHaveKeys(['generated_at', 'modules', 'total']);
    });

    it('returns FAILURE and runs nothing for a format it cannot write', function () {
        Process::fake();

        $this->artisan('module:test', ['modules' => ['Example'], '--export' => 'run.xlsx'])
            ->expectsOutputToContain('Cannot write [run.xlsx]')
            ->assertFailed();

        Process::assertNothingRan();
    });

    it('writes nothing where the run ended before any module finished', function () {
        Process::fake();

        $this->registry->exchangeArray([]);

        $this->artisan('module:test', ['--export' => $this->file])
            ->doesntExpectOutputToContain('Scoreboard written to')
            ->assertSuccessful();

        expect(is_file($this->file))->toBeFalse();
    });
});

// ---------------------------------------------------------------------------
// What the child says on its way
// ---------------------------------------------------------------------------

describe('child output', function () {
    it('streams what the child printed straight through', function () {
        child_says(["Tests:  3 passed\n"]);

        $this->artisan('module:test', ['modules' => ['Example']])
            ->expectsOutputToContain('Tests:  3 passed')
            ->assertSuccessful();
    });

    it('drops what the child printed on a scoreboard and ticks the clock instead', function () {
        $clover = ScopedCoverageReport::fileFor('Example');

        child_says(["Tests:  3 passed\n"], $clover, clover_xml('Example', [1 => 1]));

        expect(run_decorated(['modules' => ['Example'], '--totals' => true]))
            ->not->toContain('Tests:  3 passed')
            ->toContain('100.00%');
    });

    it('asks the child for compact output, having no use for a line per test', function () {
        Process::fake();

        $this->artisan('module:test', ['modules' => ['Example'], '--totals' => true])->assertSuccessful();

        Process::assertRan(fn ($process) => in_array('--compact', $process->command, true));
    });

    it('leaves a run that is not a scoreboard to draw itself', function () {
        Process::fake();

        $this->artisan('module:test', ['modules' => ['Example']])->assertSuccessful();

        Process::assertRan(fn ($process) => ! in_array('--compact', $process->command, true));
    });

    it('gives back the failures a module reported, under the result', function () {
        $clover = ScopedCoverageReport::fileFor('Example');

        child_says([
            "  ..⨯..\n",
            "  ────────\n",
            "   FAILED  it adds two numbers\n",
            "  Failed asserting that 3 is 4.\n",
            "\n",
            "  Tests:    1 failed, 4 passed\n",
            "  Duration: 21.00s\n",
        ], $clover, clover_xml('Example', [1 => 1]), 1);

        $said = run_decorated(['modules' => ['Example'], '--totals' => true]);

        expect($said)
            ->toContain('tests failed in')
            ->toContain('FAILED  it adds two numbers')
            ->toContain('Failed asserting that 3 is 4.');

        // The row above already carries the score and what the module cost, so
        // the child's progress and its own closing summary would say both again.
        expect($said)->not->toContain('..⨯..', '1 failed, 4 passed', 'Duration: 21.00s');
    });

    it('hands back the whole account where no failure can be picked out of it', function () {
        $clover = ScopedCoverageReport::fileFor('Example');

        child_says(["PHP Fatal error:  Allowed memory size exhausted\n"], $clover, clover_xml('Example', [1 => 1]), 1);

        expect(run_decorated(['modules' => ['Example'], '--totals' => true]))
            ->toContain('Allowed memory size exhausted');
    });

    it('says nothing on a scoreboard nobody can redraw', function () {
        $clover = ScopedCoverageReport::fileFor('Example');

        child_says(["Tests:  3 passed\n"], $clover, clover_xml('Example', [1 => 1]));

        $this->artisan('module:test', ['modules' => ['Example'], '--totals' => true])
            ->doesntExpectOutputToContain('Tests:  3 passed')
            ->assertSuccessful();
    });
});

// ---------------------------------------------------------------------------
// ModuleTestCommand::getInfo()
// ---------------------------------------------------------------------------

describe('getInfo()', function () {
    it('returns a static status message', function () {
        expect((new ModuleTestCommand)->getInfo())->toBe('Testing module(s)...');
    });
});
