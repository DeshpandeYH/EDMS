<?php
$pageTitle = 'Template Field Mappings';
require_once __DIR__ . '/../includes/header.php';

$db = getDB();
$template_id = $_GET['template_id'] ?? '';

if (!$template_id) { header('Location: templates.php'); exit; }

$stmt = $db->prepare("SELECT t.*, pc.code as product_code, pc.name as product_name FROM templates t JOIN product_codes pc ON t.product_code_id = pc.id WHERE t.id = ?");
$stmt->execute([$template_id]);
$template = $stmt->fetch();
if (!$template) { setFlash('error', 'Template not found.'); header('Location: templates.php'); exit; }

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_mapping') {
        $dwg_field = trim($_POST['dwg_field_name'] ?? '');
        $source_type = $_POST['source_type'] ?? '';
        $source_key = trim($_POST['source_key'] ?? '');
        $default_val = trim($_POST['default_value'] ?? '');
        $editable = isset($_POST['is_editable']) ? 1 : 0;
        
        if ($dwg_field && $source_type) {
            $db->prepare("INSERT INTO template_field_mappings (id, template_id, dwg_field_name, source_type, source_key, is_editable, default_value) VALUES (NEWID(), ?, ?, ?, ?, ?, ?)")
               ->execute([$template_id, $dwg_field, $source_type, $source_key, $editable, $default_val]);
            setFlash('success', "Field mapping '$dwg_field' added.");
        }
        header("Location: template_fields.php?template_id=$template_id");
        exit;
    }
    
    if ($action === 'delete_mapping') {
        $id = $_POST['id'] ?? '';
        $db->prepare("DELETE FROM template_field_mappings WHERE id = ?")->execute([$id]);
        setFlash('success', 'Mapping removed.');
        header("Location: template_fields.php?template_id=$template_id");
        exit;
    }
}

// Fetch mappings
$mappings = $db->prepare("SELECT * FROM template_field_mappings WHERE template_id = ? ORDER BY dwg_field_name");
$mappings->execute([$template_id]);
$mappings = $mappings->fetchAll();
?>

<h2>Template Field Mappings <span class="badge" style="background:var(--purple-bg);color:var(--purple);">ADMIN</span></h2>
<div class="gap-row mb-2">
    <a href="templates.php" class="btn btn-outline btn-sm">← Back to Templates</a>
    <span class="tag tag-blue mono"><?= sanitize($template['product_code']) ?></span>
    <span class="tag tag-cyan mono"><?= sanitize($template['template_code']) ?></span>
    <span class="tag tag-purple"><?= str_replace('_', ' ', ucwords($template['template_type'], '_')) ?></span>
</div>

<!-- ADD MAPPING FORM -->
<div class="panel">
    <div class="panel-header"><span class="panel-title">Add Field Mapping</span></div>
    <div class="panel-body">
        <form method="POST" action="">
            <input type="hidden" name="action" value="add_mapping">
            <div class="form-row">
                <div class="form-field">
                    <label class="form-label">DWG Block/Field Name</label>
                    <input type="text" name="dwg_field_name" class="form-input" placeholder="TITLE_BLOCK.ORDER_NO" required>
                </div>
                <div class="form-field">
                    <label class="form-label">Source Type</label>
                    <select name="source_type" class="form-select" required>
                        <option value="so_header">SO Header Field</option>
                        <option value="so_line">SO Line Field</option>
                        <option value="attribute_option">Attribute Option Value</option>
                        <option value="dimension_modifier">Dimension Modifier</option>
                        <option value="static">Static Value</option>
                    </select>
                </div>
                <div class="form-field">
                    <label class="form-label">Source Key</label>
                    <input type="text" name="source_key" class="form-input" placeholder="so_number / L / customer_name">
                </div>
                <div class="form-field">
                    <label class="form-label">Default Value</label>
                    <input type="text" name="default_value" class="form-input" placeholder="Optional default">
                </div>
                <div class="form-field" style="flex:0 0 auto;">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary">+ Add</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- MAPPINGS TABLE -->
<div class="panel">
    <div class="panel-header">
        <span class="panel-title">Field Mappings</span>
        <span class="tag tag-blue"><?= count($mappings) ?> fields</span>
    </div>
    <div class="panel-body" style="padding:0;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>DWG Block/Field</th>
                    <th>Source Type</th>
                    <th>Source Key</th>
                    <th>Default Value</th>
                    <th>Editable</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($mappings as $m): ?>
                <tr>
                    <td class="mono"><?= sanitize($m['dwg_field_name']) ?></td>
                    <td><span class="tag tag-<?= match($m['source_type']) { 'so_header' => 'cyan', 'attribute_option' => 'purple', 'dimension_modifier' => 'orange', 'static' => 'dim', default => 'blue' } ?>"><?= $m['source_type'] ?></span></td>
                    <td class="mono"><?= sanitize($m['source_key'] ?? '') ?></td>
                    <td><?= sanitize($m['default_value'] ?? '—') ?></td>
                    <td><?= $m['is_editable'] ? '<span class="tag tag-green">Yes</span>' : '<span class="text-dim">No</span>' ?></td>
                    <td>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Remove this mapping?')">
                            <input type="hidden" name="action" value="delete_mapping">
                            <input type="hidden" name="id" value="<?= $m['id'] ?>">
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
