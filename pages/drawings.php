<?php
$pageTitle = 'Drawings';
require_once __DIR__ . '/../includes/header.php';

$db = getDB();

// Fetch all generated documents.
// IMPORTANT: combined documents have so_line_item_id = NULL and are tied via
// combined_group_id instead. We LEFT JOIN both so individual AND combined
// drawings appear in the list.
$docs = $db->query("
    SELECT gd.*,
           sli.line_number, sli.model_code_string, sli.quantity,
           COALESCE(so_indiv.so_number, so_comb.so_number)         AS so_number,
           COALESCE(so_indiv.customer_name, so_comb.customer_name) AS customer_name,
           t.template_code,
           COALESCE(pc_indiv.code, pc_comb.code) AS product_code,
           cdg.common_attrs_display
    FROM generated_documents gd
    LEFT JOIN so_line_items sli       ON gd.so_line_item_id = sli.id
    LEFT JOIN sales_orders  so_indiv  ON sli.sales_order_id = so_indiv.id
    LEFT JOIN product_codes pc_indiv  ON sli.product_code_id = pc_indiv.id
    LEFT JOIN combined_drawing_groups cdg ON gd.combined_group_id = cdg.id
    LEFT JOIN sales_orders  so_comb   ON cdg.sales_order_id = so_comb.id
    LEFT JOIN product_codes pc_comb   ON cdg.product_code_id = pc_comb.id
    LEFT JOIN templates     t         ON gd.template_id = t.id
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
                    <td class="mono text-accent"><?= sanitize($d['so_number'] ?? '—') ?></td>
                    <td class="mono"><?= sanitize($d['product_code'] ?? '—') ?></td>
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
