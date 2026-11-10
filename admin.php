<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

$user = current_user($db);

if (!$user) {
    go('login');
}

if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Administrator access required.');
}

$adminId = (int) $user['id'];

$view = (string) ($_GET['view'] ?? 'overview');

if (!in_array(
    $view,
    ['overview', 'lost', 'found', 'returns'],
    true
)) {
    $view = 'overview';
}

/* =========================================
   ADMIN ACTIONS
========================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    check_token();

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'logout') {
        $_SESSION = [];
        session_regenerate_id(true);
        go('login');
    }

    try {
        $db->beginTransaction();

        /* NOTIFY THE LOST-ITEM REPORTER */

        if ($action === 'notify') {

            $lostId = (int) (
                $_POST['lost_item_id'] ?? 0
            );

            $foundId = (int) (
                $_POST['found_item_id'] ?? 0
            );

            $confirmed = (
                ($_POST['custody_confirmed'] ?? '') === '1'
            );

            if (
                $lostId < 1 ||
                $foundId < 1 ||
                $lostId === $foundId ||
                !$confirmed
            ) {
                throw new RuntimeException(
                    'Select both reports and confirm ' .
                    'staff have the found item.'
                );
            }

            $stmt = $db->prepare(
                'SELECT id, user_id, title, type, status
                 FROM items
                 WHERE id IN (?, ?)
                 ORDER BY id
                 FOR UPDATE'
            );

            $stmt->execute([
                $lostId,
                $foundId
            ]);

            $items = [];

            foreach ($stmt->fetchAll() as $item) {
                $items[(int) $item['id']] = $item;
            }

            if (
                !isset($items[$lostId], $items[$foundId]) ||
                $items[$lostId]['type'] !== 'lost' ||
                $items[$foundId]['type'] !== 'found' ||
                $items[$lostId]['status'] !== 'open' ||
                $items[$foundId]['status'] !== 'open'
            ) {
                throw new RuntimeException(
                    'Choose one open lost report ' .
                    'and one open found report.'
                );
            }

            /* Prevent duplicate active notices. */

            $stmt = $db->prepare(
                "SELECT id
                 FROM recovery_notices
                 WHERE status IN (
                    'notified',
                    'acknowledged'
                 )
                 AND (
                    lost_item_id = ?
                    OR found_item_id = ?
                 )
                 LIMIT 1"
            );

            $stmt->execute([
                $lostId,
                $foundId
            ]);

            if ($stmt->fetch()) {
                throw new RuntimeException(
                    'One of these items already has ' .
                    'an active notice.'
                );
            }

            /* Save the notification record. */

            $stmt = $db->prepare(
                'INSERT INTO recovery_notices
                 (
                    lost_item_id,
                    found_item_id,
                    notified_by
                 )
                 VALUES (?, ?, ?)'
            );

            $stmt->execute([
                $lostId,
                $foundId,
                $adminId
            ]);

            /* Notify the student who lost the item. */

            send_notification(
                $db,
                (int) $items[$lostId]['user_id'],
                'PLM FIND: A possible match was found ' .
                'for your lost report #' . $lostId .
                ' (' . $items[$lostId]['title'] . '). ' .
                'Open My Reports, read the notice ' .
                'and click Acknowledge. ' .
                'Please visit the designated campus ' .
                'Lost and Found desk for onsite ' .
                'ownership verification.'
            );

            $message =
                'Notification sent to the student ' .
                'who reported the lost item.';

        /* RETURN OR CANCEL */

        } elseif (
            $action === 'returned' ||
            $action === 'cancel'
        ) {

            $noticeId = (int) (
                $_POST['notice_id'] ?? 0
            );

            $stmt = $db->prepare(
                'SELECT
                    n.*,
                    l.user_id AS lost_user_id,
                    f.user_id AS found_user_id
                 FROM recovery_notices n
                 JOIN items l ON l.id = n.lost_item_id
                 JOIN items f ON f.id = n.found_item_id
                 WHERE n.id = ?
                 FOR UPDATE'
            );

            $stmt->execute([$noticeId]);

            $noticeRow = $stmt->fetch();

            if (!$noticeRow) {
                throw new RuntimeException(
                    'Notification record not found.'
                );
            }

            /* CONFIRM ONSITE RETURN */

            if ($action === 'returned') {

                if (
                    $noticeRow['status'] !== 'acknowledged' ||
                    ($_POST['handover_confirmed'] ?? '') !== '1'
                ) {
                    throw new RuntimeException(
                        'The student must acknowledge first, ' .
                        'and staff must verify ownership ' .
                        'and handover onsite.'
                    );
                }

                $stmt = $db->prepare(
                    'SELECT id, status
                     FROM items
                     WHERE id IN (?, ?)
                     ORDER BY id
                     FOR UPDATE'
                );

                $stmt->execute([
                    (int) $noticeRow['lost_item_id'],
                    (int) $noticeRow['found_item_id']
                ]);

                $linked = $stmt->fetchAll();

                if (
                    count($linked) !== 2 ||
                    $linked[0]['status'] !== 'open' ||
                    $linked[1]['status'] !== 'open'
                ) {
                    throw new RuntimeException(
                        'Both linked reports must still be open.'
                    );
                }

                $notes = trim(
                    (string) ($_POST['return_notes'] ?? '')
                );

                if (mb_strlen($notes) > 500) {
                    throw new RuntimeException(
                        'Return notes must be ' .
                        '500 characters or fewer.'
                    );
                }

                $stmt = $db->prepare(
                    "UPDATE recovery_notices
                     SET status = 'returned',
                         returned_at = NOW(),
                         returned_by = ?,
                         return_notes = ?
                     WHERE id = ?"
                );

                $stmt->execute([
                    $adminId,
                    $notes !== '' ? $notes : null,
                    $noticeId
                ]);

                $stmt = $db->prepare(
                    "UPDATE items
                     SET status = 'resolved',
                         resolved_at = NOW()
                     WHERE id IN (?, ?)
                       AND status = 'open'"
                );

                $stmt->execute([
                    (int) $noticeRow['lost_item_id'],
                    (int) $noticeRow['found_item_id']
                ]);

                if ($stmt->rowCount() !== 2) {
                    throw new RuntimeException(
                        'Unable to resolve both reports.'
                    );
                }

                foreach (array_unique([
                    (int) $noticeRow['lost_user_id'],
                    (int) $noticeRow['found_user_id']
                ]) as $recipientId) {

                    send_notification(
                        $db,
                        $recipientId,
                        'PLM FIND: Your report was resolved ' .
                        'after staff confirmed the item was returned.'
                    );
                }

                $message =
                    'Item returned. Both reports are now resolved.';

            /* CANCEL AN INCORRECT NOTIFICATION */

            } else {

                if (!in_array(
                    $noticeRow['status'],
                    ['notified', 'acknowledged'],
                    true
                )) {
                    throw new RuntimeException(
                        'Only an active notification ' .
                        'can be cancelled.'
                    );
                }

                $stmt = $db->prepare(
                    "UPDATE recovery_notices
                     SET status = 'cancelled',
                         cancelled_at = NOW(),
                         cancelled_by = ?
                     WHERE id = ?"
                );

                $stmt->execute([
                    $adminId,
                    $noticeId
                ]);

                send_notification(
                    $db,
                    (int) $noticeRow['lost_user_id'],
                    'PLM FIND: The previous possible-match ' .
                    'notice for your lost report was cancelled. ' .
                    'Your report remains open.'
                );

                $message =
                    'Notification cancelled. ' .
                    'Both reports remain open.';
            }

        } else {
            throw new RuntimeException(
                'Invalid administrator action.'
            );
        }

        $db->commit();

        notice($message);

    } catch (Throwable $error) {

        if ($db->inTransaction()) {
            $db->rollBack();
        }

        if ($error instanceof RuntimeException) {

            notice(
                $error->getMessage(),
                'error'
            );

        } else {

            error_log((string) $error);

            notice(
                'Unable to complete this action. ' .
                'Check the server error log.',
                'error'
            );
        }
    }

    header(
        'Location: admin.php?view=lost',
        true,
        303
    );
    exit;
}

