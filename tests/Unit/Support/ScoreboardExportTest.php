<?php

use SolomonOchepa\ModuleTest\Support\ScoreboardExport;

/*
| The export is the scoreboard kept rather than drawn, so everything below
| writes a real file and reads it back — a format is only right if something
| else can open it. The files land in a directory of this file's own, removed
| after each test, and one test writes into a directory that does not exist
| yet because that is what a caller naming `reports/run.txt` does.
*/

/** Where this file's exports are written. */
function scoreboard_directory(): string
{
    return sys_get_temp_dir().'/scoreboard-export-test';
}

/** A path inside it, for a file named by its format. */
function scoreboard_file(string $name): string
{
    return scoreboard_directory().'/'.$name;
}

beforeEach(function () {
    $this->export = new ScoreboardExport;

    $this->export->add('Example', 100.0, ['statements' => 8, 'covered' => 8], 3.0, '1/2');
    $this->export->add('Sample', 0.0, ['statements' => 2, 'covered' => 0], 90.0, '2/2');
});

afterEach(function () {
    foreach (glob(scoreboard_directory().'/*') ?: [] as $written) {
        unlink($written);
    }

    if (is_dir(scoreboard_directory())) {
        rmdir(scoreboard_directory());
    }
});

describe('resolve()', function () {
    it('names an unnamed scoreboard and writes it as JSON', function () {
        expect(ScoreboardExport::resolve(null))->toBe('module-test.json')
            ->and(ScoreboardExport::resolve(' '))->toBe('module-test.json');
    });

    it('keeps the default name behind a bare extension', function () {
        expect(ScoreboardExport::resolve('.png'))->toBe('module-test.png');
    });

    it('writes a name with no extension as JSON', function () {
        expect(ScoreboardExport::resolve('coverage'))->toBe('coverage.json');
    });

    it('keeps the directory a name was given in', function () {
        expect(ScoreboardExport::resolve('build/reports/run.csv'))->toBe('build/reports/run.csv');
    });
});

describe('writes()', function () {
    it('accepts every extension it has an exporter for, whatever its case', function () {
        expect(ScoreboardExport::writes('run.md'))->toBeTrue()
            ->and(ScoreboardExport::writes('run.JPEG'))->toBeTrue();
    });

    it('rejects an extension it cannot write', function () {
        expect(ScoreboardExport::writes('run.xlsx'))->toBeFalse();
    });
});

describe('formats()', function () {
    it('names the formats it writes', function () {
        expect(ScoreboardExport::formats())->toBe(['txt', 'md', 'json', 'csv', 'pdf', 'png', 'jpg', 'jpeg']);
    });
});

describe('isEmpty()', function () {
    it('has no scoreboard before any module finishes', function () {
        expect((new ScoreboardExport)->isEmpty())->toBeTrue();
    });

    it('has one once a module is recorded', function () {
        expect($this->export->isEmpty())->toBeFalse();
    });
});

describe('coverage()', function () {
    it('weighs covered statements across every module rather than averaging their scores', function () {
        // 8 of 10 statements, not the 50.00% averaging 100.0 and 0.0 would give.
        expect($this->export->coverage())->toBe(80.0);
    });

    it('is zero where nothing executable was measured', function () {
        $export = new ScoreboardExport;
        $export->add('Empty', 0.0, ['statements' => 0, 'covered' => 0], 1.0);

        expect($export->coverage())->toBe(0.0);
    });
});

describe('write()', function () {
    it('refuses a format it has no exporter for', function () {
        expect(fn () => $this->export->write(scoreboard_file('run.xlsx'), 93.0))
            ->toThrow(InvalidArgumentException::class, 'No exporter for');
    });

    it('creates the directory a name was given in', function () {
        $file = scoreboard_directory().'/run.txt';

        expect(is_dir(scoreboard_directory()))->toBeFalse();

        $this->export->write($file, 93.0);

        expect($file)->toBeReadableFile();
    });
});

