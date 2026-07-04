#!/usr/bin/env bash
set -euo pipefail

OUTPUT_DIR="tests/_output"
JUNIT_DIR="$OUTPUT_DIR/junit"
MD_FILE="test-failures.md"
TIMESTAMP=$(date '+%Y-%m-%d %H:%M:%S')

mkdir -p "$JUNIT_DIR"
rm -f "$JUNIT_DIR"/*.xml

echo "Running all tests in parallel..."

vendor/bin/pest --parallel --log-junit "$JUNIT_DIR/junit.xml" 2>&1 || true

echo ""
echo "Tests completed. Generating report..."

# Merge all junit XML files and generate the markdown report
php << 'PHPEOF'
<?php

$junitDir = __DIR__ . '/tests/_output/junit';
$mdFile = __DIR__ . '/test-failures.md';

$xmlFiles = glob($junitDir . '/*.xml');
if (empty($xmlFiles)) {
    echo "No junit files found.\n";
    exit(1);
}

function findProblems($node, &$report) {
    foreach ($node->testcase ?? [] as $case) {
        $failure = $case->failure;
        $error = $case->error;
        $problem = $failure ?: $error;
        if (!$problem) continue;

        $type = $failure ? "Failure" : "Error";
        $report[] = [
            "class"   => (string) $case["class"],
            "name"    => (string) $case["name"],
            "file"    => (string) $case["file"],
            "line"    => (string) $case["line"],
            "type"    => $type,
            "subtype" => (string) $problem["type"],
            "message" => (string) $problem["message"],
            "body"    => (string) $problem,
        ];
    }
    foreach ($node->testsuite ?? [] as $child) {
        findProblems($child, $report);
    }
}

$report = [];

foreach ($xmlFiles as $xmlFile) {
    $content = file_get_contents($xmlFile);
    $xml = @simplexml_load_string($content);
    if ($xml === false) continue;

    findProblems($xml, $report);
}

usort($report, fn($a, $b) => strcmp($a['class'] . '::' . $a['name'], $b['class'] . '::' . $b['name']));

$total = count($report);

$out = "# Test Failures\n";
$out .= "Generated: " . date('Y-m-d H:i:s') . "\n";
$out .= "Total Failures: $total\n\n";

foreach ($report as $i => $f) {
    $n = $i + 1;
    $out .= "---\n";
    $out .= "## $n. [{$f['type']}] {$f['class']}::{$f['name']}\n\n";
    $out .= "- **File**: `{$f['file']}:{$f['line']}`\n";
    $out .= "- **Type**: `{$f['subtype']}`\n\n";
    $out .= "**Message**:\n";
    $out .= "```\n";
    $out .= $f['message'] . "\n";
    $out .= "```\n\n";
    if (trim($f['body'])) {
        $out .= "**Details**:\n";
        $out .= "```\n";
        $out .= trim($f['body']) . "\n";
        $out .= "```\n\n";
    }
}

file_put_contents($mdFile, $out);
echo "\nWrote $total failure(s) to $mdFile\n";
PHPEOF
