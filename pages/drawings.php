<?php
$pageTitle = 'Drawings';
require_once __DIR__ . '/../includes/header.php';

$db = getDB();

// Fetch all generated documents
$docs = $db->query("
    SELECT gd.*, sli.line_number, sli.model_code_string, sli.quantity,
           so.so_number, so.customer_name,
           t.template_code, pc.code as product_code
    FROM generated_documents gd
    JOIN so_line_items sli ON gd.so_line_item_id = sli.id
    JOIN sales_orders so ON sli.sales_order_id = so.id
    LEFT JOIN templates t ON gd.template_id = t.id
    JOIN product_codes pc ON sli.product_code_id = pc.id
    ORDER BY gd.generated_at DESC
")->fetchAll();

$individual_docs = array_filter($docs, fn($d) => str_contains($d['document_type'], 'individual'));
$combined_docs = array_filter($docs, fn($d) => str_contains($d['document_type'], 'combined'));
?>

<h2>Drawings <span class="badge" style="background:var(--green-bg);color:var(--green);">OUTPUT</span></h2>
<p>All generated engineering and internal drawings across all sales orders.</p>

<!-- INDIVIDUAL DRAWINGS -->
<h3 class="mt-2" style="color:var(--green);">Individual Drawings</h3>
<div class="panel">
    <div class="panel-body" style="padding:0;">
        <table class="data-table">
            <thead>
                <tr><th>SO</th><th>Line</th><th>Model Code</th><th>Type</th><th>Template</th><th>Status</th><th>Generated</th><th></th></tr>
            </thead>
            <tbody>
                <?php if (empty($individual_docs)): ?>
                <tr><td colspan="8" class="text-center text-dim" style="padding:2rem;">No individual drawings generated yet.</td></tr>
                <?php else: ?>
                <?php foreach ($individual_docs as $d): ?>
                <tr>
                    <td class="mono text-accent"><?= sanitize($d['so_number']) ?></td>
                    <td><?= $d['line_number'] ?></td>
                    <td class="mono"><?= sanitize($d['model_code_string']) ?></td>
                    <td>
                        <?php $tc = str_contains($d['document_type'], 'engg') ? 'tag-blue' : 'tag-purple'; ?>
                        <span class="tag <?= $tc ?>"><?= str_contains($d['document_type'], 'engg') ? 'Engg' : 'Internal' ?></span>
                    </td>
                    <td class="mono"><?= sanitize($d['template_code'] ?? '—') ?></td>
                    <td><span class="tag tag-<?= $d['status'] === 'approved' ? 'green' : 'blue' ?>"><?= ucfirst($d['status']) ?></span></td>
                    <td style="font-size:0.75rem;"><?= date('M d, Y', strtotime($d['generated_at'])) ?></td>
                    <td><a href="drawing_preview.php?doc_id=<?= $d['id'] ?>" class="btn btn-outline btn-sm">View</a></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- COMBINED DRAWINGS -->
<h3 class="mt-2" style="color:var(--orange);">Combined Drawings</h3>
<div class="panel">
    <div class="panel-body" style="padding:0;">
        <table class="data-table">
            <thead>
                <tr><th>SO</th><th>Product</th><th>Type</th><th>Status</th><th>Generated</th><th></th></tr>
            </thead>
            <tbody>
                <?php if (empty($combined_docs)): ?>
                <tr><td colspan="6" class="text-center text-dim" style="padding:2rem;">No combined drawings generated yet.</td></tr>
                <?php else: ?>
                <?php foreach ($combined_docs as $d): ?>
                <tr>
                    <td class="mono text-accent"><?= sanitize($d['so_number']) ?></td>
                    <td class="mono"><?= sanitize($d['product_code']) ?></td>
                    <td><span class="tag tag-<?= str_contains($d['document_type'], 'engg') ? 'blue' : 'purple' ?>"><?= str_contains($d['document_type'], 'engg') ? 'Engg Combined' : 'Internal Combined' ?></span></td>
                    <td><span class="tag tag-<?= $d['status'] === 'approved' ? 'green' : 'blue' ?>"><?= ucfirst($d['status']) ?></span></td>
                    <td style="font-size:0.75rem;"><?= date('M d, Y', strtotime($d['generated_at'])) ?></td>
                    <td><a href="drawing_preview.php?doc_id=<?= $d['id'] ?>" class="btn btn-outline btn-sm">View</a></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
