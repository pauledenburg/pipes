<?php

declare(strict_types=1);

namespace Jwhulette\Pipes;

use Illuminate\Support\ServiceProvider;

class PipesPackageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind('EtlPipe', \Jwhulette\Pipes\EtlPipe::class);
        $this->app->bind('CsvExtractor', \Jwhulette\Pipes\Extractors\CsvExtractor::class);
        $this->app->bind('CaseTransformer', \Jwhulette\Pipes\Transformers\CaseTransformer::class);
        $this->app->bind('TrimTransformer', \Jwhulette\Pipes\Transformers\TrimTransformer::class);
        $this->app->bind('CsvLoader', \Jwhulette\Pipes\Loaders\CsvLoader::class);
    }

    public function boot(): void
    {
      //
    }
}
