<?php

$environment = (string) ($_SERVER['APP_ENV'] ?? getenv('APP_ENV') ?: '');
if ($environment !== 'testing') {
    throw new RuntimeException('REFUSING TO RUN TESTS: APP_ENV must be exactly testing before application bootstrap.');
}

$prefix = (string) ($_SERVER['TEST_DATABASE_PREFIX'] ?? getenv('TEST_DATABASE_PREFIX') ?: '');
if ($prefix === '') {
    throw new RuntimeException('REFUSING TO RUN TESTS: TEST_DATABASE_PREFIX must be explicit.');
}

foreach (['DB_DATABASE', 'TENANT_DB_DATABASE', 'CENTRAL_DB_DATABASE'] as $key) {
    $database = (string) ($_SERVER[$key] ?? getenv($key) ?: '');
    if ($database === '' || $database === ':memory:') {
        continue;
    }
    $name = basename($database);
    if (in_array($name, ['solavel', 'solavel_hr', 'solafone'], true)
        || preg_match('/^tenant_[0-9]{6}$/', $name) === 1
        || ! str_starts_with($name, $prefix)) {
        throw new RuntimeException("REFUSING TO RUN TESTS: {$key} does not resolve to the explicit disposable prefix.");
    }
}

require dirname(__DIR__).'/vendor/autoload.php';
