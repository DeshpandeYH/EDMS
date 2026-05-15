<?php
/**
 * Test for the combined-drawing fix in pages/sales_order_detail.php.
 *
 * Strategy:
 *   1. Take 136ULT and flip its 'MR' (Measuring Range) attribute to
 *      is_combined_attribute = 1 + set supports_combined_dwg = 1 on the product.
 *   2. Create a test SO with TWO lines that share every attribute EXCEPT MR,
 *      both with generate_combined = 1.
 *   3. Trigger the page handler over HTTP via PHP's built-in web server.
 *   4. Assert: combined_drawing_groups row exists, both lines bound to it,
 *      one generated_documents row per (engg_combined, internal_combined) tied
 *      to the group with so_line_item_id = NULL.
 *   5. Restore the original attribute flags / product flag.
 *
 * Usage: php sql/test_combined_drawing.php
 * Assumes a PHP built-in web server is already running at PHP_TEST_HOST.
 */

require_once __DIR__ . '/../config/database.php';

$HOST    = getenv('PHP_TEST_HOST') ?: 'http://127.0.0.1:8765';
$db      = getDB();
$failed  = [];
$ok      = function (string $msg) { echo "  [PASS] $msg\n"; };
$fail    = function (string $msg) use (&$failed) { echo "  [FAIL] $msg\n"; $failed[] = $msg; };

echo "============================================================\n";
echo "  COMBINED DRAWING — END-TO-END TEST\n";
echo "============================================================\n";

// ---- 1. Seed combined-attribute setup ------------------------------------
$prod = $db->query("SELECT id, code, supports_combined_dwg, combined_attribute_id FROM product_codes WHERE code='136ULT'")->fetch();
if (!$prod) { fwrite(STDERR, "136ULT not found — run seed_data.php first\n"); exit(1); }

$attrMR = $db->prepare("SELECT id, is_combined_attribute FROM attributes WHERE product_code_id = ? AND code = 'MR'");
$attrMR->execute([$prod['id']]);
$mr = $attrMR->fetch();
if (!$mr) { fwrite(STDERR, "MR attribute not found on 136ULT\n"); exit(1); }

// Remember originals so we can restore.
$origProdSupports = $prod['supports_combined_dwg'];
$origProdComboAttr = $prod['combined_attribute_id'];
$origMRFlag = $mr['is_combined_attribute'];

$db->prepare("UPDATE product_codes SET supports_combined_dwg = 1, combined_attribute_id = ? WHERE id = ?")
   ->execute([$mr['id'], $prod['id']]);
$db->prepare("UPDATE attributes SET is_combined_attribute = 1 WHERE id = ?")->execute([$mr['id']]);
echo "Marked MR as combined attribute on 136ULT.\n";

// ---- 2. Create test SO with two lines differing only in MR ---------------
$soNumber = 'SO-2026-COMB-TEST';
$old = $db->prepare("SELECT id FROM sales_orders WHERE so_number = ?");
$old->execute([$soNumber]);
$existing = $old->fetch();
if ($existing) {
    $oldId = $existing['id'];
    // Order matters: child rows (FKs) first.
    $db->prepare("DELETE FROM generated_certificates WHERE so_line_item_id IN (SELECT id FROM so_line_items WHERE sales_order_id = ?)")->execute([$oldId]);
    $db->prepare("DELETE FROM cert_mfg_data            WHERE so_line_item_id IN (SELECT id FROM so_line_items WHERE sales_order_id = ?)")->execute([$oldId]);
    $db->prepare("DELETE FROM generated_documents WHERE so_line_item_id IN (SELECT id FROM so_line_items WHERE sales_order_id = ?) OR combined_group_id IN (SELECT id FROM combined_drawing_groups WHERE sales_order_id = ?)")->execute([$oldId, $oldId]);
    $db->prepare("DELETE FROM so_line_selections WHERE so_line_item_id IN (SELECT id FROM so_line_items WHERE sales_order_id = ?)")->execute([$oldId]);
    $db->prepare("UPDATE so_line_items SET combined_group_id = NULL WHERE sales_order_id = ?")->execute([$oldId]);
    $db->prepare("DELETE FROM combined_drawing_groups WHERE sales_order_id = ?")->execute([$oldId]);
    $db->prepare("DELETE FROM so_line_items WHERE sales_order_id = ?")->execute([$oldId]);
    $db->prepare("DELETE FROM sales_orders WHERE id = ?")->execute([$oldId]);
}

$soId = generateUUID();
$db->prepare("INSERT INTO sales_orders (id, so_number, customer_name, project_name, po_reference, delivery_date, status) VALUES (?, ?, ?, ?, ?, ?, 'draft')")
   ->execute([$soId, $soNumber, 'Test Customer', 'Combined Test', 'PO-COMB-1', '2026-12-31']);