/* =========================================
   LOAD REPORTS
========================================= */

$reports = $db->query(
    'SELECT
        i.*,
        c.name AS category_name,
        u.full_name AS reporter_name,
        u.email AS reporter_email
     FROM items i
     JOIN categories c ON c.id = i.category_id
     JOIN users u ON u.id = i.user_id
     ORDER BY i.created_at DESC
     LIMIT 300'
)->fetchAll();

$lostReports = [];
$foundReports = [];

foreach ($reports as $report) {

    if ($report['type'] === 'lost') {
        $lostReports[] = $report;

    } elseif ($report['type'] === 'found') {
        $foundReports[] = $report;
    }
}

/* =========================================
   LOAD NOTIFICATION RECORDS
========================================= */

$notices = $db->query(
    'SELECT
        n.*,
        l.title AS lost_title,
        f.title AS found_title,
        u.full_name AS lost_reporter
     FROM recovery_notices n
     JOIN items l ON l.id = n.lost_item_id
     JOIN items f ON f.id = n.found_item_id
     JOIN users u ON u.id = l.user_id
     ORDER BY n.id DESC
     LIMIT 200'
)->fetchAll();

$activeByLost = [];
$activeByFound = [];

foreach ($notices as $n) {

    if (in_array(
        $n['status'],
        ['notified', 'acknowledged'],
        true
    )) {

        $activeByLost[(int) $n['lost_item_id']] = $n;

        $activeByFound[(int) $n['found_item_id']] = $n;
    }
}

