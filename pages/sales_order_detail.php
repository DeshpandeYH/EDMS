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
                    $outDir = OUTPUT_PATH . safeSoFolder($order['so_number']) . '/line' . $line['line_number'] . '/' . $info['folder'] . '/';
                    if (!is_dir($outDir)) @mkdir($outDir, 0755, true);

                    // Run the processor — copy template, inject SO data, save as new DWG.
                    $producedAbs = null;
                    // Fetch user-defined field mappings for this specific template.
                    $fieldMappings = [];
                    if ($template_id) {
                        $fm = $db->prepare("SELECT dwg_field_name, source_type, source_key, default_value FROM template_field_mappings WHERE template_id = ?");
                        $fm->execute([$template_id]);
                        $fieldMappings = $fm->fetchAll();
                    }
                    try {
                        if ($tplFile) {
                            $proc = new DWGTemplateProcessor();
                            $fmt  = ($proc->isAvailable()) ? 'dwg' : 'dxf';
                            $producedAbs = $proc->process(
                                $tplFile, $orderData, $selsFull, $dimColumns,
                                $outDir, $baseName, $fmt, $anchorConfig, $fieldMappings
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

                    // BUG 2 FIX: if no combination_matrix row matched the model code,
                    // the document was produced from a generic fallback template.
                    // Tag it so engineering knows to verify, and surface a loud
                    // ERROR (not a quiet warning) on the page.
                    $unmapped = !$match;
                    $injectedRecord = json_decode($injected, true) ?: [];
                    if ($unmapped) {
                        $injectedRecord['unmapped'] = true;
                        $injectedRecord['unmapped_reason'] = "No combination_matrix row for product+$ot+key='$combo_key'. Fallback template used — VERIFY MANUALLY.";
                    }
                    $injectedStored = json_encode($injectedRecord);

                    // Create document record with the real produced file path.
                    $db->prepare("INSERT INTO generated_documents (id, so_line_item_id, template_id, document_type, sap_item_code, output_file_path, injected_values, status) VALUES (NEWID(), ?, ?, ?, ?, ?, ?, 'generated')")
                       ->execute([$line['id'], $template_id, $ot, $sap_code, $output_path, $injectedStored]);
                    $generated++;

                    if ($unmapped) {
                        $errors[] = "ERROR Line {$line['line_number']} $ot: no combination match for key '$combo_key' — drawing uses a FALLBACK template and is tagged 'unmapped'. Verify manually or add a combination_template_map row.";
                    }
                }
            }
            
            // Update line status
            if ($generated > 0) {
                $db->prepare("UPDATE so_line_items SET status = 'generated', updated_at = GETDATE() WHERE id = ?")->execute([$line['id']]);
            }
        }

        // ============================================================
        // BUG 1 FIX: COMBINED DRAWINGS
        // ------------------------------------------------------------
        // Lines with generate_combined = 1 sharing the same product and the
        // same selections for all NON-combined attributes auto-group into a
        // single combined drawing. (Per DEVELOPMENT.md §combined drawings.)
        // ============================================================
        $combinedLinesStmt = $db->prepare("
            SELECT sli.*, pc.code as product_code, pc.name as product_name, pc.supports_combined_dwg
            FROM so_line_items sli
            JOIN product_codes pc ON sli.product_code_id = pc.id
            WHERE sli.sales_order_id = ?
              AND sli.generate_combined = 1
              AND sli.status IN ('resolved','generated')
        ");
        $combinedLinesStmt->execute([$so_id]);
        $combinedLines = $combinedLinesStmt->fetchAll();

        // Group lines by product + hash of non-combined selections.
        $groups = []; // groupKey => ['product_code_id'=>..., 'product_code'=>..., 'lines'=>[], 'common_sels'=>[], 'combined_attr_ids'=>[]]
        foreach ($combinedLines as $cl) {
            // Pull this line's selections joined with attribute metadata.
            $s = $db->prepare("
                SELECT a.id as attribute_id, a.code as attr_code, a.name as attr_name,
                       a.position_in_model, a.is_combined_attribute,
                       a.affects_engg_dwg, a.affects_internal_dwg,
                       ao.id as option_id, ao.code as opt_code, ao.value as opt_value,
                       ao.display_label, ao.dimension_modifiers
                FROM so_line_selections sl
                JOIN attributes a ON sl.attribute_id = a.id
                JOIN attribute_options ao ON sl.option_id = ao.id
                WHERE sl.so_line_item_id = ?
                ORDER BY a.position_in_model
            ");
            $s->execute([$cl['id']]);
            $allSels = $s->fetchAll();

            // Common (non-combined) selections drive the group key.
            $commonSels = array_values(array_filter($allSels, fn($r) => !$r['is_combined_attribute']));
            $commonKeyParts = [];
            foreach ($commonSels as $r) {
                $commonKeyParts[] = $r['attr_code'] . '=' . $r['opt_code'];
            }
            $groupKey = $cl['product_code_id'] . '|' . implode('|', $commonKeyParts);

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'product_code_id'   => $cl['product_code_id'],
                    'product_code'      => $cl['product_code'],
                    'product_name'      => $cl['product_name'],
                    'common_sels'       => $commonSels,
                    'all_sels_template' => $allSels, // first line's full selections (used for dim table)
                    'lines'             => [],
                    'combined_opt_rows' => [], // one per line: the combined-attribute selection(s)
                ];
            }
            $groups[$groupKey]['lines'][] = $cl;
            $combinedOnly = array_values(array_filter($allSels, fn($r) => $r['is_combined_attribute']));
            $groups[$groupKey]['combined_opt_rows'][] = [
                'line_number' => $cl['line_number'],
                'model_code'  => $cl['model_code_string'],
                'quantity'    => $cl['quantity'],
                'options'     => $combinedOnly,
            ];
        }

        foreach ($groups as $groupKey => $g) {
            if (count($g['lines']) < 1) continue; // safety

            $commonAttrsHash    = hash('sha256', $groupKey);
            $commonAttrsDisplay = implode(', ', array_map(
                fn($r) => $r['attr_code'] . ':' . $r['opt_code'],
                $g['common_sels']
            ));
            $pivotValuesJson = json_encode(array_map(function ($r) {
                return [
                    'line_number' => $r['line_number'],
                    'model_code'  => $r['model_code'],
                    'quantity'    => $r['quantity'],
                    'options'     => array_map(fn($o) => [
                        'attr_code' => $o['attr_code'],
                        'opt_code'  => $o['opt_code'],
                        'value'     => $o['display_label'] ?? $o['opt_value'],
                    ], $r['options']),
                ];
            }, $g['combined_opt_rows']));

            // Find or create the group row, then bind each line to it.
            $find = $db->prepare("
                SELECT id FROM combined_drawing_groups
                WHERE sales_order_id = ? AND product_code_id = ? AND common_attrs_hash = ?
            ");
            $find->execute([$so_id, $g['product_code_id'], $commonAttrsHash]);
            $existing = $find->fetch();
            if ($existing) {
                $groupId = $existing['id'];
                $db->prepare("UPDATE combined_drawing_groups SET common_attrs_display = ?, pivot_values = ? WHERE id = ?")
                   ->execute([$commonAttrsDisplay, $pivotValuesJson, $groupId]);
            } else {
                $groupId = generateUUID();
                $db->prepare("
                    INSERT INTO combined_drawing_groups
                        (id, sales_order_id, product_code_id, common_attrs_hash, common_attrs_display, pivot_values)
                    VALUES (?, ?, ?, ?, ?, ?)
                ")->execute([$groupId, $so_id, $g['product_code_id'], $commonAttrsHash, $commonAttrsDisplay, $pivotValuesJson]);
            }
            foreach ($g['lines'] as $cl) {
                $db->prepare("UPDATE so_line_items SET combined_group_id = ? WHERE id = ?")
                   ->execute([$groupId, $cl['id']]);
            }

            // Combined combination_key = opt_codes of NON-combined attrs in position order
            // (matches how pages/combinations.php builds keys for *_combined output types).
            $comboKeyOutputs = ['engg_combined', 'internal_combined'];
            $dimColsStmt = $db->prepare("SELECT * FROM dim_table_columns WHERE product_code_id = ? ORDER BY sort_order");
            $dimColsStmt->execute([$g['product_code_id']]);
            $dimColumns = $dimColsStmt->fetchAll();

            $anchorStmt = $db->prepare("SELECT anchor_type, x_coord, y_coord FROM template_anchor_config WHERE product_code_id = ?");
            $anchorStmt->execute([$g['product_code_id']]);
            $anchorConfig = [];
            foreach ($anchorStmt->fetchAll() as $a) {
                $anchorConfig[$a['anchor_type']] = ['x' => (float)$a['x_coord'], 'y' => (float)$a['y_coord']];
            }

            foreach ($comboKeyOutputs as $ot) {
                $flagCol = $ot === 'engg_combined' ? 'affects_engg_dwg' : 'affects_internal_dwg';
                // Build key from common (non-combined) selections that affect this output.
                $opt_codes = [];
                foreach ($g['common_sels'] as $r) {
                    if ($r[$flagCol]) $opt_codes[] = $r['opt_code'];
                }
                $combo_key = implode('-', $opt_codes);

                $combo = $db->prepare("
                    SELECT cm.id, cm.combination_key, ctm.template_id, ctm.sap_item_code, t.template_code
                    FROM combination_matrix cm
                    LEFT JOIN combination_template_map ctm ON cm.id = ctm.combination_id
                    LEFT JOIN templates t ON ctm.template_id = t.id
                    WHERE cm.product_code_id = ? AND cm.output_type = ? AND cm.combination_key = ? AND cm.is_active = 1
                ");
                $combo->execute([$g['product_code_id'], $ot, $combo_key]);
                $match = $combo->fetch();

                $template_id   = $match['template_id']   ?? null;
                $sap_code      = $match['sap_item_code'] ?? null;
                $template_code = $match['template_code'] ?? null;

                // Same fallback chain as the individual branch.
                if (!$template_id) {
                    $fb = $db->prepare("SELECT id, template_code FROM templates WHERE product_code_id = ? AND template_type = ? AND is_active = 1 ORDER BY version DESC");
                    $fb->execute([$g['product_code_id'], $ot]);
                    $f = $fb->fetch();
                    if ($f) { $template_id = $f['id']; $template_code = $f['template_code']; }
                }
                if (!$template_id) {
                    $fb = $db->prepare("SELECT id, template_code FROM templates WHERE product_code_id = ? AND is_active = 1 ORDER BY version DESC");
                    $fb->execute([$g['product_code_id']]);
                    $f = $fb->fetch();
                    if ($f) { $template_id = $f['id']; $template_code = $f['template_code']; }
                }
                if (!$template_code) {
                    $template_code = $g['product_code'] . '-' . strtoupper(str_replace('_', '-', $ot));
                }

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

                // Combined model_code: comma-joined list of grouped model codes.
                $modelCodes = array_map(fn($cl) => $cl['model_code_string'], $g['lines']);
                $combinedModelCode = implode(', ', array_filter($modelCodes));

                // Order data: use the first line's full selections to render the
                // dim table (common attrs drive it), and surface ALL model codes
                // in the title block and combined-summary stamp.
                $orderData = [
                    'so_number'     => $order['so_number'],
                    'customer_name' => $order['customer_name'],
                    'project_name'  => $order['project_name'],
                    'po_reference'  => $order['po_reference'],
                    'delivery_date' => $order['delivery_date'],
                    'model_code'    => $combinedModelCode,
                    'quantity'      => array_sum(array_map(fn($cl) => (int)$cl['quantity'], $g['lines'])),
                    'line_number'   => 'COMB',
                    'product_code'  => $g['product_code'],
                    'product_name'  => $g['product_name'],
                    'document_type' => $ot,
                    'sap_item_code' => $sap_code,
                    'template_code' => $template_code,
                    'combined_group_id' => $groupId,
                    'combined_lines'    => $g['combined_opt_rows'],
                ];

                $info = $ot === 'engg_combined'
                    ? ['ext' => 'dwg', 'folder' => 'engg_combined']
                    : ['ext' => 'dwg', 'folder' => 'internal_combined'];

                $baseName = preg_replace('/[^A-Za-z0-9_\-]/','_',
                    "{$template_code}-COMB-" . substr($commonAttrsHash, 0, 8));
                $outDir = OUTPUT_PATH . safeSoFolder($order['so_number']) . '/combined/' . $info['folder'] . '/';
                if (!is_dir($outDir)) @mkdir($outDir, 0755, true);

                $producedAbs = null;
                // Fetch user-defined field mappings for this specific template.
                $fieldMappings = [];
                if ($template_id) {
                    $fm = $db->prepare("SELECT dwg_field_name, source_type, source_key, default_value FROM template_field_mappings WHERE template_id = ?");
                    $fm->execute([$template_id]);
                    $fieldMappings = $fm->fetchAll();
                }
                try {
                    if ($tplFile) {
                        $proc = new DWGTemplateProcessor();
                        $fmt  = ($proc->isAvailable()) ? 'dwg' : 'dxf';
                        $producedAbs = $proc->process(
                            $tplFile, $orderData, $g['all_sels_template'], $dimColumns,
                            $outDir, $baseName, $fmt, $anchorConfig, $fieldMappings
                        );
                    } else {
                        $gen = new DXFGenerator();
                        $producedAbs = $outDir . $baseName . '.dxf';
                        file_put_contents($producedAbs, $gen->generate($orderData, $g['all_sels_template'], $dimColumns));
                        $errors[] = "Combined group ($commonAttrsDisplay) $ot: no template uploaded for product — used fallback DXF.";
                    }
                } catch (Throwable $e) {
                    $errors[] = "Combined group ($commonAttrsDisplay) $ot: " . $e->getMessage();
                    $gen = new DXFGenerator();
                    $producedAbs = $outDir . $baseName . '.dxf';
                    file_put_contents($producedAbs, $gen->generate($orderData, $g['all_sels_template'], $dimColumns));
                }

                $unmapped = !$match;
                $injectedRecord = [
                    'so_number'     => $order['so_number'],
                    'customer'      => $order['customer_name'],
                    'group_id'      => $groupId,
                    'common_attrs'  => $commonAttrsDisplay,
                    'lines'         => $g['combined_opt_rows'],
                ];
                if ($unmapped) {
                    $injectedRecord['unmapped'] = true;
                    $injectedRecord['unmapped_reason'] = "No combination_matrix row for product+$ot+key='$combo_key'. Fallback template used — VERIFY MANUALLY.";
                }
                $injectedStored = json_encode($injectedRecord);

                // Replace previous combined doc for this group+type so re-runs don't duplicate.
                $db->prepare("DELETE FROM generated_documents WHERE combined_group_id = ? AND document_type = ?")
                   ->execute([$groupId, $ot]);
                $db->prepare("
                    INSERT INTO generated_documents
                        (id, so_line_item_id, combined_group_id, template_id, document_type, sap_item_code, output_file_path, injected_values, status)
                    VALUES (NEWID(), NULL, ?, ?, ?, ?, ?, ?, 'generated')
                ")->execute([$groupId, $template_id, $ot, $sap_code, $producedAbs, $injectedStored]);
                $generated++;

                if ($unmapped) {
                    $errors[] = "ERROR combined group ($commonAttrsDisplay) $ot: no combination match for key '$combo_key' — drawing uses a FALLBACK template and is tagged 'unmapped'. Verify manually or add a combination_template_map row.";
                }
            }

            // Mark contributing lines as 'generated' (they now have at least the combined drawing).
            foreach ($g['lines'] as $cl) {
                $db->prepare("UPDATE so_line_items SET status = 'generated', updated_at = GETDATE() WHERE id = ?")
                   ->execute([$cl['id']]);
            }
        }
        // END BUG 1 FIX

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
