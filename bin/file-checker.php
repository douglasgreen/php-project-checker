#!/usr/bin/env php
<?php

/**
 * Repository Reporter using git ls-files
 */

require __DIR__ . '/../vendor/autoload.php';

use DouglasGreen\PHPProjectChecker\RepoMapBuilder;

// Parse command line arguments
$fixMode = false;
foreach ($argv as $arg) {
    if ($arg === '--fix') {
        $fixMode = true;
    }
}

// 1. Get list of files from git
$repoMapBuilder = new RepoMapBuilder();
$files = $repoMapBuilder->getAllFiles();

if ($files === []) {
    echo "Error: This directory does not appear to be a git repository or git is not installed.\n";
    exit(1);
}

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
    'mjs'  => 0,
    'php'  => 0,
    'sh'   => 0,
    'sql'  => 0,
    'ts'   => 0,
    'tsx'  => 0,
    'twig' => 0,
    'vue'  => 0,
    'yaml' => 0,
];

$specificFilesToCheck = [
    'composer.json', 'eslint.config.mjs', '.gitignore',
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
    if (preg_match('/\.css$/', $file)) $extensionCounts['css']++;
    if (preg_match('/\.html?$/', $file)) $extensionCounts['html']++;
    if (preg_match('/\.js$/', $file)) $extensionCounts['js']++;
    if (preg_match('/\.json$/', $file)) $extensionCounts['json']++;
    if (preg_match('/\.jsx$/', $file)) $extensionCounts['jsx']++;
    if (preg_match('/\.md$/', $file)) $extensionCounts['md']++;
    if (preg_match('/\.mjs$/', $file)) $extensionCounts['mjs']++;
    if (preg_match('/\.php$/', $file)) $extensionCounts['php']++;
    if (preg_match('/\.sh$/', $file)) $extensionCounts['sh']++;
    if (preg_match('/\.sql$/', $file)) $extensionCounts['sql']++;
    if (preg_match('/\.ts$/', $file)) $extensionCounts['ts']++;
    if (preg_match('/\.tsx$/', $file)) $extensionCounts['tsx']++;
    if (preg_match('/\.twig$|twig\.html$/', $file)) $extensionCounts['twig']++;
    if (preg_match('/\.vue$/', $file)) $extensionCounts['vue']++;
    if (preg_match('/\.(yml|yaml)$/', $file)) $extensionCounts['yaml']++;

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
$hasAnyFiles = false;
foreach ($extensionCounts as $type => $count) {
    if ($count > 0) {
        $hasAnyFiles = true;
        printf("   - %-10s : %d\n", $type, $count);
    }
}
if (!$hasAnyFiles) {
    echo "   - None found.\n";
}

// Requirement 4
echo "\n4. Specific Top-level Files Status (Preferred):\n";
if (!empty($foundSpecificFiles)) {
    foreach ($foundSpecificFiles as $file) {
        printf("   - %s\n", $file);
    }
} else {
    echo "   - None found.\n";
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

// --- Fix Mode: Copy Template Files ---
if ($fixMode) {
    echo "\n=== FIX MODE: Copying Template Files ===\n\n";

    $sourceTemplatesDir = __DIR__ . '/../templates';
    $targetDir = getcwd();

    if (!is_dir($sourceTemplatesDir)) {
        echo "Error: Templates directory not found at $sourceTemplatesDir\n";
        exit(1);
    }

    // Define always-copy templates
    $alwaysCopy = [
        'git',
        'md',
        'prettier',
    ];

    // Always copy package.json from js
    $alwaysCopyFiles = [
        'js/package.json',
    ];

    // Define conditional templates based on file types
    $conditionalTemplates = [
        'css'  => ['css'],
        'js'   => ['js'],
        'jsx'  => ['js'],
        'mjs'   => ['js'],
        'php'  => ['php'],
        'sh'   => ['bash'],
        'ts'   => ['js'],
        'tsx'  => ['js'],
        'twig' => ['twig'],
        'vue'  => ['js'],
        'yaml' => ['yaml', 'js'],
    ];

    $copiedFiles = [];
    $errors = [];

    /**
     * Copy a directory recursively from source to target.
     */
    $copyDirectory = function (string $sourcePath, string $targetPath, array &$copiedFiles, array &$errors) use (&$copyDirectory): void {
        if (!is_dir($sourcePath)) {
            return;
        }

        if (!is_dir($targetPath)) {
            if (!mkdir($targetPath, 0755, true)) {
                $errors[] = "Failed to create directory: $targetPath";
                return;
            }
        }

        $items = scandir($sourcePath);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $sourceItem = $sourcePath . '/' . $item;
            $targetItem = $targetPath . '/' . $item;

            if (is_dir($sourceItem)) {
                $copyDirectory($sourceItem, $targetItem, $copiedFiles, $errors);
            } elseif (is_file($sourceItem)) {
                if (copy($sourceItem, $targetItem)) {
                    $copiedFiles[] = substr($targetItem, strlen(getcwd()) + 1);
                } else {
                    $errors[] = "Failed to copy: $sourceItem -> $targetItem";
                }
            }
        }
    };

    // Copy always-copy directories
    foreach ($alwaysCopy as $templateDir) {
        $sourcePath = $sourceTemplatesDir . '/' . $templateDir;
        $targetPath = $targetDir;
        echo "Copying templates/$templateDir/* ...\n";
        $copyDirectory($sourcePath, $targetPath, $copiedFiles, $errors);
    }

    // Copy always-copy files
    foreach ($alwaysCopyFiles as $templateFile) {
        $sourcePath = $sourceTemplatesDir . '/' . $templateFile;
        $targetPath = $targetDir . '/' . basename($templateFile);
        if (is_file($sourcePath)) {
            $targetDirForFile = dirname($targetPath);
            if (!is_dir($targetDirForFile)) {
                mkdir($targetDirForFile, 0755, true);
            }
            if (copy($sourcePath, $targetPath)) {
                $copiedFiles[] = basename($templateFile);
                echo "Copying templates/$templateFile\n";
            } else {
                $errors[] = "Failed to copy: $sourcePath -> $targetPath";
            }
        }
    }

    // Copy conditional templates based on file type counts
    foreach ($conditionalTemplates as $fileType => $templateDirs) {
        if ($extensionCounts[$fileType] > 0) {
            foreach ($templateDirs as $templateDir) {
                $sourcePath = $sourceTemplatesDir . '/' . $templateDir;
                $targetPath = $targetDir;
                // Skip js/package.json if already copied
                if ($templateDir === 'js') {
                    echo "Copying templates/$templateDir/* (excluding package.json if exists)...\n";
                    if (is_dir($sourcePath)) {
                        $items = scandir($sourcePath);
                        foreach ($items as $item) {
                            if ($item === '.' || $item === '..') {
                                continue;
                            }
                            // Skip package.json as it's handled separately
                            if ($item === 'package.json') {
                                continue;
                            }
                            $sourceItem = $sourcePath . '/' . $item;
                            $targetItem = $targetPath . '/' . $item;
                            if (is_dir($sourceItem)) {
                                $copyDirectory($sourceItem, $targetItem, $copiedFiles, $errors);
                            } elseif (is_file($sourceItem)) {
                                if (copy($sourceItem, $targetItem)) {
                                    $copiedFiles[] = $item;
                                } else {
                                    $errors[] = "Failed to copy: $sourceItem -> $targetItem";
                                }
                            }
                        }
                    }
                } else {
                    echo "Copying templates/$templateDir/* ...\n";
                    $copyDirectory($sourcePath, $targetPath, $copiedFiles, $errors);
                }
            }
        }
    }

    // Remove duplicates from copied files
    $copiedFiles = array_unique($copiedFiles);

    // Report results
    if (!empty($copiedFiles)) {
        echo "\nCopied " . count($copiedFiles) . " file(s):\n";
        foreach ($copiedFiles as $file) {
            echo "   - $file\n";
        }
    }

    if (!empty($errors)) {
        echo "\nErrors:\n";
        foreach ($errors as $error) {
            echo "   - $error\n";
        }
    }

    if (empty($copiedFiles) && empty($errors)) {
        echo "No files needed to be copied.\n";
    }
}

echo "\nDone.\n";

