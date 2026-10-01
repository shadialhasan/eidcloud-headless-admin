<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/HeadlessAdminTest.php';

use EidCloud\HeadlessAdmin\Tests\HeadlessAdminTest;

echo "========================================================\n";
echo "🧪 EidCloud Headless Admin - Zero-Dependency Test Suite\n";
echo "PHP Version: " . PHP_VERSION . "\n";
echo "========================================================\n\n";

$suite = new HeadlessAdminTest();
$results = $suite->runAll();

$passed = 0;
$failed = 0;

foreach ($results as $name => $res) {
    if ($res['status'] === 'PASS') {
        echo "  [PASS] {$name}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$name}\n";
        echo "         Error: {$res['message']}\n";
        echo "         Trace: {$res['trace']}\n";
        $failed++;
    }
}

echo "\n--------------------------------------------------------\n";
echo "Summary: Total: " . ($passed + $failed) . " | Passed: {$passed} | Failed: {$failed}\n";
echo "--------------------------------------------------------\n";

if ($failed > 0) {
    echo "❌ TEST SUITE FAILED\n";
    exit(1);
}

echo "✅ ALL TESTS PASSED (100% SUCCESS)\n";
exit(0);
