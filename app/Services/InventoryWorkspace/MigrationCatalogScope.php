<?php
namespace App\Services\InventoryWorkspace;

/** Defer the synchronous catalog callback until the durable owner command commits. */
final class MigrationCatalogScope
{
    private bool $active=false;
    public function active(): bool { return $this->active; }
    public function run(callable $create): mixed
    {
        $previous=$this->active; $this->active=true;
        try { return $create(); } finally { $this->active=$previous; }
    }
}
