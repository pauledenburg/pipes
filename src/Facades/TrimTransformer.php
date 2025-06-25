<?php

namespace Jwhulette\Pipes\Facades;

use Illuminate\Support\Facades\Facade;

class TrimTransformer extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \Jwhulette\Pipes\Transformers\TrimTransformer::class;
    }
} 