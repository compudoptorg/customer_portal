<?php

namespace Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Point the sqlite connection (whose path is hard-coded to storage/database.sqlite) at an in-memory database
 * and migrate it, so tests never touch the real portal database.
 */
trait UsesInMemoryDatabase
{
    protected function setUpInMemoryDatabase(): void
    {
        config(['database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
    }
}
