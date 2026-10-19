<?php

declare(strict_types=1);

namespace SolomonOchepa\ModuleTest\Tests;

use Illuminate\Support\Facades\File;
use Nwidart\Modules\LaravelModulesServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use SolomonOchepa\ModuleTest\ModuleTestServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * Testbench's skeleton stands in for the application, so it is given what
     * one of the suites this runs would have: a modules directory and a
     * phpunit.xml declaring some environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        File::ensureDirectoryExists(base_path('modules'));

        if (! File::exists(base_path('phpunit.xml'))) {
            File::copy(__DIR__.'/Fixtures/phpunit.xml', base_path('phpunit.xml'));
        }
    }

    protected function getPackageProviders($app): array
    {
        return [LaravelModulesServiceProvider::class, ModuleTestServiceProvider::class];
    }
}
