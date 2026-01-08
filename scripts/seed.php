<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/services.php';

$accounts = [
    [
        'name' => 'Administrador TDesk',
        'email' => 'admin@tdesk.local',
        'password' => 'Admin@123',
        'role' => 'admin',
    ],
    [
        'name' => 'Cliente TDesk',
        'email' => 'cliente@tdesk.local',
        'password' => 'Cliente@123',
        'role' => 'client',
    ],
    [
        'name' => 'Suporte Nível 1',
        'email' => 'suporte@tdesk.local',
        'password' => 'Suporte@123',
        'role' => 'support',
    ],
];

foreach ($accounts as $account) {
    if (find_user_by_email($account['email']) !== null) {
        echo "Usuário {$account['email']} já existe." . PHP_EOL;
        continue;
    }

    create_user($account);
    echo "Usuário {$account['email']} criado com sucesso." . PHP_EOL;
}

