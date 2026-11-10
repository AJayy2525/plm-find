<?php
/** @var array<int, array<string, mixed>> $categories */

$kind = ($_GET['type'] ?? 'lost') === 'found'
    ? 'found'
    : 'lost';
?>

<div class="page-heading">

    <div>
        <span class="eyebrow">
            NEW ITEM REPORT
        </span>

        <h1>Report <?= e($kind) ?> item</h1>

        <p class="muted">
            Help your community identify this item.
        </p>
    </div>
</div>

<div class="panel form-panel">

    <div class="kind-toggle">

        <a
            class="<?= $kind === 'lost' ? 'selected' : '' ?>"
            href="report-lost.php"
        >
            Lost something
        </a>

        <a
            class="<?= $kind === 'found' ? 'selected' : '' ?>"
            href="report-found.php"
        >
            Found something
        </a>

    </div>

    <form
        method="post"
        enctype="multipart/form-data"
        class="stack-form"
    >

        <input
            type="hidden"
            name="action"
            value="report"
        >

        <input
            type="hidden"
            name="csrf"
            value="<?= e(token()) ?>"
        >

        <input
            type="hidden"
            name="type"
            value="<?= e($kind) ?>"
        >

        <label>
            Item Name

            <input
                name="title"
                maxlength="140"
                placeholder="Black wireless earbuds"
                required
            >
        </label>

        <div class="two-fields">

            <label>
                Category

                <select name="category_id" required>
                    <option value="">
                        Select category
                    </option>

                    <?php foreach ($categories as $category): ?>

                        <option
                            value="<?= (int) $category['id'] ?>"
                        >
                            <?= e($category['name']) ?>
                        </option>

                    <?php endforeach; ?>

                </select>
            </label>

            <label>
                Date <?= $kind === 'lost' ? 'Lost' : 'Found' ?>

                <input
                    type="date"
                    name="event_date"
                    max="<?= e(date('Y-m-d')) ?>"
                    required
                >
            </label>

        </div>

        <label>
            Location

            <input
                name="location"
                maxlength="180"
                placeholder="Main Library, 2nd Floor"
                required
            >
        </label>

        <label>
            Description

            <textarea
                name="description"
                rows="5"
                minlength="10"
                maxlength="4000"
                required
                placeholder="Describe the appearance, color and identifying features."
            ></textarea>
        </label>

        <label>
            Photo (optional)

            <input
                type="file"
                name="photo"
                accept="image/jpeg,image/png,image/webp"
            >
        </label>

        <p class="field-hint">
            JPG, PNG or WebP · Maximum 4 MB
        </p>

        <button
            class="primary-btn inline-btn"
            type="submit"
        >
            Publish report →
        </button>

    </form>

</div>
