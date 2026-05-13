<?php
$pageTitle = 'Sales Orders';
require_once __DIR__ . '/../includes/header.php';

$db = getDB();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_so') {
        $so_number = trim($_POST['so_number'] ?? '');
        $customer = trim($_POST['customer_name'] ?? '');
        $project = trim($_POST['project_name'] ?? '');
        $po_ref = trim($_POST['po_reference'] ?? '');
        $delivery = $_POST['delivery_date'] ?? null;
        
        if ($so_number && $customer) {
            try {
                $so_id = generateUUID();
                $stmt = $db->prepare("INSERT INTO sales_orders (id, so_number, customer_name, project_name, po_reference, delivery_date, status) VALUES (?, ?, ?, ?, ?, ?, 'draft')");
                $stmt->execute([$so_id, $so_number, $customer, $project, $po_ref, $delivery ?: null]);
                setFlash('success', "Sales Order '$so_number' created.");
                header("Location: sales_order_detail.php?id=$so_id");
                exit;
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'UNIQUE') !== false || strpos($e->getMessage(), 'duplicate') !== false) {
                    setFlash('error', "SO '$so_number' already exists.");
                } else {
                    setFlash('error', "Error: " . $e->getMessage());
                }
            }
        } else {
            setFlash('error', 'SO Number and Customer are required.');
        }
        header('Location: sales_orders.php');
        exit;
    }
}

// Fetch all sales orders
$orders = $db->query("
    SELECT so.*, 
        (SELECT COUNT(*) FROM so_line_items WHERE sales_order_id = so.id) as line_count,
        (SELECT COUNT(*) FROM generated_documents gd JOIN so_line_items sli ON gd.so_line_item_id = sli.id WHERE sli.sales_order_id = so.id) as doc_count
    FROM sales_orders so
    WHERE so.status != 'cancelled'
    ORDER BY so.created_at DESC
")->fetchAll();
?>

<h2>Sales Orders <span class="badge" style="background:var(--cyan-bg);color:var(--cyan);">SALES</span></h2>
<p>Create and manage sales orders. Each order contains line items with model codes that resolve to engineering drawings, internal drawings, SAP codes, and test certificates.</p>

<!-- CREATE SO FORM -->
<div class="panel">
    <div class="panel-header">
        <span class="panel-title">Create New Sales Order</span>
    </div>
    <div class="panel-body">
        <form method="POST" action="">
            <input type="hidden" name="action" value="create_so">
            <div class="form-row">
                <div class="form-field">
                    <label class="form-label">SO Number</label>
                    <input type="text" name="so_number" class="form-input" placeholder="SO-2026-0412" required>
                </div>
                <div class="form-field">
                    <label class="form-label">Customer</label>
                    <input type="text" name="customer_name" class="form-input" placeholder="Aramco Eng. Services" required>
                </div>
                <div class="form-field">
                    <label class="form-label">Project Name</label>
                    <input type="text" name="project_name" class="form-input" placeholder="Jafurah Gas Processing">
                </div>
            </div>
            <div class="form-row">
                <div class="form-field">
                    <label class="form-label">PO Reference</label>
                    <input type="text" name="po_reference" class="form-input" placeholder="PO-AES-44021">
                </div>
                <div class="form-field">
                    <label class="form-label">Delivery Date</label>
                    <input type="date" name="delivery_date" class="form-input">
                </div>
                <div class="form-field" style="flex:0 0 auto;">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary">+ Create Order</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ORDERS TABLE -->
<div class="panel">
    <div class="panel-header">
        <span class="panel-title">All Sales Orders</span>
        <span class="tag tag-blue"><?= count($orders) ?> orders</span>
    </div>
    <div class="panel-body" style="padding:0;">
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>SO Number</th>
                        <th>Customer</th>
                        <th>Project</th>
                        <th>PO Ref</th>
                        <th>Delivery</th>
                        <th>Lines</th>
                        <th>Docs</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($orders)): ?>
                    <tr><td colspan="10" class="text-center text-dim" style="padding:2rem;">No sales orders yet.</td></tr>
                    <?php else: ?>
                    <?php foreach ($orders as $o): ?>
                    <tr>
                        <td class="mono text-accent"><?= sanitize($o['so_number']) ?></td>
                        <td><?= sanitize($o['customer_name']) ?></td>
                        <td><?= sanitize($o['project_name'] ?? '—') ?></td>
                        <td class="mono"><?= sanitize($o['po_reference'] ?? '—') ?></td>
                        <td><?= $o['delivery_date'] ?? '—' ?></td>
                        <td><?= $o['line_count'] ?></td>
                        <td><?= $o['doc_count'] ?></td>
                        <td>
                            <?php
                            $statusClass = match($o['status']) {
                                'approved' => 'tag-green',
                                'completed' => 'tag-green',
                                'in_progress' => 'tag-blue',
                                'draft' => 'tag-orange',
                                default => 'tag-dim'
                            };
                            ?>
                            <span class="tag <?= $statusClass ?>"><?= ucfirst(str_replace('_', ' ', $o['status'])) ?></span>
                        </td>
                        <td style="font-size:0.75rem;"><?= date('M d, Y', strtotime($o['created_at'])) ?></td>
                        <td>
                            <a href="sales_order_detail.php?id=<?= $o['id'] ?>" class="btn btn-outline btn-sm">Open</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
