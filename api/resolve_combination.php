<?php
/**
 * EDMS API - Resolve combination for a set of selected options
 * Returns matched templates and SAP codes for all output types
 */
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

$product_id = $_GET['product_id'] ?? '';
$selections = $_GET['selections'] ?? ''; // JSON string: {attr_id: option_id}

if (!$product_id || !$selections) {
    jsonResponse(['error' => 'product_id and selections required'], 400);
}

$selections = json_decode($selections, true);
if (!$selections) {
    jsonResponse(['error' => 'Invalid selections JSON'], 400);
}

$db = getDB();

// Get attribute details for selected options
$option_details = [];
foreach ($selections as $attr_id => $option_id) {
    $stmt = $db->prepare("
        SELECT a.code as attr_code, a.affects_engg_dwg, a.affects_internal_dwg, a.affects_sap_item, a.affects_test_cert, a.is_combined_attribute,
               ao.code as opt_code, ao.value as opt_value, ao.dimension_modifiers
        FROM attributes a
        JOIN attribute_options ao ON ao.attribute_id = a.id AND ao.id = ?
        WHERE a.id = ?
    ");
    $stmt->execute([$option_id, $attr_id]);
    $detail = $stmt->fetch();
    if ($detail) $option_details[] = $detail;
}

// Resolve for each output type
$output_types = [
    'engg_individual' => 'affects_engg_dwg',
    'engg_combined' => 'affects_engg_dwg',
    'internal_individual' => 'affects_internal_dwg',
    'internal_combined' => 'affects_internal_dwg',
    'sap_item' => 'affects_sap_item',
    'test_cert' => 'affects_test_cert'
];

$results = [];
foreach ($output_types as $type => $flag) {
    $is_combined = str_contains($type, '_combined');
    $opt_codes = [];
    foreach ($option_details as $d) {
        if ($d[$flag] && (!$is_combined || !$d['is_combined_attribute'])) {
            $opt_codes[] = $d['opt_code'];
        }
    }
    
    if (empty($opt_codes)) {
        $results[$type] = ['status' => 'no_affecting_attrs'];
        continue;
    }
    
    $combo_key = implode('-', $opt_codes);
    
    $match = $db->prepare("
        SELECT cm.id, cm.combination_key, ctm.template_id, ctm.sap_item_code, t.template_code
        FROM combination_matrix cm
        LEFT JOIN combination_template_map ctm ON cm.id = ctm.combination_id
        LEFT JOIN templates t ON ctm.template_id = t.id
        WHERE cm.product_code_id = ? AND cm.output_type = ? AND cm.combination_key = ? AND cm.is_active = 1
    ");
    $match->execute([$product_id, $type, $combo_key]);
    $row = $match->fetch();
    
    if ($row && $row['template_id']) {
        $results[$type] = [
            'status' => 'mapped',
            'template_code' => $row['template_code'],
            'sap_item_code' => $row['sap_item_code'],
            'combination_key' => $row['combination_key']
        ];
    } elseif ($row) {
        $results[$type] = ['status' => 'unmapped', 'combination_key' => $row['combination_key']];
    } else {
        $results[$type] = ['status' => 'no_combination', 'combo_key_searched' => $combo_key];
    }
}

jsonResponse([
    'product_id' => $product_id,
    'resolutions' => $results
]);
