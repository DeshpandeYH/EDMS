<?php
$pageTitle = 'Attribute Options';
require_once __DIR__ . '/../includes/header.php';

$db = getDB();
$attribute_id = $_GET['attribute_id'] ?? '';
$product_id = $_GET['product_id'] ?? '';

if (!$attribute_id) {
    setFlash('error', 'No attribute selected.');
    header('Location: product_codes.php');
    exit;
}

// Fetch attribute and product
$stmt = $db->prepare("SELECT a.*, pc.code as product_code, pc.name as product_name FROM attributes a JOIN product_codes pc ON a.product_code_id = pc.id WHERE a.id = ?");
$stmt->execute([$attribute_id]);
$attribute = $stmt->fetch();

if (!$attribute) {
    setFlash('error', 'Attribute not found.');
    header('Location: product_codes.php');
    exit;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_option') {
        $code = strtoupper(trim($_POST['code'] ?? ''));
        $value = trim($_POST['value'] ?? '');
        $display_label = trim($_POST['display_label'] ?? '');
        $dim_modifiers = trim($_POST['dimension_modifiers'] ?? '');
        $sort_order = intval($_POST['sort_order'] ?? 0);
        
        // Validate dimension modifiers as JSON
        if ($dim_modifiers && json_decode($dim_modifiers) === null && json_last_error() !== JSON_ERROR_NONE) {
            // Try to parse key=value format
            $parts = explode(',', $dim_modifiers);
            $json = [];
            foreach ($parts as $part) {
                $kv = explode('=', trim($part));
                if (count($kv) === 2) {
                    $json[trim($kv[0])] = trim($kv[1]);
                }
            }
            $dim_modifiers = json_encode($json);
        }
        
        if ($code && $value) {
            try {
                $stmt = $db->prepare("INSERT INTO attribute_options (id, attribute_id, code, value, display_label, dimension_modifiers, sort_order) VALUES (NEWID(), ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$attribute_id, $code, $value, $display_label ?: $value, $dim_modifiers ?: null, $sort_order]);
                setFlash('success', "Option '$code — $value' added.");
            } catch (PDOException $e) {
                setFlash('error', "Error: " . $e->getMessage());
            }
        }
        header("Location: attribute_options.php?attribute_id=$attribute_id&product_id=$product_id");
        exit;
    }
    
    if ($action === 'update_option') {
        $id = $_POST['id'] ?? '';
        $value = trim($_POST['value'] ?? '');
        $display_label = trim($_POST['display_label'] ?? '');
        $dim_modifiers = trim($_POST['dimension_modifiers'] ?? '');
        $sort_order = intval($_POST['sort_order'] ?? 0);
        
        if ($dim_modifiers && json_decode($dim_modifiers) === null) {
            $parts = explode(',', $dim_modifiers);
            $json = [];
            foreach ($parts as $part) {
                $kv = explode('=', trim($part));
                if (count($kv) === 2) $json[trim($kv[0])] = trim($kv[1]);
            }
            $dim_modifiers = json_encode($json);
        }
        
        $stmt = $db->prepare("UPDATE attribute_options SET value=?, display_label=?, dimension_modifiers=?, sort_order=? WHERE id=?");
        $stmt->execute([$value, $display_label, $dim_modifiers ?: null, $sort_order, $id]);
        setFlash('success', 'Option updated.');
        header("Location: attribute_options.php?attribute_id=$attribute_id&product_id=$product_id");
        exit;
    }
    
    if ($action === 'delete_option') {
        $id = $_POST['id'] ?? '';
        $db->prepare("UPDATE attribute_options SET is_active = 0 WHERE id = ?")->execute([$id]);
        setFlash('success', 'Option deactivated.');
        header("Location: attribute_options.php?attribute_id=$attribute_id&product_id=$product_id");
        exit;
    }
}

// Fetch options
$options = $db->prepare("SELECT * FROM attribute_options WHERE attribute_id = ? AND is_active = 1 ORDER BY sort_order, code");
$options->execute([$attribute_id]);
$options = $options->fetchAll();
?>

<h2>Attribute Options <span class="badge" style="background:var(--purple-bg);color:var(--purple);">ADMIN</span></h2>
<div class="gap-row mb-2">
    <a href="attributes.php?product_id=<?= sanitize($product_id) ?>" class="btn btn-outline btn-sm">← Back to Attributes</a>
    <span class="tag tag-blue mono" style="padding:0.3rem 0.7rem;"><?= sanitize($attribute['product_code']) ?></span>
    <span class="tag tag-purple mono" style="padding:0.3rem 0.7rem;"><?= sanitize($attribute['code']) ?> — <?= sanitize($attribute['name']) ?></span>
