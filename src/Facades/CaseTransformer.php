<?php

declare(strict_types=1);

namespace Jwhulette\Pipes\Facades;

use Illuminate\Support\Facades\Facade;

class CaseTransformer extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \Jwhulette\Pipes\Transformers\CaseTransformer::class;
    }
}
