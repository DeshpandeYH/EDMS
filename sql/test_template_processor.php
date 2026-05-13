<?php
/**
 * Smoke test for DWGTemplateProcessor.
 * Uses an existing DWG template, injects test data, checks the output.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/DWGTemplateProcessor.php';

$src = $argv[1] ?? 'C:\\Users\\User\\Desktop\\SBEM_Drg_Automation\\JO-136-0939-R00_136 ULT.dwg';
if (!is_file($src)) {
    fwrite(STDERR, "Source not found: $src\n");
    exit(1);
}

$proc = new DWGTemplateProcessor();
echo "ODA available: " . ($proc->isAvailable() ? 'yes' : 'NO') . "\n";

// First, prove we can extract a DXF from the DWG.
echo "Converting DWG -> DXF ...\n";
$dxf = $proc->dwgToDxf($src);
echo "DXF length: " . strlen($dxf) . " bytes\n";

// Inject some placeholders at the END of the DXF (before EOF) so we have
// something to find. We synthesize a tiny TEXT entity with each tag,
// emulating what an engineer would do once in DraftSight.
$inject = '';
$tags = [
    '<<CUSTOMER>>'         => [200, 50],
    '<<SO_NO>>'            => [200, 45],
    '<<MODEL_CODE>>'       => [200, 40],
    '<<MODEL_CODE_TABLE>>' => [10,  200],
    '<<DIM_TABLE>>'        => [200, 200],
];
foreach ($tags as $tag => [$x, $y]) {
    $inject .= "  0\r\nTEXT\r\n  8\r\n0\r\n 10\r\n{$x}.0\r\n 20\r\n{$y}.0\r\n 30\r\n0.0\r\n 40\r\n2.5\r\n  1\r\n{$tag}\r\n";
}
// Splice these into the ENTITIES section before its ENDSEC.
$endsec = strpos($dxf, "ENDSEC", strpos($dxf, "ENTITIES"));
if ($endsec === false) { fwrite(STDERR, "No ENTITIES/ENDSEC found\n"); exit(1); }
$insert = strrpos(substr($dxf, 0, $endsec), "\n  0");
$dxf = substr($dxf, 0, $insert + 1) . $inject . substr($dxf, $insert + 1);

// Verify each tag exists post-splice
foreach ($tags as $tag => $_) {
    echo "  Pre-injection check $tag: " . (strpos($dxf, $tag) !== false ? 'OK' : 'MISSING') . "\n";
}

// Stage as a "template" by writing out and re-loading via process()
$workDir = __DIR__ . '/../outputs/_template_test';
@mkdir($workDir, 0755, true);
$tmplDxf = $workDir . '/tmpl.dxf';
file_put_contents($tmplDxf, $dxf);
echo "\nStaged template at: $tmplDxf (" . filesize($tmplDxf) . " bytes)\n\n";

$orderData = [
    'so_number'     => 'SO-2026-TEST-101',
    'customer_name' => 'Tata Projects Ltd',
    'project_name'  => 'Jamnagar Refinery',
    'po_reference'  => 'PO-99887',
    'delivery_date' => '2026-06-30',
    'model_code'    => '136ULT-A-T-01-08-1-31-1-01',
    'product_code'  => '136ULT',
    'product_name'  => 'Ultrasonic Level Transmitter',
    'quantity'      => 5,
];
$selections = [
    ['position_in_model' => 1, 'attr_code' => 'MAT', 'attr_name' => 'Material', 'opt_code' => 'A', 'opt_value' => 'SS304', 'display_label' => 'Stainless Steel 304'],
    ['position_in_model' => 2, 'attr_code' => 'TYP', 'attr_name' => 'Type',     'opt_code' => 'T', 'opt_value' => 'TopMnt','display_label' => 'Top Mount'],
    ['position_in_model' => 3, 'attr_code' => 'RNG', 'attr_name' => 'Range',    'opt_code' => '01','opt_value' => '0-1m', 'display_label' => '0-1 metre'],
];

echo "Calling DWGTemplateProcessor::process() with output=DWG ...\n";
$out = $proc->process($tmplDxf, $orderData, $selections, [], $workDir, 'TEST_OUT', 'dwg');
echo "Produced: $out (" . filesize($out) . " bytes)\n";

// Also peek at the produced DXF
$producedDxf = $workDir . '/TEST_OUT.dxf';
if (is_file($producedDxf)) {
    $finalDxf = file_get_contents($producedDxf);
    echo "DXF produced: " . filesize($producedDxf) . " bytes\n";
    foreach (['Tata Projects Ltd', 'SO-2026-TEST-101', '136ULT-A-T-01-08-1-31-1-01'] as $needle) {
        echo "  Contains '$needle': " . (strpos($finalDxf, $needle) !== false ? 'YES' : 'no') . "\n";
    }
    foreach (['<<CUSTOMER>>', '<<MODEL_CODE_TABLE>>', '<<DIM_TABLE>>'] as $tag) {
        echo "  Residual '$tag': " . (strpos($finalDxf, $tag) !== false ? 'YES (bug!)' : 'no (good)') . "\n";
    }
}

echo "\nDone.\n";
