<?php
/**
 * EDMS - Seed data from "Drawing Template Details.xlsx"
 * Inserts Product Codes, Attributes, and Attribute Options
 * 
 * Products:
 *   136ULT - SBEM Make Ultrasonic Level Transmitter
 *   171PT  - Pressure Transmitter
 */
require_once __DIR__ . '/../config/database.php';

$db = getDB();

echo "=== EDMS Seed: Drawing Template Details ===\n\n";

// ============================================================
// PRODUCT 1: 136ULT
// ============================================================
$product1_id = generateUUID();
echo "Creating product: 136ULT...\n";

try {
    $db->prepare("DELETE FROM attribute_options WHERE attribute_id IN (SELECT id FROM attributes WHERE product_code_id IN (SELECT id FROM product_codes WHERE code = '136ULT'))")->execute();
    $db->prepare("DELETE FROM attributes WHERE product_code_id IN (SELECT id FROM product_codes WHERE code = '136ULT')")->execute();
    $db->prepare("DELETE FROM product_codes WHERE code = '136ULT'")->execute();
} catch (Exception $e) { /* ignore if not exists */ }

$db->prepare("INSERT INTO product_codes (id, code, name, category, supports_combined_dwg, status) VALUES (?, '136ULT', 'SBEM Make Ultrasonic Level Transmitter', 'Instruments', 0, 'active')")
   ->execute([$product1_id]);

// 136ULT Attributes and Options
$attrs_136 = [
    [
        'code' => 'SO', 'name' => 'Single Output', 'position' => 2,
        'jo_dwg' => false, 'assy' => true,
        'options' => [
            ['A', '2 Wire (4-20mA)', '2 Wire (4-20mA)'],
            ['C', '2 Wire (4-20mA + HART)', '2 Wire (4-20mA + HART)'],
            ['G', '4 Wire (4-20mA + RS485 Modbus)', '4 Wire (4-20mA + RS485 Modbus)'],
        ]
    ],
    [
        'code' => 'CE', 'name' => 'Cable Entry', 'position' => 3,
        'jo_dwg' => false, 'assy' => false,
        'options' => [
            ['T', '1/2" NPT', '½" NPT'],
            ['M', 'M20 X 1.5', 'M20 X 1.5'],
        ]
    ],
    [
        'code' => 'SM', 'name' => 'Sensor Material', 'position' => 4,
        'jo_dwg' => false, 'assy' => true,
        'options' => [
            ['01', 'PP', 'Polypropylene (PP)'],
            ['02', 'PVDF', 'PVDF'],
        ]
    ],
    [
        'code' => 'MR', 'name' => 'Measuring Range', 'position' => 5,
        'jo_dwg' => false, 'assy' => true,
        'options' => [
            ['05', '05 m', '05 m range'],
            ['08', '08 m', '08 m range'],
            ['10', '10 m', '10 m range'],
            ['15', '15 m', '15 m range'],
        ]
    ],
    [
        'code' => 'PC', 'name' => 'Process Connection', 'position' => 6,
        'jo_dwg' => true, 'assy' => false,
        'options' => [
            ['1', 'Slip on Flanged', 'Slip on Flanged'],
            ['2', 'Threaded', 'Threaded'],
        ]
    ],
    [
        'code' => 'FS', 'name' => 'Flange Size & Standards', 'position' => 7,
        'jo_dwg' => false, 'assy' => false,
        'options' => [
            ['XX', 'Not Applicable', 'Not Applicable'],
            ['26', '2 1/2" ANSI 150#', '2½" ANSI 150#'],
            ['31', '3" ANSI 150#', '3" ANSI 150#'],
            ['41', '4" ANSI 150#', '4" ANSI 150#'],
            ['51', '5" ANSI 150#', '5" ANSI 150#'],
        ]
    ],
    [
        'code' => 'MF', 'name' => 'MOC - Flange', 'position' => 8,
        'jo_dwg' => false, 'assy' => false,
        'options' => [
            ['X', 'Not Applicable', 'Not Applicable'],
            ['1', 'Polypropylene (PP)', 'Polypropylene (PP)'],
        ]
    ],
    [
        'code' => 'PS', 'name' => 'Power Supply', 'position' => 9,
        'jo_dwg' => false, 'assy' => false,
        'options' => [
            ['01', '24VDC', '24VDC'],
        ]
    ],
];

foreach ($attrs_136 as $i => $attr) {
    $attr_id = generateUUID();
    $db->prepare("INSERT INTO attributes (id, product_code_id, code, name, position_in_model, is_combined_attribute, affects_engg_dwg, affects_internal_dwg, affects_sap_item, affects_test_cert, sort_order) VALUES (?, ?, ?, ?, ?, 0, ?, 0, ?, 0, ?)")
       ->execute([
           $attr_id, $product1_id, $attr['code'], $attr['name'], $attr['position'],
           $attr['jo_dwg'] ? 1 : 0,  // affects_engg_dwg = JO drawing impact
           $attr['assy'] ? 1 : 0,     // affects_sap_item = Assembly Number impact
           $i + 1
       ]);
    
    foreach ($attr['options'] as $j => $opt) {
        $db->prepare("INSERT INTO attribute_options (id, attribute_id, code, value, display_label, sort_order) VALUES (NEWID(), ?, ?, ?, ?, ?)")
           ->execute([$attr_id, $opt[0], $opt[1], $opt[2], $j + 1]);
    }
    echo "  Attribute: {$attr['code']} — {$attr['name']} ({". count($attr['options']) ."} options)\n";
}

echo "\n136ULT: " . count($attrs_136) . " attributes created.\n";

// ============================================================
// PRODUCT 2: 171PT
// ============================================================
$product2_id = generateUUID();
echo "\nCreating product: 171PT...\n";

