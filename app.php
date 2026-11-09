<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

$user = current_user($db);

$page = isset($page)
    ? (string) $page
    : (string) ($_GET['page'] ?? ($user ? 'dashboard' : 'login'));

/*
|--------------------------------------------------------------------------
| ROUTING AND ROLE ACCESS
|--------------------------------------------------------------------------
| STUDENT:
|   Dashboard, Report Lost, Report Found, My Reports
|
| ADMIN:
|   Admin Dashboard, Lost Reports, Found Reports,
|   Matching, Verification, Return Records
|--------------------------------------------------------------------------
*/

if ($page === 'register') {
    go('register');
}

if ($page === 'admin') {

    if (!$user) {
        go('login');
    }

    if ($user['role'] !== 'admin') {
        http_response_code(403);
        exit('Administrator access required.');
    }

    go('admin');
}

if ($page === 'my-reports') {

    if (!$user) {
        go('login');
    }

    if ($user['role'] === 'admin') {
        go('admin');
    }

    header('Location: my-reports.php', true, 303);
    exit;
}

/* Disable the old claims page. */

if ($page === 'claims') {

    http_response_code(403);

    exit(
        'Ownership claims are handled by administrators.'
    );
}

$allowedPages = [
    'login',
    'dashboard',
    'browse',
    'report',
    'item'
];

if (!in_array($page, $allowedPages, true)) {
    $page = $user ? 'dashboard' : 'login';
}

/* Require login for protected pages. */

if (!$user && $page !== 'login') {
    go('login');
}

/* Logged-in users go to their correct dashboard. */

if ($user && $page === 'login') {

    go(
        $user['role'] === 'admin'
            ? 'admin'
            : 'dashboard'
    );
}

/* Administrators use the admin workspace. */

if ($user && $user['role'] === 'admin') {

    if (in_array(
        $page,
        ['dashboard', 'report'],
        true
    )) {
        go('admin');
    }

/* Students cannot browse other people's reports. */

} elseif (
    $user &&
    in_array($page, ['browse', 'item'], true)
) {

    http_response_code(403);

    exit(
        'Access denied. You can submit reports ' .
        'and view your own reports.'
    );
}

