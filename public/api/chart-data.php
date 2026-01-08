<?php

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/services.php';

require_auth();

header('Content-Type: application/json');

try {
    $days = filter_input(INPUT_GET, 'days', FILTER_VALIDATE_INT, [
        'options' => [
            'default' => 14,
            'min_range' => 7,
            'max_range' => 60,
        ],
    ]) ?: 14;

    $data = fetch_chart_series(current_user(), $days);
    echo json_encode($data);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['error' => $exception->getMessage()]);
}

