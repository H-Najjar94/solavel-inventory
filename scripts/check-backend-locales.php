<?php
// Read only static catalogs: this check never bootstraps Laravel or a database.
$root = dirname(__DIR__);
$flatten = function (array $data, string $prefix = '') use (&$flatten): array {
    $result = [];
    foreach ($data as $key => $value) {
        $name = $prefix.$key;
        if (is_array($value)) $result += $flatten($value, $name.'.');
        else $result[$name] = $value;
    }
    return $result;
};
$tokens = static function (string $text): array {
    preg_match_all('/(?<!\w):[A-Za-z_][A-Za-z_0-9]*|\{[A-Za-z_][\w.]*\}|%(?:\d+\$)?[sdif]|SolaBooks|SolaProjects|SolaCount|SolaStock|SolaHR|SolaVel|Solavel/u', $text, $matches);
    sort($matches[0]);
    return $matches[0];
};
$english = $flatten(require "$root/lang/en/inventory.php");
$keys = array_keys($english); sort($keys);
$errors = [];
$roleConfig = require "$root/config/inventory_permissions.php";
$operationalRoles = require "$root/config/inventory_operational_roles.php";
foreach (array_unique(array_merge(array_keys($roleConfig['roles']), array_keys($operationalRoles))) as $role) {
    if (!isset($english['roles.'.$role])) $errors[] = "Missing built-in role label: $role";
}
$publicEnglish = require "$root/lang/en/public_errors.php";
foreach (['ar','fr','ru','ur','de','es','zh','pt','tr','it'] as $locale) {
    $file = "$root/lang/$locale/inventory.php";
    if (!is_file($file)) { $errors[] = "$locale: missing catalog"; continue; }
    $values = $flatten(require $file); $actual = array_keys($values); sort($actual);
    if ($actual !== $keys) $errors[] = "$locale: missing or extra backend keys";
    foreach ($english as $key => $source) {
        if (!isset($values[$key]) || !is_string($values[$key]) || trim($values[$key]) === '') { $errors[] = "$locale:$key: empty translation"; continue; }
        if ($tokens($source) !== $tokens($values[$key])) $errors[] = "$locale:$key: variables or product names changed";
    }
    $public = require "$root/lang/$locale/public_errors.php";
    if (array_keys($public) !== array_keys($publicEnglish)) $errors[] = "$locale: missing public operation errors";
    foreach ($publicEnglish as $key => $source) {
        if (!isset($public[$key]) || !is_string($public[$key]) || trim($public[$key]) === '' || $tokens($source) !== $tokens($public[$key])) $errors[] = "$locale: invalid public operation error";
    }
    $validation = require "$root/lang/$locale/validation.php";
    if (count($validation['attributes'] ?? []) < 42) $errors[] = "$locale: missing input field labels";
    echo "$locale: ".count($values)." backend templates\n";
}
if ($errors) { fwrite(STDERR, implode("\n", $errors)."\n"); exit(1); }
echo "All backend languages cover the source and preserve variables.\n";