describe('text', function () {
    it('writes the scoreboard the terminal drew, with the colours dropped', function () {
        $file = scoreboard_file('run.txt');

        $this->export->write($file, 93.0);
        $text = file_get_contents($file);

        expect($text)
            ->toContain('1/2 Example ')
            ->toContain('100.00% | 3s')
            ->toContain('2/2 Sample ')
            ->toContain('0.00% | 1m 30s')
            ->toContain('Total (2 modules)')
            ->toContain('80.00% | 1m 33s');

        expect(str_contains($text, "\033"))->toBeFalse('the exported text still carries the terminal colours');
    });

    it('lines the scores up under each other', function () {
        $file = scoreboard_file('run.txt');

        $this->export->write($file, 93.0);

        $ends = collect(explode(PHP_EOL, trim(file_get_contents($file))))
            ->map(fn (string $line): int => mb_strlen($line))
            ->unique();

        expect($ends)->toHaveCount(1);
    });

    it('leaves the counter off a run that was not counting', function () {
        $export = new ScoreboardExport;
        $export->add('Example', 50.0, ['statements' => 2, 'covered' => 1], 3.0);

        $file = scoreboard_file('run.txt');
        $export->write($file, 3.0);

        expect(file_get_contents($file))->toStartWith('Example ');
    });
});

describe('markdown', function () {
    it('writes a table whose last row is the run\'s own total', function () {
        $file = scoreboard_file('run.md');

        $this->export->write($file, 93.0);
        $lines = explode(PHP_EOL, trim(file_get_contents($file)));

        expect($lines[0])->toBe('| Module | Coverage | Statements | Covered | Time |')
            ->and($lines[1])->toBe('| :--- | ---: | ---: | ---: | ---: |')
            ->and($lines[2])->toBe('| Example | 100.00% | 8 | 8 | 3s |')
            ->and(end($lines))->toBe('| **Total (2 modules)** | **80.00%** | **10** | **8** | **1m 33s** |');
    });

    it('carries a column for the floor the run was held to', function () {
        $export = new ScoreboardExport(90.0);
        $export->add('Example', 100.0, ['statements' => 8, 'covered' => 8], 3.0);

        $file = scoreboard_file('run.md');
        $export->write($file, 3.0);
        $lines = explode(PHP_EOL, trim(file_get_contents($file)));

        expect($lines[0])->toBe('| Module | Coverage | Minimum | Statements | Covered | Time |')
            ->and($lines[2])->toBe('| Example | 100.00% | 90.00% | 8 | 8 | 3s |');
    });
});

describe('json', function () {
    it('writes the scoreboard as records, with a total over the whole run', function () {
        $file = scoreboard_file('run.json');

        $this->export->write($file, 93.0);
        $payload = json_decode(file_get_contents($file), true);

        expect($payload['minimum'])->toBeNull()
            ->and($payload['generated_at'])->toBe(now()->toIso8601String())
            ->and($payload['modules'][0])->toBe([
                'name' => 'Example',
                'coverage' => 100.0,
                'statements' => 8,
                'covered' => 8,
                'seconds' => 3.0,
                'minimum' => null,
                'passed' => null,
            ])
            ->and($payload['total'])->toBe([
                'modules' => 2,
                'coverage' => 80.0,
                'statements' => 10,
                'covered' => 8,
                'seconds' => 93.0,
                'passed' => null,
            ]);
    });

    it('says of each score whether it cleared the floor', function () {
        $export = new ScoreboardExport(90.0);
        $export->add('Example', 100.0, ['statements' => 8, 'covered' => 8], 3.0);
        $export->add('Sample', 10.0, ['statements' => 10, 'covered' => 1], 3.0);

        $file = scoreboard_file('run.json');
        $export->write($file, 6.0);
        $payload = json_decode(file_get_contents($file), true);

        expect($payload['minimum'])->toBe(90.0)
            ->and($payload['modules'][0]['passed'])->toBeTrue()
            ->and($payload['modules'][1]['passed'])->toBeFalse()
            ->and($payload['total']['passed'])->toBeFalse();
    });
});

