<?php

declare(strict_types=1);

namespace Jwhulette\Pipes\Facades;

use Illuminate\Support\Facades\Facade;

class CsvExtractor extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \Jwhulette\Pipes\Extractors\CsvExtractor::class;
    }
}
