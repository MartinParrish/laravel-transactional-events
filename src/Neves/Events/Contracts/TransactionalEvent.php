<?php

namespace Neves\Events\Contracts;

use Illuminate\Database\Connection;

interface TransactionalEvent
{
    public function getConnection(): Connection;
}
