<?php
require dirname(__DIR__) . '/../includes/bootstrap.php';

header('Content-Type: application/json');

$status = AprscStatus::getSummary();

echo json_encode($status);
