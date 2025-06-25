<?php

declare(strict_types=1);

namespace Jwhulette\Pipes;

use Illuminate\Support\ServiceProvider;

class PipesPackageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind('EtlPipe', EtlPipe::class);
        $this->app->bind('CsvExtractor', Extractors\CsvExtractor::class);
        $this->app->bind('CaseTransformer', Transformers\CaseTransformer::class);
        $this->app->bind('TrimTransformer', Transformers\TrimTransformer::class);
        $this->app->bind('CsvLoader', Loaders\CsvLoader::class);
    }

    public function boot(): void
    {
        // Register facade aliases
        $loader = \Illuminate\Foundation\AliasLoader::getInstance();
        $loader->alias('EtlPipe', Facades\EtlPipe::class);
    }
}
