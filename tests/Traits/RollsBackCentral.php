<?php

namespace Tests\Traits;

use Illuminate\Support\Facades\DB;

/**
 * Central-database fixtures (organizations, projects, entitlement snapshots, users) written through the
 * `mysql` connection are NOT covered by TenantAware's tenant-connection rollback. Without this the rows
 * survive the test and poison later tests (order-dependent failures, and the whole next run on the
 * same disposable database). Laravel calls the setUp/tearDown<TraitName> hooks automatically.
 */
trait RollsBackCentral
{
    protected function setUpRollsBackCentral(): void
    {
        DB::connection('mysql')->beginTransaction();
    }

    protected function tearDownRollsBackCentral(): void
    {
        $central = DB::connection('mysql');
        while ($central->transactionLevel() > 0) {
            $central->rollBack();
        }
    }
}