describe('csv', function () {
    it('writes one header, one row per module, and the total as the last row', function () {
        $file = scoreboard_file('run.csv');

        $this->export->write($file, 93.0);
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim(file_get_contents($file)))));

        expect($rows[0])->toBe(['module', 'coverage', 'minimum', 'statements', 'covered', 'seconds', 'passed'])
            ->and($rows[1])->toBe(['Example', '100.00', '', '8', '8', '3.0', ''])
            ->and($rows[3])->toBe(['Total (2 modules)', '80.00', '', '10', '8', '93.0', '']);
    });

    it('carries the floor and whether each score cleared it', function () {
        $export = new ScoreboardExport(90.0);
        $export->add('Example', 100.0, ['statements' => 8, 'covered' => 8], 3.0);

        $file = scoreboard_file('run.csv');
        $export->write($file, 3.0);
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim(file_get_contents($file)))));

        expect($rows[1])->toBe(['Example', '100.00', '90.00', '8', '8', '3.0', 'yes'])
            ->and($rows[2])->toBe(['Total (1 module)', '100.00', '90.00', '8', '8', '3.0', 'yes']);
    });

    it('measures a module against the floor it was given, not the run\'s', function () {
        $export = new ScoreboardExport(50.0);
        $export->add('Example', 80.0, ['statements' => 10, 'covered' => 8], 3.0, null, 100.0);
        $export->add('Sample', 60.0, ['statements' => 10, 'covered' => 6], 3.0);

        $file = scoreboard_file('run.csv');
        $export->write($file, 6.0);
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim((string) file_get_contents($file)))));

        expect($rows[1])->toBe(['Example', '80.00', '100.00', '10', '8', '3.0', 'no'])
            ->and($rows[2])->toBe(['Sample', '60.00', '50.00', '10', '6', '3.0', 'yes']);
    });
});

describe('pdf', function () {
    it('writes a single page, sized to the rows it holds', function () {
        $file = scoreboard_file('run.pdf');

        $this->export->write($file, 93.0);
        $pdf = file_get_contents($file);

        expect($pdf)->toStartWith('%PDF-1.4')
            ->toEndWith("%%EOF\n")
            ->toContain('/Count 1')
            ->toContain('/BaseFont/Courier')
            ->toMatch('#/MediaBox\[0 0 \d+ \d+\]#');
    });

    it('draws every module and the total on it', function () {
        $file = scoreboard_file('run.pdf');

        $this->export->write($file, 93.0);
        $pdf = file_get_contents($file);

        expect($pdf)
            ->toContain('(Example) Tj')
            ->toContain('(Sample) Tj')
            ->toContain('(100.00%) Tj')
            ->toContain('(0.00%) Tj');
    });

    it('escapes the punctuation a PDF string keeps for itself', function () {
        $export = new ScoreboardExport;
        $export->add('Ex(am)ple\\', 50.0, ['statements' => 2, 'covered' => 1], 3.0);

        $file = scoreboard_file('run.pdf');
        $export->write($file, 3.0);

        expect(file_get_contents($file))->toContain('(Ex\\(am\\)ple\\\\) Tj');
    });

    it('notes the floor beside each score where the run was given one', function () {
        $export = new ScoreboardExport(90.0);
        $export->add('Example', 100.0, ['statements' => 8, 'covered' => 8], 3.0);

        $file = scoreboard_file('run.pdf');
        $export->write($file, 3.0);

        expect(file_get_contents($file))->toContain('( / 90.00%) Tj');
    });
});

describe('image', function () {
    it('writes a PNG cropped to the text', function () {
        $file = scoreboard_file('run.png');

        $this->export->write($file, 93.0);

        [$width, $height, $type] = getimagesize($file);

        expect($type)->toBe(IMAGETYPE_PNG)
            ->and($width)->toBeGreaterThan(0)
            ->and($height)->toBeGreaterThan(0);
    });

    it('writes a JPEG under either spelling of the extension', function () {
        foreach (['run.jpg', 'run.jpeg'] as $name) {
            $file = scoreboard_file($name);

            $this->export->write($file, 93.0);

            expect(getimagesize($file)[2])->toBe(IMAGETYPE_JPEG);
        }
    });

    it('draws the scoreboard at twice the size of the bitmap font it is set in', function () {
        $file = scoreboard_file('run.png');

        $this->export->write($file, 93.0);

        [$width, $height] = getimagesize($file);
        $lines = 3; // Two modules and the total.

        expect($height)->toBe((imagefontheight(5) + 3) * $lines * 2 + 12 * 2 * 2)
            ->and($width % 2)->toBe(0);
    });
});
