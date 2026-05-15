<?php
/**
 * End-to-end test that template_field_mappings actually flow into the DXF.
 *
 * Setup:
 *   - Find 136ULT
 *   - Make sure there's a templates row pointing at a DXF file that contains
 *     a CUSTOM placeholder (<<MEASURING_RANGE>>) plus a standard one (<<MODEL_CODE>>)
 *   - Add a template_field_mappings row: <<MEASURING_RANGE>> -> attribute_option / MR
 *
 * Action:
 *   - Hit the existing SO-2026-TEST-001 line 1 via api/download.php which calls
 *     the processor and emits a new DXF
 *
 * Verify:
 *   - The produced DXF contains the resolved value for MR (e.g. "8 m")
 *   - The produced DXF does NOT contain the literal "<<MEASURING_RANGE>>" tag
 *   - The standard <<MODEL_CODE>> was also substituted with the real model code
 */
require_once __DIR__ . '/../config/database.php';

$HOST = getenv('PHP_TEST_HOST') ?: 'http://127.0.0.1:8765';
$db   = getDB();
$failed = 0;
$ok   = function (string $m) { echo "  [PASS] $m\n"; };
$fail = function (string $m) use (&$failed) { echo "  [FAIL] $m\n"; $failed++; };

echo "============================================================\n";
echo "  TEMPLATE FIELD MAPPINGS — E2E TEST\n";
echo "============================================================\n";

$prod = $db->query("SELECT id, code FROM product_codes WHERE code='136ULT'")->fetch();
if (!$prod) { fwrite(STDERR, "136ULT not seeded\n"); exit(1); }
$pid = $prod['id'];

// ─── 1. Write a template file with both a standard and a custom placeholder ──
$tplDir = UPLOAD_PATH . 'templates/' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $pid) . '/';
if (!is_dir($tplDir)) @mkdir($tplDir, 0755, true);
$tplCode = '136ULT-FIELDMAP-TEST';
$tplPath = $tplDir . $tplCode . '.dxf';

// Minimal valid R12 DXF with two TEXT entities containing our placeholders.
$dxf = "  0\nSECTION\n  2\nHEADER\n  0\nENDSEC\n"
     . "  0\nSECTION\n  2\nENTITIES\n"
     . "  0\nTEXT\n  8\n0\n 10\n10.0\n 20\n200.0\n 30\n0.0\n 40\n5.0\n  1\n<<MODEL_CODE>>\n"
     . "  0\nTEXT\n  8\n0\n 10\n10.0\n 20\n180.0\n 30\n0.0\n 40\n5.0\n  1\n<<MEASURING_RANGE>>\n"
     . "  0\nTEXT\n  8\n0\n 10\n10.0\n 20\n160.0\n 30\n0.0\n 40\n5.0\n  1\n<<DIM_L>>\n"
     . "  0\nTEXT\n  8\n0\n 10\n10.0\n 20\n140.0\n 30\n0.0\n 40\n5.0\n  1\n<<DRAWN_BY>>\n"
     . "  0\nENDSEC\n  0\nEOF\n";
file_put_contents($tplPath, $dxf);
echo "Wrote template: $tplPath\n";

// ─── 2. Insert or reuse the templates row (template_type=internal_individual
//        so the mapping path differs from engg) ────────────────────────────
$relPath = 'templates/' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $pid) . '/' . $tplCode . '.dxf';
$existing = $db->prepare("SELECT id FROM templates WHERE product_code_id = ? AND template_code = ?");
$existing->execute([$pid, $tplCode]);
$row = $existing->fetch();
if ($row) {
    $tplId = $row['id'];
    $db->prepare("UPDATE templates SET file_path = ?, file_format = 'dxf', is_active = 1, template_type = 'engg_individual' WHERE id = ?")
       ->execute([$relPath, $tplId]);
} else {
    $tplId = generateUUID();
    $db->prepare("INSERT INTO templates (id, product_code_id, template_type, template_code, file_path, file_format, is_active) VALUES (?, ?, 'engg_individual', ?, ?, 'dxf', 1)")
       ->execute([$tplId, $pid, $tplCode, $relPath]);
}
echo "templates id: $tplId\n";

// ─── 3. Bind this template to every engg_individual combination so it actually
//        gets picked when we generate. (wire_up_demo.php may have already
//        mapped a different template; we overwrite to use ours.) ───────────
$combos = $db->prepare("SELECT id FROM combination_matrix WHERE product_code_id = ? AND output_type = 'engg_individual' AND is_active = 1");
$combos->execute([$pid]);
$comboRows = $combos->fetchAll();
foreach ($comboRows as $c) {
    $db->prepare("DELETE FROM combination_template_map WHERE combination_id = ?")->execute([$c['id']]);
    $db->prepare("INSERT INTO combination_template_map (id, combination_id, template_id) VALUES (NEWID(), ?, ?)")
       ->execute([$c['id'], $tplId]);
}
echo "Re-bound " . count($comboRows) . " combinations to template $tplCode\n";

