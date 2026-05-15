<?php
/**
 * End-to-end wiring demo for 136ULT engg_individual:
 *   1. Generate combinations for output_type 'engg_individual'
 *      (Cartesian product of every option of every affects_engg_dwg attr)
 *   2. Create a placeholder DXF template file + templates row
 *   3. Assign the template to EVERY combination via combination_template_map
 *   4. Run Generate Documents on SO-2026-TEST-001
 *   5. Print pass/fail per line
 *
 * After this runs, opening pages/sales_order_detail.php for the SO and
 * clicking "Generate Documents" again will show NO "unmapped" warnings
 * for engg_individual on 136ULT lines.
 *
 * Each step is idempotent.
 *
 * Pre-req: PHP built-in server at http://127.0.0.1:8765 serving the repo.
 */
require_once __DIR__ . '/../config/database.php';

$HOST = getenv('PHP_TEST_HOST') ?: 'http://127.0.0.1:8765';
$db   = getDB();

echo "============================================================\n";
echo "  WIRE-UP DEMO: 136ULT engg_individual\n";
echo "============================================================\n";

// ─── 1. Generate combinations via the page handler ────────────────────────
$prod = $db->query("SELECT id, code FROM product_codes WHERE code='136ULT'")->fetch();
if (!$prod) { fwrite(STDERR, "136ULT not seeded\n"); exit(1); }
$pid = $prod['id'];

$attrs = $db->prepare("SELECT id, code FROM attributes WHERE product_code_id = ? AND is_active = 1 AND affects_engg_dwg = 1 ORDER BY position_in_model");
$attrs->execute([$pid]);
$flagged = $attrs->fetchAll();
echo "Attributes with affects_engg_dwg=1: " . implode(', ', array_column($flagged, 'code')) . "\n";

$ctx = stream_context_create(['http' => [
    'method' => 'POST',
    'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
    'content' => http_build_query(['action' => 'generate_combinations', 'output_type' => 'engg_individual']),
    'ignore_errors' => true, 'follow_location' => 0,
]]);
@file_get_contents("$HOST/pages/combinations.php?product_id=$pid&output_type=engg_individual", false, $ctx);
echo "[1] generate_combinations POST: " . ($http_response_header[0] ?? '?') . "\n";

$combos = $db->prepare("SELECT id, combination_key FROM combination_matrix WHERE product_code_id = ? AND output_type = 'engg_individual' AND is_active = 1 ORDER BY combination_key");
$combos->execute([$pid]);
$comboRows = $combos->fetchAll();
echo "    combination_matrix rows now: " . count($comboRows) . "\n";
foreach ($comboRows as $c) echo "      key=\"{$c['combination_key']}\"  id={$c['id']}\n";

if (empty($comboRows)) {
    fwrite(STDERR, "No combinations were created. Check that at least one attribute has affects_engg_dwg=1.\n");
    exit(1);
}

// ─── 2. Create placeholder DXF template file + templates row ───────────────
$tplDir = UPLOAD_PATH . 'templates/' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $pid) . '/';
if (!is_dir($tplDir)) @mkdir($tplDir, 0755, true);
$tplCode = '136ULT-ENGG-DEMO';
$tplPath = $tplDir . $tplCode . '.dxf';

if (!is_file($tplPath)) {
    // A minimal valid empty DXF (R12). The DWGTemplateProcessor will treat this
    // as the template and append the order/dim tables to it.
    $minimalDxf = "  0\nSECTION\n  2\nHEADER\n  0\nENDSEC\n"
                . "  0\nSECTION\n  2\nENTITIES\n  0\nTEXT\n  8\n0\n 10\n10.0\n 20\n280.0\n 30\n0.0\n 40\n5.0\n  1\n<<MODEL_CODE>>\n  0\nENDSEC\n"
                . "  0\nEOF\n";
    file_put_contents($tplPath, $minimalDxf);
}
echo "[2] template file: $tplPath  (exists=" . (is_file($tplPath) ? 'yes' : 'no') . ")\n";

