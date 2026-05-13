<?php
$pageTitle = 'Anchor Point Configuration';
require_once __DIR__ . '/../includes/header.php';

$db = getDB();
$product_id = $_GET['product_id'] ?? '';

if (!$product_id) { header('Location: product_codes.php'); exit; }

$stmt = $db->prepare("SELECT * FROM product_codes WHERE id = ?");
$stmt->execute([$product_id]);
$product = $stmt->fetch();
if (!$product) { setFlash('error', 'Product not found.'); header('Location: product_codes.php'); exit; }

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_anchor') {
        $anchor_type = $_POST['anchor_type'] ?? '';
        $x = floatval($_POST['x_coord'] ?? 0);
        $y = floatval($_POST['y_coord'] ?? 0);
        $width = floatval($_POST['table_width_mm'] ?? 120);
        $row_height = floatval($_POST['row_height_mm'] ?? 6);
        $max_rows = intval($_POST['max_data_rows'] ?? 12);
        
        // Upsert
        $existing = $db->prepare("SELECT id FROM template_anchor_config WHERE product_code_id = ? AND anchor_type = ?");
        $existing->execute([$product_id, $anchor_type]);
        $row = $existing->fetch();
        
        if ($row) {
            $db->prepare("UPDATE template_anchor_config SET x_coord=?, y_coord=?, table_width_mm=?, row_height_mm=?, max_data_rows=? WHERE id=?")
               ->execute([$x, $y, $width, $row_height, $max_rows, $row['id']]);
        } else {
            $db->prepare("INSERT INTO template_anchor_config (id, product_code_id, anchor_type, x_coord, y_coord, table_width_mm, row_height_mm, max_data_rows) VALUES (NEWID(), ?, ?, ?, ?, ?, ?, ?)")
               ->execute([$product_id, $anchor_type, $x, $y, $width, $row_height, $max_rows]);
        }
        setFlash('success', "Anchor '$anchor_type' saved.");
        header("Location: anchor_config.php?product_id=$product_id");
        exit;
    }
    
    if ($action === 'save_dim_column') {
        $label = trim($_POST['column_label'] ?? '');
        $ref = trim($_POST['ref_marker'] ?? '');
        $dim_key = trim($_POST['dimension_key'] ?? '');
        $source = $_POST['source_type'] ?? '';
        $width_pct = intval($_POST['width_pct'] ?? 15);
        $show_engg = isset($_POST['show_in_engg']) ? 1 : 0;
        $show_int = isset($_POST['show_in_internal']) ? 1 : 0;
        $sort = intval($_POST['sort_order'] ?? 0);
        
        $db->prepare("INSERT INTO dim_table_columns (id, product_code_id, column_label, ref_marker, dimension_key, source_type, width_pct, show_in_engg, show_in_internal, sort_order) VALUES (NEWID(), ?, ?, ?, ?, ?, ?, ?, ?, ?)")
           ->execute([$product_id, $label, $ref, $dim_key, $source, $width_pct, $show_engg, $show_int, $sort]);
        setFlash('success', "Column '$label' added.");
        header("Location: anchor_config.php?product_id=$product_id");
        exit;
    }
    
    if ($action === 'delete_dim_column') {
        $id = $_POST['id'] ?? '';
        $db->prepare("DELETE FROM dim_table_columns WHERE id = ?")->execute([$id]);
        setFlash('success', 'Column removed.');
        header("Location: anchor_config.php?product_id=$product_id");
        exit;
    }
}

// Fetch anchors
$anchors = $db->prepare("SELECT * FROM template_anchor_config WHERE product_code_id = ?");
$anchors->execute([$product_id]);
$anchor_data = [];
foreach ($anchors->fetchAll() as $a) {
    $anchor_data[$a['anchor_type']] = $a;
}

// Fetch dim columns
$columns = $db->prepare("SELECT * FROM dim_table_columns WHERE product_code_id = ? ORDER BY sort_order");
$columns->execute([$product_id]);
$dim_columns = $columns->fetchAll();
?>

<h2>Drawing Table & Anchor Configuration <span class="badge" style="background:var(--purple-bg);color:var(--purple);">ADMIN</span></h2>
<div class="gap-row mb-2">
    <a href="product_codes.php" class="btn btn-outline btn-sm">← Back to Products</a>
    <span class="tag tag-blue mono"><?= sanitize($product['code']) ?> — <?= sanitize($product['name']) ?></span>
</div>

<div class="callout callout-info">
    <strong>Unified anchor-point approach.</strong> Both individual and combined drawing templates use the same engine: tables are generated dynamically at admin-configured anchor points. No tables exist in the DWG template file.
</div>

