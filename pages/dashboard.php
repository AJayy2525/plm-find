<?php
/** @var PDO $db */
/** @var array<string, mixed> $user */

$userId = (int) $user['id'];

$stmt = $db->prepare(
    "SELECT COUNT(*) AS total,
       COALESCE(SUM(type = 'lost'), 0) AS lost,
       COALESCE(SUM(type = 'found'), 0) AS found,
       COALESCE(SUM(status = 'resolved'), 0) AS resolved
     FROM items WHERE user_id = ?"
);
$stmt->execute([$userId]);
$stats = $stmt->fetch();

$stmt = $db->prepare(
    'SELECT message, created_at, read_at
     FROM notifications WHERE user_id = ?
     ORDER BY created_at DESC, id DESC LIMIT 3'
);
$stmt->execute([$userId]);
$latestMessages = $stmt->fetchAll();

$stmt = $db->prepare(
    'SELECT title, type, status, event_date
     FROM items WHERE user_id = ?
     ORDER BY created_at DESC LIMIT 5'
);
$stmt->execute([$userId]);
$recent = $stmt->fetchAll();
?>
<div class="page-heading">
    <div>
        <span class="eyebrow">STUDENT DASHBOARD</span>
        <h1>Welcome, <?= e(explode(' ', trim($user['full_name']))[0]) ?>.</h1>
        <p class="muted">Submit lost and found reports, then track messages from the administrator.</p>
    </div>
    <a href="report-lost.php" class="primary-btn inline-btn">+ Report Lost Item</a>
</div>

<div class="stats-grid">
    <?php foreach (['MY REPORTS' => 'total', 'LOST' => 'lost', 'FOUND' => 'found', 'RESOLVED' => 'resolved'] as $label => $key): ?>
        <div class="stat-card"><span><?= e($label) ?></span><strong><?= (int) $stats[$key] ?></strong></div>
    <?php endforeach; ?>
</div>

<div class="section-heading" style="margin-top:24px">
    <div><span class="eyebrow">ADMINISTRATOR MESSAGES</span><h2>Notifications</h2></div>
    <a href="my-reports.php">View all in My Reports →</a>
</div>
<div class="panel" style="padding:20px">
    <?php foreach ($latestMessages as $note): ?>
        <div style="padding:12px 0;border-bottom:1px solid #eee">
            <?php if ($note['read_at'] === null): ?><strong>New · </strong><?php endif; ?>
            <?= e($note['message']) ?>
            <div class="muted" style="font-size:12px;margin-top:6px"><?= e($note['created_at']) ?></div>
        </div>
    <?php endforeach; ?>
    <?php if (!$latestMessages): ?><p class="muted">No notifications yet. If an administrator identifies a possible match, you'll see a message here.</p><?php endif; ?>
</div>

<div class="section-heading" style="margin-top:24px">
    <div><span class="eyebrow">RECENT ACTIVITY</span><h2>Your latest reports</h2></div>
    <a href="my-reports.php">View My Reports →</a>
</div>
<div class="panel table-wrap">
    <table>
        <thead><tr><th>REPORT</th><th>TYPE</th><th>DATE</th><th>STATUS</th></tr></thead>
        <tbody>
            <?php foreach ($recent as $r): ?>
                <tr><td><?= e($r['title']) ?></td><td><?= e(ucfirst($r['type'])) ?></td><td><?= e($r['event_date']) ?></td><td><?= e(ucfirst($r['status'])) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$recent): ?><tr><td colspan="4">No reports yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<div class="help-strip">
    <div><strong>Found something?</strong><p>Report it to PLM FIND and surrender the item to authorized campus staff.</p></div>
    <a class="outline-btn" href="report-found.php">Report Found Item →</a>
</div>
