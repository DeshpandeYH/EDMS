<?php
$pageTitle = 'Product Code Master';
require_once __DIR__ . '/../includes/header.php';

$db = getDB();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        $code = strtoupper(trim($_POST['code'] ?? ''));
        $name = trim($_POST['name'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $supports_combined = isset($_POST['supports_combined_dwg']) ? 1 : 0;
        $description = trim($_POST['description'] ?? '');
        
        if ($code && $name) {
            $stmt = $db->prepare("INSERT INTO product_codes (id, code, name, category, supports_combined_dwg, description, status) VALUES (NEWID(), ?, ?, ?, ?, ?, 'draft')");
            try {
                $stmt->execute([$code, $name, $category, $supports_combined, $description]);
                setFlash('success', "Product code '$code' created successfully.");
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'UNIQUE') !== false || strpos($e->getMessage(), 'duplicate') !== false) {
                    setFlash('error', "Product code '$code' already exists.");
                } else {
                    setFlash('error', "Error creating product: " . $e->getMessage());
                }
            }
        } else {
            setFlash('error', 'Code and Name are required.');
        }
        header('Location: product_codes.php');
        exit;
    }
    
    if ($action === 'update') {
        $id = $_POST['id'] ?? '';
        $name = trim($_POST['name'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $supports_combined = isset($_POST['supports_combined_dwg']) ? 1 : 0;
        $description = trim($_POST['description'] ?? '');
        $status = $_POST['status'] ?? 'draft';
        
        $stmt = $db->prepare("UPDATE product_codes SET name=?, category=?, supports_combined_dwg=?, description=?, status=?, updated_at=GETDATE() WHERE id=?");
        $stmt->execute([$name, $category, $supports_combined, $description, $status, $id]);
        setFlash('success', 'Product updated successfully.');
        header('Location: product_codes.php');
        exit;
    }
    
    if ($action === 'delete') {
        $id = $_POST['id'] ?? '';
        // Soft delete - set to archived
        $stmt = $db->prepare("UPDATE product_codes SET status='archived', updated_at=GETDATE() WHERE id=?");
        $stmt->execute([$id]);
        setFlash('success', 'Product archived.');
        header('Location: product_codes.php');
        exit;
    }
}

// Fetch all products with counts
$products = $db->query("
    SELECT pc.*, 
        (SELECT COUNT(*) FROM attributes WHERE product_code_id = pc.id AND is_active = 1) as attr_count,
        (SELECT COUNT(*) FROM combination_matrix WHERE product_code_id = pc.id AND is_active = 1) as combo_count,
        ca.code as combined_attr_code, ca.name as combined_attr_name
    FROM product_codes pc
    LEFT JOIN attributes ca ON pc.combined_attribute_id = ca.id
    WHERE pc.status != 'archived'
    ORDER BY pc.code
")->fetchAll();
?>

<h2>Product Code Master <span class="badge" style="background:var(--purple-bg);color:var(--purple);">ADMIN</span></h2>
<p>Define product codes (e.g., GVF = Gate Valve — Flanged). Each product has configurable attributes, options, and combination matrix.</p>

<!-- ADD PRODUCT FORM -->
<div class="panel">
    <div class="panel-header">
        <span class="panel-title">Add New Product Code</span>
    </div>
    <div class="panel-body">
        <form method="POST" action="">
            <input type="hidden" name="action" value="create">
            <div class="form-row">
                <div class="form-field">
                    <label class="form-label">Product Code</label>
                    <input type="text" name="code" class="form-input" placeholder="GVF" maxlength="10" required 
                           style="text-transform:uppercase;" pattern="[A-Za-z0-9 \-]+" title="Alphanumeric, spaces and hyphens allowed">
                </div>
                <div class="form-field">
                    <label class="form-label">Product Name</label>
                    <input type="text" name="name" class="form-input" placeholder="Gate Valve — Flanged" required>
                </div>
                <div class="form-field">
                    <label class="form-label">Category</label>
                    <select name="category" class="form-select">
                        <option value="">Select Category</option>
                        <option value="Valves">Valves</option>
                        <option value="Pumps">Pumps</option>
                        <option value="Fittings">Fittings</option>
                        <option value="Instruments">Instruments</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="form-field">
                    <label class="form-label">Supports Combined Dwg?</label>
                    <label class="form-checkbox" style="margin-top:0.5rem;">
                        <input type="checkbox" name="supports_combined_dwg" value="1"> Yes
                    </label>
                </div>
                <div class="form-field" style="flex:0 0 auto;">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary">+ Add Product</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- CALLOUT -->
<div class="callout callout-info">
    <strong>One product, two drawing types.</strong> A single product code can generate both Individual and Combined drawings. 
    Individual = one drawing per SO line item. Combined = one drawing consolidating multiple line items of the same product 
    into a dimension table, pivoted on the combined attribute.
</div>

<!-- PRODUCTS TABLE -->
<div class="panel">
    <div class="panel-header">
        <span class="panel-title">All Product Codes</span>
        <span class="tag tag-blue"><?= count($products) ?> products</span>
    </div>
    <div class="panel-body" style="padding:0;">
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Product Name</th>
                        <th>Category</th>
                        <th>Combined?</th>
                        <th>Combined Attr</th>
                        <th>Attrs</th>
                        <th>Combos</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($products)): ?>
                    <tr><td colspan="9" class="text-center text-dim" style="padding:2rem;">No product codes defined yet. Add one above.</td></tr>
                    <?php else: ?>
                    <?php foreach ($products as $p): ?>
                    <tr>
                        <td class="mono text-accent"><?= sanitize($p['code']) ?></td>
                        <td><?= sanitize($p['name']) ?></td>
                        <td><?= sanitize($p['category'] ?? '—') ?></td>
                        <td>
                            <?php if ($p['supports_combined_dwg']): ?>
                                <span class="tag tag-green">✓ Yes</span>
                            <?php else: ?>
                                <span class="tag tag-red">✗ No</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($p['combined_attr_code']): ?>
                                <span class="mono text-orange"><?= sanitize($p['combined_attr_code']) ?> — <?= sanitize($p['combined_attr_name']) ?></span>
                            <?php else: ?>
                                <span class="text-dim">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $p['attr_count'] ?></td>
                        <td><?= $p['combo_count'] ?></td>
                        <td>
                            <?php
                            $statusClass = match($p['status']) {
                                'active' => 'tag-green',
                                'draft' => 'tag-orange',
                                'incomplete' => 'tag-red',
                                default => 'tag-dim'
                            };
                            ?>
                            <span class="tag <?= $statusClass ?>"><?= ucfirst($p['status']) ?></span>
                        </td>
                        <td>
                            <div class="btn-group">
                                <a href="attributes.php?product_id=<?= $p['id'] ?>" class="btn btn-outline btn-sm">Attributes</a>
                                <a href="combinations.php?product_id=<?= $p['id'] ?>" class="btn btn-outline btn-sm">Matrix</a>
                                <a href="anchor_config.php?product_id=<?= $p['id'] ?>" class="btn btn-outline btn-sm">Anchors</a>
                                <button class="btn btn-outline btn-sm" onclick="editProduct('<?= $p['id'] ?>', '<?= sanitize($p['name']) ?>', '<?= sanitize($p['category'] ?? '') ?>', <?= $p['supports_combined_dwg'] ?>, '<?= sanitize($p['description'] ?? '') ?>', '<?= $p['status'] ?>')">Edit</button>
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
<div class="modal-overlay" id="editModal">
    <div class="modal">
        <div class="modal-header">
            <span>Edit Product Code</span>
            <button class="modal-close" onclick="closeModal('editModal')">&times;</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" id="edit_id">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Product Name</label>
                    <input type="text" name="name" id="edit_name" class="form-input" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Category</label>
                    <select name="category" id="edit_category" class="form-select">
                        <option value="">Select Category</option>
                        <option value="Valves">Valves</option>
                        <option value="Pumps">Pumps</option>
                        <option value="Fittings">Fittings</option>
                        <option value="Instruments">Instruments</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-checkbox">
                        <input type="checkbox" name="supports_combined_dwg" id="edit_combined" value="1"> Supports Combined Drawing
                    </label>
                </div>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" id="edit_description" class="form-textarea form-input" style="height:80px;"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select name="status" id="edit_status" class="form-select">
                        <option value="draft">Draft</option>
                        <option value="active">Active</option>
                        <option value="incomplete">Incomplete</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function editProduct(id, name, category, combined, description, status) {
    document.getElementById('edit_id').value = id;
    document.getElementById('edit_name').value = name;
    document.getElementById('edit_category').value = category;
    document.getElementById('edit_combined').checked = combined == 1;
    document.getElementById('edit_description').value = description;
    document.getElementById('edit_status').value = status;
    document.getElementById('editModal').classList.add('active');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
