<?php
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/DXFGenerator.php';

$gen = new DXFGenerator();
$dxf = $gen->generate(
    [
        'so_number' => 'SO_25-26_0001',
        'customer_name' => 'ABCD Ltd',
        'project_name' => 'XYZ',
        'po_reference' => 'PO-25_26-0001',
        'delivery_date' => '2026-04-07',
        'model_code' => '136ULT-A-T-01-05-2-26-1-01',
        'quantity' => 2,
        'line_number' => 1,
        'product_code' => '136ULT',
        'product_name' => 'SBEM Make Ultrasonic Level Transmitter',
        'document_type' => 'engg_individual',
    ],
    [
        ['attr_name'=>'Single Output', 'opt_code'=>'A', 'opt_value'=>'2 Wire (4-20mA)', 'display_label'=>'2 Wire (4-20mA)', 'dimension_modifiers'=>null],
        ['attr_name'=>'Cable Entry', 'opt_code'=>'T', 'opt_value'=>'1/2" NPT', 'display_label'=>'½" NPT', 'dimension_modifiers'=>null],
        ['attr_name'=>'Sensor Material', 'opt_code'=>'01', 'opt_value'=>'PP', 'display_label'=>'Polypropylene (PP)', 'dimension_modifiers'=>null],
        ['attr_name'=>'Measuring Range', 'opt_code'=>'05', 'opt_value'=>'05 m', 'display_label'=>'05 m range', 'dimension_modifiers'=>null],
        ['attr_name'=>'Process Connection', 'opt_code'=>'2', 'opt_value'=>'Threaded', 'display_label'=>'Threaded', 'dimension_modifiers'=>null],
        ['attr_name'=>'Flange Size', 'opt_code'=>'26', 'opt_value'=>'2 1/2" ANSI 150#', 'display_label'=>'2½" ANSI 150#', 'dimension_modifiers'=>null],
        ['attr_name'=>'MOC - Flange', 'opt_code'=>'1', 'opt_value'=>'Polypropylene (PP)', 'display_label'=>'Polypropylene (PP)', 'dimension_modifiers'=>null],
        ['attr_name'=>'Power Supply', 'opt_code'=>'01', 'opt_value'=>'24VDC', 'display_label'=>'24VDC', 'dimension_modifiers'=>null],
    ]
);

$outFile = __DIR__ . '/../outputs/test_drawing.dxf';
if (!is_dir(dirname($outFile))) mkdir(dirname($outFile), 0755, true);
file_put_contents($outFile, $dxf);
echo "Generated: $outFile (" . strlen($dxf) . " bytes)\n";
echo "Open this file in AutoCAD or any DXF viewer to verify.\n";
