<?php
/** @var PDO $db */
/** @var array<string, mixed> $user */

$stmt = $db->prepare(

    'SELECT cl.*, i.title
     FROM claims cl
     JOIN items i ON i.id = cl.item_id
     WHERE cl.user_id = ?
     ORDER BY cl.created_at DESC'
);

$stmt->execute([(int) $user['id']]);

$claims = $stmt->fetchAll();
?>

<div class="page-heading">
    <div>
        <span class="eyebrow">
            OWNERSHIP REQUESTS
        </span>

        <h1>My claims</h1>

        <p class="muted">
            Track the status of your ownership requests.
        </p>
    </div>
</div>

<div class="panel table-wrap">

    <table>

        <thead>
            <tr>
                <th>ITEM</th>
                <th>SUBMITTED</th>
                <th>STATUS</th>
                <th>LINK</th>
            </tr>
        </thead>

        <tbody>

            <?php foreach ($claims as $claim): ?>

                <tr>
                    <td>
                        <?= e($claim['title']) ?>
                    </td>

                    <td>
                        <?= e(date(
                            'M j, Y',
                            strtotime($claim['created_at'])
                        )) ?>
                    </td>

                    <td>
                        <span
                            class="status-label <?= e($claim['status']) ?>"
                        >
                            <?= e(ucfirst($claim['status'])) ?>
                        </span>
                    </td>

                    <td>
                        <a
                            href="item.php?id=<?= (int) $claim['item_id'] ?>"
                        >
                            View →
                        </a>
                    </td>
                </tr>

            <?php endforeach; ?>

            <?php if (!$claims): ?>

                <tr>
                    <td colspan="4">
                        No claims submitted yet.
                    </td>
                </tr>

            <?php endif; ?>

        </tbody>

    </table>

</div>
