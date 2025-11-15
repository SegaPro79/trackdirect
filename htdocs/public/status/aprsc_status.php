<?php
require dirname(__DIR__) . '/../includes/bootstrap.php';

header('Content-Type: application/json');

$status = AprscStatus::getSummary();

$response = [
    'users_online' => $status['users_online'],
    'pkts_tx' => $status['pkts_tx'],
    'pkts_rx' => $status['pkts_rx'],
    'pkts_rtx' => $status['pkts_rtx'],
    'tx_active' => (bool) ($status['tx_active'] ?? false),
    'rx_active' => (bool) ($status['rx_active'] ?? false),
];

echo json_encode($response);
