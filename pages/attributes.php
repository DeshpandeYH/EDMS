<?php
$pageTitle = 'Attribute Master';
require_once __DIR__ . '/../includes/header.php';

$db = getDB();
$product_id = $_GET['product_id'] ?? '';

if (!$product_id) {
    setFlash('error', 'No product selected.');
    header('Location: product_codes.php');
    exit;
}

// Fetch product
$stmt = $db->prepare("SELECT * FROM product_codes WHERE id = ?");
$stmt->execute([$product_id]);
$product = $stmt->fetch();

if (!$product) {
    setFlash('error', 'Product not found.');
    header('Location: product_codes.php');
    exit;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_attribute') {
        $code = strtoupper(trim($_POST['code'] ?? ''));
        $name = trim($_POST['name'] ?? '');
        $position = intval($_POST['position_in_model'] ?? 0);
        $is_combined = isset($_POST['is_combined_attribute']) ? 1 : 0;
        $affects_engg = isset($_POST['affects_engg_dwg']) ? 1 : 0;
        $affects_internal = isset($_POST['affects_internal_dwg']) ? 1 : 0;
        $affects_sap = isset($_POST['affects_sap_item']) ? 1 : 0;
        $affects_cert = isset($_POST['affects_test_cert']) ? 1 : 0;
        
        if ($code && $name) {
            try {
                $attr_id = generateUUID();
                $stmt = $db->prepare("INSERT INTO attributes (id, product_code_id, code, name, position_in_model, is_combined_attribute, affects_engg_dwg, affects_internal_dwg, affects_sap_item, affects_test_cert, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$attr_id, $product_id, $code, $name, $position, $is_combined, $affects_engg, $affects_internal, $affects_sap, $affects_cert, $position]);
                
                // If marked as combined attribute, update product and unset others
                if ($is_combined) {
                    $db->prepare("UPDATE attributes SET is_combined_attribute = 0 WHERE product_code_id = ? AND id != ?")->execute([$product_id, $attr_id]);
                    $db->prepare("UPDATE product_codes SET combined_attribute_id = ?, updated_at = GETDATE() WHERE id = ?")->execute([$attr_id, $product_id]);
                }
                
                setFlash('success', "Attribute '$code — $name' added.");
            } catch (PDOException $e) {
                setFlash('error', "Error: " . $e->getMessage());
            }
        }
        header("Location: attributes.php?product_id=$product_id");
        exit;
    }
    
    if ($action === 'update_attribute') {
        $id = $_POST['id'] ?? '';
        $name = trim($_POST['name'] ?? '');
        $position = intval($_POST['position_in_model'] ?? 0);
        $is_combined = isset($_POST['is_combined_attribute']) ? 1 : 0;
        $affects_engg = isset($_POST['affects_engg_dwg']) ? 1 : 0;
        $affects_internal = isset($_POST['affects_internal_dwg']) ? 1 : 0;
        $affects_sap = isset($_POST['affects_sap_item']) ? 1 : 0;
        $affects_cert = isset($_POST['affects_test_cert']) ? 1 : 0;
        
        $stmt = $db->prepare("UPDATE attributes SET name=?, position_in_model=?, is_combined_attribute=?, affects_engg_dwg=?, affects_internal_dwg=?, affects_sap_item=?, affects_test_cert=?, sort_order=?, updated_at=GETDATE() WHERE id=?");
        $stmt->execute([$name, $position, $is_combined, $affects_engg, $affects_internal, $affects_sap, $affects_cert, $position, $id]);
        
        if ($is_combined) {
            $db->prepare("UPDATE attributes SET is_combined_attribute = 0 WHERE product_code_id = ? AND id != ?")->execute([$product_id, $id]);
            $db->prepare("UPDATE product_codes SET combined_attribute_id = ?, updated_at = GETDATE() WHERE id = ?")->execute([$id, $product_id]);
        }
        
        setFlash('success', 'Attribute updated.');
        header("Location: attributes.php?product_id=$product_id");
        exit;
    }
    
    if ($action === 'delete_attribute') {
        $id = $_POST['id'] ?? '';
        $db->prepare("UPDATE attributes SET is_active = 0, updated_at = GETDATE() WHERE id = ?")->execute([$id]);
        setFlash('success', 'Attribute deactivated.');
        header("Location: attributes.php?product_id=$product_id");
        exit;
    }
}