// ─── 4. Insert template_field_mappings rows for the custom placeholders ────
$db->prepare("DELETE FROM template_field_mappings WHERE template_id = ?")->execute([$tplId]);
$mappings = [
    ['<<MEASURING_RANGE>>', 'attribute_option', 'MR', ''],
    ['<<DIM_L>>',           'dimension_modifier', 'L', '999'],   // 999 = obvious default if missing
    ['<<DRAWN_BY>>',        'static',           '',  'SBEM-CAD'],
];
foreach ($mappings as $m) {
    $db->prepare("INSERT INTO template_field_mappings (id, template_id, dwg_field_name, source_type, source_key, is_editable, default_value) VALUES (NEWID(), ?, ?, ?, ?, 0, ?)")
       ->execute([$tplId, $m[0], $m[1], $m[2], $m[3]]);
}
echo "Inserted " . count($mappings) . " field mappings\n";

// ─── 5. Find an SO line so we can drive a real generation ─────────────────
$line = $db->query("SELECT TOP 1 sli.id, sli.line_number, sli.model_code_string, sli.sales_order_id FROM so_line_items sli JOIN sales_orders so ON sli.sales_order_id = so.id WHERE so.so_number = 'SO-2026-TEST-001' AND sli.line_number = 1")->fetch();
if (!$line) { fwrite(STDERR, "SO-2026-TEST-001 line 1 not present — run sql/run_full_flow.php\n"); exit(1); }
echo "Test line: line {$line['line_number']}  model={$line['model_code_string']}\n";

// We need a generated_documents row for this line + engg_individual that
// points at our template. Trigger the page handler to rebuild it.
$ctx = stream_context_create(['http' => [
    'method' => 'POST',
    'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
    'content' => http_build_query(['action' => 'generate_documents']),
    'ignore_errors' => true, 'follow_location' => 0,
]]);
@file_get_contents("$HOST/pages/sales_order_detail.php?id={$line['sales_order_id']}", false, $ctx);
echo "generate_documents POST: " . ($http_response_header[0] ?? '?') . "\n";

// ─── 6. Read the produced engg_individual file for line 1 ─────────────────
$doc = $db->prepare("SELECT output_file_path, template_id FROM generated_documents WHERE so_line_item_id = ? AND document_type = 'engg_individual'");
$doc->execute([$line['id']]);
$d = $doc->fetch();
if (!$d) { $fail("no engg_individual doc generated"); }
else {
    echo "Doc file: {$d['output_file_path']}\n";
    if (strcasecmp($d['template_id'], $tplId) !== 0) {
        $fail("doc was generated with a DIFFERENT template ({$d['template_id']}), expected $tplId");
    } else {
        $ok("doc generated with our test template");
    }

    if (!is_file($d['output_file_path'])) {
        $fail("output file missing on disk");
    } else {
        $body = file_get_contents($d['output_file_path']);

        // Standard placeholder substitution check
        (strpos($body, '<<MODEL_CODE>>') === false)
            ? $ok("standard <<MODEL_CODE>> was substituted (not present in output)")
            : $fail("<<MODEL_CODE>> still literal in output");
        (strpos($body, $line['model_code_string']) !== false)
            ? $ok("model code string '{$line['model_code_string']}' appears in output")
            : $fail("model code string missing from output");

        // Custom mapping checks
        (strpos($body, '<<MEASURING_RANGE>>') === false)
            ? $ok("<<MEASURING_RANGE>> tag was substituted (not present in output)")
            : $fail("<<MEASURING_RANGE>> still literal in output");

        // For line 1, MR option is '08' which seed value is '08 m' / display '8 m'.
        // Either form should appear; check both.
        $mrFound = (strpos($body, '8 m') !== false) || (strpos($body, '08 m') !== false);
        $mrFound
            ? $ok("attribute_option MR value resolved into output")
            : $fail("MR resolved value not found in output (looked for '8 m' or '08 m')");

        (strpos($body, '<<DIM_L>>') === false)
            ? $ok("<<DIM_L>> tag was substituted")
            : $fail("<<DIM_L>> still literal in output");

        // dimension_modifier L isn't seeded on any option for the seed; should fall
        // back to default_value=999.
        (strpos($body, '999') !== false)
            ? $ok("dimension_modifier L fell back to default_value 999")
            : $fail("dim modifier default 999 not present");

        (strpos($body, '<<DRAWN_BY>>') === false)
            ? $ok("<<DRAWN_BY>> tag was substituted")
            : $fail("<<DRAWN_BY>> still literal in output");

        (strpos($body, 'SBEM-CAD') !== false)
            ? $ok("static SBEM-CAD value appears in output")
            : $fail("static SBEM-CAD not present");
    }
}

echo "\n============================================================\n";
echo ($failed === 0 ? "  ALL FIELD-MAPPING ASSERTIONS PASSED\n" : "  $failed FAILURE(S)\n");
echo "============================================================\n";
exit($failed === 0 ? 0 : 1);
