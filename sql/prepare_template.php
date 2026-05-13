<?php
/**
 * EDMS - prepare_template.php
 *
 * Diagnostic helper for engineering. Given a DWG/DXF template file path,
 * runs ODA conversion (if needed) and reports which placeholder tags are
 * present. Useful for validating a template before uploading via the UI.
 *
 * Usage (CLI):
 *   php sql/prepare_template.php "C:\path\to\template.dwg"
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/DWGTemplateProcessor.php';

if ($argc < 2) {
    fwrite(STDERR, "Usage: php prepare_template.php <path-to-dwg-or-dxf>\n");
    exit(1);
}
$path = $argv[1];
if (!is_file($path)) {
    fwrite(STDERR, "File not found: $path\n");
    exit(1);
}

$proc = new DWGTemplateProcessor();
echo "ODA File Converter: " . (defined('ODA_FILE_CONVERTER') ? ODA_FILE_CONVERTER : '(undefined)') . "\n";
echo "Available: " . ($proc->isAvailable() ? 'yes' : 'NO') . "\n\n";

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
echo "Input: $path ($ext)\n";

if ($ext === 'dwg') {
    if (!$proc->isAvailable()) {
        fwrite(STDERR, "Cannot process DWG without ODA File Converter.\n");
        exit(1);
    }
    echo "Converting DWG to DXF via ODA ...\n";
    $dxf = $proc->dwgToDxf($path);
    echo "DXF size: " . number_format(strlen($dxf)) . " bytes\n";
} else {
    $dxf = file_get_contents($path);
    echo "DXF size: " . number_format(strlen($dxf)) . " bytes\n";
}

$tags = ['<<CUSTOMER>>','<<PROJECT>>','<<SO_NO>>','<<PO_REF>>',
         '<<MODEL_CODE>>','<<PRODUCT>>','<<PRODUCT_NAME>>',
         '<<DATE>>','<<DELIVERY>>','<<QTY>>','<<DRG_NO>>',
         '<<MODEL_CODE_TABLE>>','<<DIM_TABLE>>'];

echo "\nPlaceholder report:\n";
echo str_pad('Tag', 26) . "  Found?\n";
echo str_repeat('-', 38) . "\n";
$missing = [];
foreach ($tags as $t) {
    $hit = strpos($dxf, $t) !== false;
    echo str_pad($t, 26) . "  " . ($hit ? 'YES' : 'no') . "\n";
    if (!$hit) $missing[] = $t;
}

echo "\n";
if (empty($missing)) {
    echo "OK — all expected placeholders present.\n";
} else {
    echo "Missing " . count($missing) . " placeholder(s). Engineering needs to add them as TEXT entities.\n";
}