// Fetch attributes
$attributes = $db->prepare("
    SELECT a.*, 
        (SELECT COUNT(*) FROM attribute_options WHERE attribute_id = a.id AND is_active = 1) as option_count
    FROM attributes a 
    WHERE a.product_code_id = ? AND a.is_active = 1 
    ORDER BY a.position_in_model
");
$attributes->execute([$product_id]);
$attributes = $attributes->fetchAll();
?>

<h2>Attribute Master <span class="badge" style="background:var(--purple-bg);color:var(--purple);">ADMIN</span></h2>
<div class="gap-row mb-2">
    <a href="product_codes.php" class="btn btn-outline btn-sm">← Back to Products</a>
    <span class="tag tag-blue mono" style="font-size:0.85rem; padding:0.3rem 0.7rem;">
        <?= sanitize($product['code']) ?> — <?= sanitize($product['name']) ?>
    </span>
</div>

<!-- ADD ATTRIBUTE FORM -->
<div class="panel">
    <div class="panel-header">
        <span class="panel-title">Add New Attribute</span>
    </div>
    <div class="panel-body">
        <form method="POST" action="">
            <input type="hidden" name="action" value="create_attribute">
            <div class="form-row">
                <div class="form-field">
                    <label class="form-label">Attribute Code</label>
                    <input type="text" name="code" class="form-input" placeholder="SZ" maxlength="10" required style="text-transform:uppercase;">
                </div>
                <div class="form-field">
                    <label class="form-label">Attribute Name</label>
                    <input type="text" name="name" class="form-input" placeholder="Size" required>
                </div>
                <div class="form-field">
                    <label class="form-label">Position in Model Code</label>
                    <input type="number" name="position_in_model" class="form-input" value="<?= count($attributes) + 2 ?>" min="1">
                </div>
            </div>
            <div class="form-label mt-1" style="margin-bottom:0.5rem;">AFFECTS SELECTION OF:</div>
            <div class="check-grid" style="max-width:600px; margin-bottom:1rem;">
                <label class="form-checkbox"><input type="checkbox" name="affects_engg_dwg" value="1" checked> Engg Dwg</label>
                <label class="form-checkbox"><input type="checkbox" name="affects_internal_dwg" value="1" checked> Internal Dwg</label>
                <label class="form-checkbox"><input type="checkbox" name="affects_sap_item" value="1"> SAP Item</label>
                <label class="form-checkbox"><input type="checkbox" name="affects_test_cert" value="1"> Test Cert</label>
            </div>
            <div class="form-row">
                <label class="form-checkbox">
                    <input type="checkbox" name="is_combined_attribute" value="1"> Mark as Combined Attribute (radio logic — only one per product)
                </label>
            </div>
            <button type="submit" class="btn btn-primary mt-1">+ Add Attribute</button>
        </form>
    </div>
</div>

<!-- ATTRIBUTES TABLE -->
<div class="panel">
    <div class="panel-header">
        <span class="panel-title">Attributes for <?= sanitize($product['code']) ?></span>
        <span class="tag tag-blue"><?= count($attributes) ?> attributes</span>
    </div>
    <div class="panel-body" style="padding:0;">
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Attr Code</th>
                        <th>Attribute Name</th>
                        <th>Position</th>
                        <th>Options</th>
                        <th>Combined Attr</th>
                        <th>Engg Dwg</th>
                        <th>Int Dwg</th>
                        <th>SAP Item</th>
                        <th>Test Cert</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($attributes)): ?>
                    <tr><td colspan="11" class="text-center text-dim" style="padding:2rem;">No attributes defined. Add one above.</td></tr>
                    <?php else: ?>
                    <?php foreach ($attributes as $i => $a): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td class="mono"><?= sanitize($a['code']) ?></td>
                        <td><?= sanitize($a['name']) ?></td>
                        <td><?= $a['position_in_model'] ?></td>
                        <td><?= $a['option_count'] ?></td>
                        <td>
                            <?php if ($a['is_combined_attribute']): ?>
                                <span class="tag tag-orange">◉ Yes</span>
                            <?php else: ?>
                                <span class="text-dim">○</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $a['affects_engg_dwg'] ? '<span class="tag tag-green">✓</span>' : '<span class="tag tag-red">✗</span>' ?></td>
                        <td><?= $a['affects_internal_dwg'] ? '<span class="tag tag-green">✓</span>' : '<span class="tag tag-red">✗</span>' ?></td>
                        <td><?= $a['affects_sap_item'] ? '<span class="tag tag-green">✓</span>' : '<span class="tag tag-red">✗</span>' ?></td>
                        <td><?= $a['affects_test_cert'] ? '<span class="tag tag-green">✓</span>' : '<span class="tag tag-red">✗</span>' ?></td>
                        <td>
                            <div class="btn-group">
                                <a href="attribute_options.php?attribute_id=<?= $a['id'] ?>&product_id=<?= $product_id ?>" class="btn btn-outline btn-sm">Options</a>
                                <button class="btn btn-outline btn-sm" onclick="editAttr(<?= htmlspecialchars(json_encode($a), ENT_QUOTES) ?>)">Edit</button>
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

