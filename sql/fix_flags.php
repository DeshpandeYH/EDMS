<?php
require_once __DIR__ . '/../config/database.php';
$db = getDB();

// Set all attributes to affect engg and internal drawings
$db->query("UPDATE attributes SET affects_engg_dwg = 1, affects_internal_dwg = 1 WHERE is_active = 1");

// Verify
$rows = $db->query("SELECT a.code, a.name, a.affects_engg_dwg, a.affects_internal_dwg, a.affects_sap_item, pc.code as product FROM attributes a JOIN product_codes pc ON a.product_code_id = pc.id WHERE a.is_active = 1 ORDER BY pc.code, a.position_in_model")->fetchAll();
foreach ($rows as $r) {
    echo "{$r['product']} | {$r['code']} | {$r['name']} | engg={$r['affects_engg_dwg']} | int={$r['affects_internal_dwg']} | sap={$r['affects_sap_item']}\n";
}

// Clear old combinations for regeneration
$db->query("DELETE FROM combination_options WHERE combination_id IN (SELECT id FROM combination_matrix)");
$db->query("DELETE FROM combination_template_map WHERE combination_id IN (SELECT id FROM combination_matrix)");
$db->query("DELETE FROM combination_matrix");
echo "\nOld combinations cleared. Ready to regenerate from the UI.\n";
