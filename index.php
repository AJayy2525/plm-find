<?php
declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    $routes = [
        'login' => 'login.php',
        'dashboard' => 'dashboard.php',
        'browse' => 'browse-items.php',
        'claims' => 'my-claims.php',
        'admin' => 'admin.php',
        'register' => 'register.php',
    ];

    $pageKey = (string) ($_GET['page'] ?? '');

    if (isset($routes[$pageKey])) {
        header('Location: ' . $routes[$pageKey], true, 302);
        exit;
    }

    if ($pageKey === 'report') {
        $destination = ($_GET['type'] ?? 'lost') === 'found'
            ? 'report-found.php'
            : 'report-lost.php';

        header('Location: ' . $destination, true, 302);
        exit;
    }

    if ($pageKey === 'item') {
        $itemId = max(0, (int) ($_GET['id'] ?? 0));

        header(
            'Location: item.php?id=' . $itemId,
            true,
            302
        );
        exit;
    }
}

require __DIR__ . '/app.php';