/* =========================================
   PROCESS FORM ACTIONS
========================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    check_token();

    $action = (string) ($_POST['action'] ?? '');

    /* =====================================
       LOGOUT
    ===================================== */

    if ($action === 'logout') {

        $_SESSION = [];

        session_regenerate_id(true);

        go('login');
    }

    /*
       The old claim, review and resolve
       actions are permanently disabled.
       Admin matching uses admin.php.
    */

    if (in_array(
        $action,
        ['claim', 'resolve', 'review'],
        true
    )) {

        http_response_code(403);

        exit(
            'This action is available only through ' .
            'the administrator matching workflow.'
        );
    }

    try {

        /* =================================
           LOGIN
        ================================= */

        if ($action === 'login' && !$user) {

            $email = strtolower(
                trim((string) ($_POST['email'] ?? ''))
            );

            $stmt = $db->prepare(
                'SELECT id, role, password_hash
                 FROM users
                 WHERE email = ?
                 LIMIT 1'
            );

            $stmt->execute([$email]);

            $account = $stmt->fetch();

            if (
                !$account ||
                !password_verify(
                    (string) ($_POST['password'] ?? ''),
                    $account['password_hash']
                )
            ) {

                throw new RuntimeException(
                    'Incorrect email or password.'
                );
            }

            /*
               Generate a fresh session ID
               after successful login.
            */

            session_regenerate_id(true);

            $_SESSION['user_id'] =
                (int) $account['id'];

            $_SESSION['csrf'] =
                bin2hex(random_bytes(32));

            /*
               Redirect according to role.
            */

            if ($account['role'] === 'admin') {
                go('admin');
            }

            go('dashboard');
        }

        if (!$user) {

            throw new RuntimeException(
                'Please sign in first.'
            );
        }

        /* =================================
           STUDENT LOST / FOUND REPORT
        ================================= */

        if (
            $action === 'report' &&
            $page === 'report' &&
            $user['role'] === 'student'
        ) {

            $type = (string) (
                $_POST['type'] ?? ''
            );

            $title = trim(
                (string) ($_POST['title'] ?? '')
            );

            $description = trim(
                (string) ($_POST['description'] ?? '')
            );

            $location = trim(
                (string) ($_POST['location'] ?? '')
            );

            $categoryId = (int) (
                $_POST['category_id'] ?? 0
            );

            $eventDate = (string) (
                $_POST['event_date'] ?? ''
            );

            $date = DateTimeImmutable::createFromFormat(
                '!Y-m-d',
                $eventDate
            );

            /* Validate report type. */

            if (!in_array(
                $type,
                ['lost', 'found'],
                true
            )) {

                throw new RuntimeException(
                    'Select lost or found.'
                );
            }

            /* Validate report fields. */

            if (
                mb_strlen($title) < 3 ||
                mb_strlen($title) > 140 ||
                mb_strlen($description) < 10 ||
                mb_strlen($description) > 4000 ||
                mb_strlen($location) < 3 ||
                mb_strlen($location) > 180
            ) {

                throw new RuntimeException(
                    'Please complete all item details.'
                );
            }

            /* Validate date. */

            if (
                !$date ||
                $date->format('Y-m-d') !== $eventDate ||
                $eventDate > date('Y-m-d')
            ) {

                throw new RuntimeException(
                    'Choose a valid date.'
                );
            }

            /* Validate category. */

            $stmt = $db->prepare(
                'SELECT id
                 FROM categories
                 WHERE id = ?'
            );

            $stmt->execute([$categoryId]);

            if (!$stmt->fetch()) {

                throw new RuntimeException(
                    'Choose a valid category.'
                );
            }

            /* Upload optional photo. */

            $photo = upload_photo(
                $_FILES['photo'] ?? []
            );

            /* Save report. */

            $stmt = $db->prepare(
                'INSERT INTO items
                 (
                    user_id,
                    category_id,
                    type,
                    title,
                    description,
                    location,
                    event_date,
                    photo
                 )
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );

            $stmt->execute([
                (int) $user['id'],
                $categoryId,
                $type,
                $title,
                $description,
                $location,
                $eventDate,
                $photo
            ]);

            notice(
                'Item report submitted. ' .
                'An administrator will review it.'
            );

            /*
               IMPORTANT:
               Redirect students to their own
               reports, NOT Browse Items.
            */

            header(
                'Location: my-reports.php',
                true,
                303
            );

            exit;
        }

        throw new RuntimeException(
            'Action not available.'
        );

    } catch (Throwable $ex) {

        if ($db->inTransaction()) {
            $db->rollBack();
        }

        if ($ex instanceof RuntimeException) {

            notice(
                $ex->getMessage(),
                'error'
            );

        } else {

            error_log((string) $ex);

            notice(
                'Something went wrong. ' .
                'Check the PHP error log.',
                'error'
            );
        }

        /*
           Return to the correct form
           after a submission error.
        */

        if ($page === 'report') {

            $type = (string) (
                $_POST['type'] ??
                $_GET['type'] ??
                'lost'
            );

            if (!in_array(
                $type,
                ['lost', 'found'],
                true
            )) {
                $type = 'lost';
            }

            go('report', [
                'type' => $type
            ]);
        }

        go(
            $page === 'login'
                ? 'login'
                : 'dashboard'
        );
    }
}

/* =========================================
   SHARED PAGE DATA
========================================= */

$categories = $db->query(
    'SELECT id, name
     FROM categories
     ORDER BY name'
)->fetchAll();

$flash = $_SESSION['notice'] ?? null;

unset($_SESSION['notice']);

/* =========================================
   SHARED ITEM CARD COMPONENT
========================================= */

function render_cards(array $items): void {
    ?>

    <div class="item-grid">

        <?php foreach ($items as $item): ?>

            <a
                class="item-card"
                href="item.php?id=<?= (int) $item['id'] ?>"
            >

                <div class="item-photo">

                    <?php if (!empty($item['photo'])): ?>

                        <img
                            src="<?= e($item['photo']) ?>"
                            alt="Reported item"
                            loading="lazy"
                        >

                    <?php else: ?>

                        <span class="photo-placeholder">
                            NO PHOTO
                        </span>

                    <?php endif; ?>

                    <span class="pill <?= e($item['type']) ?>">
                        <?= strtoupper(e($item['type'])) ?>
                    </span>

                </div>

                <div class="item-content">

                    <span class="eyebrow">

                        <?= e($item['category_name']) ?>
                        ·
                        <?= e(date(
                            'M j, Y',
                            strtotime($item['event_date'])
                        )) ?>

                    </span>

                    <h3>
                        <?= e($item['title']) ?>
                    </h3>

                    <p class="muted">
                        ⌖ <?= e($item['location']) ?>
                    </p>

                    <span class="item-bottom">

                        View details
                        <span>→</span>

                    </span>

                </div>

            </a>

        <?php endforeach; ?>

        <?php if (!$items): ?>

            <div class="empty">
                No item reports found.
            </div>

        <?php endif; ?>

    </div>

    <?php
}
?>