$existingT = $db->prepare("SELECT id FROM templates WHERE product_code_id = ? AND template_code = ? AND template_type = 'engg_individual'");
$existingT->execute([$pid, $tplCode]);
$tRow = $existingT->fetch();
if ($tRow) {
    $tplId = $tRow['id'];
} else {
    $tplId = generateUUID();
    $relPath = 'templates/' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $pid) . '/' . $tplCode . '.dxf';
    $db->prepare("INSERT INTO templates (id, product_code_id, template_type, template_code, file_path, file_format, is_active) VALUES (?, ?, 'engg_individual', ?, ?, 'dxf', 1)")
       ->execute([$tplId, $pid, $tplCode, $relPath]);
}
echo "    templates row id: $tplId\n";

// ─── 3. Map every combination to that template ────────────────────────────
$mapped = 0;
foreach ($comboRows as $c) {
    $exists = $db->prepare("SELECT id FROM combination_template_map WHERE combination_id = ?");
    $exists->execute([$c['id']]);
    if ($exists->fetch()) { continue; }
    $db->prepare("INSERT INTO combination_template_map (id, combination_id, template_id) VALUES (NEWID(), ?, ?)")
       ->execute([$c['id'], $tplId]);
    $mapped++;
}
echo "[3] combination_template_map: $mapped new mapping(s) inserted\n";

// ─── 4. Run Generate Documents on SO-2026-TEST-001 ────────────────────────
$so = $db->query("SELECT id, so_number FROM sales_orders WHERE so_number = 'SO-2026-TEST-001'")->fetch();
if (!$so) { fwrite(STDERR, "SO-2026-TEST-001 not present — run sql/run_full_flow.php first.\n"); exit(1); }

$ctx2 = stream_context_create(['http' => [
    'method' => 'POST',
    'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
    'content' => http_build_query(['action' => 'generate_documents']),
    'ignore_errors' => true, 'follow_location' => 0,
]]);
@file_get_contents("$HOST/pages/sales_order_detail.php?id={$so['id']}", false, $ctx2);
echo "[4] generate_documents POST: " . ($http_response_header[0] ?? '?') . "\n";

// Read back the freshly generated docs and confirm none are tagged unmapped.
$docs = $db->prepare("
    SELECT gd.document_type, gd.injected_values, gd.output_file_path,
           sli.line_number, sli.model_code_string, t.template_code
    FROM generated_documents gd
    JOIN so_line_items sli ON gd.so_line_item_id = sli.id
    LEFT JOIN templates t  ON gd.template_id = t.id
    WHERE sli.sales_order_id = ?
      AND gd.document_type IN ('engg_individual','internal_individual')
    ORDER BY sli.line_number, gd.document_type
");
$docs->execute([$so['id']]);
echo "\n[5] Generated documents:\n";
$unmappedHits = 0;
foreach ($docs->fetchAll() as $d) {
    $iv = json_decode($d['injected_values'], true) ?: [];
    $isUnmapped = !empty($iv['unmapped']);
    $flag = $isUnmapped ? 'UNMAPPED ✗' : 'mapped ✓';
    if ($isUnmapped) $unmappedHits++;
    echo "  L{$d['line_number']}  {$d['document_type']}  template={$d['template_code']}  $flag\n";
    if ($isUnmapped) echo "       reason: " . ($iv['unmapped_reason'] ?? '?') . "\n";
}

echo "\n";
if ($unmappedHits === 0) {
    echo "============================================================\n";
    echo "  SUCCESS — every engg_individual doc is now properly mapped\n";
    echo "============================================================\n";
} else {
    echo "============================================================\n";
    echo "  STILL $unmappedHits unmapped — check internal_individual flags / combos\n";
    echo "============================================================\n";
}
