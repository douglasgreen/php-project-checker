#!/usr/bin/env php
<?php

/**
 * Sort package.json sections into conventional order.
 */

// Define the desired order of keys
// @see https://docs.npmjs.com/cli/v10/configuring-npm/package-json
$keyOrders = [
    'name',
    'version',
    'private',
    'description',
    'keywords',
    'homepage',
    'bugs',
    'repository',
    'funding',
    'license',
    'author',
    'contributors',
    'type',
    'main',
    'module',
    'browser',
    'types',
    'typings',
    'exports',
    'imports',
    'bin',
    'man',
    'directories',
    'files',
    'publishConfig',
    'scripts',
    'config',
    'dependencies',
    'devDependencies',
    'peerDependencies',
    'peerDependenciesMeta',
    'optionalDependencies',
    'bundledDependencies',
    'engines',
    'os',
    'cpu',
    'workspaces',
    'packageManager',
];

// Load the package.json file
$packageJsonPath = 'package.json';
if (!file_exists($packageJsonPath)) {
    echo "package.json file not found.\n";
    exit(1);
}

$packageJsonContent = file_get_contents($packageJsonPath);
if ($packageJsonContent === false) {
    echo "Unable to load package.json\n";
    exit(1);
}

$packageData = json_decode($packageJsonContent, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    echo 'Error decoding JSON: ' . json_last_error_msg() . "\n";
    exit(1);
}

// Sort the array based on the specified key order
$sortedPackageData = [];
foreach ($keyOrders as $key) {
    if (array_key_exists($key, $packageData)) {
        $sortedPackageData[$key] = $packageData[$key];
        unset($packageData[$key]);
    }
}

// Append any remaining keys that were not in the specified order
foreach ($packageData as $key => $value) {
    $sortedPackageData[$key] = $value;
}

// Encode the sorted array back to JSON
$sortedPackageJsonContent = json_encode(
    $sortedPackageData,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
);

if ($sortedPackageJsonContent === false) {
    echo 'Error encoding JSON: ' . json_last_error_msg() . "\n";
    exit(1);
}

// Write the JSON content back to the package.json file
$sortedPackageJsonContent .= "\n";
file_put_contents($packageJsonPath, $sortedPackageJsonContent);

if ($packageJsonContent === $sortedPackageJsonContent) {
    echo "package.json was already sorted\n";
} else {
    echo "package.json has been sorted and updated successfully.\n";
}
