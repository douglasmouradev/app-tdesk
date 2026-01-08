<?php

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/services.php';

require_auth();

header('Content-Type: application/json');

try {
    $data = fetch_priority_distribution(current_user());
    echo json_encode($data);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['error' => $exception->getMessage()]);
}