<!doctype html>
<html lang="en">

<head>

    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <meta name="theme-color" content="#721923">

    <title>
        PLM FIND | Campus Lost and Found
    </title>

    <link
        rel="stylesheet"
        href="assets/style.css?v=2"
    >

</head>

<body class="<?= $user ? 'app-layout' : 'auth-layout' ?>">

<?php if ($user): ?>

    <aside class="sidebar">

        <a
            class="brand"
            href="<?= $user['role'] === 'admin' ? 'admin.php' : 'dashboard.php' ?>"
        >

            <span class="brand-mark">
                P.
            </span>

            <span>

                <strong>
                    PLM FIND
                </strong>

                <small>
                    LOST &amp; FOUND PORTAL
                </small>

            </span>

        </a>

        <div class="sidebar-caption">
            WORKSPACE
        </div>

        <?php
        require __DIR__ . '/sidebar-nav.php';
        ?>

        <div class="sidebar-bottom">

            <span class="avatar">

                <?= e(strtoupper(
                    substr($user['full_name'], 0, 1)
                )) ?>

            </span>

            <div>

                <strong>
                    <?= e($user['full_name']) ?>
                </strong>

                <small>
                    <?= e(ucfirst($user['role'])) ?>
                </small>

            </div>

        </div>

    </aside>

    <div class="main-area">

        <header class="topbar">

            <span class="topbar-kicker">
                PAMANTASAN NG LUNGSOD NG MAYNILA
            </span>

            <div class="topbar-right">

                <span class="user-chip">
                    <?= e($user['student_number']) ?>
                </span>

                <form method="post">

                    <input
                        type="hidden"
                        name="csrf"
                        value="<?= e(token()) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="logout"
                    >

                    <button
                        class="text-button"
                        type="submit"
                    >
                        Sign out ↗
                    </button>

                </form>

            </div>

        </header>

        <main class="content">

<?php else: ?>

    <div class="auth-aside">

        <a
            class="brand"
            href="login.php"
        >

            <span class="brand-mark">
                P.
            </span>

            <span>

                <strong>
                    PLM FIND
                </strong>

                <small>
                    LOST &amp; FOUND PORTAL
                </small>

            </span>

        </a>

        <div class="auth-statement">

            <span class="eyebrow light">
                YOUR CAMPUS. YOUR COMMUNITY.
            </span>

            <h1>
                Lost something?
                <em>Find your way back.</em>
            </h1>

            <p>
                A simpler, safer way for the PLM
                community to reconnect with their belongings.
            </p>

        </div>

        <div class="auth-footnote">
            PLM · CAMPUS COMMUNITY
        </div>

    </div>

    <main class="auth-main">

<?php endif; ?>

<?php if ($flash): ?>

    <div
        class="alert <?= ($flash['type'] ?? '') === 'error' ? 'alert-error' : '' ?>"
        role="status"
    >

        <?= e((string) $flash['message']) ?>

    </div>

<?php endif; ?>

<?php
/* =========================================
   LOAD THE CORRECT PAGE
========================================= */

$viewFiles = [
    'login' => 'login.php',
    'dashboard' => 'dashboard.php',
    'browse' => 'browse.php',
    'report' => 'report.php',
    'item' => 'item.php',
];

require __DIR__ . '/pages/' . $viewFiles[$page];
?>

<?php if ($user): ?>

        </main>

        <footer class="app-footer">
            PLM FIND · CAMPUS LOST &amp; FOUND MANAGEMENT SYSTEM
        </footer>

    </div>

<?php else: ?>

    </main>

<?php endif; ?>
<script src="assets/photo-preview.js" defer></script>
</body>
</html>
