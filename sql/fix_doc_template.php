<?php
require __DIR__ . '/../config/database.php';
$db = getDB();

// Get the template for 136ULT
$tmpl = $db->query("SELECT id, template_code, product_code_id FROM templates WHERE is_active = 1 AND template_code = '136-001'")->fetch();

if (!$tmpl) { echo "Template not found.\n"; exit; }

echo "Template: {$tmpl['template_code']} (id={$tmpl['id']})\n";

// Link all generated documents for this product that have no template
$updated = $db->prepare("
    UPDATE generated_documents 
    SET template_id = ? 
    WHERE template_id IS NULL 
    AND so_line_item_id IN (
        SELECT id FROM so_line_items WHERE product_code_id = ?
    )
");
$updated->execute([$tmpl['id'], $tmpl['product_code_id']]);
echo "Updated " . $updated->rowCount() . " documents with template link.\n";

// Verify
$docs = $db->query("SELECT gd.id, gd.template_id, t.template_code, t.file_path FROM generated_documents gd LEFT JOIN templates t ON gd.template_id = t.id")->fetchAll();
foreach ($docs as $d) {
    echo "  doc={$d['id']} | tmpl={$d['template_code']} | file={$d['file_path']}\n";
}
