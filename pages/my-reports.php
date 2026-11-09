<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

$user = current_user($db);

if (!$user) {
    header('Location: login.php');
    exit;
}

$userId = (int) $user['id'];

function reports_redirect(
    string $message,
    string $type = 'success'
): never {
    notice($message, $type);
    header('Location: my-reports.php');
    exit;
}

function remove_report_photo(?string $path): void {
    if (
        !$path ||
        !preg_match(
            '#^uploads/[a-f0-9]{32}\.(jpg|png|webp)$#',
            $path
        )
    ) {
        return;
    }

    $fullPath = __DIR__ . '/' . $path;

    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}

/* =========================================
   EDIT, DELETE, RESOLVE
========================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    check_token();

    $action = (string) ($_POST['action'] ?? '');
    $itemId = (int) ($_POST['item_id'] ?? 0);
    $newPhoto = null;

    try {

        if (
            !in_array($action, ['edit', 'delete', 'resolve'], true) ||
            $itemId < 1
        ) {
            throw new RuntimeException('Invalid report action.');
        }

        $db->beginTransaction();

        $stmt = $db->prepare(
            'SELECT *
             FROM items
             WHERE id = ? AND user_id = ?
             FOR UPDATE'
        );

        $stmt->execute([$itemId, $userId]);
        $item = $stmt->fetch();

        if (!$item) {
            throw new RuntimeException(
                'Report not found or access denied.'
            );
        }

        if ($item['status'] !== 'open') {
            throw new RuntimeException(
                'Resolved reports cannot be modified.'
            );
        }

        /* MARK RECOVERED */

        if ($action === 'resolve') {

            if ($item['type'] !== 'lost') {
                throw new RuntimeException(
                    'Only lost reports can be marked recovered.'
                );
            }

            $stmt = $db->prepare(
                "UPDATE items
                 SET status = 'resolved',
                     resolved_at = NOW()
                 WHERE id = ? AND user_id = ?"
            );

            $stmt->execute([$itemId, $userId]);

            $db->commit();

            reports_redirect(
                'Your lost-item report is marked as resolved.'
            );
        }

        /* PROTECT CLAIM HISTORY */

        $stmt = $db->prepare(
            'SELECT COUNT(*)
             FROM claims
             WHERE item_id = ?'
        );

        $stmt->execute([$itemId]);
        $claimCount = (int) $stmt->fetchColumn();

        if ($claimCount > 0) {
            throw new RuntimeException(
                'This report has claim history and cannot be edited or deleted.'
            );
        }

        /* DELETE REPORT */

        if ($action === 'delete') {

            $stmt = $db->prepare(
                'DELETE FROM items
                 WHERE id = ? AND user_id = ?'
            );

            $stmt->execute([$itemId, $userId]);

            $db->commit();

            remove_report_photo($item['photo']);

            reports_redirect('Report deleted successfully.');
        }

        /* EDIT REPORT */

        $title = trim((string) ($_POST['title'] ?? ''));
        $description = trim(
            (string) ($_POST['description'] ?? '')
        );
        $location = trim((string) ($_POST['location'] ?? ''));
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $eventDate = (string) ($_POST['event_date'] ?? '');

        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $eventDate
        );

        if (
            mb_strlen($title) < 3 ||
            mb_strlen($title) > 140 ||
            mb_strlen($description) < 10 ||
            mb_strlen($description) > 4000 ||
            mb_strlen($location) < 3 ||
            mb_strlen($location) > 180
        ) {
            throw new RuntimeException(
                'Check the item name, description and location.'
            );
        }

        if (
            !$date ||
            $date->format('Y-m-d') !== $eventDate ||
            $eventDate > date('Y-m-d')
        ) {
            throw new RuntimeException('Choose a valid date.');
        }

        $stmt = $db->prepare(
            'SELECT COUNT(*)
             FROM categories
             WHERE id = ?'
        );

        $stmt->execute([$categoryId]);

        if (!(int) $stmt->fetchColumn()) {
            throw new RuntimeException('Choose a valid category.');
        }

        $newPhoto = upload_photo($_FILES['photo'] ?? []);

        $removePhoto = (
            $_POST['remove_photo'] ?? ''
        ) === '1';

        $finalPhoto = $newPhoto ?? (
            $removePhoto ? null : $item['photo']
        );

        $stmt = $db->prepare(
            'UPDATE items
             SET title = ?,
                 description = ?,
                 location = ?,
                 category_id = ?,
                 event_date = ?,
                 photo = ?
             WHERE id = ? AND user_id = ?'
        );

        $stmt->execute([
            $title,
            $description,
            $location,
            $categoryId,
            $eventDate,
            $finalPhoto,
            $itemId,
            $userId
        ]);

        $db->commit();

        if ($finalPhoto !== $item['photo']) {
            remove_report_photo($item['photo']);
        }

        reports_redirect('Your report has been updated.');

    } catch (Throwable $ex) {

        if ($db->inTransaction()) {
            $db->rollBack();
        }

        if ($newPhoto !== null) {
            remove_report_photo($newPhoto);
        }

        if ($ex instanceof RuntimeException) {
            reports_redirect($ex->getMessage(), 'error');
        }

        error_log((string) $ex);

        reports_redirect(
            'Unable to save your changes.',
            'error'
        );
    }
}

