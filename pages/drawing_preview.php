<?php
$pageTitle = 'Drawing Preview';
require_once __DIR__ . '/../includes/header.php';

$db = getDB();
$doc_id = $_GET['doc_id'] ?? '';

if (!$doc_id) { header('Location: sales_orders.php'); exit; }

$stmt = $db->prepare("
    SELECT gd.*, sli.line_number, sli.model_code_string, sli.quantity, sli.generate_individual, sli.generate_combined,
           so.so_number, so.customer_name, so.project_name, so.po_reference,
           t.template_code, t.file_path as template_file,
           pc.code as product_code, pc.name as product_name
    FROM generated_documents gd
    JOIN so_line_items sli ON gd.so_line_item_id = sli.id
    JOIN sales_orders so ON sli.sales_order_id = so.id
    LEFT JOIN templates t ON gd.template_id = t.id
    JOIN product_codes pc ON sli.product_code_id = pc.id
    WHERE gd.id = ?
");
$stmt->execute([$doc_id]);
$doc = $stmt->fetch();
if (!$doc) { setFlash('error', 'Document not found.'); header('Location: sales_orders.php'); exit; }

// Get selections
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
$selections = $sels->fetchAll();

// Handle approval
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'approve') {
    $db->prepare("UPDATE generated_documents SET status = 'approved', approved_at = GETDATE() WHERE id = ?")->execute([$doc_id]);
    setFlash('success', 'Document approved.');
    header("Location: drawing_preview.php?doc_id=$doc_id");
    exit;
}

$is_combined = str_contains($doc['document_type'], 'combined');
$is_internal = str_contains($doc['document_type'], 'internal');
$type_label = str_replace('_', ' ', ucwords($doc['document_type'], '_'));
?>

<h2>Drawing Preview <span class="badge" style="background:var(--<?= $is_combined ? 'orange' : 'accent' ?>-glow ?? var(--accent-glow));color:var(--<?= $is_combined ? 'orange' : 'accent' ?>);"><?= strtoupper($type_label) ?></span></h2>
<div class="gap-row mb-2">
    <a href="sales_order_detail.php?id=<?= $db->prepare("SELECT sales_order_id FROM so_line_items WHERE id = ?")->execute([$doc['so_line_item_id']]) ? '' : '' ?>" class="btn btn-outline btn-sm">← Back</a>
    <span class="tag tag-blue"><?= sanitize($doc['so_number']) ?></span>
    <span class="tag tag-purple">Line <?= $doc['line_number'] ?></span>
    <span class="tag tag-cyan mono"><?= sanitize($doc['model_code_string']) ?></span>
    <span class="tag tag-<?= $is_combined ? 'orange' : 'green' ?>"><?= $is_combined ? 'Combined' : 'Individual' ?></span>
</div>

<!-- MODEL CODE BREAKDOWN -->
<div class="panel">
    <div class="panel-header">
        <span class="panel-title">Model Code Breakdown</span>
    </div>
    <div class="panel-body">
        <div class="model-code-display"><?= sanitize($doc['model_code_string']) ?></div>
        <table class="data-table">
            <thead><tr><th>Attribute</th><th>Code</th><th>Description</th></tr></thead>
            <tbody>
                <tr><td>Product</td><td class="mono text-accent"><?= sanitize($doc['product_code']) ?></td><td><?= sanitize($doc['product_name']) ?></td></tr>
                <?php foreach ($selections as $s): ?>
                <tr<?= $s['is_combined_attribute'] && $is_combined ? ' style="background:var(--orange-bg);"' : '' ?>>
                    <td><?= sanitize($s['attr_name']) ?></td>
                    <td class="mono <?= $s['is_combined_attribute'] && $is_combined ? 'text-orange' : 'text-accent' ?>">
                        <?= $s['is_combined_attribute'] && $is_combined ? '*' : sanitize($s['opt_code']) ?>
                    </td>
                    <td<?= $s['is_combined_attribute'] && $is_combined ? ' style="color:var(--orange);font-style:italic;"' : '' ?>>
                        <?= $s['is_combined_attribute'] && $is_combined ? 'Refer to dimension table' : sanitize($s['display_label'] ?: $s['opt_value']) ?>
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
        <span class="panel-title"><?= $type_label ?> Drawing</span>
        <span class="tag tag-<?= $doc['status'] === 'approved' ? 'green' : 'blue' ?>"><?= ucfirst($doc['status']) ?></span>
    </div>
    <div class="panel-body">
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
    <?php if ($doc['status'] !== 'approved'): ?>
    <form method="POST"><input type="hidden" name="action" value="approve">
        <button type="submit" class="btn btn-primary">✓ Approve</button>
    </form>
    <?php endif; ?>
    <a href="<?= BASE_URL ?>/api/download.php?doc_id=<?= $doc_id ?>&format=dwg" class="btn btn-primary">Download DWG Drawing</a>
    <a href="<?= BASE_URL ?>/api/download.php?doc_id=<?= $doc_id ?>&format=dxf" class="btn btn-outline">Download DXF</a>
    <a href="<?= BASE_URL ?>/api/download.php?doc_id=<?= $doc_id ?>&format=pdf" class="btn btn-outline">Export PDF</a>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
