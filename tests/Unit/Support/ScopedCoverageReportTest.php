<?php

use Oki\ModuleTest\Support\ScopedCoverageReport;

/*
| The report is read from Clover rather than from what the child printed, so
| everything below hands it a Clover document and reads back what it makes of
| it. Written to a real file because that is what the command has: a path the
| test run wrote to.
*/

/**
 * A Clover document holding these files, each as statement lines with a run
 * count — which is all the report reads.
 *
 * @param  array<string, array<int, int>>  $files  Path, then line number => times run.
 */
function clover(array $files): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8"?><coverage><project>';

    foreach ($files as $path => $lines) {
        $statements = count($lines);
        $covered = count(array_filter($lines));

        $xml .= '<file name="'.base_path($path).'">';

        foreach ($lines as $number => $count) {
            $xml .= '<line num="'.$number.'" type="stmt" count="'.$count.'"/>';
        }

        $xml .= '<metrics statements="'.$statements.'" coveredstatements="'.$covered.'"/></file>';
    }

    $path = ScopedCoverageReport::fileFor('test-'.bin2hex(random_bytes(6)));

    file_put_contents($path, $xml.'</project></coverage>');

    written_reports()[] = $path;

    return $path;
}

/**
 * The reports this file wrote, so only they are cleaned up.
 *
 * The directory is shared with every other run in the suite — a parallel
 * worker's report lives beside these, and deleting the whole directory's
 * `coverage-*.xml` takes that one out from under the command reading it.
 *
 * @return ArrayObject<int, string>
 */
function written_reports(): ArrayObject
{
    static $written;

    return $written ??= new ArrayObject;
}

// Each document above is written to a real file, since a path is what the
// command has to hand the report.
afterEach(function () {
    foreach (written_reports() as $written) {
        if (is_file($written)) {
            unlink($written);
        }
    }

    written_reports()->exchangeArray([]);
});

/** A report over the Person module. */
function person_report(): ScopedCoverageReport
{
    return new ScopedCoverageReport('modules/Person', 'Person');
}

describe('took()', function () {
    it('never says a run took no time at all', function () {
        expect(ScopedCoverageReport::took(0.2))->toBe('1s');
    });

    it('counts whole seconds under a minute', function () {
        expect(ScopedCoverageReport::took(42.4))->toBe('42s');
    });

    it('puts the minutes in front once there are any', function () {
        expect(ScopedCoverageReport::took(90.0))->toBe('1m 30s')
            ->and(ScopedCoverageReport::took(3600.0))->toBe('60m 0s');
    });
});

describe('total()', function () {
    it('counts only the files belonging to the scoped path', function () {
        $clover = clover([
            'modules/Person/app/Models/Person.php' => [10 => 1, 11 => 1],
            'modules/Contact/app/Models/Contact.php' => [10 => 0, 11 => 0],
        ]);

        expect(person_report()->total($clover))->toBe(100.0);
    });

    it('weighs a long file more heavily than a short one', function () {
        // Two files, one line uncovered in each: 8 of 10 statements, not the
        // 70.00 % that averaging 60.0 and 80.0 would give.
        $clover = clover([
            'modules/Person/app/Models/Person.php' => [1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 0],
            'modules/Person/app/Queries/People.php' => [1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 0],
        ]);

        expect(person_report()->total($clover))->toBe(80.0);
    });

    it('is 0.0 when nothing in the report belongs to the scoped path', function () {
        expect(person_report()->total(clover(['modules/Contact/app/Models/Contact.php' => [10 => 1]])))->toBe(0.0);
    });

    it('is 0.0 when there is no report to read', function () {
        expect(person_report()->total(storage_path('framework/testing/nothing-wrote-this.xml')))->toBe(0.0)
            ->and(person_report()->total(__FILE__))->toBe(0.0);
    });
});

describe('counts()', function () {
    it('gives back the statement counts behind the percentage, scoped', function () {
        $clover = clover([
            'modules/Person/app/Models/Person.php' => [1 => 1, 2 => 0],
            'modules/Contact/app/Models/Contact.php' => [1 => 1],
        ]);

        expect(person_report()->counts($clover))->toBe(['statements' => 2, 'covered' => 1]);
    });

    it('is all zeroes when there is no report to read', function () {
        expect(person_report()->counts(storage_path('framework/testing/nothing-wrote-this.xml')))
            ->toBe(['statements' => 0, 'covered' => 0]);
    });
});