/* =========================================
   LOAD REPORT DATA
========================================= */

$categories = $db->query(
    'SELECT id, name
     FROM categories
     ORDER BY name'
)->fetchAll();

/* EDIT RECORD */

$editing = null;
$editError = '';
$editId = (int) ($_GET['edit'] ?? 0);

if ($editId > 0) {

    $stmt = $db->prepare(
        'SELECT i.*,
            (
                SELECT COUNT(*)
                FROM claims cl
                WHERE cl.item_id = i.id
            ) AS claim_count
         FROM items i
         WHERE i.id = ? AND i.user_id = ?'
    );

    $stmt->execute([$editId, $userId]);

    $editing = $stmt->fetch() ?: null;

    if (
        !$editing ||
        $editing['status'] !== 'open' ||
        (int) $editing['claim_count'] > 0
    ) {
        $editing = null;
        $editError = 'This report is not available for editing.';
    }
}

/* FILTERS */

$statusFilter = (string) ($_GET['status'] ?? 'all');

if (!in_array(
    $statusFilter,
    ['all', 'open', 'resolved'],
    true
)) {
    $statusFilter = 'all';
}

$typeFilter = (string) ($_GET['type'] ?? 'all');

if (!in_array(
    $typeFilter,
    ['all', 'lost', 'found'],
    true
)) {
    $typeFilter = 'all';
}

$where = 'i.user_id = ?';
$params = [$userId];

if ($statusFilter !== 'all') {
    $where .= ' AND i.status = ?';
    $params[] = $statusFilter;
}

if ($typeFilter !== 'all') {
    $where .= ' AND i.type = ?';
    $params[] = $typeFilter;
}

$stmt = $db->prepare(
    'SELECT i.*,
            c.name AS category_name,
            (
                SELECT COUNT(*)
                FROM claims cl
                WHERE cl.item_id = i.id
            ) AS claim_count
     FROM items i
     JOIN categories c ON c.id = i.category_id
     WHERE ' . $where . '
     ORDER BY i.created_at DESC
     LIMIT 100'
);

$stmt->execute($params);
$reports = $stmt->fetchAll();

/* STATISTICS */

$stmt = $db->prepare(
    "SELECT
        COUNT(*) AS total,
        COALESCE(SUM(type = 'lost'), 0) AS lost,
        COALESCE(SUM(type = 'found'), 0) AS found,
        COALESCE(SUM(status = 'resolved'), 0) AS resolved
     FROM items
     WHERE user_id = ?"
);

