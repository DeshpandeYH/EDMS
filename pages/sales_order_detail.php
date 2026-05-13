<?php
$pageTitle = 'Sales Order Detail';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/DWGTemplateProcessor.php';
require_once __DIR__ . '/../includes/DXFGenerator.php';

$db = getDB();
$so_id = $_GET['id'] ?? '';

if (!$so_id) { header('Location: sales_orders.php'); exit; }

$stmt = $db->prepare("SELECT * FROM sales_orders WHERE id = ?");
$stmt->execute([$so_id]);
$order = $stmt->fetch();
if (!$order) { setFlash('error', 'Order not found.'); header('Location: sales_orders.php'); exit; }

// Fetch products for dropdown
$products = $db->query("SELECT id, code, name FROM product_codes WHERE status IN ('active','draft') ORDER BY code")->fetchAll();

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_line_item') {
        $product_code_id = $_POST['product_code_id'] ?? '';
        $quantity = intval($_POST['quantity'] ?? 1);
        $gen_individual = isset($_POST['generate_individual']) ? 1 : 0;
        $gen_combined = isset($_POST['generate_combined']) ? 1 : 0;
        
        // Get next line number
        $ln = $db->prepare("SELECT ISNULL(MAX(line_number), 0) + 1 as next_ln FROM so_line_items WHERE sales_order_id = ?");
        $ln->execute([$so_id]);
        $next_line = $ln->fetch()['next_ln'];
        
        $line_id = generateUUID();
        $stmt = $db->prepare("INSERT INTO so_line_items (id, sales_order_id, product_code_id, line_number, quantity, generate_individual, generate_combined, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'incomplete')");
        $stmt->execute([$line_id, $so_id, $product_code_id, $next_line, $quantity, $gen_individual, $gen_combined]);
        
        setFlash('success', "Line item $next_line added.");
        header("Location: sales_order_detail.php?id=$so_id");
        exit;
    }
    
    if ($action === 'save_selections') {
        $line_id = $_POST['line_id'] ?? '';
        $selections = $_POST['selections'] ?? [];
        
        // Delete existing selections
        $db->prepare("DELETE FROM so_line_selections WHERE so_line_item_id = ?")->execute([$line_id]);
        
        // Insert new selections
        $model_parts = [];
        $line_info = $db->prepare("SELECT sli.*, pc.code as product_code FROM so_line_items sli JOIN product_codes pc ON sli.product_code_id = pc.id WHERE sli.id = ?");
        $line_info->execute([$line_id]);
        $line_data = $line_info->fetch();
        $model_parts[] = $line_data['product_code'];
        
        // Get all attributes for this product, ordered
        $attrs = $db->prepare("SELECT id, code FROM attributes WHERE product_code_id = ? AND is_active = 1 ORDER BY position_in_model");
        $attrs->execute([$line_data['product_code_id']]);
        $all_attrs = $attrs->fetchAll();
        
        $all_selected = true;
        foreach ($all_attrs as $attr) {
            $opt_id = $selections[$attr['id']] ?? '';
            if ($opt_id) {
                $db->prepare("INSERT INTO so_line_selections (id, so_line_item_id, attribute_id, option_id) VALUES (NEWID(), ?, ?, ?)")
                   ->execute([$line_id, $attr['id'], $opt_id]);
                // Get option code for model string
                $opt = $db->prepare("SELECT code FROM attribute_options WHERE id = ?");
                $opt->execute([$opt_id]);
                $model_parts[] = $opt->fetch()['code'];
            } else {
                $all_selected = false;
                $model_parts[] = '??';
            }
        }
        
        $model_code = implode('-', $model_parts);
        $status = $all_selected ? 'resolved' : 'incomplete';
        $db->prepare("UPDATE so_line_items SET model_code_string = ?, status = ?, updated_at = GETDATE() WHERE id = ?")
           ->execute([$model_code, $status, $line_id]);
        
        setFlash('success', "Model code updated: $model_code");
        header("Location: sales_order_detail.php?id=$so_id");
        exit;
    }
    
    if ($action === 'generate_documents') {
        // Generate documents for resolved or previously generated lines (allows re-generation)
        $lines = $db->prepare("SELECT sli.*, pc.code as product_code FROM so_line_items sli JOIN product_codes pc ON sli.product_code_id = pc.id WHERE sli.sales_order_id = ? AND sli.status IN ('resolved','generated')");
        $lines->execute([$so_id]);
        $resolved_lines = $lines->fetchAll();
        
        $generated = 0;
        $errors = [];
        foreach ($resolved_lines as $line) {
            // Get selections for this line
            $sels = $db->prepare("
                SELECT s.attribute_id, s.option_id, a.code as attr_code, a.affects_engg_dwg, a.affects_internal_dwg, a.is_combined_attribute,
                       ao.code as opt_code, ao.value as opt_value, ao.dimension_modifiers
                FROM so_line_selections s
                JOIN attributes a ON s.attribute_id = a.id
                JOIN attribute_options ao ON s.option_id = ao.id
                WHERE s.so_line_item_id = ?
                ORDER BY a.position_in_model
            ");
            $sels->execute([$line['id']]);
            $selections = $sels->fetchAll();
            
            $injected = json_encode([
                'so_number' => $order['so_number'],
                'customer' => $order['customer_name'],
                'model_code' => $line['model_code_string'],
                'selections' => $selections
            ]);
            
            // Delete previously generated docs for this line to allow re-generation
            $db->prepare("DELETE FROM generated_documents WHERE so_line_item_id = ?")->execute([$line['id']]);
            
            // Generate individual engineering drawing
            if ($line['generate_individual']) {
                $output_types = [
                    'engg_individual' => ['flag' => 'affects_engg_dwg', 'ext' => 'dwg', 'folder' => 'engg'],
                    'internal_individual' => ['flag' => 'affects_internal_dwg', 'ext' => 'dwg', 'folder' => 'internal'],
                ];
                
                foreach ($output_types as $ot => $info) {
                    $opt_codes = array_map(fn($s) => $s['opt_code'], array_filter($selections, fn($s) => $s[$info['flag']]));
                    $combo_key = implode('-', $opt_codes);
                    
                    // Try to find matching combination
                    $combo = $db->prepare("
                        SELECT cm.id, cm.combination_key, ctm.template_id, ctm.sap_item_code, t.template_code
                        FROM combination_matrix cm
                        LEFT JOIN combination_template_map ctm ON cm.id = ctm.combination_id
                        LEFT JOIN templates t ON ctm.template_id = t.id
                        WHERE cm.product_code_id = ? AND cm.output_type = ? AND cm.combination_key = ? AND cm.is_active = 1
                    ");
                    $combo->execute([$line['product_code_id'], $ot, $combo_key]);
                    $match = $combo->fetch();
                    
                    $template_id = $match['template_id'] ?? null;
                    $sap_code = $match['sap_item_code'] ?? null;
                    $template_code = $match['template_code'] ?? null;
                    
                    // If no template from combination map, find any template for this product + output type
                    if (!$template_id) {
                        $fallback = $db->prepare("SELECT id, template_code FROM templates WHERE product_code_id = ? AND template_type = ? AND is_active = 1 ORDER BY version DESC");
                        $fallback->execute([$line['product_code_id'], $ot]);
                        $fb = $fallback->fetch();
                        if ($fb) {
                            $template_id = $fb['id'];
                            $template_code = $fb['template_code'];
                        }
                    }
                    // Also try any template for this product regardless of type
                    if (!$template_id) {
                        $fallback2 = $db->prepare("SELECT id, template_code FROM templates WHERE product_code_id = ? AND is_active = 1 ORDER BY version DESC");
                        $fallback2->execute([$line['product_code_id']]);
                        $fb2 = $fallback2->fetch();
                        if ($fb2) {
                            $template_id = $fb2['id'];
                            $template_code = $fb2['template_code'];
                        }
                    }
                    
                    if (!$template_code) {
                        $template_code = $line['product_code'] . '-' . strtoupper(str_replace('_', '-', $ot));
                    }

                    // Resolve template file path (relative under uploads/).
                    $tplFile = null;
                    if ($template_id) {
                        $row = $db->prepare("SELECT file_path FROM templates WHERE id = ?");
                        $row->execute([$template_id]);
                        $tr = $row->fetch();
                        if ($tr) {
                            $cand = $tr['file_path'];
                            if ($cand && !is_file($cand)) {
                                $abs = UPLOAD_PATH . ltrim($cand, '/\\');
                                if (is_file($abs)) $cand = $abs;
                            }
                            $tplFile = is_file($cand) ? $cand : null;
                        }
                    }

                    // Build orderData + sels in the shape both engines expect.
                    $sel2 = $db->prepare("
                        SELECT a.code as attr_code, a.name as attr_name, a.position_in_model,
                               a.is_combined_attribute,
                               ao.code as opt_code, ao.value as opt_value, ao.display_label,
                               ao.dimension_modifiers
                        FROM so_line_selections s
                        JOIN attributes a ON s.attribute_id = a.id
                        JOIN attribute_options ao ON s.option_id = ao.id
                        WHERE s.so_line_item_id = ? ORDER BY a.position_in_model
                    ");
                    $sel2->execute([$line['id']]);
                    $selsFull = $sel2->fetchAll();

                    $dimColsStmt = $db->prepare("SELECT * FROM dim_table_columns WHERE product_code_id = ? ORDER BY sort_order");
                    $dimColsStmt->execute([$line['product_code_id']]);
                    $dimColumns = $dimColsStmt->fetchAll();

                    $anchorStmt = $db->prepare("SELECT anchor_type, x_coord, y_coord FROM template_anchor_config WHERE product_code_id = ?");
                    $anchorStmt->execute([$line['product_code_id']]);
                    $anchorConfig = [];
                    foreach ($anchorStmt->fetchAll() as $a) {
                        $anchorConfig[$a['anchor_type']] = ['x' => (float)$a['x_coord'], 'y' => (float)$a['y_coord']];
                    }

                    $orderData = [
                        'so_number'     => $order['so_number'],
                        'customer_name' => $order['customer_name'],
                        'project_name'  => $order['project_name'],
                        'po_reference'  => $order['po_reference'],
                        'delivery_date' => $order['delivery_date'],
                        'model_code'    => $line['model_code_string'],
                        'quantity'      => $line['quantity'],
                        'line_number'   => $line['line_number'],
                        'product_code'  => $line['product_code'],
                        'product_name'  => $line['product_code'],
                        'document_type' => $ot,
                        'sap_item_code' => $sap_code,
                        'template_code' => $template_code,
                    ];

                    // Output dir + file name.
                    $baseName = preg_replace('/[^A-Za-z0-9_\-]/','_',
                        "{$template_code}-{$line['model_code_string']}");
                    $outDir = OUTPUT_PATH . $order['so_number'] . '/line' . $line['line_number'] . '/' . $info['folder'] . '/';
                    if (!is_dir($outDir)) @mkdir($outDir, 0755, true);

                    // Run the processor — copy template, inject SO data, save as new DWG.
                    $producedAbs = null;
                    try {
                        if ($tplFile) {
                            $proc = new DWGTemplateProcessor();
                            $fmt  = ($proc->isAvailable()) ? 'dwg' : 'dxf';
                            $producedAbs = $proc->process(
                                $tplFile, $orderData, $selsFull, $dimColumns,
                                $outDir, $baseName, $fmt, $anchorConfig
                            );
                        } else {
                            // No template available — fall back to from-scratch DXF.
                            $gen = new DXFGenerator();
                            $producedAbs = $outDir . $baseName . '.dxf';
                            file_put_contents($producedAbs, $gen->generate($orderData, $selsFull, $dimColumns));
                            $errors[] = "Line {$line['line_number']} $ot: no template uploaded for product — used fallback DXF.";
                        }
                    } catch (Throwable $e) {
                        $errors[] = "Line {$line['line_number']} $ot: " . $e->getMessage();
                        // Last-ditch fallback so a row still exists.
                        $gen = new DXFGenerator();
                        $producedAbs = $outDir . $baseName . '.dxf';
                        file_put_contents($producedAbs, $gen->generate($orderData, $selsFull, $dimColumns));
                    }

                    $output_path = $producedAbs;

                    // Create document record with the real produced file path.
                    $db->prepare("INSERT INTO generated_documents (id, so_line_item_id, template_id, document_type, sap_item_code, output_file_path, injected_values, status) VALUES (NEWID(), ?, ?, ?, ?, ?, ?, 'generated')")
                       ->execute([$line['id'], $template_id, $ot, $sap_code, $output_path, $injected]);
                    $generated++;

                    if (!$match) {
                        $errors[] = "Line {$line['line_number']} $ot: no combination match for key '$combo_key'";
                    }
                }
            }
            
            // Update line status
            if ($generated > 0) {
                $db->prepare("UPDATE so_line_items SET status = 'generated', updated_at = GETDATE() WHERE id = ?")->execute([$line['id']]);
            }
        }
        
        if ($generated > 0) {
            $db->prepare("UPDATE sales_orders SET status = 'in_progress', updated_at = GETDATE() WHERE id = ?")->execute([$so_id]);
        }
        
        $msg = "$generated documents generated.";
        if (!empty($errors)) {
            $msg .= " Warnings: " . implode('; ', $errors);
        }
        setFlash($generated > 0 ? 'success' : 'warning', $msg);
        header("Location: sales_order_detail.php?id=$so_id");
        exit;
    }
    
    if ($action === 'delete_line') {
        $line_id = $_POST['line_id'] ?? '';
        $db->prepare("DELETE FROM so_line_selections WHERE so_line_item_id = ?")->execute([$line_id]);
        $db->prepare("DELETE FROM so_line_items WHERE id = ?")->execute([$line_id]);
        setFlash('success', 'Line item removed.');
        header("Location: sales_order_detail.php?id=$so_id");
        exit;
    }
}

// Fetch line items
$lines = $db->prepare("
    SELECT sli.*, pc.code as product_code, pc.name as product_name, pc.supports_combined_dwg
    FROM so_line_items sli
    JOIN product_codes pc ON sli.product_code_id = pc.id
    WHERE sli.sales_order_id = ?
    ORDER BY sli.line_number
");
$lines->execute([$so_id]);
$line_items = $lines->fetchAll();

// Fetch selections for each line
$line_selections = [];
foreach ($line_items as $li) {
    $sels = $db->prepare("
        SELECT s.*, a.code as attr_code, a.name as attr_name, a.position_in_model, 
               ao.code as opt_code, ao.value as opt_value
        FROM so_line_selections s
        JOIN attributes a ON s.attribute_id = a.id
        JOIN attribute_options ao ON s.option_id = ao.id
        WHERE s.so_line_item_id = ?
        ORDER BY a.position_in_model
    ");
    $sels->execute([$li['id']]);
    $line_selections[$li['id']] = $sels->fetchAll();
}

// Fetch generated docs
$docs = $db->prepare("
    SELECT gd.*, sli.line_number, sli.model_code_string, t.template_code
    FROM generated_documents gd
    JOIN so_line_items sli ON gd.so_line_item_id = sli.id
    LEFT JOIN templates t ON gd.template_id = t.id
    WHERE sli.sales_order_id = ?
    ORDER BY sli.line_number, gd.document_type
");
$docs->execute([$so_id]);
$generated_docs = $docs->fetchAll();
?>

<h2>Sales Order: <?= sanitize($order['so_number']) ?> <span class="badge" style="background:var(--cyan-bg);color:var(--cyan);">SALES</span></h2>
<div class="gap-row mb-2">
    <a href="sales_orders.php" class="btn btn-outline btn-sm">← Back to Orders</a>
</div>

<!-- ORDER HEADER -->
<div class="panel">
    <div class="panel-header">
        <span class="panel-title">Order Details</span>
        <span class="tag tag-<?= match($order['status']) { 'approved' => 'green', 'in_progress' => 'blue', 'draft' => 'orange', default => 'dim' } ?>"><?= ucfirst(str_replace('_', ' ', $order['status'])) ?></span>
    </div>
    <div class="panel-body">
        <div class="info-grid">
            <div><span class="label">SO Number:</span> <strong class="mono text-accent"><?= sanitize($order['so_number']) ?></strong></div>
            <div><span class="label">Customer:</span> <?= sanitize($order['customer_name']) ?></div>
            <div><span class="label">Project:</span> <?= sanitize($order['project_name'] ?? '—') ?></div>
            <div><span class="label">PO Reference:</span> <span class="mono"><?= sanitize($order['po_reference'] ?? '—') ?></span></div>
            <div><span class="label">Delivery Date:</span> <?= $order['delivery_date'] ?? '—' ?></div>
            <div><span class="label">Created:</span> <?= date('M d, Y', strtotime($order['created_at'])) ?></div>
        </div>
    </div>
</div>

<!-- ADD LINE ITEM -->
<div class="panel">
    <div class="panel-header">
        <span class="panel-title">Add Line Item</span>
    </div>
    <div class="panel-body">
        <form method="POST" action="">
            <input type="hidden" name="action" value="add_line_item">
            <div class="form-row">
                <div class="form-field">
                    <label class="form-label">Product</label>
                    <select name="product_code_id" class="form-select" required>
                        <option value="">Select Product</option>
                        <?php foreach ($products as $p): ?>
                        <option value="<?= $p['id'] ?>"><?= sanitize($p['code']) ?> — <?= sanitize($p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-field" style="flex:0 0 100px;">
                    <label class="form-label">Quantity</label>
                    <input type="number" name="quantity" class="form-input" value="1" min="1" required>
                </div>
                <div class="form-field">
                    <label class="form-label">Drawing Type</label>
                    <div style="display:flex;gap:1rem;margin-top:0.4rem;">
                        <label class="form-checkbox"><input type="checkbox" name="generate_individual" value="1" checked> Individual</label>
                        <label class="form-checkbox"><input type="checkbox" name="generate_combined" value="1"> Combined</label>
                    </div>
                </div>
                <div class="form-field" style="flex:0 0 auto;">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary">+ Add Line</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- LINE ITEMS -->
<div class="panel">
    <div class="panel-header">
        <span class="panel-title">Line Items</span>
        <span class="tag tag-blue"><?= count($line_items) ?> lines</span>
    </div>
    <div class="panel-body" style="padding:0;">
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Line</th>
                        <th>Product</th>
                        <th>Model Code</th>
                        <th>Qty</th>
                        <th>Drawing Type</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($line_items)): ?>
                    <tr><td colspan="7" class="text-center text-dim" style="padding:2rem;">No line items yet.</td></tr>
                    <?php else: ?>
                    <?php foreach ($line_items as $li): ?>
                    <tr>
                        <td><?= $li['line_number'] ?></td>
                        <td><?= sanitize($li['product_code']) ?> — <?= sanitize($li['product_name']) ?></td>
                        <td class="mono text-accent"><?= $li['model_code_string'] ? sanitize($li['model_code_string']) : '<span class="text-dim">Not configured</span>' ?></td>
                        <td><?= $li['quantity'] ?></td>
                        <td>
                            <div class="gap-row">
                                <?php if ($li['generate_individual']): ?><span class="tag tag-blue">Individual</span><?php endif; ?>
                                <?php if ($li['generate_combined']): ?><span class="tag tag-purple">Combined</span><?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <?php
                            $sc = match($li['status']) {
                                'resolved','generated','approved' => 'tag-green',
                                'incomplete' => 'tag-orange',
                                'blocked' => 'tag-red',
                                default => 'tag-dim'
                            };
                            ?>
                            <span class="tag <?= $sc ?>"><?= ucfirst($li['status']) ?></span>
                        </td>
                        <td>
                            <div class="btn-group">
                                <a href="model_code_builder.php?line_id=<?= $li['id'] ?>&so_id=<?= $so_id ?>" class="btn btn-primary btn-sm">Configure</a>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Remove this line?')">
                                    <input type="hidden" name="action" value="delete_line">
                                    <input type="hidden" name="line_id" value="<?= $li['id'] ?>">
                                    <button type="submit" class="btn btn-outline btn-sm" style="color:var(--red);">✕</button>
                                </form>
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

<div class="callout callout-info">
    <strong>Drawing type per line:</strong> Both can be selected — the line gets its own individual drawing AND is included in the combined drawing group. Lines sharing the same product and common attributes (differing only by the combined attribute) auto-group.
</div>

<!-- GENERATE BUTTON -->
<?php
$actionable_count = count(array_filter($line_items, fn($l) => in_array($l['status'], ['resolved', 'generated'])));
?>
<?php if ($actionable_count > 0): ?>
<form method="POST" action="">
    <input type="hidden" name="action" value="generate_documents">
    <div class="btn-group mt-2">
        <button type="submit" class="btn btn-primary btn-lg">⚡ Generate All Documents (<?= $actionable_count ?> lines ready)</button>
    </div>
</form>
<?php endif; ?>

<!-- GENERATED DOCUMENTS -->
<?php if (!empty($generated_docs)): ?>
<h3 class="mt-3">Generated Documents</h3>
<div class="panel">
    <div class="panel-header">
        <span class="panel-title">Output Documents</span>
        <span class="tag tag-green"><?= count($generated_docs) ?> documents</span>
    </div>
    <div class="panel-body" style="padding:0;">
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Line</th>
                        <th>Model Code</th>
                        <th>Type</th>
                        <th>Template</th>
                        <th>SAP Code</th>
                        <th>Status</th>
                        <th>Generated</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($generated_docs as $doc): ?>
                    <tr>
                        <td><?= $doc['line_number'] ?></td>
                        <td class="mono text-accent"><?= sanitize($doc['model_code_string'] ?? '') ?></td>
                        <td>
                            <?php
                            $typeTag = match($doc['document_type']) {
                                'engg_individual' => ['tag-blue', 'Engg Individual'],
                                'engg_combined' => ['tag-purple', 'Engg Combined'],
                                'internal_individual' => ['tag-cyan', 'Internal Individual'],
                                'internal_combined' => ['tag-purple', 'Internal Combined'],
                                'test_cert' => ['tag-orange', 'Test Cert'],
                                default => ['tag-dim', $doc['document_type']]
                            };
                            ?>
                            <span class="tag <?= $typeTag[0] ?>"><?= $typeTag[1] ?></span>
                        </td>
                        <td class="mono"><?= sanitize($doc['template_code'] ?? '—') ?></td>
                        <td class="mono"><?= sanitize($doc['sap_item_code'] ?? '—') ?></td>
                        <td><span class="tag tag-green"><?= ucfirst($doc['status']) ?></span></td>
                        <td style="font-size:0.75rem;"><?= date('M d, Y H:i', strtotime($doc['generated_at'])) ?></td>
                        <td>
                            <a href="drawing_preview.php?doc_id=<?= $doc['id'] ?>" class="btn btn-outline btn-sm">View</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
