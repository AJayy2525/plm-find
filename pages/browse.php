
<?php
$type = (string) ($_GET['type'] ?? 'all');

if (!in_array($type, ['all', 'lost', 'found'], true)) {
    $type = 'all';
}

$search = mb_substr(
    trim((string) ($_GET['q'] ?? '')),
    0,
    100
);

$sql = "
    SELECT i.*, c.name AS category_name
    FROM items i
    JOIN categories c ON c.id = i.category_id
    WHERE i.status = 'open'
";

$params = [];

if ($type !== 'all') {
    $sql .= ' AND i.type = ?';
    $params[] = $type;
}

if ($search !== '') {

    $sql .= '
        AND (
            i.title LIKE ?
            OR i.description LIKE ?
            OR i.location LIKE ?
        )
    ';

    $term = '%' . $search . '%';

    array_push(
        $params,
        $term,
        $term,
        $term
    );
}

$sql .= '
    ORDER BY i.created_at DESC
    LIMIT 80
';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();
?>

<div class="page-heading">
    <div>
        <span class="eyebrow">
            DISCOVER &amp; RECONNECT
        </span>

        <h1>Browse reported items</h1>

        <p class="muted">
            Search recent lost and found reports on campus.
        </p>
    </div>
</div>

<form
    class="search-row"
    method="get"
    action="browse-items.php"
>

    <input
        type="search"
        name="q"
        placeholder="Search item, description or location"
        value="<?= e($search) ?>"
    >

    <select name="type">

        <option
            value="all"
            <?= $type === 'all' ? 'selected' : '' ?>
        >
            All reports
        </option>

        <option
            value="lost"
            <?= $type === 'lost' ? 'selected' : '' ?>
        >
            Lost items
        </option>

        <option
            value="found"
            <?= $type === 'found' ? 'selected' : '' ?>
        >
            Found items
        </option>

    </select>

    <button
        class="primary-btn inline-btn"
        type="submit"
    >
        Search
    </button>

</form>

<div class="results-count">
    <?= count($items) ?> matching report(s)
    <?= count($items) === 80 ? ' (first 80)' : '' ?>
</div>

<?php render_cards($items); ?>