/* =========================================
   ADMIN STATISTICS
========================================= */

$stats = $db->query(
    "SELECT
      (
        SELECT COUNT(*)
        FROM items
        WHERE type = 'lost' AND status = 'open'
      ) AS lost,

      (
        SELECT COUNT(*)
        FROM items
        WHERE type = 'found' AND status = 'open'
      ) AS found,

      (
        SELECT COUNT(*)
        FROM recovery_notices
        WHERE status = 'notified'
      ) AS waiting,

      (
        SELECT COUNT(*)
        FROM recovery_notices
        WHERE status = 'acknowledged'
      ) AS acknowledged,

      (
        SELECT COUNT(*)
        FROM recovery_notices
        WHERE status = 'returned'
      ) AS returned"
)->fetch();

/* ADMIN NOTIFICATIONS */

$stmt = $db->prepare(
    'SELECT message, created_at
     FROM notifications
     WHERE user_id = ?
     ORDER BY id DESC
     LIMIT 8'
);

$stmt->execute([$adminId]);
$adminAlerts = $stmt->fetchAll();

$flash = $_SESSION['notice'] ?? null;
unset($_SESSION['notice']);
?>

<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>Admin | PLM FIND</title>

    <link
        rel="stylesheet"
        href="assets/style.css?v=5"
    >

    <style>
        .simple-grid {
            display: grid;
            grid-template-columns:
                repeat(auto-fit, minmax(280px, 1fr));
            gap: 16px;
        }

        .simple-card {
            background: #fff;
            border: 1px solid #e8dedf;
            border-radius: 14px;
            padding: 18px;
            margin: 12px 0;
            overflow-wrap: anywhere;
        }

        .simple-card h3 {
            margin: 8px 0;
        }

        .simple-card p {
            font-size: 13px;
            color: #5d5256;
            line-height: 1.6;
            white-space: pre-wrap;
        }

        .simple-card img {
            width: 100%;
            max-height: 180px;
            object-fit: contain;
            background: #f7f1f2;
            border-radius: 8px;
        }

        .simple-form {
            display: grid;
            gap: 10px;
            margin-top: 14px;
        }

        .simple-form select,
        .simple-form textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #cbbfc1;
            border-radius: 8px;
            background: #fff;
        }

        .simple-form label {
            display: grid;
            gap: 6px;
            font-size: 13px;
        }

        .simple-form .simple-check {
            display: flex;
            align-items: flex-start;
            gap: 8px;
        }

        .simple-status {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 18px;
            background: #f3e8e9;
            font-size: 12px;
            font-weight: 700;
        }

        .simple-status.done {
            background: #dff2e6;
        }

        .simple-muted {
            font-size: 12px;
            color: #75686b;
        }
        
        /* =========================================
        PLM FIND — ADMIN DARK MODE
        ========================================= */

        html.theme-dark body,
        html.theme-dark .main-area,
        html.theme-dark .content {
            background: #11151c !important;
            color: #edf2f7 !important;
        }

        html.theme-dark .sidebar {
            background: #431923 !important;
            color: #ffffff !important;
        }

        html.theme-dark .sidebar .brand,
        html.theme-dark .nav-links a {
            color: #f2dfe4 !important;
        }

        html.theme-dark .nav-links a.active,
        html.theme-dark .nav-links a:hover {
            background: #68303d !important;
            color: #ffffff !important;
        }

        html.theme-dark .topbar,
        html.theme-dark .stat-card,
        html.theme-dark .simple-card {
            background: #1d2531 !important;
            color: #edf2f7 !important;
            border-color: #374253 !important;
        }

        html.theme-dark .simple-card h3,
        html.theme-dark .simple-card h4,
        html.theme-dark .simple-card p,
        html.theme-dark .simple-form label {
            color: #edf2f7 !important;
        }

        html.theme-dark .simple-muted,
        html.theme-dark .muted {
            color: #b5c1d0 !important;
        }

        html.theme-dark .simple-form select,
        html.theme-dark .simple-form textarea {
            background: #283241 !important;
            color: #ffffff !important;
            border-color: #536174 !important;
        }

        html.theme-dark .simple-card img {
            background: #283241 !important;
        }

        html.theme-dark .simple-status {
            background: #354052 !important;
            color: #edf2f7 !important;
        }

        html.theme-dark .simple-status.done {
            background: #1d523b !important;
            color: #b4f3d2 !important;
        }

        html.theme-dark .stat-card span,
        html.theme-dark .stat-card small {
            color: #b5c1d0 !important;
        }

        html.theme-dark .app-footer {
            color: #aab6c7 !important;
        }



    </style>
