<?php
$pageTitle = 'Template Manager';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/DWGTemplateProcessor.php';

$db = getDB();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'upload_template') {
        $product_code_id = $_POST['product_code_id'] ?? '';
        $template_type = $_POST['template_type'] ?? '';
        $template_code = trim($_POST['template_code'] ?? '');
        $file_format = $_POST['file_format'] ?? 'dwg';
        
        if ($product_code_id && $template_type && $template_code) {
            $file_path = null;
            
            // Handle file upload
            if (isset($_FILES['template_file']) && $_FILES['template_file']['error'] === UPLOAD_ERR_OK) {
                $allowed_ext = ['dwg', 'dxf', 'xlsx'];
                $ext = strtolower(pathinfo($_FILES['template_file']['name'], PATHINFO_EXTENSION));
                
                if (in_array($ext, $allowed_ext)) {
                    // SECURITY: sanitize both path segments. $template_code came
                    // from POST and was used as a filename — path traversal risk.
                    $safe_product_dir = preg_replace('/[^A-Za-z0-9_\-]/', '_', $product_code_id);
                    $safe_template_code = preg_replace('/[^A-Za-z0-9_\-]/', '_', $template_code);
                    if ($safe_template_code === '') $safe_template_code = 'template_' . bin2hex(random_bytes(4));

                    $upload_dir = TEMPLATE_PATH . $safe_product_dir . '/';
                    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

                    $filename = $safe_template_code . '.' . $ext;
                    $file_path = 'templates/' . $safe_product_dir . '/' . $filename;
                    move_uploaded_file($_FILES['template_file']['tmp_name'], $upload_dir . $filename);
                    $file_format = $ext;

                    // For DWG: pre-convert to DXF and report which placeholders are present.
                    $placeholderReport = '';
                    if ($ext === 'dwg') {
                        try {
                            $proc = new DWGTemplateProcessor();
                            if ($proc->isAvailable()) {
                                $dxf = $proc->dwgToDxf($upload_dir . $filename);
                                file_put_contents($upload_dir . $template_code . '.dxf', $dxf);
                                $found = [];
                                foreach (['<<CUSTOMER>>','<<PROJECT>>','<<SO_NO>>','<<PO_REF>>',
                                          '<<MODEL_CODE>>','<<PRODUCT>>','<<PRODUCT_NAME>>',
                                          '<<DATE>>','<<DELIVERY>>','<<QTY>>','<<DRG_NO>>',
                                          '<<MODEL_CODE_TABLE>>','<<DIM_TABLE>>'] as $tag) {
                                    if (strpos($dxf, $tag) !== false) $found[] = $tag;
                                }
                                $placeholderReport = empty($found)
                                    ? ' No placeholders detected — title block will not be auto-filled. Add <<CUSTOMER>>, <<SO_NO>>, etc. to the template.'
                                    : ' Detected placeholders: ' . implode(', ', $found);
                            } else {
                                $placeholderReport = ' (ODA File Converter not found — DWG cannot be auto-processed)';
                            }
                        } catch (Throwable $e) {
                            $placeholderReport = ' (DWG pre-conversion failed: ' . $e->getMessage() . ')';
                        }
                    }
                } else {
                    setFlash('error', 'Invalid file type. Allowed: .dwg, .dxf, .xlsx');
                    header('Location: templates.php');
                    exit;
                }
            }
            
            try {
                $stmt = $db->prepare("INSERT INTO templates (id, product_code_id, template_type, template_code, file_path, file_format) VALUES (NEWID(), ?, ?, ?, ?, ?)");
                $stmt->execute([$product_code_id, $template_type, $template_code, $file_path, $file_format]);
                setFlash('success', "Template '$template_code' created." . ($placeholderReport ?? ''));
            } catch (PDOException $e) {
                setFlash('error', "Error: " . $e->getMessage());
            }
        } else {
            setFlash('error', 'All fields are required.');
        }
        header('Location: templates.php');
        exit;
    }
}

// Fetch all products
$products = $db->query("SELECT id, code, name FROM product_codes WHERE status != 'archived' ORDER BY code")->fetchAll();

// Fetch templates
$filter_product = $_GET['product_id'] ?? '';
$sql = "SELECT t.*, pc.code as product_code, pc.name as product_name FROM templates t JOIN product_codes pc ON t.product_code_id = pc.id WHERE t.is_active = 1";
$params = [];
if ($filter_product) {
    $sql .= " AND t.product_code_id = ?";
    $params[] = $filter_product;
}
$sql .= " ORDER BY pc.code, t.template_type, t.template_code";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$templates = $stmt->fetchAll();
?>

<h2>Template Manager <span class="badge" style="background:var(--purple-bg);color:var(--purple);">ADMIN</span></h2>
<p>Upload and manage DWG/DXF/XLSX templates. Map templates to combination matrix entries for auto-generation.</p>

<div class="callout" style="background:var(--cyan-bg);border-left:4px solid var(--cyan);padding:0.75rem 1rem;margin:1rem 0;font-size:0.85rem;">
    <strong>Placeholder convention</strong> — to enable in-place data injection, the template's title block must contain literal TEXT entities with these tags:
    <code>&lt;&lt;CUSTOMER&gt;&gt;</code>, <code>&lt;&lt;PROJECT&gt;&gt;</code>, <code>&lt;&lt;SO_NO&gt;&gt;</code>, <code>&lt;&lt;PO_REF&gt;&gt;</code>,
    <code>&lt;&lt;MODEL_CODE&gt;&gt;</code>, <code>&lt;&lt;DATE&gt;&gt;</code>, <code>&lt;&lt;DELIVERY&gt;&gt;</code>,
    <code>&lt;&lt;QTY&gt;&gt;</code>, <code>&lt;&lt;PRODUCT&gt;&gt;</code>, <code>&lt;&lt;PRODUCT_NAME&gt;&gt;</code>, <code>&lt;&lt;DRG_NO&gt;&gt;</code>.
    <br>For the two tables, place a single TEXT entity at the top-left of each:
    <code>&lt;&lt;MODEL_CODE_TABLE&gt;&gt;</code> and <code>&lt;&lt;DIM_TABLE&gt;&gt;</code>. Templates without these tags still upload but will fall back to the from-scratch generator.