describe('renderCombinedTotal()', function () {
    it('names how many modules it covers and weighs them by statement', function () {
        expect(ScopedCoverageReport::renderCombinedTotal(['statements' => 4, 'covered' => 3], 2))
            ->toContain('Total (2 modules): 75.00%');
    });

    it('is 0.0 when there were no statements at all', function () {
        expect(ScopedCoverageReport::renderCombinedTotal(['statements' => 0, 'covered' => 0], 2))
            ->toContain('Total (2 modules): 0.00%');
    });
});

describe('render()', function () {
    it('draws a row for each file, in path order', function () {
        $clover = clover([
            'modules/Person/app/Queries/People.php' => [1 => 1],
            'modules/Person/app/Models/Person.php' => [1 => 1],
        ]);

        expect(person_report()->render($clover))->toMatch('#Models/Person[\s\S]+Queries/People#');
    });

    it('drops the .php from a name, as Pest does', function () {
        $rendered = person_report()->render(clover(['modules/Person/app/Models/Person.php' => [1 => 1]]));

        expect($rendered)->toContain('modules/Person/app/Models/Person');
        expect($rendered)->not->toContain('Person.php');
    });

    it('writes a run of uncovered lines as a range and a lone one on its own', function () {
        $clover = clover([
            'modules/Person/app/Models/Person.php' => [12 => 0, 13 => 0, 14 => 0, 20 => 1, 30 => 0],
        ]);

        expect(person_report()->render($clover))->toContain('12..14, 30');
    });

    it('says nothing about uncovered lines where there are none', function () {
        $rendered = person_report()->render(clover(['modules/Person/app/Models/Person.php' => [1 => 1]]));

        expect($rendered)->toContain('100.00%');
        expect($rendered)->not->toContain('/ 100.00%');
    });

    it('counts a file with nothing to run as covered, the way PHPUnit does', function () {
        expect(person_report()->render(clover(['modules/Person/app/Models/Person.php' => []])))->toContain('100.00%')
            ->and(person_report()->total(clover(['modules/Person/app/Models/Person.php' => []])))->toBe(0.0);
    });

    it('prints the total under the module own name', function () {
        expect(person_report()->render(clover(['modules/Person/app/Models/Person.php' => [1 => 1, 2 => 0]])))
            ->toContain('Total (Person): 50.00%');
    });

    it('says how long the run took beside the total when it is told', function () {
        $rendered = person_report()->render(clover(['modules/Person/app/Models/Person.php' => [1 => 1]]), '4s');

        expect($rendered)->toContain('DONE')
            ->toContain('4s')
            ->toContain('Total (Person): 100.00%');
    });

    it('says a missing report is missing rather than calling it nothing covered', function () {
        $rendered = person_report()->render(storage_path('framework/testing/nothing-wrote-this.xml'));

        expect($rendered)->toContain('No coverage report was written');
        expect($rendered)->not->toContain('0.00%');
    });

    it('says the same of a report it cannot read', function () {
        expect(person_report()->render(__FILE__))->toContain('No coverage report was written');
    });
});

describe('renderScore()', function () {
    it('draws the module and its score as one row', function () {
        expect(ScopedCoverageReport::renderScore('Person', 89.14))
            ->toContain('Person')
            ->toContain('89.14%');
    });

    it('carries the floor beside the score where there is one to meet', function () {
        expect(ScopedCoverageReport::renderScore('Person', 89.14, 100.0))
            ->toContain('89.14%')
            ->toContain('100.00%');
    });

    it('says nothing about a floor where none was asked for', function () {
        expect(ScopedCoverageReport::renderScore('Person', 89.14))
            ->not->toContain('100.00%');
    });

    it('counts the row out of the run and says what it cost', function () {
        expect(ScopedCoverageReport::renderScore('Person', 50.0, null, '2/7', '3s'))
            ->toContain('2/7')
            ->toContain('3s');
    });
});

