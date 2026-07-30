<?php

// Isolated real-HTTP durability peer. It intentionally stores only an
// idempotency key, body hash, and synthetic result in a disposable file.
$stateFile = getenv('PHASE4_RECEIVER_STATE');
$key = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '';
$body = file_get_contents('php://input');
$hash = hash('sha256', $body);
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $key === '' || ! $stateFile) {
    http_response_code(400);
    echo json_encode(['error' => ['code' => 'fixture_request_invalid']]);
    return;
}
$handle = fopen($stateFile, 'c+');
flock($handle, LOCK_EX);
$contents = stream_get_contents($handle);
$state = $contents === '' ? [] : json_decode($contents, true);
if (isset($state[$key]) && ! hash_equals($state[$key]['hash'], $hash)) {
    http_response_code(409);
    echo json_encode(['error' => ['code' => 'idempotency_content_conflict']]);
} else {
    $state[$key] ??= ['hash' => $hash, 'id' => 91001];
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($state));
    fflush($handle);
    http_response_code(isset($_SERVER['HTTP_X_PHASE4_REPLAY']) ? 200 : 201);
    echo json_encode(['success' => true, 'data' => ['id' => $state[$key]['id']]]);
}
flock($handle, LOCK_UN);
fclose($handle);
