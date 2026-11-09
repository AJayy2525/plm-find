
<?php
$claims = $db->query(
    "SELECT cl.*,
            i.title,
            i.location,
            u.full_name AS claimant
     FROM claims cl
     JOIN items i ON i.id = cl.item_id
     JOIN users u ON u.id = cl.user_id
     WHERE cl.status = 'pending'
     ORDER BY cl.created_at ASC
     LIMIT 100"
)->fetchAll();
?>

<div class="page-heading">

    <div>
        <span class="eyebrow">
            ADMINISTRATION
        </span>

        <h1>Claims awaiting review</h1>

        <p class="muted">
            Verify proof of ownership before approving.
        </p>
    </div>

</div>

<div class="admin-grid">

    <?php foreach ($claims as $claim): ?>

        <article class="panel admin-claim">

            <span class="eyebrow">
                CLAIM #<?= (int) $claim['id'] ?>
            </span>

            <h3><?= e($claim['title']) ?></h3>

            <p class="muted">
                <?= e($claim['location']) ?>
                ·
                <?= e($claim['claimant']) ?>
            </p>

            <div class="claim-explanation">
                <?= nl2br(e($claim['explanation'])) ?>
            </div>

            <div class="review-actions">

                <form
                    method="post"
                    onsubmit="return confirm('Approve this claim? This closes the item report.')"
                >

                    <input
                        type="hidden"
                        name="csrf"
                        value="<?= e(token()) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="review"
                    >

                    <input
                        type="hidden"
                        name="claim_id"
                        value="<?= (int) $claim['id'] ?>"
                    >

                    <input
                        type="hidden"
                        name="decision"
                        value="approved"
                    >

                    <button
                        class="primary-btn inline-btn"
                        type="submit"
                    >
                        Approve
                    </button>

                </form>

                <form
                    method="post"
                    onsubmit="return confirm('Reject this claim?')"
                >

                    <input
                        type="hidden"
                        name="csrf"
                        value="<?= e(token()) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="review"
                    >

                    <input
                        type="hidden"
                        name="claim_id"
                        value="<?= (int) $claim['id'] ?>"
                    >

                    <input
                        type="hidden"
                        name="decision"
                        value="rejected"
                    >

                    <button
                        class="outline-btn"
                        type="submit"
                    >
                        Reject
                    </button>

                </form>

            </div>

        </article>

    <?php endforeach; ?>

    <?php if (!$claims): ?>

        <div class="empty">
            No claims awaiting review.
        </div>

    <?php endif; ?>

</div>
