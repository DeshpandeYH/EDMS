<?php
/**
 * EDMS — Complete System Flow Test
 * Runs the entire workflow end-to-end and generates a real DXF drawing
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/DXFGenerator.php';

$db = getDB();

echo "============================================================\n";
echo "  EDMS — COMPLETE SYSTEM FLOW TEST\n";
echo "============================================================\n\n";

// ─── STEP 1: Verify Product 136ULT exists ───
echo "STEP 1: Verify Product Code\n";
echo str_repeat("-", 50) . "\n";

$product = $db->query("SELECT * FROM product_codes WHERE code = '136ULT' AND status != 'archived'")->fetch();
if (!$product) {
    die("ERROR: Product 136ULT not found. Run seed_data.php first.\n");
}
echo "  Product: {$product['code']} — {$product['name']}\n";
echo "  Status: {$product['status']}\n\n";

// ─── STEP 2: Show attributes & options ───
echo "STEP 2: Attributes & Options\n";
echo str_repeat("-", 50) . "\n";

$attrs = $db->prepare("SELECT * FROM attributes WHERE product_code_id = ? AND is_active = 1 ORDER BY position_in_model");
$attrs->execute([$product['id']]);
$attributes = $attrs->fetchAll();

$attr_first_options = []; // Store first option of each attr for auto-selection
foreach ($attributes as $attr) {
    $opts = $db->prepare("SELECT * FROM attribute_options WHERE attribute_id = ? AND is_active = 1 ORDER BY sort_order");
    $opts->execute([$attr['id']]);
    $options = $opts->fetchAll();
    $attr_first_options[$attr['id']] = $options[0] ?? null;
    
    $optCodes = implode(', ', array_map(fn($o) => $o['code'], $options));
    echo "  {$attr['code']} — {$attr['name']} (pos {$attr['position_in_model']}): [{$optCodes}]\n";
}
echo "  Total: " . count($attributes) . " attributes\n\n";

// ─── STEP 3: Create Sales Order ───
echo "STEP 3: Create Sales Order\n";
echo str_repeat("-", 50) . "\n";

$so_id = generateUUID();
$so_number = 'SO-2026-TEST-001';

// Delete if exists (re-run safe)
$existing = $db->prepare("SELECT id FROM sales_orders WHERE so_number = ?");
$existing->execute([$so_number]);
$old = $existing->fetch();
if ($old) {
    // Clean up old data
    $db->prepare("DELETE FROM generated_documents WHERE so_line_item_id IN (SELECT id FROM so_line_items WHERE sales_order_id = ?)")->execute([$old['id']]);
    $db->prepare("DELETE FROM so_line_selections WHERE so_line_item_id IN (SELECT id FROM so_line_items WHERE sales_order_id = ?)")->execute([$old['id']]);
    $db->prepare("DELETE FROM so_line_items WHERE sales_order_id = ?")->execute([$old['id']]);
    $db->prepare("DELETE FROM sales_orders WHERE id = ?")->execute([$old['id']]);
    echo "  (Cleaned up previous test order)\n";
}

$db->prepare("INSERT INTO sales_orders (id, so_number, customer_name, project_name, po_reference, delivery_date, status) VALUES (?, ?, ?, ?, ?, ?, 'draft')")
   ->execute([$so_id, $so_number, 'Tata Projects Ltd', 'Jamnagar Refinery Expansion', 'PO-TATA-2026-4401', '2026-06-30']);

echo "  SO Number:    $so_number\n";
echo "  Customer:     Tata Projects Ltd\n";
echo "  Project:      Jamnagar Refinery Expansion\n";
echo "  PO Reference: PO-TATA-2026-4401\n";
echo "  Delivery:     2026-06-30\n\n";

// ─── STEP 4: Add Line Items ───
echo "STEP 4: Add Line Items\n";
echo str_repeat("-", 50) . "\n";

// Line 1: 136ULT with specific selections
$line1_id = generateUUID();
$db->prepare("INSERT INTO so_line_items (id, sales_order_id, product_code_id, line_number, quantity, generate_individual, generate_combined, status) VALUES (?, ?, ?, 1, 5, 1, 0, 'incomplete')")
   ->execute([$line1_id, $so_id, $product['id']]);

// Select options: A (4-20mA), T (1/2" NPT), 01 (PP), 08 (8m), 1 (Slip on Flanged), 31 (3" ANSI), 1 (PP Flange), 01 (24VDC)
$line1_selections = [
    // [attr_code, option_code_to_pick]
    ['SO', 'A'],   // Single Output = 2 Wire (4-20mA)
    ['CE', 'T'],   // Cable Entry = ½" NPT
    ['SM', '01'],  // Sensor Material = PP
    ['MR', '08'],  // Measuring Range = 08 m
    ['PC', '1'],   // Process Connection = Slip on Flanged
    ['FS', '31'],  // Flange Size = 3" ANSI 150#
    ['MF', '1'],   // MOC Flange = Polypropylene (PP)
    ['PS', '01'],  // Power Supply = 24VDC
];

$model_parts = [$product['code']];
foreach ($line1_selections as $sel) {
    $attr = $db->prepare("SELECT id FROM attributes WHERE product_code_id = ? AND code = ? AND is_active = 1");
    $attr->execute([$product['id'], $sel[0]]);
    $attr_row = $attr->fetch();
    
    $opt = $db->prepare("SELECT id, code, value, display_label FROM attribute_options WHERE attribute_id = ? AND code = ? AND is_active = 1");
    $opt->execute([$attr_row['id'], $sel[1]]);
    $opt_row = $opt->fetch();
    
    $db->prepare("INSERT INTO so_line_selections (id, so_line_item_id, attribute_id, option_id) VALUES (NEWID(), ?, ?, ?)")
       ->execute([$line1_id, $attr_row['id'], $opt_row['id']]);
    
    $model_parts[] = $opt_row['code'];
    echo "  {$sel[0]} = {$opt_row['code']} ({$opt_row['display_label']})\n";
}

$model_code_1 = implode('-', $model_parts);
$db->prepare("UPDATE so_line_items SET model_code_string = ?, status = 'resolved', updated_at = GETDATE() WHERE id = ?")
   ->execute([$model_code_1, $line1_id]);

echo "  Line 1: Model Code = $model_code_1 | Qty = 5 | Status = Resolved\n\n";

// Line 2: Different configuration
$line2_id = generateUUID();
$db->prepare("INSERT INTO so_line_items (id, sales_order_id, product_code_id, line_number, quantity, generate_individual, generate_combined, status) VALUES (?, ?, ?, 2, 10, 1, 0, 'incomplete')")
   ->execute([$line2_id, $so_id, $product['id']]);

$line2_selections = [
    ['SO', 'C'],   // 2 Wire (4-20mA + HART)
    ['CE', 'M'],   // M20 X 1.5
    ['SM', '02'],  // PVDF
    ['MR', '15'],  // 15 m
    ['PC', '2'],   // Threaded
    ['FS', 'XX'],  // Not Applicable
    ['MF', 'X'],   // Not Applicable
    ['PS', '01'],  // 24VDC
];

$model_parts2 = [$product['code']];
foreach ($line2_selections as $sel) {
    $attr = $db->prepare("SELECT id FROM attributes WHERE product_code_id = ? AND code = ? AND is_active = 1");
    $attr->execute([$product['id'], $sel[0]]);
    $attr_row = $attr->fetch();
    
    $opt = $db->prepare("SELECT id, code, value, display_label FROM attribute_options WHERE attribute_id = ? AND code = ? AND is_active = 1");
    $opt->execute([$attr_row['id'], $sel[1]]);
    $opt_row = $opt->fetch();
    
    $db->prepare("INSERT INTO so_line_selections (id, so_line_item_id, attribute_id, option_id) VALUES (NEWID(), ?, ?, ?)")
       ->execute([$line2_id, $attr_row['id'], $opt_row['id']]);
    
    $model_parts2[] = $opt_row['code'];
}

$model_code_2 = implode('-', $model_parts2);
$db->prepare("UPDATE so_line_items SET model_code_string = ?, status = 'resolved', updated_at = GETDATE() WHERE id = ?")
   ->execute([$model_code_2, $line2_id]);

echo "  Line 2: Model Code = $model_code_2 | Qty = 10 | Status = Resolved\n\n";

// ─── STEP 5: Generate Documents ───
echo "STEP 5: Generate Documents\n";
echo str_repeat("-", 50) . "\n";

// Get the template
$tmpl = $db->prepare("SELECT id, template_code FROM templates WHERE product_code_id = ? AND is_active = 1 ORDER BY version DESC");
$tmpl->execute([$product['id']]);
$template = $tmpl->fetch();

$so = $db->prepare("SELECT * FROM sales_orders WHERE id = ?");
$so->execute([$so_id]);
$order = $so->fetch();

$generated_docs = [];

foreach ([$line1_id, $line2_id] as $lineIdx => $line_id) {
    $line = $db->prepare("SELECT * FROM so_line_items WHERE id = ?");
    $line->execute([$line_id]);
    $lineData = $line->fetch();
    
    $sels = $db->prepare("
        SELECT s.*, a.code as attr_code, a.name as attr_name, a.affects_engg_dwg, a.affects_internal_dwg,
               ao.code as opt_code, ao.value as opt_value, ao.display_label, ao.dimension_modifiers
        FROM so_line_selections s
        JOIN attributes a ON s.attribute_id = a.id
        JOIN attribute_options ao ON s.option_id = ao.id
        WHERE s.so_line_item_id = ?
        ORDER BY a.position_in_model
    ");
    $sels->execute([$line_id]);
    $selections = $sels->fetchAll();
    
    $injected = json_encode([
        'so_number' => $order['so_number'],
        'customer' => $order['customer_name'],
        'project' => $order['project_name'],
        'model_code' => $lineData['model_code_string'],
        'quantity' => $lineData['quantity'],
        'selections' => $selections
    ]);
    
    // Generate Engg Individual
    $doc_id_engg = generateUUID();
    $output_path_engg = "/outputs/{$order['so_number']}/line{$lineData['line_number']}/engg/136ULT-ENGG-{$lineData['model_code_string']}.dxf";
    $db->prepare("INSERT INTO generated_documents (id, so_line_item_id, template_id, document_type, output_file_path, injected_values, status) VALUES (?, ?, ?, 'engg_individual', ?, ?, 'generated')")
       ->execute([$doc_id_engg, $line_id, $template ? $template['id'] : null, $output_path_engg, $injected]);
    echo "  Line {$lineData['line_number']}: Engg Individual → doc_id = $doc_id_engg\n";
    $generated_docs[] = ['id' => $doc_id_engg, 'line' => $lineData['line_number'], 'type' => 'Engg Individual', 'model' => $lineData['model_code_string']];
    
    // Generate Internal Individual
    $doc_id_int = generateUUID();
    $output_path_int = "/outputs/{$order['so_number']}/line{$lineData['line_number']}/internal/136ULT-INT-{$lineData['model_code_string']}.dxf";
    $db->prepare("INSERT INTO generated_documents (id, so_line_item_id, template_id, document_type, output_file_path, injected_values, status) VALUES (?, ?, ?, 'internal_individual', ?, ?, 'generated')")
       ->execute([$doc_id_int, $line_id, $template ? $template['id'] : null, $output_path_int, $injected]);
    echo "  Line {$lineData['line_number']}: Internal Individual → doc_id = $doc_id_int\n";
    $generated_docs[] = ['id' => $doc_id_int, 'line' => $lineData['line_number'], 'type' => 'Internal Individual', 'model' => $lineData['model_code_string']];
    
    // Update line status
    $db->prepare("UPDATE so_line_items SET status = 'generated', updated_at = GETDATE() WHERE id = ?")->execute([$line_id]);
}

// Update SO status
$db->prepare("UPDATE sales_orders SET status = 'in_progress', updated_at = GETDATE() WHERE id = ?")->execute([$so_id]);

echo "\n  Total: " . count($generated_docs) . " documents generated\n\n";

// ─── STEP 6: Generate actual DXF files ───
echo "STEP 6: Generate DXF Drawing Files\n";
echo str_repeat("-", 50) . "\n";

foreach ($generated_docs as $docInfo) {
    $doc = $db->prepare("
        SELECT gd.*, sli.model_code_string, sli.line_number, sli.quantity,
               so.so_number, so.customer_name, so.project_name, so.po_reference, so.delivery_date,
               pc.code as product_code, pc.name as product_name
        FROM generated_documents gd
        JOIN so_line_items sli ON gd.so_line_item_id = sli.id
        JOIN sales_orders so ON sli.sales_order_id = so.id
        JOIN product_codes pc ON sli.product_code_id = pc.id
        WHERE gd.id = ?
    ");
    $doc->execute([$docInfo['id']]);
    $docData = $doc->fetch();
    
    $sels = $db->prepare("
        SELECT a.code as attr_code, a.name as attr_name, ao.code as opt_code, 
               ao.value as opt_value, ao.display_label, ao.dimension_modifiers
        FROM so_line_selections s
        JOIN attributes a ON s.attribute_id = a.id
        JOIN attribute_options ao ON s.option_id = ao.id
        WHERE s.so_line_item_id = ?
        ORDER BY a.position_in_model
    ");
    $sels->execute([$docData['so_line_item_id']]);
    $selections = $sels->fetchAll();
    
    $orderData = [
        'so_number' => $docData['so_number'],
        'customer_name' => $docData['customer_name'],
        'project_name' => $docData['project_name'],
        'po_reference' => $docData['po_reference'],
        'delivery_date' => $docData['delivery_date'],
        'model_code' => $docData['model_code_string'],
        'quantity' => $docData['quantity'],
        'line_number' => $docData['line_number'],
        'product_code' => $docData['product_code'],
        'product_name' => $docData['product_name'],
        'document_type' => $docData['document_type'],
    ];
    
    $generator = new DXFGenerator();
    $dxfContent = $generator->generate($orderData, $selections);
    
    $type_short = str_contains($docData['document_type'], 'engg') ? 'ENGG' : 'INT';
    $filename = "{$docData['so_number']}_Line{$docData['line_number']}_{$docData['product_code']}_{$type_short}.dxf";
    $filename = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $filename);
    
    $outputDir = OUTPUT_PATH . str_replace('-', '_', $docData['so_number']) . '/';
    if (!is_dir($outputDir)) mkdir($outputDir, 0755, true);
    $outputFile = $outputDir . $filename;
    file_put_contents($outputFile, $dxfContent);
    
    $db->prepare("UPDATE generated_documents SET output_file_path = ? WHERE id = ?")->execute([$outputFile, $docInfo['id']]);
    
    $size = strlen($dxfContent);
    echo "  ✓ {$filename} ({$size} bytes)\n";
    echo "    Saved: {$outputFile}\n";
}

echo "\n";

// ─── STEP 7: Summary ───
echo "============================================================\n";
echo "  FLOW COMPLETE — SUMMARY\n";
echo "============================================================\n\n";

echo "  Sales Order:  $so_number\n";
echo "  Customer:     Tata Projects Ltd\n";
echo "  Project:      Jamnagar Refinery Expansion\n";
echo "  PO Reference: PO-TATA-2026-4401\n\n";

echo "  LINE ITEMS:\n";
echo "  ┌─────┬──────────────────────────────────┬─────┬────────────┐\n";
echo "  │ Line│ Model Code                       │ Qty │ Status     │\n";
echo "  ├─────┼──────────────────────────────────┼─────┼────────────┤\n";
echo "  │  1  │ $model_code_1   │   5 │ Generated  │\n";
echo "  │  2  │ $model_code_2  │  10 │ Generated  │\n";
echo "  └─────┴──────────────────────────────────┴─────┴────────────┘\n\n";

echo "  GENERATED DOCUMENTS:\n";
echo "  ┌─────┬─────────────────────┬────────────────────────────────────────┐\n";
echo "  │ Line│ Type                │ File                                   │\n";
echo "  ├─────┼─────────────────────┼────────────────────────────────────────┤\n";
foreach ($generated_docs as $d) {
    $typeStr = str_pad($d['type'], 19);
    echo "  │  {$d['line']}  │ {$typeStr} │ {$d['model']}.dxf │\n";
}
echo "  └─────┴─────────────────────┴────────────────────────────────────────┘\n\n";

echo "  VIEW IN BROWSER:\n";
echo "  → SO Detail: http://localhost/edms/pages/sales_order_detail.php?id=$so_id\n";
echo "  → Drawing 1: http://localhost/edms/pages/drawing_preview.php?doc_id={$generated_docs[0]['id']}\n";
echo "  → Drawing 2: http://localhost/edms/pages/drawing_preview.php?doc_id={$generated_docs[2]['id']}\n\n";

echo "  DOWNLOAD DXF FILES:\n";
echo "  → Line 1 Engg: http://localhost/edms/api/download.php?doc_id={$generated_docs[0]['id']}\n";
echo "  → Line 1 Int:  http://localhost/edms/api/download.php?doc_id={$generated_docs[1]['id']}\n";
echo "  → Line 2 Engg: http://localhost/edms/api/download.php?doc_id={$generated_docs[2]['id']}\n";
echo "  → Line 2 Int:  http://localhost/edms/api/download.php?doc_id={$generated_docs[3]['id']}\n\n";

echo "  OUTPUT FILES ON DISK:\n";
$files = glob($outputDir . '*.dxf');
foreach ($files as $f) {
    $sz = filesize($f);
    echo "  → " . basename($f) . " ($sz bytes)\n";
}

echo "\n============================================================\n";
echo "  Open DXF files in AutoCAD / DraftSight / LibreCAD\n";
echo "============================================================\n";
