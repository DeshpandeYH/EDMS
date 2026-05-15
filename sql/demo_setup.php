<?php
/**
 * One-shot demo setup. Run this once before showing the demo. It guarantees:
 *   - 136ULT exists with the seeded attributes
 *   - affects_engg_dwg flag set on PC + MR (so engg drawings differ by both)
 *   - 1 attribute (MR) is marked as the combined attribute
 *   - engg_individual + engg_combined combinations are generated
 *   - A demo template DXF is created with title-block + custom placeholders
 *   - The template is bound to every combination
 *   - template_field_mappings rows wire <<MEASURING_RANGE>>, <<INSPECTION_STD>>,
 *     <<DRAWN_BY>> to attribute_option / static sources
 *   - SO-2026-DEMO exists with 2 line items differing in MR only (so they
 *     auto-group into one combined drawing)
 *   - Lines marked resolved, ready to "Generate Documents"
 *
 * After running:
 *   open http://127.0.0.1:8765/pages/sales_order_detail.php?id=<SO_ID printed at end>
 *   or  http://localhost/edms/pages/sales_order_detail.php?id=<SO_ID>
 *
 * Idempotent — safe to re-run before each demo.
 */
require_once __DIR__ . '/../config/database.php';

$db = getDB();

echo "============================================================\n";
echo "  DEMO SETUP — building a clean, working scenario\n";
echo "============================================================\n";

// ─── Product ──────────────────────────────────────────────────────────────
$prod = $db->query("SELECT id FROM product_codes WHERE code='136ULT'")->fetch();
if (!$prod) { fwrite(STDERR, "Run sql/seed_data.php first.\n"); exit(1); }
$pid = $prod['id'];

// ─── Set flags on attributes ──────────────────────────────────────────────
// Make MR the "combined attribute" and flag PC + MR as affecting engg drawings.
$db->prepare("UPDATE attributes SET affects_engg_dwg = CASE WHEN code IN ('PC','MR') THEN 1 ELSE 0 END, is_combined_attribute = CASE WHEN code = 'MR' THEN 1 ELSE 0 END WHERE product_code_id = ?")->execute([$pid]);
$db->prepare("UPDATE product_codes SET supports_combined_dwg = 1 WHERE id = ?")->execute([$pid]);
$mrId = $db->prepare("SELECT id FROM attributes WHERE product_code_id = ? AND code = 'MR'");
$mrId->execute([$pid]); $mrId = $mrId->fetchColumn();
$db->prepare("UPDATE product_codes SET combined_attribute_id = ? WHERE id = ?")->execute([$mrId, $pid]);
echo "[1] Flags set: PC + MR affect engg drawing; MR is combined attribute.\n";

// ─── Wipe stale combinations and regenerate fresh ─────────────────────────
$staleCombos = $db->prepare("SELECT id FROM combination_matrix WHERE product_code_id = ? AND output_type IN ('engg_individual','engg_combined')");
$staleCombos->execute([$pid]);
foreach ($staleCombos->fetchAll() as $c) {
    $db->prepare("DELETE FROM combination_template_map WHERE combination_id = ?")->execute([$c['id']]);
    $db->prepare("DELETE FROM combination_options WHERE combination_id = ?")->execute([$c['id']]);
    $db->prepare("DELETE FROM combination_matrix WHERE id = ?")->execute([$c['id']]);
}

