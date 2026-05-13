<?php
require __DIR__ . '/../config/database.php';
$db = getDB();

echo "=== GENERATED DOCUMENTS ===\n";
$docs = $db->query("SELECT gd.id, gd.template_id, gd.document_type, t.template_code, t.file_path, t.product_code_id FROM generated_documents gd LEFT JOIN templates t ON gd.template_id = t.id")->fetchAll();
foreach ($docs as $d) {
    echo "doc={$d['id']} | tmpl_id={$d['template_id']} | type={$d['document_type']} | code={$d['template_code']} | file={$d['file_path']}\n";
    if ($d['file_path']) {
        $full = UPLOAD_PATH . $d['file_path'];
        echo "  Full path: $full\n";
        echo "  Exists: " . (file_exists($full) ? 'YES' : 'NO') . "\n";
    }
}

echo "\n=== TEMPLATES ON DISK ===\n";
$tpls = $db->query("SELECT id, template_code, file_path, product_code_id FROM templates WHERE is_active = 1")->fetchAll();
foreach ($tpls as $t) {
    $full = UPLOAD_PATH . $t['file_path'];
    echo "tmpl={$t['template_code']} | file={$t['file_path']} | product_id={$t['product_code_id']}\n";
    echo "  Full: $full\n";
    echo "  Exists: " . (file_exists($full) ? 'YES' : 'NO') . "\n";
}

echo "\n=== UPLOAD PATH ===\n";
echo UPLOAD_PATH . "\n";

echo "\n=== FILES IN UPLOADS ===\n";
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(UPLOAD_PATH, RecursiveDirectoryIterator::SKIP_DOTS));
foreach ($it as $file) {
    echo $file->getPathname() . " (" . $file->getSize() . " bytes)\n";
}
