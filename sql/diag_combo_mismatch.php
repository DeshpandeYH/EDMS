<?php
/**
 * Diagnose why generation reports "no combination match for key 'X'" even when
 * a combination IS mapped. Prints, for every recent generated_document tagged
 * as unmapped:
 *   - the key the generator built
 *   - all combination_matrix rows for that product+output_type
 *   - whether ANY of those have a combination_template_map entry
 *
 * Usage:  php sql/diag_combo_mismatch.php [so_number_pattern]
 * Pattern defaults to the most recent SO.
 */
require_once __DIR__ . '/../config/database.php';

$db = getDB();

$pattern = $argv[1] ?? null;
if ($pattern) {
    $so = $db->prepare("SELECT TOP 1 id, so_number FROM sales_orders WHERE so_number LIKE ? ORDER BY created_at DESC");
    $so->execute(['%' . $pattern . '%']);
} else {
    $so = $db->query("SELECT TOP 1 id, so_number FROM sales_orders ORDER BY created_at DESC");
}
$soRow = $so->fetch();
if (!$soRow) { echo "No SO found.\n"; exit(1); }
echo "Inspecting SO: {$soRow['so_number']}  ({$soRow['id']})\n\n";

// Pull all lines + their (sorted) selections
$lines = $db->prepare("SELECT sli.id, sli.line_number, sli.model_code_string, sli.product_code_id, pc.code AS product_code FROM so_line_items sli JOIN product_codes pc ON sli.product_code_id = pc.id WHERE sli.sales_order_id = ? ORDER BY sli.line_number");
$lines->execute([$soRow['id']]);

foreach ($lines->fetchAll() as $line) {
    echo "─── Line {$line['line_number']}  product={$line['product_code']}  model={$line['model_code_string']}\n";

    // Fetch selections with full flag info, in canonical position order.
    $selStmt = $db->prepare("
        SELECT a.code AS attr_code, a.position_in_model,
               a.affects_engg_dwg, a.affects_internal_dwg, a.is_combined_attribute,
               ao.code AS opt_code
        FROM so_line_selections s
        JOIN attributes a ON s.attribute_id = a.id
        JOIN attribute_options ao ON s.option_id = ao.id
        WHERE s.so_line_item_id = ?
        ORDER BY a.position_in_model
    ");
    $selStmt->execute([$line['id']]);
    $sels = $selStmt->fetchAll();

    foreach ([
        'engg_individual'     => ['flag' => 'affects_engg_dwg',     'excl_combined' => false],
        'internal_individual' => ['flag' => 'affects_internal_dwg', 'excl_combined' => false],
        'engg_combined'       => ['flag' => 'affects_engg_dwg',     'excl_combined' => true],
        'internal_combined'   => ['flag' => 'affects_internal_dwg', 'excl_combined' => true],
    ] as $ot => $cfg) {
        // Build key the way the generator does.
        $opts = [];
        foreach ($sels as $s) {
            if (!$s[$cfg['flag']]) continue;
            if ($cfg['excl_combined'] && $s['is_combined_attribute']) continue;
            $opts[] = $s['opt_code'];
        }
        if (empty($opts)) {
            echo "  [$ot] no affecting attrs — skipped\n";
            continue;
        }
        $expected = implode('-', $opts);

        // Look up
        $match = $db->prepare("
            SELECT cm.id, cm.combination_key, cm.is_active,
                   ctm.template_id, ctm.sap_item_code,
                   t.template_code
            FROM combination_matrix cm
            LEFT JOIN combination_template_map ctm ON cm.id = ctm.combination_id
            LEFT JOIN templates t ON ctm.template_id = t.id
            WHERE cm.product_code_id = ? AND cm.output_type = ? AND cm.combination_key = ?
        ");
        $match->execute([$line['product_code_id'], $ot, $expected]);
        $rows = $match->fetchAll();

        $verdict = empty($rows) ? 'NO MATCH'
                  : ($rows[0]['is_active'] ? ($rows[0]['template_id'] ? 'MAPPED ✓' : 'matrix row exists, NO template_map') : 'matrix row INACTIVE');
        echo "  [$ot] key='$expected'  →  $verdict\n";

        if (empty($rows)) {
            // Show what DOES exist for this product+output_type
            $all = $db->prepare("SELECT TOP 5 combination_key, is_active FROM combination_matrix WHERE product_code_id = ? AND output_type = ? ORDER BY combination_key");
            $all->execute([$line['product_code_id'], $ot]);
            $existing = $all->fetchAll();
            $cnt = $db->prepare("SELECT COUNT(*) FROM combination_matrix WHERE product_code_id = ? AND output_type = ?");
            $cnt->execute([$line['product_code_id'], $ot]);
            $total = (int)$cnt->fetchColumn();
            echo "        existing keys for $ot ($total total): ";
            if ($total === 0) {
                echo "(none — combinations were never generated for this output_type)\n";
            } else {
                echo implode(', ', array_map(fn($r) => $r['combination_key'] . ($r['is_active'] ? '' : '[INACTIVE]'), $existing));
                if ($total > 5) echo " ... +" . ($total - 5) . " more";
                echo "\n";
            }
            // Sanity check: maybe attributes flagged differently now than when combos were built.
            // Count how many attrs currently have this flag set.
            $flagCnt = $db->prepare("SELECT COUNT(*) FROM attributes WHERE product_code_id = ? AND is_active = 1 AND " . $cfg['flag'] . " = 1" . ($cfg['excl_combined'] ? " AND is_combined_attribute = 0" : ""));
            $flagCnt->execute([$line['product_code_id']]);
            echo "        current attr count for $ot flag: " . $flagCnt->fetchColumn() . "\n";
        } elseif (!$rows[0]['template_id']) {
            // Matrix row exists but no template assignment — show which combinations DO have templates for this product.
            $mapped = $db->prepare("SELECT TOP 5 cm.combination_key, t.template_code FROM combination_matrix cm JOIN combination_template_map ctm ON cm.id = ctm.combination_id JOIN templates t ON ctm.template_id = t.id WHERE cm.product_code_id = ? AND cm.output_type = ?");
            $mapped->execute([$line['product_code_id'], $ot]);
            $mappedRows = $mapped->fetchAll();
            echo "        templates mapped for $ot: " . (empty($mappedRows) ? '(none)' : implode(', ', array_map(fn($r) => $r['combination_key'] . '→' . $r['template_code'], $mappedRows))) . "\n";
        }
    }
    echo "\n";
}

// Also dump current attribute flag state for the product so we can spot stale combos.
$pid = $line['product_code_id'] ?? null;
if ($pid) {
    echo "─── Current attribute flag matrix (product {$line['product_code']}):\n";
    $attrs = $db->prepare("SELECT code, position_in_model, affects_engg_dwg, affects_internal_dwg, affects_sap_item, affects_test_cert, is_combined_attribute, is_active FROM attributes WHERE product_code_id = ? ORDER BY position_in_model");
    $attrs->execute([$pid]);
    printf("  %-6s %-4s %-5s %-5s %-5s %-5s %-5s %-5s\n", 'CODE', 'POS', 'engg', 'int', 'sap', 'cert', 'comb', 'active');
    foreach ($attrs->fetchAll() as $a) {
        printf("  %-6s %-4s %-5s %-5s %-5s %-5s %-5s %-5s\n",
            $a['code'], $a['position_in_model'],
            $a['affects_engg_dwg'], $a['affects_internal_dwg'],
            $a['affects_sap_item'], $a['affects_test_cert'],
            $a['is_combined_attribute'], $a['is_active']);
    }
}
