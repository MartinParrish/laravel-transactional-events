<?php

namespace Neves\TransactionalEvents\Contracts;

interface TransactionalEvent
{
    public function getConnection(): \Illuminate\Database\Connection;

    public function setConnection(\Illuminate\Database\Connection $connection);
}