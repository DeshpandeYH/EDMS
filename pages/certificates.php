<?php
$pageTitle = 'Test & Guarantee Certificates';
require_once __DIR__ . '/../includes/header.php';

$db = getDB();

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_mfg_data') {
        $line_id = $_POST['so_line_item_id'] ?? '';
        $serial_entries = trim($_POST['serial_entries'] ?? '');
        $heat_body = trim($_POST['heat_body'] ?? '');
        $heat_trim = trim($_POST['heat_trim'] ?? '');
        $heat_seat = trim($_POST['heat_seat'] ?? '');
        $inspector = trim($_POST['inspector_name'] ?? '');
        $insp_date = $_POST['inspection_date'] ?? null;
        
        $heat_numbers = json_encode([
            'body' => $heat_body,
            'trim' => $heat_trim,
            'seat' => $heat_seat
        ]);
        
        $compliance = [];
        foreach ($_POST as $key => $val) {
            if (str_starts_with($key, 'check_')) {
                $compliance[str_replace('check_', '', $key)] = $val === '1' ? true : false;
            }
        }
        $compliance_json = json_encode($compliance);
        
        // Check if exists
        $existing = $db->prepare("SELECT id FROM cert_mfg_data WHERE so_line_item_id = ?");
        $existing->execute([$line_id]);
        $row = $existing->fetch();
        
        $is_complete = !empty($serial_entries) && !empty($heat_body) && !empty($inspector) && !empty($insp_date);
        
        if ($row) {
            $db->prepare("UPDATE cert_mfg_data SET serial_entries = ?, heat_numbers = ?, compliance_checks = ?, inspector_name = ?, inspection_date = ?, is_complete = ?, entered_at = GETDATE() WHERE id = ?")
               ->execute([json_encode(['raw' => $serial_entries]), $heat_numbers, $compliance_json, $inspector, $insp_date ?: null, $is_complete ? 1 : 0, $row['id']]);
        } else {
            $db->prepare("INSERT INTO cert_mfg_data (id, so_line_item_id, serial_entries, heat_numbers, compliance_checks, inspector_name, inspection_date, is_complete) VALUES (NEWID(), ?, ?, ?, ?, ?, ?, ?)")
               ->execute([$line_id, json_encode(['raw' => $serial_entries]), $heat_numbers, $compliance_json, $inspector, $insp_date ?: null, $is_complete ? 1 : 0]);
        }
        
        setFlash('success', 'Manufacturing data saved.' . ($is_complete ? ' Ready for certificate generation.' : ''));
        header("Location: certificates.php?line_id=$line_id");
        exit;
    }
    
    if ($action === 'generate_certificate') {
        $line_id = $_POST['so_line_item_id'] ?? '';
        
        // Get line and mfg data
        $line = $db->prepare("
            SELECT sli.*, so.so_number, so.customer_name, pc.code as product_code
            FROM so_line_items sli
            JOIN sales_orders so ON sli.sales_order_id = so.id
            JOIN product_codes pc ON sli.product_code_id = pc.id
            WHERE sli.id = ?
        ");
        $line->execute([$line_id]);
        $line_data = $line->fetch();
        
        $mfg = $db->prepare("SELECT * FROM cert_mfg_data WHERE so_line_item_id = ? AND is_complete = 1");
        $mfg->execute([$line_id]);
        $mfg_data = $mfg->fetch();
        
        if ($line_data && $mfg_data) {
            $cert_number = $line_data['product_code'] . '-TC-' . date('Y') . '-' . str_replace(['SO-', '-'], '', $line_data['so_number']) . '-' . str_pad($line_data['line_number'], 2, '0', STR_PAD_LEFT);
            
            // Find cert template
            $cert_tmpl = $db->prepare("SELECT id FROM cert_templates WHERE product_code_id = ? AND is_active = 1 ORDER BY version DESC");
            $cert_tmpl->execute([$line_data['product_code_id']]);
            $tmpl = $cert_tmpl->fetch();
            
            $output_path = "/outputs/{$line_data['so_number']}/line{$line_data['line_number']}/cert/{$cert_number}.xlsx";
            
            $merged = json_encode([
                'line' => $line_data,
                'mfg' => $mfg_data,
                'generated_at' => date('Y-m-d H:i:s')
            ]);
            
            $db->prepare("INSERT INTO generated_certificates (id, so_line_item_id, cert_template_id, certificate_number, output_file_path, merged_values, inspector_name, inspection_date, status) VALUES (NEWID(), ?, ?, ?, ?, ?, ?, ?, 'generated')")
               ->execute([$line_id, $tmpl ? $tmpl['id'] : null, $cert_number, $output_path, $merged, $mfg_data['inspector_name'], $mfg_data['inspection_date']]);
            
            setFlash('success', "Certificate $cert_number generated.");
        } else {
            setFlash('error', 'Manufacturing data incomplete.');
        }
        header("Location: certificates.php?line_id=$line_id");
        exit;
    }
}

