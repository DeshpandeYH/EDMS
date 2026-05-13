<?php
$pageTitle = 'Change Management';
require_once __DIR__ . '/../includes/header.php';

$db = getDB();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'confirm_change') {
        $change_id = $_POST['change_id'] ?? '';
        $db->prepare("UPDATE change_events SET status = 'confirmed', confirmed_at = GETDATE() WHERE id = ?")
           ->execute([$change_id]);
        setFlash('success', 'Change confirmed and applied.');
        header('Location: changes.php');
        exit;
    }
    
    if ($action === 'rollback_change') {
        $change_id = $_POST['change_id'] ?? '';
        $db->prepare("UPDATE change_events SET status = 'rolled_back' WHERE id = ?")
           ->execute([$change_id]);
        setFlash('success', 'Change rolled back.');
        header('Location: changes.php');
        exit;
    }
}

// Fetch all change events
$changes = $db->query("
    SELECT ce.*, pc.code as product_code, pc.name as product_name,
           a.code as attr_code, a.name as attr_name,
           ao.code as opt_code, ao.value as opt_value
    FROM change_events ce
    JOIN product_codes pc ON ce.product_code_id = pc.id
    LEFT JOIN attributes a ON ce.attribute_id = a.id
    LEFT JOIN attribute_options ao ON ce.option_id = ao.id
    ORDER BY ce.created_at DESC
")->fetchAll();

$pending = array_filter($changes, fn($c) => $c['status'] === 'pending');
?>

<h2>Change Management <span class="badge" style="background:var(--red-bg);color:var(--red);">AUDIT</span></h2>
<p>Track attribute and option changes, review impact on combination matrix and active sales orders.</p>

<?php if (!empty($pending)): ?>
<div class="callout callout-warn">
    <strong><?= count($pending) ?> pending changes detected.</strong> Changes to attributes or options require review before the combination matrix is regenerated.
</div>
<?php endif; ?>

<!-- CHANGE EVENTS TABLE -->
<div class="panel">
    <div class="panel-header">
        <span class="panel-title">Change Events</span>
        <span class="tag tag-blue"><?= count($changes) ?> events</span>
    </div>
    <div class="panel-body" style="padding:0;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>Product</th>
                    <th>Change Type</th>
                    <th>Detail</th>
                    <th>Combos Δ</th>
                    <th>SOs Affected</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($changes)): ?>
                <tr><td colspan="8" class="text-center text-dim" style="padding:2rem;">No change events recorded yet. Changes are automatically logged when attributes or options are modified.</td></tr>
                <?php else: ?>
                <?php foreach ($changes as $c): ?>
                <tr>
                    <td style="font-size:0.75rem;"><?= date('M d H:i', strtotime($c['created_at'])) ?></td>
                    <td class="mono text-accent"><?= sanitize($c['product_code']) ?></td>
                    <td>
                        <?php
                        $typeInfo = match($c['change_type']) {
                            'attr_add' => ['tag-purple', 'Attr Added'],
                            'attr_delete' => ['tag-red', 'Attr Deleted'],
                            'attr_flag_change' => ['tag-orange', 'Flag Changed'],
                            'opt_add' => ['tag-cyan', 'Option Added'],
                            'opt_deactivate' => ['tag-red', 'Opt Deactivated'],
                            'opt_reactivate' => ['tag-green', 'Opt Reactivated'],
                            default => ['tag-dim', $c['change_type']]
                        };
                        ?>
                        <span class="tag <?= $typeInfo[0] ?>"><?= $typeInfo[1] ?></span>
                    </td>
                    <td style="font-size:0.8rem;">
                        <?php if ($c['attr_name']): ?>
                            <strong><?= sanitize($c['attr_name']) ?> (<?= sanitize($c['attr_code']) ?>)</strong>
                        <?php endif; ?>
                        <?php if ($c['opt_value']): ?>
                            — <?= sanitize($c['opt_code']) ?>: <?= sanitize($c['opt_value']) ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($c['combos_added'] > 0): ?>
                            <span class="text-orange">+<?= $c['combos_added'] ?></span>
                        <?php endif; ?>
                        <?php if ($c['combos_removed'] > 0): ?>
                            <span class="text-red">−<?= $c['combos_removed'] ?></span>
                        <?php endif; ?>
                        <?php if ($c['combos_added'] == 0 && $c['combos_removed'] == 0): ?>
                            <span class="text-dim">0</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $c['affected_so_count'] ?></td>
                    <td>
                        <?php
                        $sc = match($c['status']) {
                            'confirmed' => 'tag-green',
                            'pending' => 'tag-orange',
                            'rolled_back' => 'tag-red',
                            default => 'tag-dim'
                        };
                        ?>
                        <span class="tag <?= $sc ?>"><?= ucfirst(str_replace('_', ' ', $c['status'])) ?></span>
                    </td>
                    <td>
                        <?php if ($c['status'] === 'pending'): ?>
                        <div class="btn-group">
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="confirm_change">
                                <input type="hidden" name="change_id" value="<?= $c['id'] ?>">
                                <button type="submit" class="btn btn-primary btn-sm" onclick="return confirm('Confirm this change?')">Confirm</button>
                            </form>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="rollback_change">
                                <input type="hidden" name="change_id" value="<?= $c['id'] ?>">
                                <button type="submit" class="btn btn-outline btn-sm" style="color:var(--red);">Cancel</button>
                            </form>
                        </div>
                        <?php else: ?>
                        <button class="btn btn-outline btn-sm">Log</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="callout callout-tip mt-2">
    <strong>Full traceability.</strong> Every change is immutably logged with before/after snapshots. Archived combinations and orphaned template mappings can be restored from this log.
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