<div class="callout callout-tip">
    <strong>Combined Attribute</strong> — Only one attribute per product can be the combined attribute (radio button logic). 
    This attribute's options become rows in the combined drawing's dimension table. For <?= sanitize($product['code']) ?>, 
    size values (2", 4", 6") would become rows with their respective dimensions, quantities, and SAP codes.
</div>

<!-- EDIT MODAL -->
<div class="modal-overlay" id="editAttrModal">
    <div class="modal">
        <div class="modal-header">
            <span>Edit Attribute</span>
            <button class="modal-close" onclick="closeModal('editAttrModal')">&times;</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="update_attribute">
            <input type="hidden" name="id" id="ea_id">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Attribute Name</label>
                    <input type="text" name="name" id="ea_name" class="form-input" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Position in Model Code</label>
                    <input type="number" name="position_in_model" id="ea_position" class="form-input" min="1">
                </div>
                <div class="form-label mt-1">AFFECTS:</div>
                <div class="form-row" style="margin-top:0.5rem;">
                    <label class="form-checkbox"><input type="checkbox" name="affects_engg_dwg" id="ea_engg" value="1"> Engg Dwg</label>
                    <label class="form-checkbox"><input type="checkbox" name="affects_internal_dwg" id="ea_internal" value="1"> Internal Dwg</label>
                    <label class="form-checkbox"><input type="checkbox" name="affects_sap_item" id="ea_sap" value="1"> SAP Item</label>
                    <label class="form-checkbox"><input type="checkbox" name="affects_test_cert" id="ea_cert" value="1"> Test Cert</label>
                </div>
                <div class="form-group mt-2">
                    <label class="form-checkbox"><input type="checkbox" name="is_combined_attribute" id="ea_combined" value="1"> Combined Attribute</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('editAttrModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function editAttr(a) {
    document.getElementById('ea_id').value = a.id;
    document.getElementById('ea_name').value = a.name;
    document.getElementById('ea_position').value = a.position_in_model;
    document.getElementById('ea_engg').checked = a.affects_engg_dwg == 1;
    document.getElementById('ea_internal').checked = a.affects_internal_dwg == 1;
    document.getElementById('ea_sap').checked = a.affects_sap_item == 1;
    document.getElementById('ea_cert').checked = a.affects_test_cert == 1;
    document.getElementById('ea_combined').checked = a.is_combined_attribute == 1;
    document.getElementById('editAttrModal').classList.add('active');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