// If a specific line is selected, show detail view
$line_id = $_GET['line_id'] ?? '';

if ($line_id) {
    // DETAIL VIEW: Manufacturing data entry for a specific line
    $line = $db->prepare("
        SELECT sli.*, so.so_number, so.customer_name, so.po_reference,
               pc.code as product_code, pc.name as product_name
        FROM so_line_items sli
        JOIN sales_orders so ON sli.sales_order_id = so.id
        JOIN product_codes pc ON sli.product_code_id = pc.id
        WHERE sli.id = ?
    ");
    $line->execute([$line_id]);
    $line_data = $line->fetch();
    
    if (!$line_data) { setFlash('error', 'Line not found.'); header('Location: certificates.php'); exit; }
    
    // Get existing mfg data
    $mfg = $db->prepare("SELECT * FROM cert_mfg_data WHERE so_line_item_id = ?");
    $mfg->execute([$line_id]);
    $mfg_data = $mfg->fetch();
    
    $heat = $mfg_data ? json_decode($mfg_data['heat_numbers'] ?? '{}', true) : [];
    $serial_raw = $mfg_data ? json_decode($mfg_data['serial_entries'] ?? '{}', true)['raw'] ?? '' : '';
    $compliance = $mfg_data ? json_decode($mfg_data['compliance_checks'] ?? '{}', true) : [];
    
    // Get selections for pre-fill
    $sels = $db->prepare("
        SELECT a.name as attr_name, ao.display_label, ao.value as opt_value
        FROM so_line_selections s
        JOIN attributes a ON s.attribute_id = a.id
        JOIN attribute_options ao ON s.option_id = ao.id
        WHERE s.so_line_item_id = ?
        ORDER BY a.position_in_model
    ");
    $sels->execute([$line_id]);
    $selections = $sels->fetchAll();
    
    // Get generated certificates
    $certs = $db->prepare("SELECT * FROM generated_certificates WHERE so_line_item_id = ? ORDER BY generated_at DESC");
    $certs->execute([$line_id]);
    $gen_certs = $certs->fetchAll();
    ?>
    
    <h2>Manufacturing Data Entry <span class="badge" style="background:var(--red-bg);color:var(--red);">MFG / QC</span></h2>
    <div class="gap-row mb-2">
        <a href="certificates.php" class="btn btn-outline btn-sm">← Back to Certificates</a>
        <span class="tag tag-blue"><?= sanitize($line_data['so_number']) ?></span>
        <span class="tag tag-purple">Line <?= $line_data['line_number'] ?></span>
        <span class="tag tag-cyan mono"><?= sanitize($line_data['model_code_string'] ?? '—') ?></span>
        <span class="tag tag-green">Qty: <?= $line_data['quantity'] ?></span>
    </div>
    
    <div class="callout callout-info">
        <strong>1 certificate per line item.</strong> Serial numbers can be ranges or individual values. Testing parameters are compliance confirmations (Yes/No).
    </div>
    
    <!-- PRE-FILLED DATA -->
    <div class="panel">
        <div class="panel-header"><span class="panel-title">Pre-filled from Model Code & SO (read-only)</span></div>
        <div class="panel-body">
            <div class="info-grid">
                <div><span class="label">Customer:</span> <?= sanitize($line_data['customer_name']) ?></div>
                <div><span class="label">PO:</span> <?= sanitize($line_data['po_reference'] ?? '—') ?></div>
                <div><span class="label">Quantity:</span> <?= $line_data['quantity'] ?></div>
                <?php foreach ($selections as $s): ?>
                <div><span class="label"><?= sanitize($s['attr_name']) ?>:</span> <?= sanitize($s['display_label'] ?: $s['opt_value']) ?></div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
    <!-- MFG DATA ENTRY FORM -->
    <div class="panel">
        <div class="panel-header"><span class="panel-title">Manufacturing & QC Data</span></div>
        <div class="panel-body">
            <form method="POST" action="">
                <input type="hidden" name="action" value="save_mfg_data">
                <input type="hidden" name="so_line_item_id" value="<?= $line_id ?>">
                
                <div class="form-label mb-1">SERIAL NUMBERS</div>
                <div class="callout callout-tip mb-2" style="font-size:0.8rem;">
                    Enter as: single range <code>1007–1024</code>, multiple ranges <code>1007–1015, 1020–1028</code>, or mixed.
                </div>
                <div class="form-group">
                    <textarea name="serial_entries" class="form-input form-textarea" rows="3" placeholder="1007-1015, 1020, 1023, 1025, 1030-1034"><?= sanitize($serial_raw) ?></textarea>
                </div>
                
                <div class="divider"></div>
                
                <div class="form-label mb-1">HEAT NUMBERS</div>
                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Heat No. — Body</label>
                        <input type="text" name="heat_body" class="form-input" value="<?= sanitize($heat['body'] ?? '') ?>" placeholder="H4521A">
                    </div>
                    <div class="form-field">
                        <label class="form-label">Heat No. — Trim</label>
                        <input type="text" name="heat_trim" class="form-input" value="<?= sanitize($heat['trim'] ?? '') ?>" placeholder="T7890B">
                    </div>
                    <div class="form-field">
                        <label class="form-label">Heat No. — Seat</label>
                        <input type="text" name="heat_seat" class="form-input" value="<?= sanitize($heat['seat'] ?? '') ?>" placeholder="S2210C">
                    </div>
                </div>
                
                <div class="divider"></div>
                
                <div class="form-label mb-1">TESTING & COMPLIANCE PARAMETERS</div>
                <table class="data-table">
                    <thead><tr><th>Test / Inspection</th><th>Standard</th><th>Performed?</th></tr></thead>
                    <tbody>
                        <?php
                        $checks = [
                            'shell_test' => ['Hydrostatic Shell Test', 'API 598 / BS EN 12266-1'],
                            'seat_test' => ['Hydrostatic Seat Test', 'API 598 / BS EN 12266-1'],
                            'visual' => ['Visual Inspection', 'MSS SP-55'],
                            'dimensional' => ['Dimensional Inspection', 'ASME B16.10 / B16.5'],
                            'material_cert' => ['Material Certificate Verified', 'EN 10204 3.1'],
                            'marking' => ['Marking & Tagging', 'MSS SP-25'],
                        ];
                        foreach ($checks as $key => $info):
                            $checked = $compliance[$key] ?? false;
                        ?>
                        <tr>
                            <td><?= $info[0] ?></td>
                            <td class="mono" style="font-size:0.72rem;"><?= $info[1] ?></td>
                            <td>
                                <label class="form-checkbox">
                                    <input type="hidden" name="check_<?= $key ?>" value="0">
                                    <input type="checkbox" name="check_<?= $key ?>" value="1" <?= $checked ? 'checked' : '' ?>> Yes
                                </label>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <div class="divider"></div>
                
                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Inspector Name</label>
                        <input type="text" name="inspector_name" class="form-input" value="<?= sanitize($mfg_data['inspector_name'] ?? '') ?>">
                    </div>
                    <div class="form-field">
                        <label class="form-label">Inspection Date</label>
                        <input type="date" name="inspection_date" class="form-input" value="<?= $mfg_data['inspection_date'] ?? '' ?>">
                    </div>
                </div>
                
                <div class="btn-group mt-2">
                    <button type="submit" class="btn btn-primary">💾 Save Manufacturing Data</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- GENERATE CERTIFICATE -->
    <?php if ($mfg_data && $mfg_data['is_complete']): ?>
    <form method="POST" action="">
        <input type="hidden" name="action" value="generate_certificate">
        <input type="hidden" name="so_line_item_id" value="<?= $line_id ?>">
        <button type="submit" class="btn btn-success btn-lg mt-2">📊 Generate Certificate</button>
    </form>
    <?php endif; ?>
    
    <!-- GENERATED CERTIFICATES -->
    <?php if (!empty($gen_certs)): ?>
    <h3 class="mt-3">Generated Certificates</h3>
    <div class="panel">
        <div class="panel-body" style="padding:0;">
            <table class="data-table">
                <thead><tr><th>Certificate No.</th><th>Inspector</th><th>Date</th><th>Status</th><th>Generated</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($gen_certs as $gc): ?>
                    <tr>
                        <td class="mono text-orange"><?= sanitize($gc['certificate_number']) ?></td>
                        <td><?= sanitize($gc['inspector_name'] ?? '') ?></td>
                        <td><?= $gc['inspection_date'] ?? '—' ?></td>
                        <td><span class="tag tag-green"><?= ucfirst($gc['status']) ?></span></td>
                        <td style="font-size:0.75rem;"><?= date('M d, Y H:i', strtotime($gc['generated_at'])) ?></td>
                        <td><button class="btn btn-outline btn-sm">Download</button></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

<?php } else { ?>
    <!-- LIST VIEW: All line items needing certificates -->
    <h2>Test & Guarantee Certificates <span class="badge" style="background:var(--orange-bg);color:var(--orange);">XLSX</span></h2>
    <p>Manage manufacturing data entry and generate test certificates for SO line items.</p>
    
    <?php
    $lines = $db->query("
        SELECT sli.*, so.so_number, so.customer_name, pc.code as product_code,
               cmd.is_complete as mfg_complete,
               gc.certificate_number, gc.status as cert_status
        FROM so_line_items sli
        JOIN sales_orders so ON sli.sales_order_id = so.id
        JOIN product_codes pc ON sli.product_code_id = pc.id
        LEFT JOIN cert_mfg_data cmd ON sli.id = cmd.so_line_item_id
        LEFT JOIN generated_certificates gc ON sli.id = gc.so_line_item_id
        WHERE sli.status IN ('resolved', 'generated', 'approved')
        ORDER BY so.so_number, sli.line_number
    ")->fetchAll();
    ?>
    
    <div class="panel">
        <div class="panel-header">
            <span class="panel-title">Line Items for Certificate Processing</span>
            <span class="tag tag-blue"><?= count($lines) ?> items</span>
        </div>
        <div class="panel-body" style="padding:0;">
            <table class="data-table">
                <thead>
                    <tr><th>SO</th><th>Line</th><th>Product</th><th>Model Code</th><th>Qty</th><th>Mfg Data</th><th>Certificate</th><th></th></tr>
                </thead>
                <tbody>
                    <?php if (empty($lines)): ?>
                    <tr><td colspan="8" class="text-center text-dim" style="padding:2rem;">No resolved line items yet.</td></tr>
                    <?php else: ?>
                    <?php foreach ($lines as $l): ?>
                    <tr>
                        <td class="mono text-accent"><?= sanitize($l['so_number']) ?></td>
                        <td><?= $l['line_number'] ?></td>
                        <td class="mono"><?= sanitize($l['product_code']) ?></td>
                        <td class="mono"><?= sanitize($l['model_code_string'] ?? '—') ?></td>
                        <td><?= $l['quantity'] ?></td>
                        <td>
                            <?php if ($l['mfg_complete']): ?>
                                <span class="tag tag-green">✓ Complete</span>
                            <?php elseif ($l['mfg_complete'] === 0): ?>
                                <span class="tag tag-orange">In Progress</span>
                            <?php else: ?>
                                <span class="tag tag-red">Not Started</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($l['certificate_number']): ?>
                                <span class="tag tag-green"><?= sanitize($l['certificate_number']) ?></span>
                            <?php else: ?>
                                <span class="tag tag-dim">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="certificates.php?line_id=<?= $l['id'] ?>" class="btn btn-outline btn-sm">
                                <?= $l['certificate_number'] ? 'View' : 'Enter Data' ?>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php } ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
