<?php
namespace App\Exceptions;

use RuntimeException;

/** Expected, recoverable integration prerequisite; never an internal server error. */
class FinanceSetupRequired extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(__('inventory.integration.finance_setup_required'));
    }

    public function render($request)
    {
        return response()->json(['success' => false, 'error' => [
            'code' => 'finance_setup_required', 'message' => $this->getMessage(),
        ]], 409);
    }

    public function report(): bool
    {
        return true;
    }
}
