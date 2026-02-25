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
    'css'  => 0,
    'html' => 0,
    'js'   => 0,
    'json' => 0,
    'jsx'  => 0,
    'md'   => 0,
    'sh'   => 0,
    'sql'  => 0,
    'ts'   => 0,
    'tsx'  => 0,
    'twig' => 0,
    'vue'  => 0,
    'yaml' => 0,
];

$specificFilesToCheck = [
    'AGENTS.md', 'composer.json', 'eslint.config.mjs', '.gitignore',
    '.markdownlintignore', '.markdownlint.json', 'package.json', '.php-cs-fixer.php',
    'phpstan.neon.dist', 'phpunit.xml.dist', 'playwright.config.ts', '.prettierignore',
    '.prettierrc.json', 'rector.php', '.shellcheckrc', '.stylelintignore',
    '.stylelintrc.json', '.twig-cs-fixer.dist.php', '.yamllint.yml'
];

/**
 * Mapping of preferred files to the alternative forms that should NOT exist.
 */
$forbiddenAlternatives = [
    'eslint.config.mjs'       => ['eslint.config.js', 'eslint.config.cjs', '.eslintrc.js', '.eslintrc.json', '.eslintrc.yml', '.eslintrc', '.eslintignore'],
    'playwright.config.ts'    => ['playwright.config.js', 'playwright.config.mjs', 'playwright.config.cjs'],
    'phpstan.neon.dist'       => ['phpstan.neon'],
    'phpunit.xml.dist'        => ['phpunit.xml', 'phpunit.dist.xml'],
    '.prettierrc.json'        => ['.prettierrc', '.prettierrc.js', '.prettierrc.cjs', '.prettierrc.yml', '.prettierrc.yaml'],
    '.stylelintrc.json'       => ['.stylelintrc', '.stylelintrc.js', '.stylelintrc.yml', '.stylelintrc.yaml'],
    '.markdownlint.json'      => ['.markdownlint.yaml', '.markdownlint.yml'],
    '.yamllint.yml'           => ['.yamllint', '.yamllint.yaml'],
    '.twig-cs-fixer.dist.php' => ['.twig-cs-fixer.php'],
];

$foundSpecificFiles = [];
$foundForbiddenFiles = [];

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
    if (preg_match('/\.jsx$/', $file)) $extensionCounts['jsx']++;
    if (preg_match('/\.tsx$/', $file)) $extensionCounts['tsx']++;
    if (preg_match('/\.vue$/', $file)) $extensionCounts['vue']++;
    if (preg_match('/\.css$/', $file)) $extensionCounts['css']++;
    if (preg_match('/\.html?$/', $file)) $extensionCounts['html']++;
    if (preg_match('/\.twig$|twig\.html$/', $file)) $extensionCounts['twig']++;
    if (preg_match('/\.sql$/', $file)) $extensionCounts['sql']++;

    if ($isTopLevel) {
        // 4. Specific Top-level files (Preferred)
        if (in_array($file, $specificFilesToCheck)) {
            $foundSpecificFiles[] = $file;
        }

        // 5. Forbidden Alternatives
        foreach ($forbiddenAlternatives as $preferred => $alternatives) {
            if (in_array($file, $alternatives)) {
                $foundForbiddenFiles[$preferred][] = $file;
            }
        }
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
echo "\n4. Specific Top-level Files Status (Preferred):\n";
foreach ($specificFilesToCheck as $target) {
    $exists = in_array($target, $foundSpecificFiles);
    printf("   - [%s] %s\n", $exists ? "X" : " ", $target);
}

// Requirement 5
echo "\n5. Non-preferred Alternative Files (Should NOT exist):\n";
$anyForbiddenFound = false;
foreach ($forbiddenAlternatives as $preferred => $alternatives) {
    if (!empty($foundForbiddenFiles[$preferred])) {
        $anyForbiddenFound = true;
        foreach ($foundForbiddenFiles[$preferred] as $badFile) {
            echo "   - [!] Found: $badFile (Conflict with preferred: $preferred)\n";
        }
    }
}

if (!$anyForbiddenFound) {
    echo "   - No conflicting alternative files found. Excellent.\n";
}

echo "\nDone.\n";

