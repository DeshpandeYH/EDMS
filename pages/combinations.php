<?php
$pageTitle = 'Combination Matrix';
require_once __DIR__ . '/../includes/header.php';

$db = getDB();
$product_id = $_GET['product_id'] ?? '';
$output_type = $_GET['output_type'] ?? 'engg_individual';

if (!$product_id) {
    setFlash('error', 'No product selected.');
    header('Location: product_codes.php');
    exit;
}

$stmt = $db->prepare("SELECT * FROM product_codes WHERE id = ?");
$stmt->execute([$product_id]);
$product = $stmt->fetch();

if (!$product) {
    setFlash('error', 'Product not found.');
    header('Location: product_codes.php');
    exit;
}

// Handle combination generation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'generate_combinations') {
        $target_type = $_POST['output_type'] ?? 'engg_individual';
        
        // Determine which attributes affect this output type
        $flag_col = match($target_type) {
            'engg_individual', 'engg_combined' => 'affects_engg_dwg',
            'internal_individual', 'internal_combined' => 'affects_internal_dwg',
            'sap_item' => 'affects_sap_item',
            'test_cert' => 'affects_test_cert',
            default => 'affects_engg_dwg'
        };
        
        // For combined types, exclude the combined attribute
        $is_combined_type = str_contains($target_type, '_combined');
        
        $sql = "SELECT a.id, a.code, a.name FROM attributes a WHERE a.product_code_id = ? AND a.is_active = 1 AND a.$flag_col = 1";
        if ($is_combined_type) {
            $sql .= " AND a.is_combined_attribute = 0";
        }
        $sql .= " ORDER BY a.position_in_model";
        
        $attrs_stmt = $db->prepare($sql);
        $attrs_stmt->execute([$product_id]);
        $affecting_attrs = $attrs_stmt->fetchAll();
        
        if (empty($affecting_attrs)) {
            setFlash('warning', 'No attributes affect this output type.');
            header("Location: combinations.php?product_id=$product_id&output_type=$target_type");
            exit;
        }
        
        // Get options for each attribute
        $attr_options = [];
        foreach ($affecting_attrs as $attr) {
            $opts_stmt = $db->prepare("SELECT id, code, value FROM attribute_options WHERE attribute_id = ? AND is_active = 1 ORDER BY sort_order");
            $opts_stmt->execute([$attr['id']]);
            $opts = $opts_stmt->fetchAll();
            if (empty($opts)) continue;
            $attr_options[] = ['attr' => $attr, 'options' => $opts];
        }
        
        if (empty($attr_options)) {
            setFlash('warning', 'No options defined for affecting attributes.');
            header("Location: combinations.php?product_id=$product_id&output_type=$target_type");
            exit;
        }
        
        // Generate Cartesian product
        $combinations = [['key' => '', 'pairs' => []]];
        foreach ($attr_options as $ao) {
            $new_combos = [];
            foreach ($combinations as $combo) {
                foreach ($ao['options'] as $opt) {
                    $new_key = $combo['key'] ? $combo['key'] . '-' . $opt['code'] : $opt['code'];
                    $new_pairs = $combo['pairs'];
                    $new_pairs[] = ['attr_id' => $ao['attr']['id'], 'option_id' => $opt['id'], 'code' => $opt['code']];
                    $new_combos[] = ['key' => $new_key, 'pairs' => $new_pairs];
                }
            }
            $combinations = $new_combos;
        }
        
        // Insert combinations
        $added = 0;
        $db->beginTransaction();
        try {
            foreach ($combinations as $combo) {
                $hash = hash('sha256', $product_id . '|' . $target_type . '|' . $combo['key']);
                
                // Check if exists
                $check = $db->prepare("SELECT id FROM combination_matrix WHERE combination_hash = ? AND output_type = ?");
                $check->execute([$hash, $target_type]);
                if ($check->fetch()) continue;
                
                $combo_id = generateUUID();
                $stmt = $db->prepare("INSERT INTO combination_matrix (id, product_code_id, output_type, combination_key, combination_hash) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$combo_id, $product_id, $target_type, $combo['key'], $hash]);
                
                // Insert combination options
                foreach ($combo['pairs'] as $pair) {
                    $db->prepare("INSERT INTO combination_options (id, combination_id, attribute_id, option_id) VALUES (NEWID(), ?, ?, ?)")
                       ->execute([$combo_id, $pair['attr_id'], $pair['option_id']]);
                }
                $added++;
            }
            $db->commit();
            setFlash('success', "$added combinations generated for $target_type.");
        } catch (PDOException $e) {
            $db->rollBack();
            setFlash('error', "Error generating combinations: " . $e->getMessage());
        }
        
        header("Location: combinations.php?product_id=$product_id&output_type=$target_type");
        exit;
    }
    
    if ($action === 'assign_template') {
        $combo_id = $_POST['combination_id'] ?? '';
        $template_id = $_POST['template_id'] ?? '';
        $sap_code = trim($_POST['sap_item_code'] ?? '');
        
        // Check if mapping exists
        $existing = $db->prepare("SELECT id FROM combination_template_map WHERE combination_id = ?");
        $existing->execute([$combo_id]);
        $row = $existing->fetch();
        
        if ($row) {
            $db->prepare("UPDATE combination_template_map SET template_id = ?, sap_item_code = ? WHERE id = ?")
               ->execute([$template_id ?: null, $sap_code, $row['id']]);
        } else {
            $db->prepare("INSERT INTO combination_template_map (id, combination_id, template_id, sap_item_code) VALUES (NEWID(), ?, ?, ?)")
               ->execute([$combo_id, $template_id ?: null, $sap_code]);
        }
        setFlash('success', 'Template assignment saved.');
        header("Location: combinations.php?product_id=$product_id&output_type=$output_type");
        exit;
    }
}

