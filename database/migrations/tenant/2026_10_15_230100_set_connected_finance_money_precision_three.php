<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Configuration only. Never change posted events, currency/rate snapshots or ledger amounts. */
return new class extends Migration
{
    public function getConnection(): ?string { return 'tenant'; }

    public function up(): void
    {
        if (!Schema::connection('tenant')->hasTable('integration_settings')
            || !Schema::connection('tenant')->hasColumn('integration_settings', 'meta')) return;
        $connection = DB::connection('tenant');
        if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            // JSON_SET changes exactly one existing configuration key and leaves every other value intact.
            // Do not manufacture a reviewed currency/valuation contract for an unconfigured connection.
            $connection->update("UPDATE integration_settings SET meta = JSON_SET(meta, '$.finance_currency_contract.money_scale', 3)
                WHERE integration = ? AND JSON_VALID(meta)
                AND JSON_TYPE(JSON_EXTRACT(meta, '$.finance_currency_contract')) = 'OBJECT'
                AND JSON_CONTAINS_PATH(meta, 'one', '$.finance_currency_contract.money_scale') = 1
                AND JSON_EXTRACT(meta, '$.finance_currency_contract.money_scale') <> 3", ['solabooks']);
            return;
        }
        // SQLite qualification has the same JSON functions, except JSON_CONTAINS_PATH/JSON_TYPE.
        $connection->update("UPDATE integration_settings SET meta = json_set(meta, '$.finance_currency_contract.money_scale', 3)
            WHERE integration = ? AND json_valid(meta)
            AND json_type(meta, '$.finance_currency_contract') = 'object'
            AND json_type(meta, '$.finance_currency_contract.money_scale') IS NOT NULL
            AND json_extract(meta, '$.finance_currency_contract.money_scale') <> 3", ['solabooks']);
    }

    public function down(): void
    {
        // Product policy remains precision 3; restoring a hard-coded 2 would break new posted amounts.
    }
};
