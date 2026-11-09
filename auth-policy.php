<?php
// Included by app.php after $user and $page have been set.
// Disable the previous direct-claim workflow.

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $blockedActions = [
        'claim',
        'review',
        'resolve'
    ];

    if (in_array(
        (string) ($_POST['action'] ?? ''),
        $blockedActions,
        true
    )) {
        http_response_code(403);

        exit(
            'This action is restricted to the ' .
            'administrator matching workflow.'
        );
    }
}

// Students cannot browse all reports,
// access another student's item, or review claims.

if ($user && ($user['role'] ?? '') !== 'admin') {

    if (in_array(
        $page,
        ['browse', 'item', 'claims', 'admin'],
        true
    )) {
        http_response_code(403);

        exit(
            'Access denied. You can submit reports ' .
            'and view your own reports.'
        );
    }
}

// Administrators go to their own dashboard.

if (
    $user &&
    ($user['role'] ?? '') === 'admin' &&
    $page === 'dashboard' &&
    $_SERVER['REQUEST_METHOD'] === 'GET'
) {
    header('Location: admin.php');
    exit;
}
