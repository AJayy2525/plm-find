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

$userId = (int) $user['id'];

/* =========================================
   STUDENT ACKNOWLEDGMENT
========================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    check_token();

    if (($_POST['action'] ?? '') !== 'acknowledge') {

        http_response_code(403);

        exit(
            'Students cannot change report statuses.'
        );
    }

    try {

        $noticeId = (int) (
            $_POST['notice_id'] ?? 0
        );

        $db->beginTransaction();

        $stmt = $db->prepare(
            'SELECT
                n.id,
                n.status,
                n.lost_item_id,
                i.user_id
             FROM recovery_notices n
             JOIN items i ON i.id = n.lost_item_id
             WHERE n.id = ?
             FOR UPDATE'
        );

        $stmt->execute([$noticeId]);

        $noticeRow = $stmt->fetch();

        if (
            !$noticeRow ||
            (int) $noticeRow['user_id'] !== $userId ||
            $noticeRow['status'] !== 'notified'
        ) {

            throw new RuntimeException(
                'This notification cannot be acknowledged.'
            );
        }

        /* RECORD THE ACKNOWLEDGMENT */

        $stmt = $db->prepare(
            "UPDATE recovery_notices
             SET status = 'acknowledged',
                 acknowledged_at = NOW()
             WHERE id = ?
               AND status = 'notified'"
        );

        $stmt->execute([$noticeId]);

        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException(
                'This notification has already been handled.'
            );
        }

        /* NOTIFY THE ADMINISTRATORS */

        $adminIds = $db->query(
            "SELECT id
             FROM users
             WHERE role = 'admin'"
        )->fetchAll(PDO::FETCH_COLUMN);

        foreach ($adminIds as $adminId) {

            send_notification(
                $db,
                (int) $adminId,
                'PLM FIND: ' . $user['full_name'] .
                ' acknowledged the possible-match notice ' .
                'for lost report #' .
                $noticeRow['lost_item_id'] .
                '. The student plans to visit onsite.'
            );
        }

        $db->commit();

        notice(
            'Acknowledgment sent to the administrator. ' .
            'Visit the designated Lost and Found desk ' .
            'for onsite verification.'
        );

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
                'Unable to acknowledge the notification. ' .
                'Try again.',
                'error'
            );
        }
    }

    header(
        'Location: my-reports.php',
        true,
        303
    );
    exit;
}

/* =========================================
   LOAD STUDENT NOTIFICATIONS
========================================= */

$stmt = $db->prepare(
    'SELECT
        n.id,
        n.status,
        n.lost_item_id,
        n.notified_at,
        n.acknowledged_at,
        i.title AS lost_title
     FROM recovery_notices n
     JOIN items i ON i.id = n.lost_item_id
     WHERE i.user_id = ?
     ORDER BY n.id DESC
     LIMIT 50'
);

$stmt->execute([$userId]);

$notices = $stmt->fetchAll();

/* =========================================
   LOAD STUDENT REPORTS
========================================= */

$stmt = $db->prepare(
    'SELECT
        i.*,
        c.name AS category_name
     FROM items i
     JOIN categories c ON c.id = i.category_id
     WHERE i.user_id = ?
     ORDER BY i.created_at DESC
     LIMIT 100'
);

$stmt->execute([$userId]);

$reports = $stmt->fetchAll();

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

    <title>My Reports | PLM FIND</title>

    <link
        rel="stylesheet"
        href="assets/style.css?v=2"
    >

    <style>
        .student-grid {
            display: grid;
            grid-template-columns:
                repeat(auto-fit, minmax(260px, 1fr));
            gap: 16px;
        }

        .student-card {
            background: #fff;
            border: 1px solid #e6dcde;
            border-radius: 13px;
            padding: 18px;
            margin: 12px 0;
            overflow-wrap: anywhere;
        }

        .student-card p {
            font-size: 13px;
            color: #62565a;
            line-height: 1.6;
            white-space: pre-wrap;
        }

        .student-card img {
            width: 100%;
            height: 155px;
            object-fit: contain;
            background: #f7f2f2;
            border-radius: 8px;
        }

        .student-label {
            display: inline-block;
            border-radius: 20px;
            padding: 5px 10px;
            background: #f3e9eb;
            font-size: 12px;
            font-weight: bold;
        }

        .student-label.done {
            background: #dff2e6;
        }

        .student-note {
            border-left: 4px solid #721923;
        }
    </style>
</head>