$stmt->execute([$userId]);
$stats = $stmt->fetch();

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
        .my-report-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 15px;
        }

        .my-report-actions form {
            margin: 0;
        }

        .my-report-actions .outline-btn,
        .my-report-actions .primary-btn {
            padding: 9px 13px;
            font-size: 12px;
        }

        .my-report-card {
            background: white;
            border: 1px solid var(--line);
            border-radius: 14px;
            overflow: hidden;
            box-shadow: var(--shadow);
        }

        .my-report-photo {
            height: 170px;
            background: #f0e8e4;
            display: grid;
            place-items: center;
            color: #95888a;
            font-size: 11px;
            font-weight: 800;
        }

        .my-report-photo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .my-report-body {
            padding: 18px;
        }

        .my-report-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 14px;
        }

        .my-report-head h3 {
            margin: 0 0 8px;
        }

        .my-report-badge {
            display: inline-block;
            font-size: 11px;
            font-weight: 700;
            padding: 5px 9px;
            border-radius: 7px;
            background: #f3edea;
            color: #635659;
        }

        .my-report-badge.open {
            background: #fff3d9;
            color: #916314;
        }

        .my-report-badge.resolved {
            background: #e6f5ea;
            color: #256d43;
        }

        .my-report-meta {
            font-size: 12px;
            color: var(--muted);
            margin: 0 0 7px;
        }

        .my-report-desc {
            font-size: 12px;
            color: #716669;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .my-reports-filters {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            margin-bottom: 22px;
        }

        .my-reports-filters select {
            padding: 11px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: white;
            min-width: 155px;
        }

        .my-reports-edit {
            max-width: 800px;
            margin-bottom: 32px;
        }

        .my-reports-preview {
            max-width: 200px;
            border-radius: 9px;
            border: 1px solid var(--line);
        }

        .my-reports-inline {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .my-reports-inline input {
            width: auto;
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
                <strong><?= e($user['full_name']) ?></strong>
                <small><?= e(ucfirst($user['role'])) ?></small>
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

                <form method="post" action="dashboard.php">

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

                    <button class="text-button" type="submit">
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

            <?php if ($editError): ?>

                <div class="alert alert-error">
                    <?= e($editError) ?>
                </div>

            <?php endif; ?>

            <div class="page-heading">

                <div>
                    <span class="eyebrow">
                        MY ACCOUNT · ITEM TRACKING
                    </span>

                    <h1>My reports</h1>

                    <p class="muted">
                        Manage your submitted lost and found reports.
                    </p>
                </div>

                <a
                    class="primary-btn inline-btn"
                    href="report-lost.php"
                >
                    + New report
                </a>

            </div>

            <!-- REPORT STATISTICS -->

            <div class="stats-grid">

                <div class="stat-card">
                    <span>MY REPORTS</span>
                    <strong><?= (int) $stats['total'] ?></strong>
                    <small>All reports</small>
                </div>

                <div class="stat-card">
                    <span>LOST</span>
                    <strong><?= (int) $stats['lost'] ?></strong>
                    <small>Lost-item posts</small>
                </div>

                <div class="stat-card">
                    <span>FOUND</span>
                    <strong><?= (int) $stats['found'] ?></strong>
                    <small>Found-item posts</small>
                </div>

                <div class="stat-card highlight">
                    <span>RESOLVED</span>
                    <strong><?= (int) $stats['resolved'] ?></strong>
                    <small>Closed reports</small>
                </div>

            </div>

            <!-- EDIT FORM -->

            <?php if ($editing): ?>

                <section
                    class="panel my-reports-edit"
                    id="edit-report"
                >

                    <div class="my-report-head">

                        <div>
                            <span class="eyebrow">
                                EDIT REPORT #<?= (int) $editing['id'] ?>
                            </span>

                            <h2>Update item details</h2>
                        </div>

                        <a
                            class="outline-btn"
                            href="my-reports.php"
                        >
                            Cancel
                        </a>

                    </div>

                    <form
                        class="stack-form"
                        method="post"
                        enctype="multipart/form-data"
                    >

                        <input
                            type="hidden"
                            name="csrf"
                            value="<?= e(token()) ?>"
                        >

                        <input
                            type="hidden"
                            name="action"
                            value="edit"
                        >

                        <input
                            type="hidden"
                            name="item_id"
                            value="<?= (int) $editing['id'] ?>"
                        >

                        <label>
                            Item name

                            <input
                                name="title"
                                required
                                minlength="3"
                                maxlength="140"
                                value="<?= e($editing['title']) ?>"
                            >
                        </label>

                        <div class="two-fields">

                            <label>
                                Category

                                <select name="category_id" required>

                                    <?php foreach ($categories as $category): ?>

                                        <option
                                            value="<?= (int) $category['id'] ?>"
                                            <?= (int) $category['id'] === (int) $editing['category_id'] ? 'selected' : '' ?>
                                        >
                                            <?= e($category['name']) ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>
                            </label>

                            <label>
                                Date <?= $editing['type'] === 'lost' ? 'lost' : 'found' ?>

                                <input
                                    type="date"
                                    name="event_date"
                                    required
                                    max="<?= e(date('Y-m-d')) ?>"
                                    value="<?= e($editing['event_date']) ?>"
                                >
                            </label>

                        </div>

                        <label>
                            Location

                            <input
                                name="location"
                                required
                                minlength="3"
                                maxlength="180"
                                value="<?= e($editing['location']) ?>"
                            >
                        </label>

                        <label>
                            Description

                            <textarea
                                name="description"
                                rows="5"
                                required
                                minlength="10"
                                maxlength="4000"
                            ><?= e($editing['description']) ?></textarea>
                        </label>

                        <?php if ($editing['photo']): ?>

                            <div>
                                <p>Current photo</p>

                                <img
                                    class="my-reports-preview"
                                    src="<?= e($editing['photo']) ?>"
                                    alt="Current item photo"
                                >

                                <label class="my-reports-inline">
                                    <input
                                        type="checkbox"
                                        name="remove_photo"
                                        value="1"
                                    >
                                    Remove current photo
                                </label>
                            </div>

                        <?php endif; ?>

                        <label>
                            Replace photo (optional)

                            <input
                                type="file"
                                name="photo"
                                accept="image/jpeg,image/png,image/webp"
                            >
                        </label>

                        <button
                            class="primary-btn inline-btn"
                            type="submit"
                        >
                            Save changes →
                        </button>

                    </form>

                </section>

            <?php endif; ?>

            <!-- FILTERS -->

            <form
                class="my-reports-filters"
                method="get"
                action="my-reports.php"
            >

                <select
                    name="status"
                    aria-label="Filter by status"
                >

                    <?php foreach ([
                        'all' => 'All statuses',
                        'open' => 'Open',
                        'resolved' => 'Resolved'
                    ] as $value => $label): ?>

                        <option
                            value="<?= e($value) ?>"
                            <?= $statusFilter === $value ? 'selected' : '' ?>
                        >
                            <?= e($label) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

                <select
                    name="type"
                    aria-label="Filter by type"
                >

                    <?php foreach ([
                        'all' => 'Lost & Found',
                        'lost' => 'Lost',
                        'found' => 'Found'
                    ] as $value => $label): ?>

                        <option
                            value="<?= e($value) ?>"
                            <?= $typeFilter === $value ? 'selected' : '' ?>
                        >
                            <?= e($label) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

                <button
                    class="primary-btn inline-btn"
                    type="submit"
                >
                    Apply filters
                </button>

                <span class="muted">
                    <?= count($reports) ?> reports shown
                </span>

            </form>

            <!-- REPORT LIST -->

            <div class="item-grid">

                <?php foreach ($reports as $report): ?>

                    <?php
                    $locked =
                        $report['status'] !== 'open' ||
                        (int) $report['claim_count'] > 0;
                    ?>

                    <article class="my-report-card">

                        <div class="my-report-photo">

                            <?php if ($report['photo']): ?>

                                <img
                                    src="<?= e($report['photo']) ?>"
                                    alt="<?= e($report['title']) ?>"
                                    loading="lazy"
                                >

                            <?php else: ?>

                                NO PHOTO

                            <?php endif; ?>

                        </div>

                        <div class="my-report-body">

                            <div class="my-report-head">

                                <div>
                                    <span class="eyebrow">
                                        <?= e(strtoupper($report['type'])) ?>
                                        ·
                                        <?= e($report['category_name']) ?>
                                    </span>

                                    <h3><?= e($report['title']) ?></h3>
                                </div>

                                <span
                                    class="my-report-badge <?= e($report['status']) ?>"
                                >
                                    <?= e(ucfirst($report['status'])) ?>
                                </span>

                            </div>

                            <p class="my-report-meta">
                                ⌖ <?= e($report['location']) ?>
                                ·
                                <?= e(date(
                                    'M j, Y',
                                    strtotime($report['event_date'])
                                )) ?>
                            </p>

                            <p class="my-report-desc">
                                <?= e($report['description']) ?>
                            </p>

                            <?php if ((int) $report['claim_count'] > 0): ?>

                                <p class="muted">
                                    Claims received:
                                    <?= (int) $report['claim_count'] ?>
                                    · Editing and deletion locked
                                </p>

                            <?php endif; ?>

                            <div class="my-report-actions">

                                <a
                                    class="outline-btn"
                                    href="item.php?id=<?= (int) $report['id'] ?>"
                                >
                                    View
                                </a>

                                <?php if (!$locked): ?>

                                    <a
                                        class="outline-btn"
                                        href="my-reports.php?edit=<?= (int) $report['id'] ?>#edit-report"
                                    >
                                        Edit
                                    </a>

                                    <form
                                        method="post"
                                        onsubmit="return confirm('Permanently delete this report?');"
                                    >

                                        <input
                                            type="hidden"
                                            name="csrf"
                                            value="<?= e(token()) ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="delete"
                                        >

                                        <input
                                            type="hidden"
                                            name="item_id"
                                            value="<?= (int) $report['id'] ?>"
                                        >

                                        <button
                                            class="outline-btn"
                                            type="submit"
                                        >
                                            Delete
                                        </button>

                                    </form>

                                <?php endif; ?>

                                <?php if (
                                    $report['type'] === 'lost' &&
                                    $report['status'] === 'open'
                                ): ?>

                                    <form
                                        method="post"
                                        onsubmit="return confirm('Confirm that you recovered this item?');"
                                    >

                                        <input
                                            type="hidden"
                                            name="csrf"
                                            value="<?= e(token()) ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="resolve"
                                        >

                                        <input
                                            type="hidden"
                                            name="item_id"
                                            value="<?= (int) $report['id'] ?>"
                                        >

                                        <button
                                            class="primary-btn inline-btn"
                                            type="submit"
                                        >
                                            Mark recovered
                                        </button>

                                    </form>

                                <?php endif; ?>

                            </div>

                        </div>

                    </article>

                <?php endforeach; ?>

                <?php if (!$reports): ?>

                    <div class="empty">
                        No reports match your filters.

                        <a href="report-lost.php">
                            Create your first report
                        </a>.
                    </div>

                <?php endif; ?>

            </div>

        </main>

        <footer class="app-footer">
            PLM FIND · CAMPUS LOST &amp; FOUND MANAGEMENT SYSTEM
        </footer>

    </div>

</body>
</html>