// Generate engg_individual = PC × MR (2 × 8 = 16 combinations)
$engg = $db->prepare("SELECT id, code FROM attributes WHERE product_code_id = ? AND affects_engg_dwg = 1 AND is_active = 1 ORDER BY position_in_model");
$engg->execute([$pid]);
$engAttrs = $engg->fetchAll();
$engCombos = [['key' => '', 'pairs' => []]];
foreach ($engAttrs as $a) {
    $opts = $db->prepare("SELECT id, code FROM attribute_options WHERE attribute_id = ? AND is_active = 1 ORDER BY sort_order");
    $opts->execute([$a['id']]);
    $optRows = $opts->fetchAll();
    $new = [];
    foreach ($engCombos as $c) {
        foreach ($optRows as $o) {
            $new[] = ['key' => $c['key'] === '' ? $o['code'] : $c['key'].'-'.$o['code'], 'pairs' => [...$c['pairs'], ['attr_id'=>$a['id'],'option_id'=>$o['id']]]];
        }
    }
    $engCombos = $new;
}
foreach ($engCombos as $c) {
    $cid = generateUUID();
    $db->prepare("INSERT INTO combination_matrix (id, product_code_id, output_type, combination_key, combination_hash) VALUES (?, ?, 'engg_individual', ?, ?)")
       ->execute([$cid, $pid, $c['key'], hash('sha256', $pid.'|engg_individual|'.$c['key'])]);
    foreach ($c['pairs'] as $p) {
        $db->prepare("INSERT INTO combination_options (id, combination_id, attribute_id, option_id) VALUES (NEWID(), ?, ?, ?)")
           ->execute([$cid, $p['attr_id'], $p['option_id']]);
    }
}
echo "[2] Generated " . count($engCombos) . " engg_individual combinations.\n";

// engg_combined excludes the combined attribute (MR), so just PC's options
$pcOpts = $db->prepare("SELECT id, code FROM attribute_options WHERE attribute_id = (SELECT id FROM attributes WHERE product_code_id = ? AND code = 'PC') ORDER BY sort_order");
$pcOpts->execute([$pid]);
$combCount = 0;
foreach ($pcOpts->fetchAll() as $o) {
    $cid = generateUUID();
    $db->prepare("INSERT INTO combination_matrix (id, product_code_id, output_type, combination_key, combination_hash) VALUES (?, ?, 'engg_combined', ?, ?)")
       ->execute([$cid, $pid, $o['code'], hash('sha256', $pid.'|engg_combined|'.$o['code'])]);
    $db->prepare("INSERT INTO combination_options (id, combination_id, attribute_id, option_id) VALUES (NEWID(), ?, (SELECT id FROM attributes WHERE product_code_id = ? AND code = 'PC'), ?)")
       ->execute([$cid, $pid, $o['id']]);
    $combCount++;
}
echo "[3] Generated $combCount engg_combined combinations.\n";

// ─── Write demo template DXF ──────────────────────────────────────────────
$tplDir = UPLOAD_PATH . 'templates/' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $pid) . '/';
if (!is_dir($tplDir)) @mkdir($tplDir, 0755, true);
$tplCode = '136ULT-DEMO';
$tplPath = $tplDir . $tplCode . '.dxf';
$dxf  = "  0\nSECTION\n  2\nHEADER\n  0\nENDSEC\n";
$dxf .= "  0\nSECTION\n  2\nENTITIES\n";
$line = function($y, $text) { return "  0\nTEXT\n  8\n0\n 10\n10.0\n 20\n$y\n 30\n0.0\n 40\n4.0\n  1\n$text\n"; };
$dxf .= $line(290, "SBEM ENGINEERING DRAWING");
$dxf .= $line(280, "Customer: <<CUSTOMER>>");
$dxf .= $line(270, "Project:  <<PROJECT>>");
$dxf .= $line(260, "SO No:    <<SO_NO>>");
$dxf .= $line(250, "PO Ref:   <<PO_REF>>");
$dxf .= $line(240, "Date:     <<DATE>>");
$dxf .= $line(230, "Model:    <<MODEL_CODE>>");
$dxf .= $line(220, "Product:  <<PRODUCT_NAME>>");
$dxf .= $line(210, "Qty:      <<QTY>>");
$dxf .= $line(195, "----- Custom mappings -----");
$dxf .= $line(185, "Measuring Range: <<MEASURING_RANGE>>");
$dxf .= $line(175, "Process Conn:    <<PROCESS_CONN>>");
$dxf .= $line(165, "Inspection Std:  <<INSPECTION_STD>>");
$dxf .= $line(155, "Drawn By:        <<DRAWN_BY>>");
$dxf .= $line(120, "<<MODEL_CODE_TABLE>>");
$dxf .= $line(60,  "<<DIM_TABLE>>");
$dxf .= "  0\nENDSEC\n  0\nEOF\n";
file_put_contents($tplPath, $dxf);
echo "[4] Demo template DXF written: $tplPath\n";