<body class="app-layout">

    <aside class="sidebar">

        <a class="brand" href="dashboard.php">

            <span class="brand-mark">P.</span>

            <span>
                <strong>PLM FIND</strong>
                <small>LOST &amp; FOUND PORTAL</small>
            </span>

        </a>

        <div class="sidebar-caption">
            WORKSPACE
        </div>

        <?php require __DIR__ . '/sidebar-nav.php'; ?>

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

                <small>Student</small>
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

                <form
                    method="post"
                    action="dashboard.php"
                >

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

            <div class="page-heading">

                <div>
                    <span class="eyebrow">
                        STUDENT WORKSPACE
                    </span>

                    <h1>
                        My Reports &amp; Notifications
                    </h1>

                    <p class="muted">
                        If staff find a possible match,
                        acknowledge the notice and visit
                        Lost and Found onsite.
                    </p>
                </div>

                <a
                    class="primary-btn inline-btn"
                    href="report-lost.php"
                >
                    + New Report
                </a>

            </div>

            <?php if ($flash): ?>

                <div
                    class="alert <?= ($flash['type'] ?? '') === 'error' ? 'alert-error' : '' ?>"
                    role="status"
                >
                    <?= e((string) $flash['message']) ?>
                </div>

            <?php endif; ?>

            <h2 id="notifications">
                Notifications
            </h2>

            <div class="student-grid">

                <?php foreach ($notices as $n): ?>

                    <article class="student-card <?= $n['status'] === 'notified' ? 'student-note' : '' ?>">

                        <span class="eyebrow">
                            LOST REPORT #<?= (int) $n['lost_item_id'] ?>
                        </span>

                        <h3><?= e($n['lost_title']) ?></h3>

                        <?php if ($n['status'] === 'notified'): ?>

                            <span class="student-label">
                                New — Possible Match
                            </span>

                            <p>
                                PLM FIND may have found an item
                                matching your lost report.

                                Please visit the designated campus
                                Lost and Found desk for
                                <strong>
                                    onsite ownership verification
                                    and claiming.
                                </strong>
                            </p>

                            <p>
                                Click Acknowledge to let the
                                administrator know you have seen
                                this notice. This does not confirm
                                the item belongs to you.
                            </p>

                            <form method="post">

                                <input
                                    type="hidden"
                                    name="csrf"
                                    value="<?= e(token()) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="acknowledge"
                                >

                                <input
                                    type="hidden"
                                    name="notice_id"
                                    value="<?= (int) $n['id'] ?>"
                                >

                                <button
                                    class="primary-btn inline-btn"
                                    type="submit"
                                >
                                    Acknowledge — I Have Seen This
                                </button>

                            </form>

                        <?php elseif (
                            $n['status'] === 'acknowledged'
                        ): ?>

                            <span class="student-label done">
                                Acknowledged
                            </span>

                            <p>
                                You've informed the administrator.

                                Please visit Lost and Found onsite
                                for verification.

                                Acknowledgment is not ownership
                                approval.
                            </p>

                        <?php elseif (
                            $n['status'] === 'returned'
                        ): ?>

                            <span class="student-label done">
                                Returned / Resolved
                            </span>

                            <p>
                                Staff recorded the item's return.
                                Your lost report is resolved.
                            </p>

                        <?php else: ?>

                            <span class="student-label">
                                Notice Cancelled
                            </span>

                            <p>
                                The possible match was not confirmed.
                                Your original lost report remains open.
                            </p>

                        <?php endif; ?>

                        <p>
                            <small>
                                Notified:
                                <?= e($n['notified_at']) ?>
                            </small>
                        </p>

                    </article>

                <?php endforeach; ?>

                <?php if (!$notices): ?>

                    <div class="student-card">
                        <p>
                            No possible-match notifications yet.
                            You'll see them here if an administrator
                            identifies a possible match.
                        </p>
                    </div>

                <?php endif; ?>

            </div>

            <h2 style="margin-top:24px">
                My Submitted Reports
            </h2>

            <div class="student-grid">

                <?php foreach ($reports as $r): ?>

                    <article class="student-card">

                        <span class="eyebrow">
                            <?= e(strtoupper($r['type'])) ?>
                            #<?= (int) $r['id'] ?>
                            ·
                            <?= e($r['category_name']) ?>
                        </span>

                        <h3><?= e($r['title']) ?></h3>

                        <?php if (!empty($r['photo'])): ?>

                            <img
                                src="<?= e($r['photo']) ?>"
                                alt="Item report"
                                loading="lazy"
                            >

                        <?php endif; ?>

                        <p><?= e($r['description']) ?></p>

                        <p>
                            <strong>Location:</strong>
                            <?= e($r['location']) ?>

                            <strong>Date:</strong>
                            <?= e($r['event_date']) ?>
                        </p>

                        <span class="student-label <?= $r['status'] === 'resolved' ? 'done' : '' ?>">
                            <?= e(ucfirst($r['status'])) ?>
                        </span>

                    </article>

                <?php endforeach; ?>

                <?php if (!$reports): ?>

                    <div class="student-card">
                        <p>
                            You haven't submitted a report yet.
                        </p>
                    </div>

                <?php endif; ?>

            </div>

        </main>

        <footer class="app-footer">
            PLM FIND · CAMPUS LOST &amp; FOUND
        </footer>

    </div>

</body>
</html>
