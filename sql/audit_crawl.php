<?php
/**
 * Crawl every page over HTTP and report 500s, fatals, warnings, notices.
 * Pre-req: PHP built-in web server running at http://127.0.0.1:8765 serving
 *          the edms repo root.
 */
require_once __DIR__ . '/../config/database.php';

$HOST = getenv('PHP_TEST_HOST') ?: 'http://127.0.0.1:8765';
$db   = getDB();

// Fetch real IDs.
$pid       = $db->query("SELECT TOP 1 id FROM product_codes WHERE code='136ULT'")->fetchColumn();
$attrId    = $db->prepare("SELECT TOP 1 id FROM attributes WHERE product_code_id = ? ORDER BY position_in_model");
$attrId->execute([$pid]); $attrId = $attrId->fetchColumn();
$soId      = $db->query("SELECT TOP 1 id FROM sales_orders WHERE so_number='SO-2026-TEST-001'")->fetchColumn();
$comboSoId = $db->query("SELECT TOP 1 id FROM sales_orders WHERE so_number='SO-2026-COMB-TEST'")->fetchColumn();
$indDocId  = $db->query("SELECT TOP 1 id FROM generated_documents WHERE so_line_item_id IS NOT NULL")->fetchColumn();
$comDocId  = $db->query("SELECT TOP 1 id FROM generated_documents WHERE combined_group_id IS NOT NULL")->fetchColumn();

echo "IDs: pid=$pid attr=$attrId so=$soId comboSo=$comboSoId indDoc=$indDocId comDoc=$comDocId\n\n";

$urls = [
    '/index.php',
    '/setup.php',
    '/pages/product_codes.php',
    "/pages/attributes.php?product_id=$pid",
    "/pages/attribute_options.php?attribute_id=$attrId",
    "/pages/combinations.php?product_id=$pid",
    '/pages/templates.php',
    "/pages/templates.php?product_id=$pid",
    '/pages/template_fields.php',
    "/pages/anchor_config.php?product_id=$pid",
    '/pages/sales_orders.php',
    "/pages/sales_order_detail.php?id=$soId",
    "/pages/sales_order_detail.php?id=$comboSoId",
    "/pages/model_code_builder.php?product_id=$pid",
    '/pages/drawings.php',
    "/pages/drawing_preview.php?doc_id=$indDocId",
    "/pages/drawing_preview.php?doc_id=$comDocId",
    '/pages/certificates.php',
    '/pages/changes.php',
    "/api/get_product_attributes.php?product_id=$pid",
    "/api/resolve_combination.php?product_id=$pid&selections=" . urlencode('{}'),
    "/api/download.php?doc_id=$indDocId",
    "/api/download.php?doc_id=$comDocId",
];

$problems = [];
foreach ($urls as $path) {
    $url = $HOST . $path;
    $ctx = stream_context_create(['http' => ['timeout' => 15, 'ignore_errors' => true, 'follow_location' => 0]]);
    $body = @file_get_contents($url, false, $ctx);
    $status = $http_response_header[0] ?? 'NO_RESPONSE';
    $statusCode = 0;
    if (preg_match('#HTTP/[\d.]+\s+(\d+)#', $status, $m)) $statusCode = (int)$m[1];

    $flags = [];
    if ($statusCode >= 500) $flags[] = "HTTP_$statusCode";
    if ($statusCode === 0)  $flags[] = "NO_RESPONSE";
    if ($body !== false) {
        foreach (['Fatal error', 'Parse error', 'Warning:', 'Notice:', 'Deprecated:', 'Uncaught', 'TypeError', 'Undefined variable', 'Undefined array key', 'Undefined property', 'Trying to access array offset on'] as $needle) {
            if (stripos($body, $needle) !== false) $flags[] = $needle;
        }
    }
    if (empty($flags)) {
        echo "  OK    $path  ($statusCode)\n";
    } else {
        echo "  FAIL  $path  ($statusCode) :: " . implode(' | ', array_unique($flags)) . "\n";
        $problems[$path] = [
            'status' => $statusCode,
            'flags'  => array_unique($flags),
            'snippet' => $body !== false ? mb_substr(strip_tags($body), 0, 800) : '(no body)',
        ];
    }
}

echo "\n--- PROBLEM DETAILS ---\n";
foreach ($problems as $path => $p) {
    echo "\n## $path  [{$p['status']}]  " . implode(', ', $p['flags']) . "\n";
    echo trim(preg_replace('/\s+/', ' ', $p['snippet'])) . "\n";
}
echo "\nDone. " . count($problems) . " problem page(s) / " . count($urls) . " total.\n";