// templates row
$relPath = 'templates/' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $pid) . '/' . $tplCode . '.dxf';
$tplRow = $db->prepare("SELECT id FROM templates WHERE product_code_id = ? AND template_code = ?");
$tplRow->execute([$pid, $tplCode]);
if ($r = $tplRow->fetch()) {
    $tplId = $r['id'];
    $db->prepare("UPDATE templates SET file_path = ?, file_format='dxf', is_active=1, template_type='engg_individual' WHERE id = ?")->execute([$relPath, $tplId]);
} else {
    $tplId = generateUUID();
    $db->prepare("INSERT INTO templates (id, product_code_id, template_type, template_code, file_path, file_format, is_active) VALUES (?, ?, 'engg_individual', ?, ?, 'dxf', 1)")
       ->execute([$tplId, $pid, $tplCode, $relPath]);
}
echo "[5] templates row id: $tplId\n";

// engg_combined template (same file, different type so the resolver picks it)
$tplCodeC = '136ULT-DEMO-COMBINED';
$relPathC = 'templates/' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $pid) . '/' . $tplCode . '.dxf';
$tplRowC = $db->prepare("SELECT id FROM templates WHERE product_code_id = ? AND template_code = ?");
$tplRowC->execute([$pid, $tplCodeC]);
if ($r = $tplRowC->fetch()) {
    $tplIdC = $r['id'];
    $db->prepare("UPDATE templates SET file_path = ?, file_format='dxf', is_active=1, template_type='engg_combined' WHERE id = ?")->execute([$relPathC, $tplIdC]);
} else {
    $tplIdC = generateUUID();
    $db->prepare("INSERT INTO templates (id, product_code_id, template_type, template_code, file_path, file_format, is_active) VALUES (?, ?, 'engg_combined', ?, ?, 'dxf', 1)")
       ->execute([$tplIdC, $pid, $tplCodeC, $relPathC]);
}

// Map every combination to the template
foreach ($db->query("SELECT id, output_type FROM combination_matrix WHERE product_code_id = '$pid' AND output_type IN ('engg_individual','engg_combined')")->fetchAll() as $cm) {
    $db->prepare("DELETE FROM combination_template_map WHERE combination_id = ?")->execute([$cm['id']]);
    $useTpl = $cm['output_type'] === 'engg_combined' ? $tplIdC : $tplId;
    $db->prepare("INSERT INTO combination_template_map (id, combination_id, template_id) VALUES (NEWID(), ?, ?)")
       ->execute([$cm['id'], $useTpl]);
}
echo "[6] All combinations mapped to their templates.\n";

// template_field_mappings — wire the custom <<TAGS>>
foreach ([$tplId, $tplIdC] as $tid) {
    $db->prepare("DELETE FROM template_field_mappings WHERE template_id = ?")->execute([$tid]);
    foreach ([
        ['<<MEASURING_RANGE>>', 'attribute_option',   'MR', ''],
        ['<<PROCESS_CONN>>',    'attribute_option',   'PC', ''],
        ['<<INSPECTION_STD>>',  'static',             '',   'API 598 / EN 12266-1'],
        ['<<DRAWN_BY>>',        'static',             '',   'SBEM-CAD-Auto'],
    ] as $fm) {
        $db->prepare("INSERT INTO template_field_mappings (id, template_id, dwg_field_name, source_type, source_key, is_editable, default_value) VALUES (NEWID(), ?, ?, ?, ?, 0, ?)")
           ->execute([$tid, $fm[0], $fm[1], $fm[2], $fm[3]]);
    }
}
echo "[7] Inserted 4 field mappings per template.\n";

