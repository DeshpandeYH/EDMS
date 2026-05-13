<?php
require __DIR__ . '/../config/database.php';
$db = getDB();

echo "=== ALL PRODUCTS ===\n";
$rows = $db->query("SELECT id, code, name FROM product_codes WHERE status != 'archived' ORDER BY code")->fetchAll();
foreach ($rows as $r) echo "{$r['id']} | [{$r['code']}] | {$r['name']}\n";

echo "\n=== TEMPLATES ===\n";
$rows = $db->query("SELECT t.id, t.template_code, t.product_code_id, pc.code as pc_code FROM templates t JOIN product_codes pc ON t.product_code_id = pc.id")->fetchAll();
foreach ($rows as $r) echo "{$r['template_code']} -> product [{$r['pc_code']}] (pid={$r['product_code_id']})\n";

echo "\n=== COMBINATIONS (count per product) ===\n";
$rows = $db->query("SELECT pc.code, COUNT(*) as cnt FROM combination_matrix cm JOIN product_codes pc ON cm.product_code_id = pc.id GROUP BY pc.code")->fetchAll();
foreach ($rows as $r) echo "[{$r['code']}] -> {$r['cnt']} combos\n";

// Fix: Move template from '136 ULT' to '136ULT' and delete the duplicate product
$ult_space = $db->query("SELECT id FROM product_codes WHERE code = '136 ULT'")->fetch();
$ult_nospace = $db->query("SELECT id FROM product_codes WHERE code = '136ULT'")->fetch();

if ($ult_space && $ult_nospace) {
    echo "\nFIXING: Moving templates from '136 ULT' ({$ult_space['id']}) to '136ULT' ({$ult_nospace['id']})...\n";
    
    // Move templates
    $db->prepare("UPDATE templates SET product_code_id = ? WHERE product_code_id = ?")->execute([$ult_nospace['id'], $ult_space['id']]);
    
    // Move any attributes/options if they exist under '136 ULT'
    $db->prepare("UPDATE attributes SET product_code_id = ? WHERE product_code_id = ? AND code NOT IN (SELECT code FROM attributes WHERE product_code_id = ?)")
       ->execute([$ult_nospace['id'], $ult_space['id'], $ult_nospace['id']]);
    
    // Archive the duplicate
    $db->prepare("UPDATE product_codes SET status = 'archived' WHERE id = ?")->execute([$ult_space['id']]);
    
    echo "Done. '136 ULT' archived, templates moved to '136ULT'.\n";
} else {
    echo "\nNo duplicate found or already fixed.\n";
}

echo "\n=== VERIFY TEMPLATES NOW ===\n";
$rows = $db->query("SELECT t.template_code, pc.code as pc_code FROM templates t JOIN product_codes pc ON t.product_code_id = pc.id")->fetchAll();
foreach ($rows as $r) echo "{$r['template_code']} -> [{$r['pc_code']}]\n";
