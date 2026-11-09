<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

$user = current_user($db);

if (!$user) {
    go('login');
}

if (($user['role'] ?? '') === 'admin') {
    go('admin');
}

header(
    'Location: my-reports.php',
    true,
    303
);

exit;