<!-- ANCHOR POINTS -->
<?php foreach (['model_code_table' => 'MODEL CODE TABLE', 'dim_data_table' => 'DIMENSION / DATA TABLE'] as $type => $label): ?>
<?php $a = $anchor_data[$type] ?? []; ?>
<div class="panel">
    <div class="panel-header">
        <span class="panel-title">ANCHOR: <?= $label ?></span>
        <span class="tag tag-<?= $type === 'model_code_table' ? 'blue' : 'orange' ?>">Both drawing types</span>
    </div>
    <div class="panel-body">
        <form method="POST" action="">
            <input type="hidden" name="action" value="save_anchor">
            <input type="hidden" name="anchor_type" value="<?= $type ?>">
            <div class="form-row">
                <div class="form-field">
                    <label class="form-label">X Coordinate</label>
                    <input type="number" step="0.01" name="x_coord" class="form-input" value="<?= $a['x_coord'] ?? '0' ?>">
                </div>
                <div class="form-field">
                    <label class="form-label">Y Coordinate</label>
                    <input type="number" step="0.01" name="y_coord" class="form-input" value="<?= $a['y_coord'] ?? '0' ?>">
                </div>
                <div class="form-field">
                    <label class="form-label">Table Width (mm)</label>
                    <input type="number" step="0.01" name="table_width_mm" class="form-input" value="<?= $a['table_width_mm'] ?? '120' ?>">
                </div>
                <div class="form-field">
                    <label class="form-label">Row Height (mm)</label>
                    <input type="number" step="0.01" name="row_height_mm" class="form-input" value="<?= $a['row_height_mm'] ?? '6' ?>">
                </div>
                <?php if ($type === 'dim_data_table'): ?>
                <div class="form-field">
                    <label class="form-label">Max Data Rows</label>
                    <input type="number" name="max_data_rows" class="form-input" value="<?= $a['max_data_rows'] ?? '12' ?>">
                </div>
                <?php endif; ?>
                <div class="form-field" style="flex:0 0 auto;">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endforeach; ?>

<!-- DIMENSION TABLE COLUMNS -->
<h3>Dimension Table Column Definitions</h3>
<div class="callout callout-tip mb-2">
    <strong>Define columns</strong> for the dimension table. These apply to both individual (1 row) and combined (N rows) drawings.
</div>

<div class="panel">
    <div class="panel-header"><span class="panel-title">Add Column</span></div>
    <div class="panel-body">
        <form method="POST" action="">
            <input type="hidden" name="action" value="save_dim_column">
            <div class="form-row">
                <div class="form-field">
                    <label class="form-label">Column Label</label>
                    <input type="text" name="column_label" class="form-input" placeholder="Face-to-Face" required>
                </div>
                <div class="form-field" style="flex:0 0 80px;">
                    <label class="form-label">Ref Marker</label>
                    <input type="text" name="ref_marker" class="form-input" placeholder="A" maxlength="5">
                </div>
                <div class="form-field">
                    <label class="form-label">Dim Key</label>
                    <input type="text" name="dimension_key" class="form-input" placeholder="L">
                </div>
                <div class="form-field">
                    <label class="form-label">Source Type</label>
                    <select name="source_type" class="form-select">
                        <option value="dimension_modifier">Dimension Modifier</option>
                        <option value="sap_item_code">SAP Item Code</option>
                        <option value="quantity">Quantity (SO)</option>
                        <option value="so_field">SO Field</option>
                    </select>
                </div>
                <div class="form-field" style="flex:0 0 80px;">
                    <label class="form-label">Width %</label>
                    <input type="number" name="width_pct" class="form-input" value="15">
                </div>
            </div>
            <div class="form-row">
                <label class="form-checkbox"><input type="checkbox" name="show_in_engg" value="1" checked> Show in Engg</label>
                <label class="form-checkbox"><input type="checkbox" name="show_in_internal" value="1" checked> Show in Internal</label>
                <div class="form-field" style="flex:0 0 80px;">
                    <label class="form-label">Sort</label>
                    <input type="number" name="sort_order" class="form-input" value="<?= count($dim_columns) + 1 ?>">
                </div>
                <button type="submit" class="btn btn-primary">+ Add Column</button>
            </div>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel-body" style="padding:0;">
        <table class="data-table">
            <thead>
                <tr><th>Column Label</th><th>Ref</th><th>Dim Key</th><th>Source</th><th>Width %</th><th>Engg</th><th>Internal</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($dim_columns as $col): ?>
                <tr>
                    <td><?= sanitize($col['column_label']) ?></td>
                    <td class="mono text-accent"><?= sanitize($col['ref_marker'] ?? '—') ?></td>
                    <td class="mono"><?= sanitize($col['dimension_key'] ?? '') ?></td>
                    <td><?= sanitize($col['source_type']) ?></td>
                    <td><?= $col['width_pct'] ?>%</td>
                    <td><?= $col['show_in_engg'] ? '<span class="tag tag-green">✓</span>' : '<span class="tag tag-red">✗</span>' ?></td>
                    <td><?= $col['show_in_internal'] ? '<span class="tag tag-green">✓</span>' : '<span class="tag tag-red">✗</span>' ?></td>
                    <td>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Remove?')">
                            <input type="hidden" name="action" value="delete_dim_column">
                            <input type="hidden" name="id" value="<?= $col['id'] ?>">
                            <button type="submit" class="btn btn-outline btn-sm" style="color:var(--red);">✕</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