</head>

<body class="app-layout">

    <aside class="sidebar">

        <a class="brand" href="admin.php">
            <span class="brand-mark">P.</span>
            <span>
                <strong>PLM FIND</strong>
                <small>ADMIN PORTAL</small>
            </span>
        </a>

        <div class="sidebar-caption">
            ADMINISTRATION
        </div>

        <?php require __DIR__ . '/sidebar-nav.php'; ?>

        <div class="sidebar-bottom">
            <span class="avatar">
                <?= e(strtoupper(
                    substr($user['full_name'], 0, 1)
                )) ?>
            </span>

            <div>
                <strong><?= e($user['full_name']) ?></strong>
                <small>Administrator</small>
            </div>
        </div>

    </aside>

    <div class="main-area">

        <header class="topbar">

            <span class="topbar-kicker">
                PAMANTASAN NG LUNGSOD NG MAYNILA
            </span>

            <div class="topbar-right">

                <span class="user-chip">ADMIN</span>

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

            <?php if ($flash): ?>

                <div
                    class="alert <?= ($flash['type'] ?? '') === 'error' ? 'alert-error' : '' ?>"
                    role="status"
                >
                    <?= e((string) $flash['message']) ?>
                </div>

            <?php endif; ?>

            <div class="page-heading">
                <div>

                    <span class="eyebrow">
                        ADMINISTRATOR WORKSPACE
                    </span>

                    <h1>
                        <?= e(match ($view) {
                            'lost' => 'Lost Reports',
                            'found' => 'Found Reports',
                            'returns' => 'Return Records',
                            default => 'Admin Dashboard'
                        }) ?>
                    </h1>

                    <p class="muted">
                        Review reports manually, notify the owner,
                        and check their acknowledgment.
                    </p>

                </div>
            </div>

            <div class="stats-grid">

                <?php foreach ([
                    'OPEN LOST' => 'lost',
                    'OPEN FOUND' => 'found',
                    'WAITING FOR STUDENT' => 'waiting',
                    'ACKNOWLEDGED' => 'acknowledged',
                    'RETURNED' => 'returned'
                ] as $label => $key): ?>

                    <div class="stat-card">
                        <span><?= e($label) ?></span>
                        <strong>
                            <?= (int) $stats[$key] ?>
                        </strong>
                    </div>

                <?php endforeach; ?>

            </div>
            

            <?php if ($view === 'overview'): ?>

                <div class="simple-grid">

                    <section class="simple-card">

                        <h3>1. Check Lost Reports</h3>

                        <p>
                            Look at each student's lost-item
                            description, location, and date.
                        </p>

                        <a
                            class="outline-btn"
                            href="admin.php?view=lost"
                        >
                            Open Lost Reports
                        </a>

                    </section>

                    <section class="simple-card">

                        <h3>2. Check Found Reports</h3>

                        <p>
                            Compare the found-item details with
                            the lost reports. Make sure staff have
                            received the item before notifying anyone.
                        </p>

                        <a
                            class="outline-btn"
                            href="admin.php?view=found"
                        >
                            Open Found Reports
                        </a>

                    </section>

                    <section class="simple-card">

                        <h3>3. Notify and Wait</h3>

                        <p>
                            Back in Lost Reports, select the matching
                            found report and click Notify Owner.
                            You'll see Acknowledged when the student responds.
                        </p>

                        <a
                            class="primary-btn inline-btn"
                            href="admin.php?view=lost"
                        >
                            Manage Notifications
                        </a>

                    </section>

                </div>

                <section class="simple-card">

                    <h3>
                        Recent Student Responses and Updates
                    </h3>

                    <?php foreach ($adminAlerts as $a): ?>

                        <p>
                            <?= e($a['message']) ?>

                            <span class="simple-muted">
                                <?= e($a['created_at']) ?>
                            </span>
                        </p>

                    <?php endforeach; ?>

                    <?php if (!$adminAlerts): ?>
                        <p>No new updates yet.</p>
                    <?php endif; ?>

                </section>

            <?php elseif ($view === 'lost'): ?>

                <p class="muted">
                    Manually compare each lost report with the
                    Found Reports tab. When you're confident it's
                    a possible match, select the corresponding
                    found report below and notify the student.
                </p>

                <div class="simple-grid">

                    <?php foreach ($lostReports as $r): ?>

                        <article class="simple-card">

                            <span class="eyebrow">
                                LOST #<?= (int) $r['id'] ?>
                                ·
                                <?= e($r['category_name']) ?>
                            </span>

                            <h3><?= e($r['title']) ?></h3>

                            <?php if (!empty($r['photo'])): ?>
                                <img
                                    src="<?= e($r['photo']) ?>"
                                    alt="Lost item report"
                                    loading="lazy"
                                >
                            <?php endif; ?>

                            <p><?= e($r['description']) ?></p>

                            <p>
                                <strong>Location:</strong>
                                <?= e($r['location']) ?>

                                <strong>Date:</strong>
                                <?= e($r['event_date']) ?>

                                <strong>Student:</strong>
                                <?= e($r['reporter_name']) ?>
                            </p>

                            <?php
                            $active = $activeByLost[
                                (int) $r['id']
                            ] ?? null;
                            ?>

                            <?php if ($r['status'] === 'resolved'): ?>

                                <span class="simple-status done">
                                    Resolved
                                </span>

                            <?php elseif ($active): ?>

                                <span class="simple-status <?= $active['status'] === 'acknowledged' ? 'done' : '' ?>">
                                    <?= $active['status'] === 'acknowledged'
                                        ? 'Student Acknowledged'
                                        : 'Owner Notified — Waiting' ?>
                                </span>

                                <p>
                                    <strong>Linked found report:</strong>
                                    #<?= (int) $active['found_item_id'] ?>
                                    —
                                    <?= e($active['found_title']) ?>
                                </p>

                                <?php if (
                                    $active['status'] === 'acknowledged'
                                ): ?>

                                    <p>
                                        The student has seen the notification.
                                        Verify identity and ownership onsite
                                        before releasing the item.
                                    </p>

                                    <form
                                        class="simple-form"
                                        method="post"
                                    >

                                        <input
                                            type="hidden"
                                            name="csrf"
                                            value="<?= e(token()) ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="returned"
                                        >

                                        <input
                                            type="hidden"
                                            name="notice_id"
                                            value="<?= (int) $active['id'] ?>"
                                        >

                                        <label>
                                            Optional handover notes

                                            <textarea
                                                name="return_notes"
                                                maxlength="500"
                                                rows="2"
                                                placeholder="Date, place and staff reference (no full ID numbers)"
                                            ></textarea>
                                        </label>

                                        <label class="simple-check">

                                            <input
                                                type="checkbox"
                                                name="handover_confirmed"
                                                value="1"
                                                required
                                            >

                                            I verified ownership onsite
                                            and physically returned the item.

                                        </label>

                                        <button
                                            class="primary-btn inline-btn"
                                            type="submit"
                                        >
                                            Mark Item Returned
                                        </button>

                                    </form>

                                <?php endif; ?>

                                <details>

                                    <summary>
                                        Wrong match? Cancel notification
                                    </summary>

                                    <form
                                        class="simple-form"
                                        method="post"
                                        onsubmit="return confirm('Cancel this possible-match notification?')"
                                    >

                                        <input
                                            type="hidden"
                                            name="csrf"
                                            value="<?= e(token()) ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="cancel"
                                        >

                                        <input
                                            type="hidden"
                                            name="notice_id"
                                            value="<?= (int) $active['id'] ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="outline-btn"
                                        >
                                            Cancel Notification
                                        </button>

                                    </form>

                                </details>

                            <?php else: ?>

                                <h4>
                                    Notify student of a possible match
                                </h4>

                                <form
                                    class="simple-form"
                                    method="post"
                                >

                                    <input
                                        type="hidden"
                                        name="csrf"
                                        value="<?= e(token()) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="notify"
                                    >

                                    <input
                                        type="hidden"
                                        name="lost_item_id"
                                        value="<?= (int) $r['id'] ?>"
                                    >

                                    <label>
                                        Matching found report

                                        <select
                                            name="found_item_id"
                                            required
                                        >

                                            <option value="">
                                                Select the report you checked
                                            </option>

                                            <?php foreach ($foundReports as $f): ?>

                                                <?php
                                                if (
                                                    $f['status'] !== 'open' ||
                                                    isset(
                                                        $activeByFound[
                                                            (int) $f['id']
                                                        ]
                                                    )
                                                ) {
                                                    continue;
                                                }
                                                ?>

                                                <option value="<?= (int) $f['id'] ?>">
                                                    #<?= (int) $f['id'] ?>
                                                    · <?= e($f['title']) ?>
                                                    · <?= e($f['category_name']) ?>
                                                    · <?= e($f['location']) ?>
                                                </option>

                                            <?php endforeach; ?>

                                        </select>
                                    </label>

                                    <label class="simple-check">

                                        <input
                                            type="checkbox"
                                            name="custody_confirmed"
                                            value="1"
                                            required
                                        >

                                        I confirm authorized staff have
                                        received the found item.

                                    </label>

                                    <button
                                        class="primary-btn inline-btn"
                                        type="submit"
                                    >
                                        Notify Owner
                                    </button>

                                </form>

                                <p class="simple-muted">
                                    The student receives a fixed notice
                                    to visit campus Lost and Found and
                                    acknowledge it. No chat is created.
                                </p>

                            <?php endif; ?>

                        </article>

                    <?php endforeach; ?>

                    <?php if (!$lostReports): ?>
                        <p>No lost reports.</p>
                    <?php endif; ?>

                </div>

            <?php elseif ($view === 'found'): ?>

                <p class="muted">
                    Review found-item information here,
                    then return to Lost Reports to notify
                    the appropriate student.
                </p>

                <div class="simple-grid">

                    <?php foreach ($foundReports as $r): ?>

                        <article class="simple-card">

                            <span class="eyebrow">
                                FOUND #<?= (int) $r['id'] ?>
                                · <?= e($r['category_name']) ?>
                            </span>

                            <h3><?= e($r['title']) ?></h3>

                            <?php if (!empty($r['photo'])): ?>

                                <img
                                    src="<?= e($r['photo']) ?>"
                                    alt="Found item report"
                                    loading="lazy"
                                >

                            <?php endif; ?>

                            <p><?= e($r['description']) ?></p>

                            <p>
                                <strong>Location:</strong>
                                <?= e($r['location']) ?>

                                <strong>Date:</strong>
                                <?= e($r['event_date']) ?>

                                <strong>Reporter:</strong>
                                <?= e($r['reporter_name']) ?>
                            </p>

                            <?php if ($r['status'] === 'resolved'): ?>

                                <span class="simple-status done">
                                    Resolved
                                </span>

                            <?php elseif (isset(
                                $activeByFound[(int) $r['id']]
                            )): ?>

                                <span class="simple-status">
                                    Notification active
                                </span>

                            <?php else: ?>

                                <span class="simple-status">
                                    Open
                                </span>

                            <?php endif; ?>

                        </article>

                    <?php endforeach; ?>

                    <?php if (!$foundReports): ?>
                        <p>No found reports.</p>
                    <?php endif; ?>

                </div>

            <?php else: ?>

                <h2>Completed Returns</h2>

                <div class="simple-grid">

                    <?php
                    $shown = 0;

                    foreach ($notices as $n):

                        if ($n['status'] !== 'returned') {
                            continue;
                        }

                        $shown++;
                    ?>

                        <article class="simple-card">

                            <span class="eyebrow">
                                NOTICE #<?= (int) $n['id'] ?>
                            </span>

                            <h3>
                                <?= e($n['lost_title']) ?>
                                ↔
                                <?= e($n['found_title']) ?>
                            </h3>

                            <span class="simple-status done">
                                Returned
                            </span>

                            <p>
                                <strong>Student:</strong>
                                <?= e($n['lost_reporter']) ?>

                                <strong>Notified:</strong>
                                <?= e($n['notified_at']) ?>

                                <strong>Acknowledged:</strong>
                                <?= e($n['acknowledged_at']) ?>

                                <strong>Returned:</strong>
                                <?= e($n['returned_at']) ?>
                            </p>

                            <p><?= e($n['return_notes']) ?></p>

                        </article>

                    <?php endforeach; ?>

                    <?php if (!$shown): ?>
                        <p>No completed returns yet.</p>
                    <?php endif; ?>

                </div>

            <?php endif; ?>

        </main>

        <footer class="app-footer">
            PLM FIND · ADMINISTRATOR WORKSPACE
        </footer>

    </div>
    
</body>
</html>
