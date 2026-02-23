#!/usr/bin/env php
<?php

/**
 * Repository Reporter using git ls-files
 */

// 1. Get list of files from git
$gitOutput = shell_exec('git ls-files 2>/dev/null');

if ($gitOutput === null) {
    echo "Error: This directory does not appear to be a git repository or git is not installed.\n";
    exit(1);
}

$files = array_filter(explode("\n", trim($gitOutput)));

// Data structures for reporting
$phpTopLevelDirs = [];
$phpunitFiles = ['phpunit.xml.dist', 'phpunit.xml', 'phpunit.dist.xml'];
$foundPhpunitConfigs = [];
$hasTestsDirFiles = false;

$extensionCounts = [
    'json' => 0,
    'yaml' => 0, // covers yml/yaml
    'sh'   => 0,
    'md'   => 0,
    'js'   => 0,
    'ts'   => 0,
    'css'  => 0,
    'html' => 0, // covers html/htm
    'twig' => 0, // covers twig.html/html.twig
];

$specificFilesToCheck = [
    'AGENTS.md', 'composer.json', 'eslint.config.mjs', '.eslintignore',
    '.gitignore', '.markdownlint.json', 'package.json', '.php-cs-fixer.php',
    'phpstan.neon.dist', 'phpunit.xml.dist', '.prettierignore',
    '.prettierrc.json', 'rector.php', '.shellcheckrc', '.stylelintignore',
    '.stylelintrc.json', '.twig-cs-fixer.dist.php', '.yamllint.yml'
];
$foundSpecificFiles = [];

// --- Processing ---

foreach ($files as $file) {
    $parts = explode('/', $file);
    $fileName = basename($file);
    $isTopLevel = (count($parts) === 1);

    // 1. Top-level directories containing PHP files
    if (count($parts) > 1 && str_ends_with($file, '.php')) {
        $phpTopLevelDirs[$parts[0]] = true;
    }

    // 2. PHPUnit Configs & Tests directory
    if ($isTopLevel && in_array($file, $phpunitFiles)) {
        $foundPhpunitConfigs[] = $file;
    }
    if (str_starts_with($file, 'tests/')) {
        $hasTestsDirFiles = true;
    }

    // 3. Extension Counting
    if (preg_match('/\.json$/', $file)) $extensionCounts['json']++;
    if (preg_match('/\.(yml|yaml)$/', $file)) $extensionCounts['yaml']++;
    if (preg_match('/\.sh$/', $file)) $extensionCounts['sh']++;
    if (preg_match('/\.md$/', $file)) $extensionCounts['md']++;
    if (preg_match('/\.js$/', $file)) $extensionCounts['js']++;
    if (preg_match('/\.ts$/', $file)) $extensionCounts['ts']++;
    if (preg_match('/\.css$/', $file)) $extensionCounts['css']++;
    if (preg_match('/\.html?$/', $file)) $extensionCounts['html']++;
    if (preg_match('/\.twig$|twig\.html$/', $file)) $extensionCounts['twig']++;

    // 4. Specific Top-level files
    if ($isTopLevel && in_array($file, $specificFilesToCheck)) {
        $foundSpecificFiles[] = $file;
    }
}

// --- Output Reporting ---

echo "=== REPOSITORY REPORT ===\n\n";

// Requirement 1
echo "1. Top-level directories containing PHP files:\n";
if (empty($phpTopLevelDirs)) {
    echo "   - None found.\n";
} else {
    foreach (array_keys($phpTopLevelDirs) as $dir) {
        echo "   - $dir/\n";
    }
}

// Requirement 2
echo "\n2. PHPUnit & Tests Status:\n";
if (!empty($foundPhpunitConfigs)) {
    echo "   - Config found: " . implode(', ', $foundPhpunitConfigs) . "\n";
} else {
    echo "   - No PHPUnit config file found in root.\n";
}
echo "   - Files in tests/ directory: " . ($hasTestsDirFiles ? "Yes" : "No") . "\n";

// Requirement 3
echo "\n3. File Type Counts:\n";
foreach ($extensionCounts as $type => $count) {
    printf("   - %-10s : %d\n", $type, $count);
}

// Requirement 4
echo "\n4. Specific Top-level Files Status:\n";
foreach ($specificFilesToCheck as $target) {
    $exists = in_array($target, $foundSpecificFiles);
    printf("   - [%s] %s\n", $exists ? "X" : " ", $target);
}

echo "\nDone.\n";
