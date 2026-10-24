# Laravel Module Test

`php artisan module:test` — run a [nwidart/laravel-modules](https://github.com/nWidart/laravel-modules)
application's suite a module at a time, with coverage scoped to each module, a floor per
module, and a scoreboard you can export.

```bash
composer require --dev oki-technologies/laravel-module-test
```

The service provider is discovered. Pest 4, Laravel 12 or 13, PHP 8.3+, and a coverage
driver (pcov or Xdebug) for anything that measures coverage. GD is needed only to export
the scoreboard as an image.

## Why run tests a module at a time

Pest's own `--min` is checked against the whole `<source>` tree in `phpunit.xml`, not
against the files the run touched — so a module-scoped run with `--min` either fails on
coverage it never went near, or passes on somebody else's. `module:test` scopes the report
to the module and enforces the floor against that instead. `ScopedCoverageReport` does the
scoping.

## How the coverage report is made

A scoped run asks the child for `--coverage-clover=<file>` instead of `--coverage`, and the
report is built from that XML. Three things follow.

**The total is Pest's own arithmetic, scoped.** Covered statements over executable
statements, for the module's files alone. It used to be the mean of the per-file
percentages, which let a three-line file weigh as much as a seven-hundred-line one and
rounded a module with uncovered lines up to `100.0%`.

**The run streams.** The child prints no coverage table of its own, so its progress reaches
the terminal as it happens rather than being held back until the end.

**Several modules add up.** A run naming more than one prints a combined
`Total (N modules): X%` under the last of them, weighed by statement across every module
in the run rather than averaged over their percentages. `--min` still applies per module,
so one thin module cannot be carried by a thorough one.

**A missing report is said out loud.** A run that dies before writing one reports that, not
`0.0%`. Reading what the child printed used to mean that anything which drew differently —
`laravel/pao` folds the whole table into one JSON line under an AI agent — came back as a
fully uncovered module.

## Using it

```bash
php artisan module:test -p                        # every registered module, one run each
php artisan module:test -p Workspace              # one module
php artisan module:test -p workspace tag          # several, by bare name
php artisan module:test -p Workspace --coverage --min=80
php artisan module:test -p Module Auth --coverage       # per-module tables, then a combined total
php artisan module:test modules/Workspace/tests/Unit/WorkspaceTest.php
php artisan module:test -t                        # a scoreboard: one row per module, nothing else
php artisan module:test -t --from=Tag --to=12     # picking a run back up where it left off
php artisan module:test -t --from=Tag --limit=5  # or just the next five of them
php artisan module:test -t --root                 # every module, and the root tests/ suite beside them
php artisan module:test -t --root Root            # the root suite on its own
php artisan module:test -p --export                # the same scoreboard, kept as module-test.json
php artisan module:test -p --export=coverage.png   # or as a picture of itself
```

**Pass `-p` unless something stops you.** It is Pest's own `--parallel`, and on this suite
it is roughly twice as quick — a module that takes two minutes serially takes one. Drop it
when you are chasing a failure whose output you want in order, or when the tests under it
share something a parallel run would have two workers both own.

A target is a module name, a path under `modules/` or `tests/`, or a file. A bare name is
studly-cased into `modules/{Name}`; a `name/rest/of/path` has only its first segment
studly-cased.

| Option | What it does |
| --- | --- |
| `--coverage` | Collect coverage, scoped to the module being tested; several modules also get a combined total |
| `--min=` | Coverage floor, checked against that module's own total (implies `--coverage`) |
| `--mins=` | A floor for named modules — `Module[,Module]:percentage` — over the one `--min` gives the rest; repeatable, or `@path` to read them out of a file (implies `--coverage`) |
| `--root` | Also run the root `tests/` suite, measured against the root `app/`, as a row called `Root` |
| `-t`, `--totals` | One row per module — its score, the floor beside it, and what it cost — and nothing the child printed but a failing module's failures (implies `--coverage` and `--compact`) |
| `--sort=` | Order several modules run in: `asc` (default), `desc`, `none` |
| `--from=`, `--to=` | Narrow the run to a window, both ends included; each end is a position in the run (`1` for the first) or a module name |
| `--limit=`, `--take=`, `--pick=` | Run at most this many modules, counted from the start of the run the window left; three names for one option |
| `--shard=` | Run one part of the run, as `index/total`: `2/4` is the second of four parts of about equal weight |
| `--durations=` | A scoreboard written by `--export=….json`, whose seconds weigh each module for `--shard` |
| `--export=` | Write the scoreboard to a file; the extension picks the format, and either half of the name may be left off (implies `--totals`) |
| `--artisan` | Run `php artisan test` rather than `vendor/bin/pest` |
| `-p` | Pest's `--parallel`. Use it by default |

Pest's own options pass straight through — `--parallel`, `--filter`, `--bail` and the rest.
With `--parallel` and a module named, the module becomes a `--filter` regex instead of a
positional path, because ParaTest's CLI does not accept one. That run also carries
`--do-not-fail-on-empty-test-suite`: ParaTest shards every test file across its workers and
applies the filter inside each one, so a worker holding nothing for this module executes no
tests, which PHPUnit reports as a failed run and ParaTest returns as the module's exit code.
Without it a module whose tests all pass is still named in `tests failed in …`, and whether
it is depends on how the shards happen to fall. Only the empty shard is excused — a real
failure still fails, and a module with no tests of its own still scores 0 % against the
coverage floor.

### The root suite

`modules/` is not the whole of the code. The root `app/` holds what no module owns — the
cross-cutting helpers, the registries every module registers into, the console commands — and
the root `tests/` holds its tests. A run over every module reaches none of it, so without
`--root` the one tree nothing else covers is the one tree nobody is measuring.

```bash
php artisan module:test -t --root          # every module, then the root suite
php artisan module:test -t --root Root     # the root suite alone
```

`--root` adds a row called `Root`; naming `Root` as a target is what narrows the run to it.
It is a target like any other from there on: it sorts into the run, `--from`/`--to`/`--limit`
count it, and it takes a floor under that name (`--mins=Root:14`, or `Root:14` written on the
target). The option is a flag rather than a name for the row, because an option that takes a
value takes the token after it with or without an `=` — a nameable `--root` would have read
`--root Root` as the name and run every module under it.

**Its tests and the code they cover are two trees.** A module holds both under one directory,
so one path answers for what to run and what to measure. The root does not: the run is
`tests/` and the measurement is `app/`, and measuring `tests/` would report on a tree
`phpunit.xml` excludes from coverage. Nothing under `modules/` is in its row — neither its
tests nor its coverage — so the figure is what the root suite does for the root code, which
is the same question every module's row answers.

Under `--parallel` its filter is anchored (`/^Tests/`). A module's test classes are
namespaced `Modules\{Module}\Tests\…`, so an unanchored `Tests\` would pull in every
module's tests alongside the root's.

### A floor of a module's own

`--min` holds every module in the run to one number, which is the wrong shape for a codebase
part-way through being raised: set it to what the best modules have reached and the run fails
for every module still climbing; set it to what the weakest have and nothing is held to
anything. `--mins` gives named modules a floor of their own and leaves the rest on `--min`:

```bash
# everything has to hold 50, and the two that are finished have to hold 100
php artisan module:test -t --min=50 --mins=Auth,Activity:100

# more than one floor, given more than once
php artisan module:test -t --min=0 --mins=Auth,Activity:100 --mins=Donor:80
```

A floor can also be written on the target itself, which is the shortest way to say it:

```bash
# Auth has to hold 100; Module runs under whatever --min gives it, or no floor at all
php artisan module:test -t Auth:100 Module
```

`Module:percentage` and `--mins` are the same floor said two ways, and a module may be given
one either way. Where it is given both, the one written on the target wins, being the nearer
of the two to what is being run.

The value is `Module[,Module]:percentage`. Names are the module's, as the registry spells them,
and case does not matter. It applies wherever coverage is collected, scoreboard or not, and
asking for a floor is asking for coverage — `--mins` turns it on by itself, the same way
`--min` does.

**A floor holds a module the run reaches, and adds nothing to it.** `module:test -t
--mins=Module:99.98 Auth` tests Auth alone; Module's floor waits for a run that has it. So one
floors file serves every run — the whole suite, a window, one part of a `--shard` — and each
module carries its own floor into whichever of them it lands in.

A name that is not a module here is refused, rather than quietly holding nothing to anything:
a floor that silently never applies is worse than one that was never asked for. A value that
names no percentage, or no module, is refused the same way, and nothing runs. A floor written
on a path (`modules/Auth:100`) is refused too — a floor goes on a module's name.

### Floors kept in a file

Thirty floors on one command line is a line nobody reads and nobody can review — a diff over
it says the whole thing changed. A `--mins` value starting with `@` is a path instead, read a
floor to a line:

```bash
php artisan module:test -t --root --min=100 --mins=@coverage-floors.txt
```

```
# Everything not named here is held to --min.

Deposit,Employment,Media,Tenancy,Tenant:0
Auth,Note,PrayerRequest:28
Person:34
```

Blank lines are skipped, `#` starts a comment to the end of the line, and whitespace around
the percentage is ignored. Each line is otherwise exactly what would have been written after
`--mins=`, so the two can be mixed in one run and the file can be sorted and annotated.
Keep one at the repository root and point a Composer script at it. A module not named in
it is held to `--min`, so a module added tomorrow is measured against the benchmark
without anybody remembering to list it.

Only a target whose last colon is followed by a number is read as carrying a floor, so the
stray `100` left behind by a `--min 100` written without its `=` is still the stray target it
has always been, and is still dropped quietly.

**Every percentage is written to two decimals, and a score is never rounded up.** 99.996 % is
not 100 %, so it prints as `99.99%` — rounding to the nearest hundredth would put `100.00%`
beside a failure against a floor of 100, a row that reads as a contradiction. Decimals alone
do not fix that, they only move the boundary, so a score is rounded down and understates by
less than a hundredth rather than claiming coverage that is not there.

**Both halves are rounded to the row before they are compared.** A floor is rounded to the
nearest hundredth — it is a number somebody typed, not something counted, so `28.284` is
`28.28` and `28.285` is `28.29`. The score is rounded down to a hundredth, as a measurement
that must not claim what it has not got. The comparison is then between the two figures the
row shows, so a row can never disagree with the verdict beside it.

Held to the raw numbers instead, a module at 28.288 % passed against `Auth:28.285` and
failed against `Auth:28.289`, printing the identical `28.28% / 28.29%` both times:

| floor given | floor compared | against 28.288 % |
| --- | --- | --- |
| `28.284` | 28.28 | clears it |
| `28.285` | 28.29 | below the floor |
| `28.289` | 28.29 | below the floor |

Where the modules were not all held to one number, each shortfall is named with the floor it
missed — `below the floor: Note 28.1 % / 50.0 %, Barcode 88.9 % / 100.0 %` — since a score on
its own says nothing when 60 % passes against one floor and fails against the next. An
exported scoreboard records each row's own floor in the same way.

**A limit is how many, where a window is which ones.** `--limit=5` runs the first five of
whatever is about to go — the whole registry, or the stretch `--from`/`--to` left — so
`--from=9 --limit=5` is the five modules starting at the ninth. A limit longer than what is
there takes what is there; one that is not a whole number of modules, `1` or more, fails
before the run starts. `--take` and `--pick` are the same option under its other names —
given more than one, they have to agree, and a run told two different numbers refuses rather
than picking one. It applies to a scoreboard exactly as it does to a test run, since
both narrow the same list of targets.

**A window is a way back into a long run.** `--from`/`--to` narrow a run to a stretch of it,
both ends included, each end given as a position in the run as it is about to go or as the
name of one of its modules — so a run interrupted at the ninth of thirty is picked back up
with `--from=9`. An end naming nothing in the run fails rather than quietly running
something else, and so does a window that closes before it opens.

**A shard is one machine's part of the run.** `--shard=index/total` cuts the run into `total`
parts of about equal weight and runs part `index`, so several machines given `1/4` … `4/4`
cover the run between them, each module once. It is the run the other options left — the
sort, the window, the limit — that is cut, and every machine cuts it the same way:

```bash
php artisan module:test -t --root --min=100 --mins=@coverage.txt --shard=2/4
php artisan module:test -t --root --min=100 --shard=2/4 --durations=module-test.json
```

Each target, heaviest first, joins the part carrying least so far. A module weighs the
seconds a previous `--export=….json` scoreboard recorded for it, given `--durations`, and
otherwise as many tests as it holds — every `it(` or `test(` opening a line in its test
files, at the rate the recorded modules ran. A floor goes with its module whichever part that
is, so the parts together answer exactly what one run over everything would. A part with
nothing in it says so and passes; a shard that is not `index/total`, with `index` from 1 to
`total`, fails before anything runs.

**Disabled modules are tested too.** A module is switched off in `modules.json` for what it
does at runtime, which has nothing to do with whether its tests should still pass.

**A target naming nothing here is dropped, quietly** — the `100` left behind by a `--min 100`
written without its `=`, or a module renamed since somebody wrote the command down. Running
it would report 0 % for a module that was never tested. It is only worth saying anything
when nothing is left, and then the run fails with `Nothing to test.`

The exit code is the worst of the runs, so one failing module fails the lot.

**A run that failed names what failed, at the end.** Where more than one module ran — or
where the scoreboard swallowed the child's own output — the last lines say which modules'
tests failed and which fell below the floor, each with the score it reached beside the floor
it had to clear. Shortfalls are named worst first, and past five the rest are counted rather
than listed, since thirty names on one line is a line nobody reads and the rows above hold
them all. A single module says it under its own report instead, where the number it is about
is a line away, and is not repeated at the end.

**On a scoreboard, a failing module's failures follow the result.** The rows are one line
each and the child's output is held back while the run goes, but a module whose tests failed
has them printed under the summary, named, in the order the modules ran. Without it
`tests failed in …` is the only thing said about a run that failed, and the reason has to be
hunted for by running that module again.

Only the failures are handed back — from the first one Pest reported to the line before its
closing summary. The progress and the `Tests:`/`Duration:` lines are dropped, since the row
above has already given the score and what the module cost, and a second duration under it
reads like the module ran twice. A module that passed says nothing, and a module that only
fell short of the floor says nothing either — the row already carries that number. Where no
failure can be picked out — a child that died before reporting one — the whole account is
handed back instead, since something unreadable beats nothing at all.

## The scoreboard

`--totals` answers "where are we" rather than running a suite to be read. The same coverage
is collected, everything the child says on the way is dropped (a failing module's is given
back at the end, see above), and each module gets one row:
its name, its score, the floor beside it where there is one, and how long it took. The row
goes up the moment the module is picked up — `running 12s`, redrawn in place — so a run over
every module says which one is being read now rather than going quiet for an hour. Piped to
a file, a log or an agent, where a written line cannot be taken back, the row is written once,
when it has something to say.

### Keeping it

`--export` writes the same rows to a file rather than only to the terminal — to send on, to
attach to a pull request, or to put beside the last run and see what moved. It implies
`--totals`, so the run collects coverage and says nothing else on the way.

The extension picks the format, and both halves of the name have a whole answer behind them:

| Written | Gets you |
| --- | --- |
| `--export` | `module-test.json` |
| `--export=.csv` | `module-test.csv` |
| `--export=coverage` | `coverage.json` |
| `--export=build/run.md` | that path, with the directory created if it is not there |

**JSON is the default because an unnamed export is the one nobody chose a format for.** It
is read back by whatever asked for it — a pipeline step, a diff against the last run — where
the drawn formats are chosen on purpose, by somebody who means to look at them. A name whose
extension names no format it writes fails before the run starts, rather than after it.

| Format | What it is |
| --- | --- |
| `json` | Records: every module, the run's total, and whether each score cleared the floor |
| `txt` | The scoreboard as it stood on the screen, with the colours dropped |
| `md` | A table for a README, a pull request or a wiki, the total as its last row |
| `csv` | The same records as a sheet, one header and one row per module |
| `pdf` | A single page in the scoreboard's own colours, cut to the rows rather than padded out to A4 |
| `png`, `jpg`, `jpeg` | A picture of the scoreboard, cropped to the text |

A run that failed before any module finished has no scoreboard, and writes no file.
