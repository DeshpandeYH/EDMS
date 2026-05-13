<?php
$pageTitle = 'Model Code Builder';
require_once __DIR__ . '/../includes/header.php';

$db = getDB();
$line_id = $_GET['line_id'] ?? '';
$so_id = $_GET['so_id'] ?? '';

if (!$line_id) { header('Location: sales_orders.php'); exit; }

// Fetch line item with product info
$stmt = $db->prepare("
    SELECT sli.*, pc.code as product_code, pc.name as product_name, pc.id as pc_id,
           so.so_number, so.customer_name
    FROM so_line_items sli
    JOIN product_codes pc ON sli.product_code_id = pc.id
    JOIN sales_orders so ON sli.sales_order_id = so.id
    WHERE sli.id = ?
");
$stmt->execute([$line_id]);
$line = $stmt->fetch();
if (!$line) { setFlash('error', 'Line item not found.'); header('Location: sales_orders.php'); exit; }

// Fetch attributes with options
$attrs = $db->prepare("
    SELECT a.* FROM attributes a 
    WHERE a.product_code_id = ? AND a.is_active = 1 
    ORDER BY a.position_in_model
");
$attrs->execute([$line['pc_id']]);
$attributes = $attrs->fetchAll();

$attr_options = [];
foreach ($attributes as $attr) {
    $opts = $db->prepare("SELECT * FROM attribute_options WHERE attribute_id = ? AND is_active = 1 ORDER BY sort_order");
    $opts->execute([$attr['id']]);
    $attr_options[$attr['id']] = $opts->fetchAll();
}

// Fetch current selections
$current_sels = $db->prepare("SELECT attribute_id, option_id FROM so_line_selections WHERE so_line_item_id = ?");
$current_sels->execute([$line_id]);
$selections = [];
foreach ($current_sels->fetchAll() as $s) {
    $selections[$s['attribute_id']] = $s['option_id'];
}

// Color palette for attributes
$colors = ['var(--green)', 'var(--orange)', 'var(--purple)', 'var(--cyan)', 'var(--red)', 'var(--accent)', '#f472b6', '#facc15'];
?>

<h2>Model Code Builder <span class="badge" style="background:var(--cyan-bg);color:var(--cyan);">SALES</span></h2>
<div class="gap-row mb-2">
    <a href="sales_order_detail.php?id=<?= sanitize($so_id) ?>" class="btn btn-outline btn-sm">← Back to SO</a>
    <span class="tag tag-blue"><?= sanitize($line['so_number']) ?></span>
    <span class="tag tag-purple">Line <?= $line['line_number'] ?></span>
    <span class="tag tag-cyan"><?= sanitize($line['product_code']) ?> — <?= sanitize($line['product_name']) ?></span>
</div>

<div class="panel">
    <div class="panel-header">
        <span class="panel-title">Build Model Code</span>
    </div>
    <div class="panel-body">
        <!-- Live Model Code Display -->
        <div class="model-code-display" id="modelCodeDisplay">
            <?= $line['model_code_string'] ? sanitize($line['model_code_string']) : sanitize($line['product_code']) . ' — Select options below' ?>
        </div>
        
        <form method="POST" action="sales_order_detail.php?id=<?= sanitize($so_id) ?>">
            <input type="hidden" name="action" value="save_selections">
            <input type="hidden" name="line_id" value="<?= $line_id ?>">
            
            <div class="grid-2">
                <?php foreach ($attributes as $i => $attr): ?>
                <div class="form-field">
                    <label class="form-label" style="color:<?= $colors[$i % count($colors)] ?>">
                        <?= sanitize($attr['name']) ?> (<?= sanitize($attr['code']) ?>)
                        <?php if ($attr['is_combined_attribute']): ?><span class="tag tag-orange" style="font-size:0.6rem;">COMBINED</span><?php endif; ?>
                    </label>
                    <select name="selections[<?= $attr['id'] ?>]" class="form-select" onchange="updateModelCode()">
                        <option value="">— Select <?= sanitize($attr['name']) ?> —</option>
                        <?php foreach ($attr_options[$attr['id']] ?? [] as $opt): ?>
                        <option value="<?= $opt['id'] ?>" 
                                data-code="<?= sanitize($opt['code']) ?>"
                                <?= ($selections[$attr['id']] ?? '') === $opt['id'] ? 'selected' : '' ?>>
                            <?= sanitize($opt['code']) ?> — <?= sanitize($opt['display_label'] ?: $opt['value']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endforeach; ?>
            </div>
            
            <div class="divider"></div>
            
            <div class="btn-group">
                <button type="submit" class="btn btn-primary btn-lg">💾 Save Model Code</button>
                <a href="sales_order_detail.php?id=<?= sanitize($so_id) ?>" class="btn btn-outline">Cancel</a>
            </div>
        </form>
    </div>
</div>

<!-- AUTO-RESOLVED OUTPUTS -->
<?php if ($line['status'] === 'resolved' || $line['status'] === 'generated'): ?>
<div class="panel">
    <div class="panel-header">
        <span class="panel-title">Auto-Resolved Outputs</span>
        <span class="tag tag-green">✓ Resolved</span>
    </div>
    <div class="panel-body">
        <div class="info-grid" style="grid-template-columns:1fr 1fr;">
            <?php
            // Look up resolved templates from combination matrix
            $sel_data = $db->prepare("
                SELECT a.code as attr_code, a.affects_engg_dwg, a.affects_internal_dwg, a.affects_sap_item, a.affects_test_cert,
                       ao.code as opt_code
                FROM so_line_selections s
                JOIN attributes a ON s.attribute_id = a.id
                JOIN attribute_options ao ON s.option_id = ao.id
                WHERE s.so_line_item_id = ?
                ORDER BY a.position_in_model
            ");
            $sel_data->execute([$line_id]);
            $sel_list = $sel_data->fetchAll();
            
            $output_types = [
                'engg_individual' => ['label' => 'Engg Drawing', 'flag' => 'affects_engg_dwg'],
                'internal_individual' => ['label' => 'Internal Dwg', 'flag' => 'affects_internal_dwg'],
                'sap_item' => ['label' => 'SAP Item Code', 'flag' => 'affects_sap_item'],
                'test_cert' => ['label' => 'Test Cert Tmpl', 'flag' => 'affects_test_cert']
            ];
            
            foreach ($output_types as $ot => $info):
                $opt_codes = array_map(fn($s) => $s['opt_code'], array_filter($sel_list, fn($s) => $s[$info['flag']]));
                $combo_key = implode('-', $opt_codes);
                
                $match = $db->prepare("
                    SELECT t.template_code, ctm.sap_item_code 
                    FROM combination_matrix cm
                    LEFT JOIN combination_template_map ctm ON cm.id = ctm.combination_id
                    LEFT JOIN templates t ON ctm.template_id = t.id
                    WHERE cm.product_code_id = ? AND cm.output_type = ? AND cm.combination_key = ?
                ");
                $match->execute([$line['pc_id'], $ot, $combo_key]);
                $result = $match->fetch();
            ?>
            <div>
                <span class="text-dim"><?= $info['label'] ?>:</span> 
                <?php if ($result && $result['template_code']): ?>
                    <span class="mono text-cyan"><?= sanitize($result['template_code']) ?></span>
                <?php elseif ($result && $result['sap_item_code']): ?>
                    <span class="mono text-green"><?= sanitize($result['sap_item_code']) ?></span>
                <?php else: ?>
                    <span class="text-dim">— not mapped —</span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function updateModelCode() {
    const product = '<?= sanitize($line['product_code']) ?>';
    const selects = document.querySelectorAll('select[name^="selections"]');
    let parts = [product];
    let allSelected = true;
    
    selects.forEach(sel => {
        const opt = sel.options[sel.selectedIndex];
        if (opt && opt.value) {
            parts.push(opt.dataset.code);
        } else {
            parts.push('??');
            allSelected = false;
        }
    });
    
    const display = document.getElementById('modelCodeDisplay');
    display.textContent = parts.join(' — ');
    display.style.color = allSelected ? 'var(--green)' : 'var(--accent)';
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
