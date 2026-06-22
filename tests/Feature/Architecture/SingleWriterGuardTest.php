<?php

namespace Tests\Feature\Architecture;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Static architecture guard (no database). Scans app/ source and FAILS if any
 * file OUTSIDE the approved stock-engine namespace writes to the canonical stock
 * tables/models. This enforces the single-writer rule at the source level so a
 * future change that bypasses StockLedgerService is caught by CI.
 *
 * Approved writers: App\Services\Stock\* (StockLedgerService, CostingEngine,
 * IntegrityChecker is read-only but lives here too).
 */
class SingleWriterGuardTest extends TestCase
{
    /** Files allowed to write stock tables/models. */
    private const APPROVED_PATHS = [
        'app/Services/Stock/',
    ];

    /** Models that represent canonical stock state. */
    private const STOCK_MODELS = ['StockLedger', 'StockBalance', 'CostLayer'];

    /** Write-ish Eloquent/DB call fragments that would mutate stock. */
    private const WRITE_PATTERNS = [
        // Model write calls, e.g. StockLedger::create(, (new StockBalance)->save(
        '/\b(StockLedger|StockBalance|CostLayer)::(create|insert|update|updateOrCreate|firstOrCreate|forceCreate)\s*\(/',
        // DB::table('stock_ledger')->insert/update/... (raw writes)
        '/DB::(connection\([^)]*\)->)?table\(\s*[\'"](stock_ledger|stock_balances|cost_layers)[\'"]\s*\)\s*->\s*(insert|update|delete|upsert|insertGetId)/',
        // remaining_qty direct assignment outside the engine
        '/->\s*remaining_qty\s*=/',
        // on_hand_qty direct assignment outside the engine
        '/->\s*on_hand_qty\s*=/',
    ];

    #[Test]
    public function only_the_stock_engine_writes_canonical_stock(): void
    {
        $appDir = base_path('app');
        $violations = [];

        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($appDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($rii as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            $relative = ltrim(str_replace(base_path(), '', $path), '/');

            // Skip approved engine paths.
            $approved = false;
            foreach (self::APPROVED_PATHS as $ap) {
                if (str_starts_with($relative, $ap)) {
                    $approved = true;
                    break;
                }
            }
            if ($approved) {
                continue;
            }

            // The stock models themselves legitimately define these columns/casts;
            // skip the model class files (they don't *write* via the patterns).
            $basename = $file->getBasename('.php');
            $contents = file_get_contents($path);

            foreach (self::WRITE_PATTERNS as $pattern) {
                if (preg_match_all($pattern, $contents, $matches)) {
                    // Allow the stock model classes to assign their own attributes
                    // only inside App\Models\Tenant (they are data holders, but the
                    // assignment patterns target *external* writers; a model
                    // assigning its own on_hand_qty would still be a violation if
                    // outside the engine, which is what we want to catch).
                    foreach ($matches[0] as $hit) {
                        $violations[] = "{$relative}: ".trim($hit);
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Direct stock writes found OUTSIDE App\\Services\\Stock\\:\n".implode("\n", $violations)
        );
    }
}
