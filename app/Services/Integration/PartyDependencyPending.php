<?php
namespace App\Services\Integration;

final class PartyDependencyPending extends \RuntimeException
{
    public function __construct(public readonly array $details)
    {
        parent::__construct(__('inventory.purchasing.connection_review_required'));
    }
}