// Pick the same options across all attrs except MR.
// Common picks: SO=A, CE=T, SM=01, PC=1, FS=31, MF=1, PS=01
// Line 1 MR=08, Line 2 MR=15
$commonPicks = [
    'SO' => 'A', 'CE' => 'T', 'SM' => '01', 'PC' => '1', 'FS' => '31', 'MF' => '1', 'PS' => '01'
];
$createLine = function (int $lineNo, int $qty, string $mrOpt) use ($db, $soId, $prod, $commonPicks) {
    $lineId = generateUUID();
    $db->prepare("INSERT INTO so_line_items (id, sales_order_id, product_code_id, line_number, quantity, generate_individual, generate_combined, status) VALUES (?, ?, ?, ?, ?, 0, 1, 'incomplete')")
       ->execute([$lineId, $soId, $prod['id'], $lineNo, $qty]);

    $picks = $commonPicks + ['MR' => $mrOpt];
    $modelParts = [$prod['code']];
    // Insert in position_in_model order so model_code is canonical.
    $attrs = $db->prepare("SELECT id, code FROM attributes WHERE product_code_id = ? AND is_active = 1 ORDER BY position_in_model");
    $attrs->execute([$prod['id']]);
    foreach ($attrs->fetchAll() as $a) {
        $code = $a['code'];
        if (!isset($picks[$code])) continue;
        $opt = $db->prepare("SELECT id, code FROM attribute_options WHERE attribute_id = ? AND code = ?");
        $opt->execute([$a['id'], $picks[$code]]);
        $optRow = $opt->fetch();
        if (!$optRow) { fwrite(STDERR, "Missing option $code={$picks[$code]}\n"); exit(1); }
        $db->prepare("INSERT INTO so_line_selections (id, so_line_item_id, attribute_id, option_id) VALUES (NEWID(), ?, ?, ?)")
           ->execute([$lineId, $a['id'], $optRow['id']]);
        $modelParts[] = $optRow['code'];
    }
    $model = implode('-', $modelParts);
    $db->prepare("UPDATE so_line_items SET model_code_string = ?, status = 'resolved' WHERE id = ?")
       ->execute([$model, $lineId]);
    return ['id' => $lineId, 'model' => $model, 'line_number' => $lineNo];
};

$line1 = $createLine(1, 5,  '08');
$line2 = $createLine(2, 10, '15');
echo "Created SO $soNumber with lines:\n";
echo "  Line 1: {$line1['model']}\n";
echo "  Line 2: {$line2['model']}\n\n";

// ---- 3. Trigger generation over HTTP -------------------------------------
$url  = "$HOST/pages/sales_order_detail.php?id=$soId";
$body = http_build_query(['action' => 'generate_documents']);
$ctx  = stream_context_create([
    'http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($body) . "\r\n",
        'content' => $body,
        'timeout' => 60,
        'ignore_errors' => true,
    ],
]);
echo "POST $url\n";
$resp = @file_get_contents($url, false, $ctx);
if ($resp === false) {
    fwrite(STDERR, "HTTP request failed. Is the PHP built-in server running at $HOST?\n");
    exit(2);
}
$status = $http_response_header[0] ?? '';
echo "Response: $status\n\n";

// ---- 4. Assertions --------------------------------------------------------
echo "Assertions:\n";

$g = $db->prepare("SELECT * FROM combined_drawing_groups WHERE sales_order_id = ?");
$g->execute([$soId]);
$groups = $g->fetchAll();
count($groups) === 1
    ? $ok("exactly one combined_drawing_groups row created (got " . count($groups) . ")")
    : $fail("expected 1 combined_drawing_groups row, got " . count($groups));

if (count($groups) === 1) {
    $groupId = $groups[0]['id'];

    $b = $db->prepare("SELECT id, line_number, combined_group_id FROM so_line_items WHERE sales_order_id = ? ORDER BY line_number");
    $b->execute([$soId]);
    $rows = $b->fetchAll();
    $bound = array_filter($rows, fn($r) => $r['combined_group_id'] === $groupId);
    count($bound) === 2
        ? $ok("both lines bound to the group (got " . count($bound) . ")")
        : $fail("expected 2 lines bound to group, got " . count($bound));

    $d = $db->prepare("SELECT document_type, so_line_item_id, output_file_path, injected_values FROM generated_documents WHERE combined_group_id = ? ORDER BY document_type");
    $d->execute([$groupId]);
    $docs = $d->fetchAll();
    $types = array_map(fn($r) => $r['document_type'], $docs);
    in_array('engg_combined', $types)
        ? $ok("engg_combined document generated")
        : $fail("no engg_combined document — types: " . implode(',', $types));
    in_array('internal_combined', $types)
        ? $ok("internal_combined document generated")
        : $fail("no internal_combined document — types: " . implode(',', $types));

    foreach ($docs as $doc) {
        $doc['so_line_item_id'] === null
            ? $ok("doc {$doc['document_type']}: so_line_item_id is NULL (group-scoped)")
            : $fail("doc {$doc['document_type']}: so_line_item_id should be NULL");

        $iv = json_decode($doc['injected_values'], true);
        $linesIn = $iv['lines'] ?? null;
        (is_array($linesIn) && count($linesIn) === 2)
            ? $ok("doc {$doc['document_type']}: injected_values lists 2 grouped lines")
            : $fail("doc {$doc['document_type']}: expected 2 lines in injected_values, got " . (is_array($linesIn) ? count($linesIn) : 'null'));

        $file = $doc['output_file_path'];
        ($file && file_exists($file))
            ? $ok("doc {$doc['document_type']}: output file exists at $file")
            : $fail("doc {$doc['document_type']}: output file missing ($file)");
    }
}

// ---- 5. Restore originals -------------------------------------------------
$db->prepare("UPDATE product_codes SET supports_combined_dwg = ?, combined_attribute_id = ? WHERE id = ?")
   ->execute([$origProdSupports, $origProdComboAttr, $prod['id']]);
$db->prepare("UPDATE attributes SET is_combined_attribute = ? WHERE id = ?")
   ->execute([$origMRFlag, $mr['id']]);
echo "\nRestored original combined-attribute flags.\n";

echo "\n============================================================\n";
if (empty($failed)) {
    echo "  ALL ASSERTIONS PASSED\n";
    echo "============================================================\n";
    exit(0);
} else {
    echo "  FAILED ASSERTIONS (" . count($failed) . "):\n";
    foreach ($failed as $f) echo "   - $f\n";
    echo "============================================================\n";
    exit(1);
}
