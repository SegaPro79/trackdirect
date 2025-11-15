<?php
require dirname(__DIR__) . '/../includes/bootstrap.php';

header('Content-Type: application/json');

echo json_encode(AprscStatus::getSummary());
