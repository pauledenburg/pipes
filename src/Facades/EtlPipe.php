<?php

namespace Jwhulette\Pipes\Facades;

use Illuminate\Support\Facades\Facade;

class EtlPipe extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \Jwhulette\Pipes\EtlPipe::class;
    }
} 