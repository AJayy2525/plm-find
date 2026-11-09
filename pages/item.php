<?php
/** @var PDO $db */
/** @var array<string, mixed> $user */

$stmt = $db->prepare(

    'SELECT i.*,
            c.name AS category_name,
            u.full_name AS reporter
     FROM items i
     JOIN categories c ON c.id = i.category_id
     JOIN users u ON u.id = i.user_id
     WHERE i.id = ?'
);

$stmt->execute([(int) ($_GET['id'] ?? 0)]);
$item = $stmt->fetch();
?>

<?php if (!$item): ?>

    <div class="empty">
        Item not found.
        <a href="browse-items.php">Browse reports</a>
    </div>

<?php else: ?>

    <a class="back-link" href="browse-items.php">
        ← Back to browse
    </a>

    <div class="detail-layout">

        <div class="detail-photo">

            <?php if ($item['photo']): ?>

                <img
                    src="<?= e($item['photo']) ?>"
                    alt="<?= e($item['title']) ?>"
                >

            <?php else: ?>

                <span>NO PHOTO AVAILABLE</span>

            <?php endif; ?>

        </div>

        <div class="panel detail-panel">

            <span class="pill <?= e($item['type']) ?>">
                <?= strtoupper(e($item['type'])) ?>
            </span>

            <span class="pill neutral">
                <?= strtoupper(e($item['status'])) ?>
            </span>

            <h1><?= e($item['title']) ?></h1>

            <p class="muted">
                Reported
                <?= e(date(
                    'M j, Y',
                    strtotime($item['created_at'])
                )) ?>
            </p>

            <div class="detail-data">

                <div>
                    <small>LOCATION</small>
                    <strong>
                        <?= e($item['location']) ?>
                    </strong>
                </div>

                <div>
                    <small>DATE</small>
                    <strong>
                        <?= e(date(
                            'F j, Y',
                            strtotime($item['event_date'])
                        )) ?>
                    </strong>
                </div>

                <div>
                    <small>CATEGORY</small>
                    <strong>
                        <?= e($item['category_name']) ?>
                    </strong>
                </div>

                <div>
                    <small>REPORTED BY</small>
                    <strong>
                        <?= e($item['reporter']) ?>
                    </strong>
                </div>

            </div>

            <h3>Description</h3>

            <p class="description">
                <?= nl2br(e($item['description'])) ?>
            </p>

            <?php if (
                $item['status'] === 'open' &&
                $item['type'] === 'found' &&
                (int) $item['user_id'] !== (int) $user['id']
            ): ?>

                <div class="claim-box">

                    <h3>Is this your item?</h3>

                    <p class="muted">
                        An administrator will review your claim.
                        Do not submit passwords or sensitive IDs.
                    </p>

                    <form method="post" class="stack-form">

                        <input
                            type="hidden"
                            name="csrf"
                            value="<?= e(token()) ?>"
                        >

                        <input
                            type="hidden"
                            name="action"
                            value="claim"
                        >

                        <input
                            type="hidden"
                            name="item_id"
                            value="<?= (int) $item['id'] ?>"
                        >

                        <label>
                            Explain how you can verify ownership

                            <textarea
                                name="explanation"
                                rows="4"
                                minlength="20"
                                maxlength="3000"
                                required
                                placeholder="Mention identifying details not shared in the listing."
                            ></textarea>
                        </label>

                        <button
                            class="primary-btn"
                            type="submit"
                        >
                            Submit claim →
                        </button>

                    </form>

                </div>

            <?php elseif (
                $item['status'] === 'open' &&
                $item['type'] === 'lost' &&
                (int) $item['user_id'] === (int) $user['id']
            ): ?>

                <form
                    method="post"
                    onsubmit="return confirm('Mark this report as resolved?')"
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
                        value="<?= (int) $item['id'] ?>"
                    >

                    <button
                        class="outline-btn"
                        type="submit"
                    >
                        I recovered this item
                    </button>

                </form>

            <?php endif; ?>

        </div>

    </div>

<?php endif; ?>
