<?php
/**
 * Post-fix verification: confirms the certificate text-fallback writer
 * actually produces a real file on disk, and that resolve_combination
 * accepts an empty selections object.
 *
 * Pre-req: PHP built-in server at http://127.0.0.1:8765 serving the repo root.
 */
require_once __DIR__ . '/../config/database.php';

$HOST = getenv('PHP_TEST_HOST') ?: 'http://127.0.0.1:8765';
$db   = getDB();
$failed = 0;
$ok   = function (string $m) { echo "  [PASS] $m\n"; };
$fail = function (string $m) use (&$failed) { echo "  [FAIL] $m\n"; $failed++; };

echo "============================================================\n";
echo "  POST-FIX VERIFICATION\n";
echo "============================================================\n";

// ---- A. resolve_combination with empty {} ----
$pid = $db->query("SELECT TOP 1 id FROM product_codes WHERE code='136ULT'")->fetchColumn();
$url = "$HOST/api/resolve_combination.php?product_id=$pid&selections=" . urlencode('{}');
$ctx = stream_context_create(['http' => ['ignore_errors' => true]]);
$resp = file_get_contents($url, false, $ctx);
$status = 0;
if (preg_match('#HTTP/[\d.]+\s+(\d+)#', $http_response_header[0] ?? '', $m)) $status = (int)$m[1];
echo "\n[A] resolve_combination with empty selections:\n";
echo "    Status: $status  Body: " . mb_substr($resp ?: '', 0, 200) . "\n";
$status === 200 ? $ok("returns 200 (was 400 before fix)") : $fail("expected 200, got $status");
$json = json_decode($resp ?: '', true);
(is_array($json) && isset($json['resolutions']))
    ? $ok("response is valid JSON with 'resolutions' key")
    : $fail("response shape wrong: " . print_r($json, true));

// ---- B. Certificate text-fallback writes a real file ----
echo "\n[B] Certificate flow writes real file on disk:\n";
$lineId = $db->query("SELECT TOP 1 id FROM so_line_items WHERE status IN ('resolved','generated') ORDER BY line_number")->fetchColumn();
if (!$lineId) { $fail("no resolved/generated line item to test against"); }
else {
    // Clean any prior cert for this line so we observe a fresh insert.
    $db->prepare("DELETE FROM generated_certificates WHERE so_line_item_id = ?")->execute([$lineId]);
    $db->prepare("DELETE FROM cert_mfg_data WHERE so_line_item_id = ?")->execute([$lineId]);

    // Step 1: save mfg data (complete).
    $body1 = http_build_query([
        'action' => 'save_mfg_data',
        'so_line_item_id' => $lineId,
        'serial_entries' => '1001-1005',
        'heat_body' => 'H123',
        'heat_trim' => 'T456',
        'heat_seat' => 'S789',
        'inspector_name' => 'Test Inspector',
        'inspection_date' => '2026-05-15',
        'check_shell_test' => '1',
        'check_visual' => '1',
    ]);
    $ctx1 = stream_context_create(['http' => [
        'method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => $body1, 'ignore_errors' => true, 'follow_location' => 0,
    ]]);
    @file_get_contents("$HOST/pages/certificates.php?line_id=$lineId", false, $ctx1);
    $s1 = $http_response_header[0] ?? '';
    echo "    save_mfg_data: $s1\n";

    $row = $db->prepare("SELECT is_complete FROM cert_mfg_data WHERE so_line_item_id = ?");
    $row->execute([$lineId]);
    $r = $row->fetch();
    (!empty($r) && $r['is_complete'])
        ? $ok("cert_mfg_data persisted with is_complete=1")
        : $fail("cert_mfg_data missing or incomplete");

    // Step 2: generate certificate.
    $body2 = http_build_query(['action' => 'generate_certificate', 'so_line_item_id' => $lineId]);
    $ctx2 = stream_context_create(['http' => [
        'method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => $body2, 'ignore_errors' => true, 'follow_location' => 0,
    ]]);
    $body2resp = @file_get_contents("$HOST/pages/certificates.php?line_id=$lineId", false, $ctx2);
    $s2 = $http_response_header[0] ?? '';
    $loc = '';
    foreach ($http_response_header as $h) { if (stripos($h, 'Location:') === 0) { $loc = trim(substr($h, 9)); break; } }
    echo "    generate_certificate: $s2  Location='$loc'\n";
    echo "    body excerpt: " . mb_substr(preg_replace('/\s+/', ' ', strip_tags($body2resp ?? '')), 0, 500) . "\n";

    $cert = $db->prepare("SELECT certificate_number, output_file_path FROM generated_certificates WHERE so_line_item_id = ? ORDER BY generated_at DESC");
    $cert->execute([$lineId]);
    $c = $cert->fetch();
    $c ? $ok("generated_certificates row inserted: {$c['certificate_number']}")
       : $fail("no generated_certificates row created");

    if ($c) {
        $f = $c['output_file_path'];
        echo "    output_file_path: $f\n";
        if ($f && is_file($f)) {
            $ok("file exists on disk at output_file_path");
            $bytes = filesize($f);
            ($bytes > 100)
                ? $ok("file has real content ($bytes bytes)")
                : $fail("file is too small ($bytes bytes)");
            $body = file_get_contents($f);
            (strpos($body, 'TEST & GUARANTEE CERTIFICATE') !== false)
                ? $ok("file contains expected header line")
                : $fail("file content unexpected");
            echo "\n    --- first 20 lines of cert file ---\n";
            foreach (array_slice(explode("\n", $body), 0, 20) as $ln) echo "    $ln\n";
        } else {
            $fail("output_file_path is NOT a real file on disk: $f");
        }
    }
}

echo "\n============================================================\n";
if ($failed === 0) {
    echo "  ALL POST-FIX CHECKS PASSED\n";
} else {
    echo "  $failed FAILURE(S)\n";
}
echo "============================================================\n";
exit($failed === 0 ? 0 : 1);
