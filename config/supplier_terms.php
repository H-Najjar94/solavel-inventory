<?php

return [
    // Kill switch for supplier-item terms, suggestions, history and reorder suggestions.
    'enabled' => (bool) env('SUPPLIER_ITEMS_ENABLED', true),
    // SolaCount origin used for links to posted bills in purchase history.
    'finance_url' => rtrim((string) env('SOLACOUNT_URL', 'https://solacount.com'), '/'),
    'history_limit' => 200,
];
