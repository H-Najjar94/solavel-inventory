<?php

return [
    'receipt_already_billed' => 'This receipt quantity is already reserved or billed by another financial matching. Review the existing bill references before continuing.',
    'financial_reversal_required' => 'This receipt belongs to a posted supplier bill. Reverse the financial receipt matching and bill through the authorized Finance workflow first, or use the supplier-return workflow; then retry the receipt reversal.',
    'valuation_pending' => 'Financial receipt matching is in progress for this product and warehouse. Retry after matching finishes; contact the integration manager if it remains pending.',
    'valuation_changed' => 'Stock movements changed the reviewed valuation. Review the financial matching before continuing; no new receipt or valuation change was made.',
    'lot_required' => 'Enter the batch or lot number for this product.',
    'serials_required' => 'Enter one serial number for each unit received.',
    'expiry_required' => 'Enter the expiry date for this batch.',
    'variant_required' => 'Choose the product variant being received.',
    'quantity_remaining' => 'Enter a quantity greater than zero and no more than the remaining :quantity.',
    'fixed_cost' => 'This receipt uses the approved purchase cost. Only an authorized inventory valuation user may change it.',
    'receive_failed' => 'Receiving could not be completed. Review the warehouse, quantities and product tracking details, then retry the same operation.',
];
