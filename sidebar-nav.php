<?php
/** @var array<string, mixed> $user */

$isAdminNav = ($user['role'] ?? '') === 'admin';

$scriptNav = basename(
    (string) ($_SERVER['SCRIPT_NAME'] ?? '')
);

$viewNav = (string) (
    $_GET['view'] ?? 'overview'
);
?>

<nav class="nav-links" aria-label="Primary navigation">

<?php if ($isAdminNav): ?>

    <a
        class="<?= $scriptNav === 'admin.php' && $viewNav === 'overview' ? 'active' : '' ?>"
        href="admin.php?view=overview"
    >
        ◫ &nbsp; Admin Dashboard
    </a>

    <a
        class="<?= $scriptNav === 'admin.php' && $viewNav === 'lost' ? 'active' : '' ?>"
        href="admin.php?view=lost"
    >
        ⌕ &nbsp; Lost Reports
    </a>

    <a
        class="<?= $scriptNav === 'admin.php' && $viewNav === 'found' ? 'active' : '' ?>"
        href="admin.php?view=found"
    >
        ▣ &nbsp; Found Reports
    </a>

    <a
        class="<?= $scriptNav === 'admin.php' && $viewNav === 'returns' ? 'active' : '' ?>"
        href="admin.php?view=returns"
    >
        ✓ &nbsp; Return Records
    </a>

<?php else: ?>

    <a
        class="<?= $scriptNav === 'dashboard.php' ? 'active' : '' ?>"
        href="dashboard.php"
    >
        ◫ &nbsp; Dashboard
    </a>

    <a
        class="<?= $scriptNav === 'report-lost.php' ? 'active' : '' ?>"
        href="report-lost.php"
    >
        − &nbsp; Report Lost
    </a>

    <a
        class="<?= $scriptNav === 'report-found.php' ? 'active' : '' ?>"
        href="report-found.php"
    >
        + &nbsp; Report Found
    </a>

    <a
        class="<?= $scriptNav === 'my-reports.php' ? 'active' : '' ?>"
        href="my-reports.php"
    >
        ▤ &nbsp; My Reports / Notifications
    </a>

<?php endif; ?>

</nav>
<script src="assets/plm-interactions.js" defer></script>