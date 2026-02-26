#!/usr/bin/env php
<?php

$targetDir = __DIR__ . '/../templates';

if (!is_dir($targetDir)) {
    echo "Error: Directory '$targetDir' not found.\n";
    exit(1);
}

/**
 * Recursively gets all files in a directory.
 */
function getRecursiveFiles($dir) {
    $files = [];
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            $files = array_merge($files, getRecursiveFiles($path));
        } else {
            $files[] = $path;
        }
    }
    return $files;
}

$dirFiles = getRecursiveFiles($targetDir);

// Use git ls-files to get tracked files in the repository
// Since the script runs in the root, this returns paths relative to the root.
$repoFilesOutput = shell_exec('git ls-files');
if ($repoFilesOutput === null) {
    echo "Error: Failed to execute 'git ls-files'. Ensure you are in a git repository.\n";
    exit(1);
}

$repoFiles = explode("\n", trim($repoFilesOutput));

// Create a mapping of filename (basename) to its full repo path(s)
// This handles cases where multiple files in different subdirectories have the same name.
$repoMap = [];
foreach ($repoFiles as $repoPath) {
    if (empty($repoPath)) continue;
    $name = basename($repoPath);
    $repoMap[$name][] = $repoPath;
}

/**
 * Extracts a modification date (YYYY-MM-DD) from the first 10 lines of a file.
 * Matches lines containing the word "modified" and a date.
 */
function getModificationDate($filePath) {
    if (!is_file($filePath) || !is_readable($filePath)) {
        return null;
    }

    $handle = fopen($filePath, 'r');
    if (!$handle) return null;

    $lineCount = 0;
    $foundDate = null;

    while (($line = fgets($handle)) !== false && $lineCount < 10) {
        $lineCount++;
        // Regex: case-insensitive "modified", followed by any chars, then YYYY-MM-DD
        if (preg_match('/modified.*(\d{4}-\d{2}-\d{2})/i', $line, $matches)) {
            $foundDate = $matches[1];
            break;
        }
    }
    fclose($handle);
    return $foundDate;
}

// Compare files in the target directory with files in the repository
echo "Checking files in '$targetDir' against repository...\n\n";

foreach ($dirFiles as $dirFilePath) {
    $fileName = basename($dirFilePath);

    // Check if a file with this name exists anywhere in the Git repository
    if (isset($repoMap[$fileName])) {
        $newDate = getModificationDate($dirFilePath);

        foreach ($repoMap[$fileName] as $repoFilePath) {
            // Don't compare a file against itself
            if (realpath($dirFilePath) === realpath(__DIR__ . '/../' . $repoFilePath)) {
                continue;
            }

            $oldDate = getModificationDate($repoFilePath);
            $oldDate = getModificationDate($repoFilePath);

            // If the date is missing in either file or they don't match, report it
            if ($oldDate === null || $newDate === null || $oldDate !== $newDate) {
                $displayOldDate = $oldDate ?? "[date missing]";
                $displayNewDate = $newDate ?? "[date missing]";

                echo "Outdated or Missing Date Found:\n";
                echo "  File in Repo: $repoFilePath\n";
                echo "  Old Date:     $displayOldDate\n";
                echo "  New Date:     $displayNewDate\n";
                echo "--------------------------------------------------\n";
            }
        }
    }
}