try {
    $db->prepare("DELETE FROM attribute_options WHERE attribute_id IN (SELECT id FROM attributes WHERE product_code_id IN (SELECT id FROM product_codes WHERE code = '171PT'))")->execute();
    $db->prepare("DELETE FROM attributes WHERE product_code_id IN (SELECT id FROM product_codes WHERE code = '171PT')")->execute();
    $db->prepare("DELETE FROM product_codes WHERE code = '171PT'")->execute();
} catch (Exception $e) { /* ignore */ }

$db->prepare("INSERT INTO product_codes (id, code, name, category, supports_combined_dwg, status) VALUES (?, '171PT', 'Pressure Transmitter', 'Instruments', 0, 'active')")
   ->execute([$product2_id]);

$attrs_171 = [
    [
        'code' => 'DV', 'name' => 'Display Version', 'position' => 2,
        'jo_dwg' => true, 'assy' => true,
        'options' => [
            ['1', 'With Display', 'With Display'],
            ['2', 'Without Display', 'Without Display'],
        ]
    ],
    [
        'code' => 'PT', 'name' => 'Pressure Types', 'position' => 3,
        'jo_dwg' => false, 'assy' => false,
        'options' => [
            ['G', 'Gauge', 'Gauge'],
            ['A', 'Absolute', 'Absolute'],
        ]
    ],
    [
        'code' => 'AC', 'name' => 'Accuracy', 'position' => 4,
        'jo_dwg' => false, 'assy' => true,
        'options' => [
            ['3', '+/-0.25% of FS', '±0.25% of FS'],
            ['4', '+/-0.5% of FS', '±0.5% of FS'],
        ]
    ],
    [
        'code' => 'MR', 'name' => 'Measuring Ranges', 'position' => 5,
        'jo_dwg' => false, 'assy' => true,
        'options' => [
            ['011', '0 to 2.5 kg/cm2', '0 kg/cm² to 2.5 kg/cm²'],
            ['021', '0 to 10 kg/cm2', '0 kg/cm² to 10 kg/cm²'],
            ['031', '0 to 16 kg/cm2', '0 kg/cm² to 16 kg/cm²'],
            ['041', '0 to 20 kg/cm2', '0 kg/cm² to 20 kg/cm²'],
            ['051', '0 to 30 kg/cm2', '0 kg/cm² to 30 kg/cm²'],
            ['012', '0 to 2.5 bar', '0 bar to 2.5 bar'],
            ['022', '0 to 10 bar', '0 bar to 10 bar'],
        ]
    ],
    [
        'code' => 'CE', 'name' => 'Cable Entry', 'position' => 6,
        'jo_dwg' => true, 'assy' => true,
        'options' => [
            ['D', 'DIN43650 Connector', 'DIN43650 Connector'],
            ['S', 'Sealed Cable', 'Sealed Cable'],
            ['M', 'M12 Connector', 'M12 Connector'],
        ]
    ],
    [
        'code' => 'DP', 'name' => 'Diaphragm', 'position' => 7,
        'jo_dwg' => false, 'assy' => false,
        'options' => [
            ['1', 'Stainless Steel SS316L', 'Stainless Steel SS316L'],
        ]
    ],
    [
        'code' => 'TH', 'name' => 'Transmitter Housing', 'position' => 8,
        'jo_dwg' => false, 'assy' => false,
        'options' => [
            ['0', 'SS316L', 'SS316L'],
            ['1', 'SS304', 'SS304'],
        ]
    ],
    [
        'code' => 'PC', 'name' => 'Process Connection', 'position' => 9,
        'jo_dwg' => false, 'assy' => true,
        'options' => [
            ['0', 'G 1/4" (M) Threaded', 'G ¼" (M) Threaded'],
            ['1', 'G 1/2" (M) Threaded', 'G ½" (M) Threaded'],
            ['2', '1/4" NPT (M) Threaded', '¼" NPT (M) Threaded'],
            ['3', '1/2" NPT (M) Threaded', '½" NPT (M) Threaded'],
            ['4', '1/2" NPT (F) Threaded', '½" NPT (F) Threaded'],
        ]
    ],
    [
        'code' => 'O1', 'name' => 'Output 1 (Signal Output)', 'position' => 10,
        'jo_dwg' => false, 'assy' => false,
        'options' => [
            ['1', '2 Wire 4-20mA', '2 Wire 4-20mA'],
        ]
    ],
];

foreach ($attrs_171 as $i => $attr) {
    $attr_id = generateUUID();
    $db->prepare("INSERT INTO attributes (id, product_code_id, code, name, position_in_model, is_combined_attribute, affects_engg_dwg, affects_internal_dwg, affects_sap_item, affects_test_cert, sort_order) VALUES (?, ?, ?, ?, ?, 0, ?, 0, ?, 0, ?)")
       ->execute([
           $attr_id, $product2_id, $attr['code'], $attr['name'], $attr['position'],
           $attr['jo_dwg'] ? 1 : 0,
           $attr['assy'] ? 1 : 0,
           $i + 1
       ]);
    
    foreach ($attr['options'] as $j => $opt) {
        $db->prepare("INSERT INTO attribute_options (id, attribute_id, code, value, display_label, sort_order) VALUES (NEWID(), ?, ?, ?, ?, ?)")
           ->execute([$attr_id, $opt[0], $opt[1], $opt[2], $j + 1]);
    }
    echo "  Attribute: {$attr['code']} — {$attr['name']} ({". count($attr['options']) ."} options)\n";
}

echo "\n171PT: " . count($attrs_171) . " attributes created.\n";
echo "\n=== Seed complete! ===\n";
echo "Total: 2 products, " . (count($attrs_136) + count($attrs_171)) . " attributes\n";