</div>

<!-- UPLOAD FORM -->
<div class="panel">
    <div class="panel-header">
        <span class="panel-title">Upload New Template</span>
    </div>
    <div class="panel-body">
        <form method="POST" action="" enctype="multipart/form-data">
            <input type="hidden" name="action" value="upload_template">
            <div class="form-row">
                <div class="form-field">
                    <label class="form-label">Template Type</label>
                    <select name="template_type" class="form-select" required>
                        <option value="">Select Type</option>
                        <option value="engg_individual">Engg Drawing — Individual</option>
                        <option value="engg_combined">Engg Drawing — Combined</option>
                        <option value="internal_individual">Internal Drawing — Individual</option>
                        <option value="internal_combined">Internal Drawing — Combined</option>
                        <option value="test_cert">Test Certificate (XLSX)</option>
                    </select>
                </div>
                <div class="form-field">
                    <label class="form-label">Product Code</label>
                    <select name="product_code_id" class="form-select" required>
                        <option value="">Select Product</option>
                        <?php foreach ($products as $p): ?>
                        <option value="<?= $p['id'] ?>"><?= sanitize($p['code']) ?> — <?= sanitize($p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-field">
                    <label class="form-label">Template Code</label>
                    <input type="text" name="template_code" class="form-input" placeholder="GVF-ED-001" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-field">
                    <label class="form-label">Upload File (.dwg / .dxf / .xlsx)</label>
                    <input type="file" name="template_file" class="form-input" style="padding:0.4rem;" accept=".dwg,.dxf,.xlsx">
                </div>
                <div class="form-field">
                    <label class="form-label">File Format</label>
                    <select name="file_format" class="form-select">
                        <option value="dwg">DWG</option>
                        <option value="dxf">DXF</option>
                        <option value="xlsx">XLSX</option>
                    </select>
                </div>
                <div class="form-field" style="flex:0 0 auto;">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary">📄 Upload Template</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="callout callout-info">
    <strong>5 template types:</strong> Engg Individual, Engg Combined, Internal Individual, Internal Combined, Test Certificate (XLSX). 
    DWG templates contain only geometry, title block, and borders — tables are dynamically generated and inserted at anchor points.
</div>

<!-- FILTER -->
<div class="gap-row mb-2">
    <span class="form-label" style="margin:0;">Filter by Product:</span>
    <a href="templates.php" class="btn btn-sm <?= !$filter_product ? 'btn-primary' : 'btn-outline' ?>">All</a>
    <?php foreach ($products as $p): ?>
    <a href="templates.php?product_id=<?= $p['id'] ?>" class="btn btn-sm <?= $filter_product === $p['id'] ? 'btn-primary' : 'btn-outline' ?>"><?= sanitize($p['code']) ?></a>
    <?php endforeach; ?>
</div>

<!-- TEMPLATES TABLE -->
<div class="panel">
    <div class="panel-header">
        <span class="panel-title">All Templates</span>
        <span class="tag tag-blue"><?= count($templates) ?> templates</span>
    </div>
    <div class="panel-body" style="padding:0;">
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Template Code</th>
                        <th>Product</th>
                        <th>Type</th>
                        <th>Format</th>
                        <th>Version</th>
                        <th>File</th>
                        <th>Uploaded</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($templates)): ?>
                    <tr><td colspan="8" class="text-center text-dim" style="padding:2rem;">No templates uploaded yet.</td></tr>
                    <?php else: ?>
                    <?php foreach ($templates as $t): ?>
                    <tr>
                        <td class="mono text-cyan"><?= sanitize($t['template_code']) ?></td>
                        <td class="mono text-accent"><?= sanitize($t['product_code']) ?></td>
                        <td>
                            <?php
                            $typeInfo = match($t['template_type']) {
                                'engg_individual' => ['tag-blue', 'Engg Individual'],
                                'engg_combined' => ['tag-purple', 'Engg Combined'],
                                'internal_individual' => ['tag-cyan', 'Internal Individual'],
                                'internal_combined' => ['tag-purple', 'Internal Combined'],
                                'test_cert' => ['tag-orange', 'Test Cert'],
                                default => ['tag-dim', $t['template_type']]
                            };
                            ?>
                            <span class="tag <?= $typeInfo[0] ?>"><?= $typeInfo[1] ?></span>
                        </td>
                        <td class="mono">.<?= sanitize($t['file_format'] ?? '') ?></td>
                        <td>v<?= $t['version'] ?></td>
                        <td style="font-size:0.75rem;"><?= $t['file_path'] ? sanitize($t['file_path']) : '<span class="text-dim">No file</span>' ?></td>
                        <td style="font-size:0.75rem;"><?= date('M d, Y', strtotime($t['uploaded_at'])) ?></td>
                        <td>
                            <a href="template_fields.php?template_id=<?= $t['id'] ?>" class="btn btn-outline btn-sm">Field Map</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
