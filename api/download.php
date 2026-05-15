<?php
/**
 * EDMS - Download Generated Document
 * Creates a NEW DXF drawing file with actual injected data from the sales order
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/DXFGenerator.php';
require_once __DIR__ . '/../includes/DWGTemplateProcessor.php';

$doc_id = $_GET['doc_id'] ?? '';
// Default to dwg when a real template is mapped; falls back to dxf.
$format = strtolower($_GET['format'] ?? 'dwg');

if (!$doc_id) {
    http_response_code(400);
    die('Missing doc_id');
}

$db = getDB();

// Get document + order + product details.
// Combined documents have so_line_item_id = NULL; LEFT JOIN both sides so this
// endpoint works for individual AND combined drawings.
$stmt = $db->prepare("
    SELECT gd.*, t.file_path as template_file, t.template_code, t.file_format,
           sli.model_code_string, sli.line_number, sli.quantity,
           COALESCE(so_indiv.so_number, so_comb.so_number)         AS so_number,
           COALESCE(so_indiv.customer_name, so_comb.customer_name) AS customer_name,
           COALESCE(so_indiv.project_name, so_comb.project_name)   AS project_name,
           COALESCE(so_indiv.po_reference, so_comb.po_reference)   AS po_reference,
           COALESCE(so_indiv.delivery_date, so_comb.delivery_date) AS delivery_date,
           COALESCE(pc_indiv.code, pc_comb.code) AS product_code,
           COALESCE(pc_indiv.name, pc_comb.name) AS product_name
    FROM generated_documents gd
    LEFT JOIN templates t ON gd.template_id = t.id
    LEFT JOIN so_line_items sli ON gd.so_line_item_id = sli.id
    LEFT JOIN sales_orders  so_indiv ON sli.sales_order_id = so_indiv.id
    LEFT JOIN product_codes pc_indiv ON sli.product_code_id = pc_indiv.id
    LEFT JOIN combined_drawing_groups cdg ON gd.combined_group_id = cdg.id
    LEFT JOIN sales_orders  so_comb  ON cdg.sales_order_id = so_comb.id
    LEFT JOIN product_codes pc_comb  ON cdg.product_code_id = pc_comb.id
    WHERE gd.id = ?
");
$stmt->execute([$doc_id]);
$doc = $stmt->fetch();

if (!$doc) {
    http_response_code(404);
    die('Document not found');
}

// Combined docs: the file was produced once at generation time and lives at
// output_file_path. We do NOT regenerate combined drawings on download because
// that would require re-grouping the lines and is owned by the page handler.
// Just stream the existing file.
$isCombinedDoc = empty($doc['so_line_item_id']) && !empty($doc['combined_group_id']);
if ($isCombinedDoc) {
    $existing = $doc['output_file_path'] ?? '';
    if (!$existing || !is_file($existing)) {
        http_response_code(404);
        die('Combined drawing file missing on disk. Re-run "Generate Documents" on the sales order.');
    }
    $extOut = strtolower(pathinfo($existing, PATHINFO_EXTENSION));
    $mime   = $extOut === 'dwg' ? 'application/acad' : 'application/dxf';
    $bytes  = file_get_contents($existing);
    $base   = preg_replace('/[^A-Za-z0-9_\-]/', '_',
        ($doc['so_number'] ?? 'SO') . '_COMBINED_' . ($doc['product_code'] ?? 'PROD') . '_' . strtoupper($doc['document_type']));
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $base . '.' . $extOut . '"');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: no-cache, must-revalidate');
    echo $bytes;
    exit;
}

// From here on we KNOW we have a line-item-scoped (individual) document.

// Get all attribute selections for this line item
$selections = $db->prepare("
    SELECT a.code as attr_code, a.name as attr_name, a.position_in_model,
           a.is_combined_attribute,
           ao.code as opt_code, ao.value as opt_value, ao.display_label, 
           ao.dimension_modifiers
    FROM so_line_selections s
    JOIN attributes a ON s.attribute_id = a.id
    JOIN attribute_options ao ON s.option_id = ao.id
    WHERE s.so_line_item_id = ?
    ORDER BY a.position_in_model
");
$selections->execute([$doc['so_line_item_id']]);
$sels = $selections->fetchAll();

// Get dimension table columns config
$dimCols = $db->prepare("SELECT * FROM dim_table_columns WHERE product_code_id = (SELECT product_code_id FROM so_line_items WHERE id = ?) ORDER BY sort_order");
$dimCols->execute([$doc['so_line_item_id']]);
$dimColumns = $dimCols->fetchAll();

// Get anchor config (X/Y where to drop tables when the template has
// no <<MODEL_CODE_TABLE>>/<<DIM_TABLE>> placeholder TEXT entities).
$anchorStmt = $db->prepare("
    SELECT anchor_type, x_coord, y_coord
    FROM template_anchor_config
    WHERE product_code_id = (SELECT product_code_id FROM so_line_items WHERE id = ?)
");
$anchorStmt->execute([$doc['so_line_item_id']]);
$anchorConfig = [];
foreach ($anchorStmt->fetchAll() as $a) {
    $anchorConfig[$a['anchor_type']] = ['x' => (float)$a['x_coord'], 'y' => (float)$a['y_coord']];
}

// Build data array for generator
$orderData = [
    'so_number' => $doc['so_number'],
    'customer_name' => $doc['customer_name'],
    'project_name' => $doc['project_name'],
    'po_reference' => $doc['po_reference'],
    'delivery_date' => $doc['delivery_date'],
    'model_code' => $doc['model_code_string'],
    'quantity' => $doc['quantity'],
    'line_number' => $doc['line_number'],
    'product_code' => $doc['product_code'],
    'product_name' => $doc['product_name'],
    'document_type' => $doc['document_type'],
    'sap_item_code' => $doc['sap_item_code'],
    'template_code' => $doc['template_code'],
];

// Build filename
$type_short = str_replace(
    ['engg_individual', 'engg_combined', 'internal_individual', 'internal_combined', 'test_cert'], 
    ['ENGG', 'ENGG-COMB', 'INT', 'INT-COMB', 'CERT'], 
    $doc['document_type']
);
$download_name = "{$doc['so_number']}_Line{$doc['line_number']}_{$doc['product_code']}_{$type_short}";
$download_name = preg_replace('/[^A-Za-z0-9_\-]/', '_', $download_name);

// Output dir per SO + line (use safeSoFolder so the same SO doesn't end up
// split across hyphen/underscore-named folders).
$outputDir = OUTPUT_PATH . safeSoFolder($doc['so_number']) . '/line' . (int)$doc['line_number'] . '/';
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

// If "Generate Documents" already produced a real file, just stream it.
// This guarantees the downloaded file is exactly what was generated and
// avoids regenerating on every click.
$existing = $doc['output_file_path'] ?? '';
if ($existing && is_file($existing) && filesize($existing) > 0) {
    $extOut = strtolower(pathinfo($existing, PATHINFO_EXTENSION));
    // Honor explicit ?format= override only if the formats differ AND
    // the requested format is supported by the processor.
    if ($format !== $extOut && in_array($format, ['dwg','dxf'], true)) {
        // Fall through to regenerate at the requested format below.
    } else {
        $mime  = $extOut === 'dwg' ? 'application/acad' : 'application/dxf';
        $bytes = file_get_contents($existing);
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $download_name . '.' . $extOut . '"');
        header('Content-Length: ' . strlen($bytes));
        header('Cache-Control: no-cache, must-revalidate');
        echo $bytes;
        exit;
    }
}

// Decide engine: template-based (DWG/DXF) vs. from-scratch fallback.
$templateFile = $doc['template_file'] ?? null;
// Stored file_path is relative to uploads/ — resolve it to absolute.
if ($templateFile && !is_file($templateFile)) {
    $candidate = UPLOAD_PATH . ltrim($templateFile, '/\\');
    if (is_file($candidate)) {
        $templateFile = $candidate;
    }
}
$templateExt  = $templateFile ? strtolower(pathinfo($templateFile, PATHINFO_EXTENSION)) : '';
$useTemplate  = $templateFile
    && is_file($templateFile)
    && in_array($templateExt, ['dwg','dxf'], true);

if ($useTemplate) {
    try {
        $proc = new DWGTemplateProcessor();
        // For DWG output we need ODA available. If not, force DXF.
        if ($format === 'dwg' && !$proc->isAvailable()) {
            $format = 'dxf';
        }
        $producedPath = $proc->process(
            $templateFile,
            $orderData,
            $sels,
            $dimColumns,
            $outputDir,
            $download_name,
            $format,
            $anchorConfig
        );
        $outFmt = strtolower(pathinfo($producedPath, PATHINFO_EXTENSION));
        $mime   = $outFmt === 'dwg' ? 'application/acad' : 'application/dxf';
        $bytes  = file_get_contents($producedPath);

        $db->prepare("UPDATE generated_documents SET output_file_path = ? WHERE id = ?")
           ->execute([$producedPath, $doc_id]);

        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $download_name . '.' . $outFmt . '"');
        header('Content-Length: ' . strlen($bytes));
        header('Cache-Control: no-cache, must-revalidate');
        echo $bytes;
        exit;
    } catch (Throwable $e) {
        // Fall through to from-scratch DXF generator.
        error_log('[EDMS] Template processor failed: ' . $e->getMessage());
    }
}

// Fallback: generate from scratch (no template, or template processing failed).
$generator = new DXFGenerator();
$dxfContent = $generator->generate($orderData, $sels, $dimColumns);
$outputFile = $outputDir . $download_name . '.dxf';
file_put_contents($outputFile, $dxfContent);

$db->prepare("UPDATE generated_documents SET output_file_path = ? WHERE id = ?")
   ->execute([$outputFile, $doc_id]);

header('Content-Type: application/dxf');
header('Content-Disposition: attachment; filename="' . $download_name . '.dxf"');
header('Content-Length: ' . strlen($dxfContent));
header('Cache-Control: no-cache, must-revalidate');
echo $dxfContent;
exit;
