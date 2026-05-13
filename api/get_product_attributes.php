<?php
/**
 * EDMS API - Get attributes and options for a product code
 * Used by Model Code Builder for dynamic dropdowns
 */
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

$product_id = $_GET['product_id'] ?? '';
if (!$product_id) {
    jsonResponse(['error' => 'product_id required'], 400);
}

$db = getDB();

// Get attributes
$attrs = $db->prepare("
    SELECT id, code, name, position_in_model, is_combined_attribute,
           affects_engg_dwg, affects_internal_dwg, affects_sap_item, affects_test_cert
    FROM attributes 
    WHERE product_code_id = ? AND is_active = 1 
    ORDER BY position_in_model
");
$attrs->execute([$product_id]);
$attributes = $attrs->fetchAll();

// Get options for each attribute
$result = [];
foreach ($attributes as $attr) {
    $opts = $db->prepare("SELECT id, code, value, display_label, dimension_modifiers FROM attribute_options WHERE attribute_id = ? AND is_active = 1 ORDER BY sort_order");
    $opts->execute([$attr['id']]);
    $attr['options'] = $opts->fetchAll();
    $result[] = $attr;
}

jsonResponse(['attributes' => $result]);
