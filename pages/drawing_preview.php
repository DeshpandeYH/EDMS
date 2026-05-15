<?php
$pageTitle = 'Drawing Preview';
require_once __DIR__ . '/../includes/header.php';

$db = getDB();
$doc_id = $_GET['doc_id'] ?? '';

if (!$doc_id) { header('Location: sales_orders.php'); exit; }

// Combined documents have so_line_item_id = NULL; LEFT JOIN both sides so the
// page works for individual AND combined drawings.
$stmt = $db->prepare("
    SELECT gd.*,
           sli.line_number, sli.model_code_string, sli.quantity,
           sli.generate_individual, sli.generate_combined,
           sli.sales_order_id AS line_sales_order_id,
           COALESCE(so_indiv.id, so_comb.id)                       AS sales_order_id,
           COALESCE(so_indiv.so_number, so_comb.so_number)         AS so_number,
           COALESCE(so_indiv.customer_name, so_comb.customer_name) AS customer_name,
           COALESCE(so_indiv.project_name, so_comb.project_name)   AS project_name,
           COALESCE(so_indiv.po_reference, so_comb.po_reference)   AS po_reference,
           t.template_code, t.file_path AS template_file,
           COALESCE(pc_indiv.code, pc_comb.code) AS product_code,
           COALESCE(pc_indiv.name, pc_comb.name) AS product_name,
           cdg.common_attrs_display, cdg.pivot_values
    FROM generated_documents gd
    LEFT JOIN so_line_items sli      ON gd.so_line_item_id = sli.id
    LEFT JOIN sales_orders  so_indiv ON sli.sales_order_id = so_indiv.id
    LEFT JOIN product_codes pc_indiv ON sli.product_code_id = pc_indiv.id
    LEFT JOIN combined_drawing_groups cdg ON gd.combined_group_id = cdg.id
    LEFT JOIN sales_orders  so_comb  ON cdg.sales_order_id = so_comb.id
    LEFT JOIN product_codes pc_comb  ON cdg.product_code_id = pc_comb.id
    LEFT JOIN templates     t        ON gd.template_id = t.id
    WHERE gd.id = ?
");
$stmt->execute([$doc_id]);
$doc = $stmt->fetch();
if (!$doc) { setFlash('error', 'Document not found.'); header('Location: sales_orders.php'); exit; }

$is_combined = str_contains($doc['document_type'], 'combined');
$is_internal = str_contains($doc['document_type'], 'internal');
$type_label  = str_replace('_', ' ', ucwords($doc['document_type'], '_'));

// Selections: for individual docs the line's own; for combined docs we pull
// selections from the FIRST grouped line (representative of the common attrs).
if ($is_combined && $doc['combined_group_id']) {
    $sels = $db->prepare("
        SELECT a.code as attr_code, a.name as attr_name, a.is_combined_attribute,
               ao.code as opt_code, ao.value as opt_value, ao.display_label, ao.dimension_modifiers
        FROM so_line_selections s
        JOIN attributes a ON s.attribute_id = a.id
        JOIN attribute_options ao ON s.option_id = ao.id
        JOIN so_line_items sli2 ON s.so_line_item_id = sli2.id
        WHERE sli2.combined_group_id = ?
          AND sli2.line_number = (SELECT MIN(line_number) FROM so_line_items WHERE combined_group_id = ?)
        ORDER BY a.position_in_model
    ");
    $sels->execute([$doc['combined_group_id'], $doc['combined_group_id']]);
} else {
    $sels = $db->prepare("
        SELECT a.code as attr_code, a.name as attr_name, a.is_combined_attribute,
               ao.code as opt_code, ao.value as opt_value, ao.display_label, ao.dimension_modifiers
        FROM so_line_selections s
        JOIN attributes a ON s.attribute_id = a.id
        JOIN attribute_options ao ON s.option_id = ao.id
        WHERE s.so_line_item_id = ?
        ORDER BY a.position_in_model
    ");
    $sels->execute([$doc['so_line_item_id']]);
}
$selections = $sels->fetchAll();

// Pivot data for the combined view.
$pivotLines = [];
if ($is_combined && !empty($doc['pivot_values'])) {
    $decoded = json_decode($doc['pivot_values'], true);
    if (is_array($decoded)) $pivotLines = $decoded;
}

// Handle approval / rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    if ($a === 'approve') {
        $db->prepare("UPDATE generated_documents SET status = 'approved', approved_at = GETDATE() WHERE id = ?")->execute([$doc_id]);
        setFlash('success', 'Document approved.');
        header("Location: drawing_preview.php?doc_id=$doc_id");
        exit;
    }
    if ($a === 'reject') {
        $db->prepare("UPDATE generated_documents SET status = 'rejected' WHERE id = ?")->execute([$doc_id]);
        setFlash('warning', 'Document rejected. Re-run "Generate Documents" on the SO to replace it.');
        header("Location: drawing_preview.php?doc_id=$doc_id");
        exit;
    }
}

// Display fields with sensible fallbacks for combined docs.
$displayModelCode  = $doc['model_code_string'] ?? ($pivotLines
    ? implode(', ', array_map(fn($l) => $l['model_code'] ?? '', $pivotLines))
    : '(combined)');
$displayLineLabel  = $doc['line_number'] ?? 'COMB';
$backSoId          = $doc['sales_order_id'] ?? '';
?>

<h2>Drawing Preview <span class="badge" style="background:var(--<?= $is_combined ? 'orange' : 'accent' ?>-glow ?? var(--accent-glow));color:var(--<?= $is_combined ? 'orange' : 'accent' ?>);"><?= strtoupper($type_label) ?></span></h2>
<div class="gap-row mb-2">
    <a href="sales_order_detail.php?id=<?= sanitize($backSoId) ?>" class="btn btn-outline btn-sm">← Back</a>
    <span class="tag tag-blue"><?= sanitize($doc['so_number'] ?? '—') ?></span>
    <span class="tag tag-purple">Line <?= sanitize((string)$displayLineLabel) ?></span>
    <span class="tag tag-cyan mono"><?= sanitize($displayModelCode) ?></span>
    <span class="tag tag-<?= $is_combined ? 'orange' : 'green' ?>"><?= $is_combined ? 'Combined' : 'Individual' ?></span>
</div>

<?php if ($is_combined && $pivotLines): ?>
<div class="panel">
    <div class="panel-header"><span class="panel-title">Lines in this Combined Drawing</span></div>
    <div class="panel-body" style="padding:0;">
        <table class="data-table">
            <thead><tr><th>Line</th><th>Model Code</th><th>Qty</th><th>Combined-Attribute Value(s)</th></tr></thead>
            <tbody>
                <?php foreach ($pivotLines as $pl): ?>
                <tr>
                    <td><?= sanitize((string)($pl['line_number'] ?? '')) ?></td>
                    <td class="mono"><?= sanitize($pl['model_code'] ?? '') ?></td>
                    <td><?= sanitize((string)($pl['quantity'] ?? '')) ?></td>
                    <td><?php
                        $opts = $pl['options'] ?? [];
                        $bits = [];
                        foreach ($opts as $o) {
                            $bits[] = sanitize(($o['attr_code'] ?? '') . '=' . ($o['opt_code'] ?? '') . ' (' . ($o['value'] ?? '') . ')');
                        }
                        echo implode(', ', $bits) ?: '—';
                    ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- MODEL CODE BREAKDOWN -->
<div class="panel">
    <div class="panel-header">
        <span class="panel-title">Model Code Breakdown<?= $is_combined ? ' (common attributes)' : '' ?></span>
    </div>
    <div class="panel-body">
        <div class="model-code-display"><?= sanitize($displayModelCode) ?></div>
        <table class="data-table">
            <thead><tr><th>Attribute</th><th>Code</th><th>Description</th></tr></thead>
            <tbody>
                <tr><td>Product</td><td class="mono text-accent"><?= sanitize($doc['product_code'] ?? '') ?></td><td><?= sanitize($doc['product_name'] ?? '') ?></td></tr>
                <?php foreach ($selections as $s): ?>
                <tr<?= $s['is_combined_attribute'] && $is_combined ? ' style="background:var(--orange-bg);"' : '' ?>>
                    <td><?= sanitize($s['attr_name']) ?></td>
                    <td class="mono <?= $s['is_combined_attribute'] && $is_combined ? 'text-orange' : 'text-accent' ?>">
                        <?= $s['is_combined_attribute'] && $is_combined ? '*' : sanitize($s['opt_code']) ?>
                    </td>
                    <td<?= $s['is_combined_attribute'] && $is_combined ? ' style="color:var(--orange);font-style:italic;"' : '' ?>>
                        <?= $s['is_combined_attribute'] && $is_combined ? 'Refer to dimension / lines table above' : sanitize($s['display_label'] ?: $s['opt_value']) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- DRAWING PREVIEW -->
<div class="panel">
    <div class="panel-header">
        <span class="panel-title">Data Preview — Schematic (not your DWG)</span>
        <span class="tag tag-<?= $doc['status'] === 'approved' ? 'green' : 'blue' ?>"><?= ucfirst($doc['status']) ?></span>
    </div>
    <div class="panel-body">
        <div class="callout callout-info" style="margin-bottom:1rem;font-size:0.85rem;">
            <strong>Heads up:</strong> the diagram below is a <em>schematic</em> EDMS draws from the order data — it is <strong>NOT</strong> a render of your uploaded DWG template.
            The actual generated file <em>does</em> use your template — every <code>&lt;&lt;TAG&gt;&gt;</code> in it gets substituted with the values shown above.
            To see the real drawing, click <strong>Download DWG/DXF</strong> at the bottom of this page and open it in DraftSight.
        </div>
        <?php
        // Build an SVG schematic of the produced drawing using the same
        // data that gets injected into the DWG. This is not a pixel-perfect
        // render of the template — it's a faithful preview of the data
        // overlay (title block, model-code table, dimension table, stamp)
        // exactly as it will appear on the generated file.
        $svgW = 1100; $svgH = 620;
        $stroke = '#3a4a66'; $fg = '#cfd8e8'; $accent = '#4ea1ff'; $green = '#3fd17a'; $bg = '#0f1726';
        ?>
        <div style="width:100%;background:<?= $bg ?>;border:1px solid var(--border);border-radius:6px;overflow:hidden;">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 <?= $svgW ?> <?= $svgH ?>" style="display:block;width:100%;height:auto;">
            <!-- sheet -->
            <rect x="10" y="10" width="<?= $svgW-20 ?>" height="<?= $svgH-20 ?>" fill="none" stroke="<?= $stroke ?>" stroke-width="2"/>
            <rect x="20" y="20" width="<?= $svgW-40 ?>" height="<?= $svgH-40 ?>" fill="none" stroke="<?= $stroke ?>" stroke-width="1"/>

            <!-- model code table -->
            <g font-family="Consolas, monospace" font-size="13" fill="<?= $fg ?>">
                <text x="40" y="50" font-size="14" fill="<?= $accent ?>">MODEL CODE TABLE  (anchor: &lt;&lt;MODEL_CODE_TABLE&gt;&gt;)</text>
                <?php
                $headers = ['Pos','Attribute','Code','Value'];
                $colX = [40, 100, 280, 360];
                $rowY = 80; $rowH = 22;
                foreach ($headers as $i => $h):
                ?>
                <text x="<?= $colX[$i] ?>" y="<?= $rowY ?>" font-weight="bold" fill="<?= $accent ?>"><?= sanitize($h) ?></text>
                <?php endforeach; ?>
                <line x1="40" y1="<?= $rowY+5 ?>" x2="540" y2="<?= $rowY+5 ?>" stroke="<?= $stroke ?>"/>
                <?php
                $r = 0;
                foreach ($selections as $s):
                    $y = $rowY + $rowH * ($r + 1);
                    $cells = [
                        (string)($s['attr_code'] ? '' : ''), // pos comes from position_in_model when available
                        sanitize($s['attr_name']),
                        sanitize($s['opt_code']),
                        sanitize($s['display_label'] ?: $s['opt_value'])
                    ];
                    // We don't have position_in_model in this query; render row index.
                    $cells[0] = (string)($r + 1);
                ?>
                <text x="<?= $colX[0] ?>" y="<?= $y ?>"><?= $cells[0] ?></text>
                <text x="<?= $colX[1] ?>" y="<?= $y ?>"><?= $cells[1] ?></text>
                <text x="<?= $colX[2] ?>" y="<?= $y ?>" fill="<?= $accent ?>"><?= $cells[2] ?></text>
                <text x="<?= $colX[3] ?>" y="<?= $y ?>"><?= $cells[3] ?></text>
                <?php $r++; endforeach; ?>
            </g>

            <!-- dim table -->
            <g font-family="Consolas, monospace" font-size="13" fill="<?= $fg ?>">
                <text x="600" y="50" font-size="14" fill="<?= $accent ?>">DIMENSION TABLE  (anchor: &lt;&lt;DIM_TABLE&gt;&gt;)</text>
                <text x="600" y="80" font-weight="bold" fill="<?= $accent ?>">Field</text>
                <text x="800" y="80" font-weight="bold" fill="<?= $accent ?>">Value</text>
                <line x1="600" y1="85" x2="1060" y2="85" stroke="<?= $stroke ?>"/>
                <?php
                $dimRows = [
                    ['Order No.',    $doc['so_number']],
                    ['Customer',     $doc['customer_name']],
                    ['Project',      $doc['project_name'] ?? ''],
                    ['Quantity',     (string)$doc['quantity']],
                ];
                foreach ($selections as $s) {
                    if ($s['dimension_modifiers']) {
                        $dims = json_decode($s['dimension_modifiers'], true) ?? [];
                        foreach ($dims as $k => $v) $dimRows[] = [$s['attr_name'].' → '.$k, $v.' mm'];
                    }
                }
                if ($doc['sap_item_code']) $dimRows[] = ['SAP Part No.', $doc['sap_item_code']];
                $rr = 0; foreach ($dimRows as $dr):
                    $y = 80 + 22 * ($rr + 1);
                ?>
                <text x="600" y="<?= $y ?>"><?= sanitize($dr[0]) ?></text>
                <text x="800" y="<?= $y ?>" fill="<?= $green ?>"><?= sanitize((string)$dr[1]) ?></text>
                <?php $rr++; endforeach; ?>
            </g>

            <!-- title block (bottom right) -->
            <?php $tbX = $svgW - 460; $tbY = $svgH - 150; ?>
            <g>
                <rect x="<?= $tbX ?>" y="<?= $tbY ?>" width="440" height="130" fill="none" stroke="<?= $accent ?>" stroke-width="1.5"/>
                <line x1="<?= $tbX ?>" y1="<?= $tbY+22 ?>" x2="<?= $tbX+440 ?>" y2="<?= $tbY+22 ?>" stroke="<?= $accent ?>"/>
                <text x="<?= $tbX+8 ?>" y="<?= $tbY+16 ?>" font-family="Consolas, monospace" font-size="13" fill="<?= $accent ?>">EDMS GENERATED · TITLE BLOCK</text>
                <g font-family="Consolas, monospace" font-size="12" fill="<?= $fg ?>">
                    <?php
                    $tb = [
                        ['SO NO',      $doc['so_number']],
                        ['CUSTOMER',   $doc['customer_name']],
                        ['PROJECT',    $doc['project_name'] ?? ''],
                        ['PO REF',     $doc['po_reference'] ?? ''],
                        ['MODEL CODE', $doc['model_code_string']],
                        ['PRODUCT',    $doc['product_code'].' — '.$doc['product_name']],
                        ['QTY',        (string)$doc['quantity']],
                        ['DATE',       date('d-m-Y')],
                    ];
                    foreach ($tb as $i => $row): $y = $tbY + 38 + $i * 12; ?>
                    <text x="<?= $tbX+8 ?>" y="<?= $y ?>"><?= sanitize($row[0]) ?></text>
                    <text x="<?= $tbX+110 ?>" y="<?= $y ?>" fill="<?= $green ?>">: <?= sanitize((string)$row[1]) ?></text>
                    <?php endforeach; ?>
                </g>
            </g>

            <!-- footer caption -->
            <text x="40" y="<?= $svgH-25 ?>" font-family="Consolas, monospace" font-size="11" fill="#6b7a90">
                Template: <?= sanitize($doc['template_code'] ?? 'N/A') ?>
                · Schematic preview of injected data — open downloaded DWG/DXF in DraftSight for the full drawing.
            </text>
        </svg>
        </div>
    </div>
</div>

<!-- ACTUAL FILE CONTENT PREVIEW -->
<?php
// Show what's REALLY inside the generated file so the user can see their
// template's content with substituted values — NOT a schematic.
$realPath = $doc['output_file_path'] ?? '';
$realText = '';
$realSize = 0;
$realExt = '';
if ($realPath && is_file($realPath)) {
    $realSize = filesize($realPath);
    $realExt = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
    if ($realExt === 'dxf' && $realSize < 5 * 1024 * 1024) {
        $raw = file_get_contents($realPath);
        // Extract every TEXT entity's string (DXF group code 1 after a TEXT entity).
        preg_match_all('/^\s*0\s*\nTEXT\s*$.*?^\s*1\s*\n([^\n]*)\n/sm', $raw, $matches);
        $realText = $matches[1] ?? [];
    }
}
?>
<div class="panel">
    <div class="panel-header">
        <span class="panel-title">Actual Generated File</span>
        <span class="tag tag-<?= $realPath && is_file($realPath) ? 'green' : 'red' ?>">
            <?= $realPath && is_file($realPath) ? strtoupper($realExt) . ' · ' . number_format($realSize) . ' bytes' : 'FILE MISSING' ?>
        </span>
    </div>
    <div class="panel-body">
        <?php if ($realPath && is_file($realPath)): ?>
        <div class="callout callout-success" style="margin-bottom:0.75rem;font-size:0.85rem;">
            <strong>This is what got written to disk</strong> — your uploaded template was copied, every <code>&lt;&lt;TAG&gt;&gt;</code> in it was substituted, and the model-code and dimension tables were drawn at their anchors. Below: every text string the engine put into the file.
        </div>
        <div style="font-family:'Consolas',monospace;font-size:0.8rem;background:var(--bg-2,#0f1726);border:1px solid var(--border);border-radius:4px;padding:0.5rem 0.75rem;max-height:300px;overflow:auto;">
            <?php if (is_array($realText) && !empty($realText)): ?>
                <?php foreach ($realText as $i => $t):
                    if (trim($t) === '') continue;
                    $isPlaceholder = (strpos($t, '<<') !== false);
                ?>
                <div style="color:<?= $isPlaceholder ? '#ff6b6b' : '#cfd8e8' ?>;">
                    <span style="color:#6b7a90;"><?= str_pad((string)($i+1), 3, ' ', STR_PAD_LEFT) ?>:</span>
                    <?= sanitize($t) ?>
                    <?= $isPlaceholder ? ' <span style="color:#ff6b6b;font-size:0.7rem;">← UNSUBSTITUTED PLACEHOLDER</span>' : '' ?>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <em style="color:#6b7a90;">No TEXT entities found (DWG round-trip strips text strings — preview only works on .dxf files).</em>
            <?php endif; ?>
        </div>
        <div style="font-size:0.75rem;color:var(--text-dim);margin-top:0.5rem;">
            Path: <code><?= sanitize($realPath) ?></code>
            <?php $hasUnsub = is_array($realText) && array_filter($realText, fn($t) => strpos($t, '<<') !== false);
            if ($hasUnsub): ?>
                <br><strong style="color:#ff6b6b;">⚠ Unsubstituted placeholders detected.</strong> Add a row in <a href="template_fields.php?template_id=<?= sanitize($doc['template_id'] ?? '') ?>">Template Field Mappings</a> for each one, or use one of the hardcoded tags listed there.
            <?php endif; ?>
        </div>
        <?php else: ?>
        <em>No output file on disk. Re-run "Generate Documents" on the sales order.</em>
        <?php endif; ?>
    </div>
</div>

<!-- DIMENSION TABLE -->
<div class="panel">
    <div class="panel-header">
        <span class="panel-title">Dimension Table (inserted at DIM_TABLE_ANCHOR)</span>
    </div>
    <div class="panel-body" style="padding:0;">
        <table class="data-table">
            <thead><tr><th>Field</th><th>Source</th><th>Value</th></tr></thead>
            <tbody>
                <tr><td>Order No.</td><td>SO Header</td><td class="mono"><?= sanitize($doc['so_number']) ?></td></tr>
                <tr><td>Customer</td><td>SO Header</td><td><?= sanitize($doc['customer_name']) ?></td></tr>
                <tr><td>Quantity</td><td>SO Line</td><td class="mono"><?= $doc['quantity'] ?></td></tr>
                <?php foreach ($selections as $s): ?>
                <?php if ($s['dimension_modifiers']): ?>
                <?php $dims = json_decode($s['dimension_modifiers'], true) ?? []; ?>
                <?php foreach ($dims as $key => $val): ?>
                <tr><td><?= sanitize($s['attr_name']) ?> → <?= sanitize($key) ?></td><td>Dimension Modifier</td><td class="mono"><?= sanitize($val) ?> mm</td></tr>
                <?php endforeach; ?>
                <?php endif; ?>
                <?php endforeach; ?>
                <?php if ($doc['sap_item_code']): ?>
                <tr><td>SAP Part No.</td><td>Combo Map</td><td class="mono text-green"><?= sanitize($doc['sap_item_code']) ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ACTIONS -->
<div class="btn-group mt-2">
    <?php if ($doc['status'] !== 'approved' && $doc['status'] !== 'rejected'): ?>
    <form method="POST" style="display:inline"><input type="hidden" name="action" value="approve">
        <button type="submit" class="btn btn-primary">✓ Approve</button>
    </form>
    <form method="POST" style="display:inline" onsubmit="return confirm('Reject this drawing? It will be marked rejected and you should re-generate from the SO.');">
        <input type="hidden" name="action" value="reject">
        <button type="submit" class="btn btn-outline" style="color:var(--red);border-color:var(--red);">✗ Reject</button>
    </form>
    <?php endif; ?>
    <a href="<?= BASE_URL ?>/api/download.php?doc_id=<?= $doc_id ?>&format=dwg" class="btn btn-primary">Download DWG</a>
    <a href="<?= BASE_URL ?>/api/download.php?doc_id=<?= $doc_id ?>&format=dxf" class="btn btn-outline">Download DXF</a>
    <!-- PDF export not implemented; the download endpoint only emits DWG or DXF. -->
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
