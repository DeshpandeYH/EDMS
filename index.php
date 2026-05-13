<?php
$pageTitle = 'Dashboard';
require_once __DIR__ . '/includes/header.php';

$db = getDB();

// Dashboard statistics
try {
    $stats = [
        'products' => $db->query("SELECT COUNT(*) FROM product_codes WHERE status != 'archived'")->fetchColumn(),
        'attributes' => $db->query("SELECT COUNT(*) FROM attributes WHERE is_active = 1")->fetchColumn(),
        'combinations' => $db->query("SELECT COUNT(*) FROM combination_matrix WHERE is_active = 1")->fetchColumn(),
        'templates' => $db->query("SELECT COUNT(*) FROM templates WHERE is_active = 1")->fetchColumn(),
        'sales_orders' => $db->query("SELECT COUNT(*) FROM sales_orders WHERE status != 'cancelled'")->fetchColumn(),
        'line_items' => $db->query("SELECT COUNT(*) FROM so_line_items")->fetchColumn(),
        'documents' => $db->query("SELECT COUNT(*) FROM generated_documents")->fetchColumn(),
        'certificates' => $db->query("SELECT COUNT(*) FROM generated_certificates")->fetchColumn(),
    ];
} catch (PDOException $e) {
    $stats = array_fill_keys(['products','attributes','combinations','templates','sales_orders','line_items','documents','certificates'], 0);
}

// Recent orders
try {
    $recent_orders = $db->query("
        SELECT TOP 5 so.*, 
            (SELECT COUNT(*) FROM so_line_items WHERE sales_order_id = so.id) as line_count
        FROM sales_orders so 
        WHERE so.status != 'cancelled'
        ORDER BY so.created_at DESC
    ")->fetchAll();
} catch (PDOException $e) {
    $recent_orders = [];
}

// Pending changes
try {
    $pending_changes = $db->query("SELECT COUNT(*) FROM change_events WHERE status = 'pending'")->fetchColumn();
} catch (PDOException $e) {
    $pending_changes = 0;
}
?>

<h1>Dashboard</h1>
<p>Engineering Drawing Management System — Overview</p>

<!-- STATS -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value text-accent"><?= $stats['products'] ?></div>
        <div class="stat-label">Product Codes</div>
    </div>
    <div class="stat-card">
        <div class="stat-value text-purple"><?= $stats['combinations'] ?></div>
        <div class="stat-label">Combinations</div>
    </div>
    <div class="stat-card">
        <div class="stat-value text-cyan"><?= $stats['templates'] ?></div>
        <div class="stat-label">Templates</div>
    </div>
    <div class="stat-card">
        <div class="stat-value text-orange"><?= $stats['sales_orders'] ?></div>
        <div class="stat-label">Sales Orders</div>
    </div>
    <div class="stat-card">
        <div class="stat-value text-green"><?= $stats['documents'] ?></div>
        <div class="stat-label">Generated Docs</div>
    </div>
    <div class="stat-card">
        <div class="stat-value" style="color:var(--orange);"><?= $stats['certificates'] ?></div>
        <div class="stat-label">Certificates</div>
    </div>
</div>

<?php if ($pending_changes > 0): ?>
<div class="callout callout-warn">
    <strong><?= $pending_changes ?> pending change(s)</strong> require review. 
    <a href="<?= BASE_URL ?>/pages/changes.php">Review now →</a>
</div>
<?php endif; ?>

<!-- QUICK ACTIONS -->
<div class="grid-3 mt-3">
    <a href="<?= BASE_URL ?>/pages/product_codes.php" class="card" style="text-decoration:none;">
        <div class="card-header">
            <div class="card-icon" style="background:var(--purple-bg);color:var(--purple);">📦</div>
            Product Codes
        </div>
        <p>Define products, attributes, options, and combination matrix.</p>
    </a>
    <a href="<?= BASE_URL ?>/pages/sales_orders.php" class="card" style="text-decoration:none;">
        <div class="card-header">
            <div class="card-icon" style="background:var(--cyan-bg);color:var(--cyan);">📋</div>
            Sales Orders
        </div>
        <p>Create orders, build model codes, generate documents.</p>
    </a>
    <a href="<?= BASE_URL ?>/pages/templates.php" class="card" style="text-decoration:none;">
        <div class="card-header">
            <div class="card-icon" style="background:var(--green-bg);color:var(--green);">📄</div>
            Templates
        </div>
        <p>Upload DWG/DXF/XLSX templates and configure field mappings.</p>
    </a>
</div>

<!-- RECENT ORDERS -->
<h3 class="mt-3">Recent Sales Orders</h3>
<div class="panel">
    <div class="panel-body" style="padding:0;">
        <table class="data-table">
            <thead>
                <tr><th>SO Number</th><th>Customer</th><th>Project</th><th>Lines</th><th>Status</th><th>Created</th><th></th></tr>
            </thead>
            <tbody>
                <?php if (empty($recent_orders)): ?>
                <tr><td colspan="7" class="text-center text-dim" style="padding:2rem;">No sales orders yet. <a href="<?= BASE_URL ?>/pages/sales_orders.php">Create one →</a></td></tr>
                <?php else: ?>
                <?php foreach ($recent_orders as $o): ?>
                <tr>
                    <td class="mono text-accent"><?= sanitize($o['so_number']) ?></td>
                    <td><?= sanitize($o['customer_name']) ?></td>
                    <td><?= sanitize($o['project_name'] ?? '—') ?></td>
                    <td><?= $o['line_count'] ?></td>
                    <td>
                        <span class="tag tag-<?= match($o['status']) { 'approved' => 'green', 'in_progress' => 'blue', 'draft' => 'orange', default => 'dim' } ?>">
                            <?= ucfirst(str_replace('_', ' ', $o['status'])) ?>
                        </span>
                    </td>
                    <td style="font-size:0.75rem;"><?= date('M d, Y', strtotime($o['created_at'])) ?></td>
                    <td><a href="<?= BASE_URL ?>/pages/sales_order_detail.php?id=<?= $o['id'] ?>" class="btn btn-outline btn-sm">Open</a></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- PROCESS FLOW -->
<h3 class="mt-3">System Process Flow</h3>
<div class="card">
    <div class="flow-container">
        <div class="flow-row">
            <div class="flow-step purple">Create Product Code</div>
            <div class="flow-arrow">→</div>
            <div class="flow-step purple">Define Attributes</div>
            <div class="flow-arrow">→</div>
            <div class="flow-step purple">Define Options</div>
            <div class="flow-arrow">→</div>
            <div class="flow-step green">Auto-Generate Combos</div>
            <div class="flow-arrow">→</div>
            <div class="flow-step orange">Assign Templates</div>
        </div>
        <div class="flow-row">
            <div class="flow-step blue">Create Sales Order</div>
            <div class="flow-arrow">→</div>
            <div class="flow-step blue">Build Model Codes</div>
            <div class="flow-arrow">→</div>
            <div class="flow-step green">Auto-Resolve</div>
            <div class="flow-arrow">→</div>
            <div class="flow-step green">Generate Documents</div>
            <div class="flow-arrow">→</div>
            <div class="flow-step orange">Review & Approve</div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