// Fetch combinations for selected output type
$combos = $db->prepare("
    SELECT cm.*, ctm.template_id, ctm.sap_item_code, t.template_code as template_name
    FROM combination_matrix cm
    LEFT JOIN combination_template_map ctm ON cm.id = ctm.combination_id
    LEFT JOIN templates t ON ctm.template_id = t.id
    WHERE cm.product_code_id = ? AND cm.output_type = ? AND cm.is_active = 1
    ORDER BY cm.combination_key
");
$combos->execute([$product_id, $output_type]);
$combos = $combos->fetchAll();

// Get combo details (attribute option labels) for each combo
$combo_details = [];
foreach ($combos as $c) {
    $details = $db->prepare("
        SELECT a.code as attr_code, a.name as attr_name, ao.code as opt_code, ao.value as opt_value
        FROM combination_options co
        JOIN attributes a ON co.attribute_id = a.id
        JOIN attribute_options ao ON co.option_id = ao.id
        WHERE co.combination_id = ?
        ORDER BY a.position_in_model
    ");
    $details->execute([$c['id']]);
    $combo_details[$c['id']] = $details->fetchAll();
}

// Fetch templates for assignment dropdown
$templates = $db->prepare("SELECT id, template_code, template_type FROM templates WHERE product_code_id = ? AND is_active = 1 ORDER BY template_code");
$templates->execute([$product_id]);
$templates = $templates->fetchAll();

// Count stats
$total = count($combos);
$mapped = count(array_filter($combos, fn($c) => $c['template_id']));
$pending = $total - $mapped;

// Get affecting attribute info
$flag_col = match($output_type) {
    'engg_individual', 'engg_combined' => 'affects_engg_dwg',
    'internal_individual', 'internal_combined' => 'affects_internal_dwg',
    'sap_item' => 'affects_sap_item',
    'test_cert' => 'affects_test_cert',
    default => 'affects_engg_dwg'
};
$is_combined_type = str_contains($output_type, '_combined');
$sql = "SELECT a.code, a.name, (SELECT COUNT(*) FROM attribute_options WHERE attribute_id = a.id AND is_active = 1) as opt_count FROM attributes a WHERE a.product_code_id = ? AND a.is_active = 1 AND a.$flag_col = 1";
if ($is_combined_type) $sql .= " AND a.is_combined_attribute = 0";
$sql .= " ORDER BY a.position_in_model";
$affecting_attrs = $db->prepare($sql);
$affecting_attrs->execute([$product_id]);
$affecting_attrs = $affecting_attrs->fetchAll();
$formula = implode(' × ', array_map(fn($a) => $a['code'] . " ({$a['opt_count']} opts)", $affecting_attrs));
$expected = array_product(array_map(fn($a) => $a['opt_count'], $affecting_attrs)) ?: 0;
?>

<h2>Combination Matrix <span class="badge" style="background:var(--green-bg);color:var(--green);">AUTO</span></h2>
<div class="gap-row mb-2">
    <a href="product_codes.php" class="btn btn-outline btn-sm">← Back to Products</a>
    <span class="tag tag-blue mono" style="padding:0.3rem 0.7rem;"><?= sanitize($product['code']) ?> — <?= sanitize($product['name']) ?></span>
</div>

<div class="callout callout-info">
    <strong>Auto-generated combinations.</strong> When you generate, the system creates all valid combinations of affected attributes per output type. Each combination row must be tagged with its template / SAP code.
</div>

<!-- OUTPUT TYPE TABS -->
<div class="form-label mb-1">FILTER BY OUTPUT TYPE:</div>
<div class="tab-nav">
    <?php foreach (['engg_individual'=>'Engg Individual','engg_combined'=>'Engg Combined','internal_individual'=>'Internal Individual','internal_combined'=>'Internal Combined','sap_item'=>'SAP Item Code','test_cert'=>'Test Certificate'] as $key => $label): ?>
    <a href="?product_id=<?= $product_id ?>&output_type=<?= $key ?>" class="tab-btn <?= $output_type === $key ? 'active' : '' ?>" style="text-decoration:none;"><?= $label ?></a>
    <?php endforeach; ?>
</div>

<!-- STATS -->
<div class="form-label mt-2" style="margin-bottom:0.5rem;">
    Showing: <strong class="text-accent"><?= str_replace('_', ' ', ucwords($output_type, '_')) ?></strong> for <?= sanitize($product['code']) ?>
    &nbsp;·&nbsp; Affected attrs: <?= $formula ?> = <strong class="text-green"><?= $expected ?> expected combinations</strong>
</div>

<!-- GENERATE BUTTON -->
<div class="gap-row mb-2">
    <form method="POST" action="">
        <input type="hidden" name="action" value="generate_combinations">
        <input type="hidden" name="output_type" value="<?= sanitize($output_type) ?>">
        <button type="submit" class="btn btn-success" onclick="return confirm('Generate combinations for <?= $output_type ?>?')">⚡ Generate Combinations</button>
    </form>
    <span class="text-dim">Total: <?= $total ?> | Mapped: <?= $mapped ?> | Pending: <?= $pending ?></span>
</div>

<!-- COMBINATIONS TABLE -->
<div class="panel">
    <div class="panel-header">
        <span class="panel-title"><?= $total ?> Combinations</span>
        <?php if ($pending > 0): ?>
        <span class="tag tag-red"><?= $pending ?> unmapped</span>
        <?php else: ?>
        <span class="tag tag-green">All mapped</span>
        <?php endif; ?>
    </div>
    <div class="panel-body" style="padding:0;">
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <?php foreach ($affecting_attrs as $aa): ?>
                        <th><?= sanitize($aa['name']) ?></th>
                        <?php endforeach; ?>
                        <th>Combination Key</th>
                        <th>Template</th>
                        <th>SAP Code</th>
                        <th>Status</th>
                        <th>Assign</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($combos)): ?>
                    <tr><td colspan="<?= count($affecting_attrs) + 6 ?>" class="text-center text-dim" style="padding:2rem;">
                        No combinations yet. Click "Generate Combinations" to create them.
                    </td></tr>
                    <?php else: ?>
                    <?php foreach ($combos as $i => $c): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <?php 
                        $details = $combo_details[$c['id']] ?? [];
                        foreach ($affecting_attrs as $aa):
                            $val = '—';
                            foreach ($details as $d) {
                                if ($d['attr_code'] === $aa['code']) { $val = $d['opt_value']; break; }
                            }
                        ?>
                        <td><?= sanitize($val) ?></td>
                        <?php endforeach; ?>
                        <td class="mono"><?= sanitize($c['combination_key']) ?></td>
                        <td class="mono text-cyan"><?= $c['template_name'] ? sanitize($c['template_name']) : '<span class="text-dim">— not assigned —</span>' ?></td>
                        <td class="mono"><?= $c['sap_item_code'] ? sanitize($c['sap_item_code']) : '<span class="text-dim">—</span>' ?></td>
                        <td>
                            <?= $c['template_id'] ? '<span class="tag tag-green">Mapped</span>' : '<span class="tag tag-red">Pending</span>' ?>
                        </td>
                        <td>
                            <form method="POST" action="" style="display:flex;gap:0.25rem;">
                                <input type="hidden" name="action" value="assign_template">
                                <input type="hidden" name="combination_id" value="<?= $c['id'] ?>">
                                <select name="template_id" class="form-select" style="width:130px;height:30px;font-size:0.72rem;">
                                    <option value="">— Select —</option>
                                    <?php foreach ($templates as $t): ?>
                                    <option value="<?= $t['id'] ?>" <?= $c['template_id'] === $t['id'] ? 'selected' : '' ?>><?= sanitize($t['template_code']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="text" name="sap_item_code" class="form-input" style="width:120px;height:30px;font-size:0.72rem;" placeholder="SAP Code" value="<?= sanitize($c['sap_item_code'] ?? '') ?>">
                                <button type="submit" class="btn btn-outline btn-sm">Save</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($pending > 0): ?>
<div class="callout callout-warn">
    <strong><?= $pending ?> of <?= $total ?> combinations</strong> still need template assignment. Complete mapping before enabling auto-generation for this product.
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
