#!/usr/bin/env php
<?php

/**
 * Sort composer.json sections into conventional order.
 */

// Define the desired order of keys
// @see https://getcomposer.org/doc/04-schema.md
$keyOrders = [
    'name',
    'description',
    'version',
    'type',
    'keywords',
    'homepage',
    'readme',
    'time',
    'license',
    'authors',
    'support',
    'funding',
    'require',
    'require-dev',
    'conflict',
    'replace',
    'provide',
    'suggest',
    'autoload',
    'autoload-dev',
    'include-path',
    'target-dir',
    'minimum-stability',
    'prefer-stable',
    'repositories',
    'config',
    'scripts',
    'extra',
    'bin',
    'archive',
    'abandoned',
    '_comment',
];

// Load the composer.json file
$composerJsonPath = 'composer.json';
if (!file_exists($composerJsonPath)) {
    echo "composer.json file not found.\n";
    exit(1);
}

$composerJsonContent = file_get_contents($composerJsonPath);
if ($composerJsonContent === false) {
    echo "Unable to load composer.json\n";
    exit(1);
}
$composerData = json_decode($composerJsonContent, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    echo 'Error decoding JSON: ' . json_last_error_msg() . "\n";
    exit(1);
}

// Sort the array based on the specified key order
$sortedComposerData = [];
foreach ($keyOrders as $key) {
    if (array_key_exists($key, $composerData)) {
        $sortedComposerData[$key] = $composerData[$key];
        unset($composerData[$key]);
    }
}

// Append any remaining keys that were not in the specified order
foreach ($composerData as $key => $value) {
    $sortedComposerData[$key] = $value;
}

// Encode the sorted array back to JSON
$sortedComposerJsonContent = json_encode(
    $sortedComposerData,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
);

if ($sortedComposerJsonContent === false) {
    echo 'Error encoding JSON: ' . json_last_error_msg() . "\n";
    exit(1);
}

// Write the JSON content back to the composer.json file
$sortedComposerJsonContent .= "\n";
file_put_contents($composerJsonPath, $sortedComposerJsonContent);

if ($composerJsonContent === $sortedComposerJsonContent) {
    echo "composer.json was already sorted\n";
} else {
    echo "composer.json has been sorted and updated successfully.\n";
}