</div>

<!-- ADD OPTION FORM -->
<div class="panel">
    <div class="panel-header">
        <span class="panel-title">Add New Option</span>
    </div>
    <div class="panel-body">
        <form method="POST" action="">
            <input type="hidden" name="action" value="create_option">
            <div class="form-row">
                <div class="form-field">
                    <label class="form-label">Option Code</label>
                    <input type="text" name="code" class="form-input" placeholder="02" maxlength="10" required style="text-transform:uppercase;">
                </div>
                <div class="form-field">
                    <label class="form-label">Option Value</label>
                    <input type="text" name="value" class="form-input" placeholder='2"' required>
                </div>
                <div class="form-field">
                    <label class="form-label">Display Label</label>
                    <input type="text" name="display_label" class="form-input" placeholder="2 inch / DN50">
                </div>
                <div class="form-field">
                    <label class="form-label">Dimension Modifiers (JSON or key=val)</label>
                    <input type="text" name="dimension_modifiers" class="form-input" placeholder='L=180, H=230 or {"L":180,"H":230}'>
                </div>
                <div class="form-field" style="flex:0 0 80px;">
                    <label class="form-label">Sort</label>
                    <input type="number" name="sort_order" class="form-input" value="<?= count($options) + 1 ?>">
                </div>
                <div class="form-field" style="flex:0 0 auto;">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary">+ Add Option</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- OPTIONS TABLE -->
<div class="panel">
    <div class="panel-header">
        <span class="panel-title">Options for <?= sanitize($attribute['code']) ?> — <?= sanitize($attribute['name']) ?></span>
        <span class="tag tag-blue"><?= count($options) ?> options</span>
    </div>
    <div class="panel-body" style="padding:0;">
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Option Code</th>
                        <th>Option Value</th>
                        <th>Display Label</th>
                        <th>Dimension Modifiers</th>
                        <th>Sort Order</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($options)): ?>
                    <tr><td colspan="6" class="text-center text-dim" style="padding:2rem;">No options defined. Add one above.</td></tr>
                    <?php else: ?>
                    <?php foreach ($options as $o): ?>
                    <tr>
                        <td class="mono"><?= sanitize($o['code']) ?></td>
                        <td><?= sanitize($o['value']) ?></td>
                        <td><?= sanitize($o['display_label'] ?? '') ?></td>
                        <td class="mono" style="font-size:0.75rem;"><?= sanitize($o['dimension_modifiers'] ?? '—') ?></td>
                        <td><?= $o['sort_order'] ?></td>
                        <td>
                            <div class="btn-group">
                                <button class="btn btn-outline btn-sm" onclick='editOption(<?= json_encode($o, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Edit</button>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Deactivate this option?')">
                                    <input type="hidden" name="action" value="delete_option">
                                    <input type="hidden" name="id" value="<?= $o['id'] ?>">
                                    <button type="submit" class="btn btn-outline btn-sm" style="color:var(--red);">Deactivate</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- EDIT MODAL -->
<div class="modal-overlay" id="editOptModal">
    <div class="modal">
        <div class="modal-header">
            <span>Edit Option</span>
            <button class="modal-close" onclick="closeModal('editOptModal')">&times;</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="update_option">
            <input type="hidden" name="id" id="eo_id">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Option Value</label>
                    <input type="text" name="value" id="eo_value" class="form-input" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Display Label</label>
                    <input type="text" name="display_label" id="eo_display" class="form-input">
                </div>
                <div class="form-group">
                    <label class="form-label">Dimension Modifiers</label>
                    <input type="text" name="dimension_modifiers" id="eo_dims" class="form-input">
                </div>
                <div class="form-group">
                    <label class="form-label">Sort Order</label>
                    <input type="number" name="sort_order" id="eo_sort" class="form-input">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('editOptModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function editOption(o) {
    document.getElementById('eo_id').value = o.id;
    document.getElementById('eo_value').value = o.value;
    document.getElementById('eo_display').value = o.display_label || '';
    document.getElementById('eo_dims').value = o.dimension_modifiers || '';
    document.getElementById('eo_sort').value = o.sort_order;
    document.getElementById('editOptModal').classList.add('active');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