describe('renderPending()', function () {
    it('says the module is running before there is a score for it', function () {
        expect(ScopedCoverageReport::renderPending('Person'))
            ->toContain('Person')
            ->toContain('running');
    });

    it('counts the row out of the run and says how long it has been going', function () {
        expect(ScopedCoverageReport::renderPending('Person', '2/7', '12s'))
            ->toContain('2/7')
            ->toContain('running 12s');
    });
});

describe('fileFor()', function () {
    it('gives each module a report of its own, in the same place every run', function () {
        expect(ScopedCoverageReport::fileFor('Person'))->toBe(ScopedCoverageReport::fileFor('Person'));
        expect(ScopedCoverageReport::fileFor('Person'))->not->toBe(ScopedCoverageReport::fileFor('Contact'));
    });

    it('writes it where a test run may write', function () {
        expect(dirname(ScopedCoverageReport::fileFor('Person')))->toBeDirectory();
    });

    it('makes that place where the install has never had one', function () {
        $storage = sys_get_temp_dir().'/storage-'.bin2hex(random_bytes(6));

        app()->useStoragePath($storage);

        expect(dirname(ScopedCoverageReport::fileFor('Person')))->toBeDirectory();

        // Nothing else in the suite should inherit a storage path that is
        // about to be deleted.
        app()->useStoragePath(base_path('storage'));
        exec('rm -rf '.escapeshellarg($storage));
    });
});

describe('reached()', function () {
    it('never says more coverage than there is', function (float $coverage, string $said) {
        expect(ScopedCoverageReport::reached($coverage))->toBe($said);
    })->with([
        'a whole number keeps its decimals' => [100.0, '100.00'],
        'nothing is nothing' => [0.0, '0.00'],
        'a hundredth is exact' => [88.88, '88.88'],
        'the hundredth below is kept, not rounded up' => [99.996, '99.99'],
        'and a whole is never claimed from short of one' => [99.999, '99.99'],
        'nor from a hair short of one' => [99.999999, '99.99'],
        'a trace of coverage is not a hundredth of it' => [0.001, '0.00'],
        'a binary hundredth is not lost' => [8.07, '8.07'],
        'nor is one that lands on the boundary' => [28.29, '28.29'],
        'two thirds is not two thirds and a bit' => [66.666666, '66.66'],
        'the run that found this, 157 of 555' => [28.28828828828829, '28.28'],
    ]);
});

describe('asked()', function () {
    it('writes a floor to two decimals, to the nearest', function (float $minimum, string $asked) {
        expect(ScopedCoverageReport::asked($minimum))->toBe($asked);
    })->with([
        'a whole floor is padded out' => [100.0, '100.00'],
        'a hundredth is exact already' => [99.99, '99.99'],
        'a tenth is padded, not cut' => [50.5, '50.50'],
        'zero is zero' => [0.0, '0.00'],
        'below the halfway mark it keeps the hundredth it is in' => [28.284, '28.28'],
        'at the halfway mark it goes to the next' => [28.285, '28.29'],
        'and above it, the same' => [28.289, '28.29'],
    ]);
});

describe('a score against a floor', function () {
    // 157 of 555 statements: the module that found the two floors below
    // printing the same row and disagreeing about it.
    $auth = 157 / 555 * 100;

    it('settles on the two figures the row shows, not the ones behind them', function (float $minimum, bool $clears) use ($auth) {
        expect(ScopedCoverageReport::scoreOf($auth) >= ScopedCoverageReport::floorOf($minimum))->toBe($clears);
    })->with([
        'a floor that rounds below the score clears it' => [28.284, true],
        'a floor that rounds to the score clears it' => [28.28, true],
        'a floor that rounds above it does not' => [28.285, false],
        'nor does the one beside it that reads the same' => [28.289, false],
        'nor the hundredth itself' => [28.29, false],
    ]);

    it('says the same thing on screen as it settled on', function (float $minimum) use ($auth) {
        $score = ScopedCoverageReport::reached($auth);
        $floor = ScopedCoverageReport::asked($minimum);

        expect($score >= $floor)->toBe(
            ScopedCoverageReport::scoreOf($auth) >= ScopedCoverageReport::floorOf($minimum),
        );
    })->with([[28.284], [28.285], [28.289], [28.28], [28.29], [100.0], [0.0]]);
});