// ─── Demo Sales Order ─────────────────────────────────────────────────────
$soNumber = 'SO-2026-DEMO';
$old = $db->prepare("SELECT id FROM sales_orders WHERE so_number = ?");
$old->execute([$soNumber]);
if ($o = $old->fetch()) {
    $oid = $o['id'];
    $db->prepare("DELETE FROM generated_certificates WHERE so_line_item_id IN (SELECT id FROM so_line_items WHERE sales_order_id = ?)")->execute([$oid]);
    $db->prepare("DELETE FROM cert_mfg_data            WHERE so_line_item_id IN (SELECT id FROM so_line_items WHERE sales_order_id = ?)")->execute([$oid]);
    $db->prepare("DELETE FROM generated_documents WHERE so_line_item_id IN (SELECT id FROM so_line_items WHERE sales_order_id = ?) OR combined_group_id IN (SELECT id FROM combined_drawing_groups WHERE sales_order_id = ?)")->execute([$oid, $oid]);
    $db->prepare("DELETE FROM so_line_selections WHERE so_line_item_id IN (SELECT id FROM so_line_items WHERE sales_order_id = ?)")->execute([$oid]);
    $db->prepare("UPDATE so_line_items SET combined_group_id = NULL WHERE sales_order_id = ?")->execute([$oid]);
    $db->prepare("DELETE FROM combined_drawing_groups WHERE sales_order_id = ?")->execute([$oid]);
    $db->prepare("DELETE FROM so_line_items WHERE sales_order_id = ?")->execute([$oid]);
    $db->prepare("DELETE FROM sales_orders WHERE id = ?")->execute([$oid]);
}

$soId = generateUUID();
$db->prepare("INSERT INTO sales_orders (id, so_number, customer_name, project_name, po_reference, delivery_date, status) VALUES (?, ?, 'Reliance Industries Ltd', 'Jamnagar Refinery PT-Upgrade', 'PO-RIL-2026-DEMO-001', '2026-07-31', 'draft')")
   ->execute([$soId, $soNumber]);

// Two lines, same product, differ only in MR
$commonPicks = ['SO'=>'A','CE'=>'T','SM'=>'01','PC'=>'1','FS'=>'31','MF'=>'1','PS'=>'01'];
$createLine = function (int $ln, int $qty, string $mr) use ($db, $soId, $pid, $commonPicks) {
    $lid = generateUUID();
    $db->prepare("INSERT INTO so_line_items (id, sales_order_id, product_code_id, line_number, quantity, generate_individual, generate_combined, status) VALUES (?, ?, ?, ?, ?, 1, 1, 'incomplete')")
       ->execute([$lid, $soId, $pid, $ln, $qty]);
    $picks = $commonPicks + ['MR' => $mr];
    $attrs = $db->prepare("SELECT id, code FROM attributes WHERE product_code_id = ? AND is_active = 1 ORDER BY position_in_model");
    $attrs->execute([$pid]);
    $parts = ['136ULT'];
    foreach ($attrs->fetchAll() as $a) {
        if (!isset($picks[$a['code']])) continue;
        $o = $db->prepare("SELECT id, code FROM attribute_options WHERE attribute_id = ? AND code = ?");
        $o->execute([$a['id'], $picks[$a['code']]]);
        $or = $o->fetch();
        $db->prepare("INSERT INTO so_line_selections (id, so_line_item_id, attribute_id, option_id) VALUES (NEWID(), ?, ?, ?)")->execute([$lid, $a['id'], $or['id']]);
        $parts[] = $or['code'];
    }
    $model = implode('-', $parts);
    $db->prepare("UPDATE so_line_items SET model_code_string = ?, status = 'resolved' WHERE id = ?")->execute([$model, $lid]);
    return $model;
};
$m1 = $createLine(1, 50, '08');   // 8 m range
$m2 = $createLine(2, 30, '15');   // 15 m range
echo "[8] Demo SO ($soNumber) created with:\n";
echo "      Line 1: $m1  (qty 50)\n";
echo "      Line 2: $m2  (qty 30)\n";

echo "\n============================================================\n";
echo "  DEMO READY\n";
echo "============================================================\n";
echo "Open the SO detail page and click 'Generate Documents':\n";
echo "  http://127.0.0.1:8765/pages/sales_order_detail.php?id=$soId\n";
echo "or (production XAMPP):\n";
echo "  http://localhost/edms/pages/sales_order_detail.php?id=$soId\n";
echo "============================================================\n";
