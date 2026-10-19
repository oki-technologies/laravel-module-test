<?php

declare(strict_types=1);

namespace SolomonOchepa\ModuleTest;

use Illuminate\Support\ServiceProvider;
use SolomonOchepa\ModuleTest\Console\ModuleTestCommand;

/** Puts `module:test` on `php artisan`. Nothing else. */
final class ModuleTestServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ModuleTestCommand::class]);
        }
    }
}
