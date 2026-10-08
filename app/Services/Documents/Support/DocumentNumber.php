<?php

namespace App\Services\Documents\Support;

/**
 * Server-side, org-scoped document number generator. Users should never have to
 * invent technical numbers (entry_number, po_number, grn_number, …); the server
 * issues clean sequential ones like PO-000001, OS-000042.
 *
 * Reusable across all document types — call inside the create transaction (the
 * document creates already run in one) so the max+1 read and the insert are
 * consistent. The numeric suffix is derived from the latest existing number for
 * the (org, prefix), so deletes don't cause collisions and per-org sequences are
 * independent. The DB's org+number unique index is the final backstop.
 */
class DocumentNumber
{
    /**
     * @param  class-string  $modelClass  the document model (org-scoped)
     * @param  string  $column  the number column (e.g. 'po_number')
     */
    public static function next(string $prefix, string $modelClass, string $column, int $orgId, string $connection): string
    {
        $latest = $modelClass::on($connection)
            ->where('organization_id', $orgId)
            ->where($column, 'like', $prefix.'-%')
            ->orderByDesc('id')
            // Every caller generates inside its document-create transaction.
            // Serialize concurrent readers on the sequence tail so two creates
            // cannot both issue the same max+1 number.
            ->lockForUpdate()
            ->value($column);

        $seq = 1;
        if ($latest && preg_match('/(\d+)$/', (string) $latest, $m)) {
            $seq = (int) $m[1] + 1;
        }

        return sprintf('%s-%06d', $prefix, $seq);
    }

    /**
     * Keep a caller-derived number (e.g. PICK-<order>) but make it unique in the
     * organization: the first use keeps it, later ones get -2, -3, … Deleted
     * documents still hold their number in the unique index, so they count too.
     *
     * @param  class-string  $modelClass
     */
    public static function unique(?string $base, string $modelClass, string $column, int $orgId, string $connection): ?string
    {
        if ($base === null || $base === '') {
            return $base;
        }
        $taken = $modelClass::on($connection)->withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->where(fn ($q) => $q->where($column, $base)->orWhere($column, 'like', $base.'-%'))
            ->lockForUpdate()
            ->pluck($column)->all();
        if (! in_array($base, $taken, true)) {
            return $base;
        }
        $suffix = 2;
        while (in_array($base.'-'.$suffix, $taken, true)) {
            $suffix++;
        }

        return $base.'-'.$suffix;
    }
}
