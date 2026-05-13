<?php
/**
 * EDMS Setup & Database Check
 * Run this page to verify configuration and create the database
 */
require_once __DIR__ . '/config/database.php';
$pageTitle = 'Setup';

$checks = [];
$errors = [];

// Check PHP version
$checks[] = ['PHP Version', PHP_VERSION, version_compare(PHP_VERSION, '8.0', '>=')];

// Check extensions
$checks[] = ['PDO Extension', extension_loaded('pdo') ? 'Loaded' : 'Missing', extension_loaded('pdo')];
$checks[] = ['PDO SQLSRV', extension_loaded('pdo_sqlsrv') ? 'Loaded' : 'Missing', extension_loaded('pdo_sqlsrv')];
$checks[] = ['SQLSRV Extension', extension_loaded('sqlsrv') ? 'Loaded' : 'Missing', extension_loaded('sqlsrv')];

// Check directories
$dirs = [UPLOAD_PATH, OUTPUT_PATH, TEMPLATE_PATH];
foreach ($dirs as $dir) {
    $writable = is_dir($dir) && is_writable($dir);
    $checks[] = ['Directory: ' . basename(dirname($dir)) . '/' . basename($dir), $writable ? 'Writable' : 'Not writable', $writable];
}

// Test DB connection
$db_ok = false;
try {
    $db = getDB();
    $db->query("SELECT 1");
    $checks[] = ['Database Connection', 'Connected to ' . DB_SERVER . '/' . DB_NAME, true];
    $db_ok = true;
    
    // Check if tables exist
    $tables = $db->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
    $checks[] = ['Database Tables', count($tables) . ' tables found', count($tables) >= 20];
    
    if (count($tables) < 20) {
        $errors[] = 'Database tables not found. Please run sql/schema.sql in SQL Server Management Studio.';
    }
} catch (PDOException $e) {
    $checks[] = ['Database Connection', 'Failed: ' . $e->getMessage(), false];
    $errors[] = 'Cannot connect to database. Check config/database.php settings and ensure SQL Server is running.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EDMS Setup Check</title>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600&family=DM+Sans:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>
<div class="content" style="max-width:800px; margin:2rem auto;">
    <h1>EDMS Setup Check</h1>
    <p>Verify your installation and database connection.</p>
    
    <div class="panel mt-2">
        <div class="panel-header"><span class="panel-title">System Requirements</span></div>
        <div class="panel-body" style="padding:0;">
            <table class="data-table">
                <thead><tr><th>Check</th><th>Value</th><th>Status</th></tr></thead>
                <tbody>
                    <?php foreach ($checks as $c): ?>
                    <tr>
                        <td><?= $c[0] ?></td>
                        <td class="mono" style="font-size:0.8rem;"><?= htmlspecialchars($c[1]) ?></td>
                        <td><span class="tag tag-<?= $c[2] ? 'green' : 'red' ?>"><?= $c[2] ? '✓ OK' : '✗ FAIL' ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <?php if (!empty($errors)): ?>
    <div class="callout callout-danger mt-2">
        <strong>Setup Issues:</strong>
        <ul style="margin-top:0.5rem;">
            <?php foreach ($errors as $e): ?>
            <li><?= $e ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
    
    <?php if ($db_ok && count($tables ?? []) < 20): ?>
    <div class="callout callout-warn mt-2">
        <strong>Run the schema SQL:</strong> Open SQL Server Management Studio and execute <code>C:\xampp\htdocs\edms\sql\schema.sql</code> to create all 24 database tables.
    </div>
    <?php endif; ?>
    
    <?php if ($db_ok && count($tables ?? []) >= 20): ?>
    <div class="callout callout-tip mt-2">
        <strong>All checks passed!</strong> Your EDMS installation is ready.
        <a href="<?= BASE_URL ?>/" style="margin-left:0.5rem;">Go to Dashboard →</a>
    </div>
    <?php endif; ?>
    
    <div class="card mt-2">
        <h3>Configuration</h3>
        <div class="info-grid mt-1">
            <div><span class="label">Server:</span> <span class="mono"><?= DB_SERVER ?></span></div>
            <div><span class="label">Database:</span> <span class="mono"><?= DB_NAME ?></span></div>
            <div><span class="label">Auth:</span> <?= DB_USE_WINDOWS_AUTH ? 'Windows Auth' : 'SQL Auth' ?></div>
            <div><span class="label">Base URL:</span> <span class="mono"><?= BASE_URL ?></span></div>
            <div><span class="label">Upload Path:</span> <span class="mono" style="font-size:0.72rem;"><?= UPLOAD_PATH ?></span></div>
            <div><span class="label">PHP Path:</span> <span class="mono" style="font-size:0.72rem;">C:\xampp\php</span></div>
        </div>
    </div>
    
    <?php if ($db_ok && isset($tables)): ?>
    <div class="card mt-2">
        <h3>Database Tables (<?= count($tables) ?>)</h3>
        <div style="columns:3; font-size:0.8rem; font-family:'JetBrains Mono'; color:var(--text-muted); margin-top:0.5rem;">
            <?php foreach ($tables as $t): ?>
            <div style="padding:0.15rem 0;"><?= $t ?></div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
</body>
</html>
