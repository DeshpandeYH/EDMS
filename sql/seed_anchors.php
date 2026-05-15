<?php
/**
 * Seed default template_anchor_config rows for every product that doesn't
 * already have them. The processor falls back to hardcoded (10, 280) / (150, 280)
 * when no anchor row exists, which lands tables on a baseline A3 sheet — fine
 * for most paper. Engineers can override per-product via pages/anchor_config.php.
 *
 * Idempotent: only inserts where missing.
 */
require_once __DIR__ . '/../config/database.php';

$db = getDB();

$defaults = [
    // anchor_type => [x_mm, y_mm]
    'model_code_table' => [10.0,  280.0],
    'dim_data_table'   => [150.0, 280.0],
];

$products = $db->query("SELECT id, code FROM product_codes WHERE status IN ('active','draft')")->fetchAll();
$added = 0;

foreach ($products as $p) {
    foreach ($defaults as $type => [$x, $y]) {
        $check = $db->prepare("SELECT id FROM template_anchor_config WHERE product_code_id = ? AND anchor_type = ?");
        $check->execute([$p['id'], $type]);
        if ($check->fetch()) continue;
        $db->prepare("INSERT INTO template_anchor_config (id, product_code_id, anchor_type, x_coord, y_coord) VALUES (NEWID(), ?, ?, ?, ?)")
           ->execute([$p['id'], $type, $x, $y]);
        echo "  + {$p['code']}: $type @ ($x, $y)\n";
        $added++;
    }
}

echo "Done. $added anchor row(s) inserted.\n";
