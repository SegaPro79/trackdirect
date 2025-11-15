<?php
require dirname(__DIR__) . '/../includes/bootstrap.php';

header('Content-Type: application/json');

$status = AprscStatus::getSummary();

$response = [
    'connected' => !empty($status['connected']),
    'users_online' => $status['users_online'] ?? null,
];

echo json_encode($response);
