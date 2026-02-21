#!/usr/bin/env php
<?php

/**
 * PHP Public API Extractor for Chatbot Context
 *
 * Extracts namespaces, classes, interfaces, traits, enums, public methods,
 * attributes, and docblocks from tracked PHP files and outputs Markdown.
 */

$outputFile = 'chatbot_api_context.md';
$output = "# Project Public API Context\n\n";
$output .= "This document outlines the available public APIs, classes, and methods within the project.\n\n";

// ==========================================
// 1. Extract Composer.json Data
// ==========================================
$composerFile = 'composer.json';
if (file_exists($composerFile)) {
    $composerData = json_decode(file_get_contents($composerFile), true);
    $output .= "## Composer Dependencies\n\n";

    $deps = array_merge(
        $composerData['require'] ?? [],
        $composerData['require-dev'] ?? []
    );

    if (!empty($deps)) {
        foreach ($deps as $pkg => $version) {
            $output .= "- `$pkg`: `$version`\n";
        }
    } else {
        $output .= "*No dependencies found.*\n";
    }
    $output .= "\n---\n\n";
}

// ==========================================
// 2. Retrieve tracked PHP files
// ==========================================
exec('git ls-files 2>/dev/null', $files, $returnCode);

if ($returnCode !== 0) {
    die("Error: This script must be run inside a valid git repository.\n");
}

$phpFiles = array_filter($files, function($file) {
    return pathinfo($file, PATHINFO_EXTENSION) === 'php' && is_file($file);
});

// Helper function to clean and flatten DocBlocks to save AI tokens
function cleanDocBlock($doc) {
    $lines = explode("\n", $doc);
    $cleaned = [];
    foreach ($lines as $line) {
        $line = trim($line);
        // Strip the standard comment asterisks and slashes
        $line = ltrim($line, "/* \t");
        $line = rtrim($line, "*/ \t");
        if ($line !== '') {
            $cleaned[] = $line;
        }
    }
    return implode(' ', $cleaned);
}

// ==========================================
// 3. Parse PHP Files
// ==========================================
$output .= "## Source Files API\n\n";

foreach ($phpFiles as $file) {
    $code = file_get_contents($file);
    $fileOutput = "";
    $hasApi = false;

    // Extract Namespace
    $namespace = '';
    if (preg_match('/namespace\s+([^;{\s]+)/', $code, $nsMatch)) {
        $namespace = $nsMatch[1];
        $fileOutput .= "**Namespace**: `{$namespace}`\n\n";
    }

    // Extract Structs (Class, Interface, Trait, Enum)
    // Grabs DocBlocks, Attributes, Modifiers, Type, Name, and any Extends/Implements text up to the opening bracket '{'
    $structRegex = '/(?:(?P<doc>\/\*\*[\s\S]*?\*\/)\s+)?(?:(?P<attr>#\[[\s\S]*?\])\s+)?(?P<mod>(?:abstract\s+|final\s+|readonly\s+)*)(?P<type>class|interface|trait|enum)\s+(?P<name>[a-zA-Z0-9_]+)(?P<extends>[^{]*)/';

    if (preg_match_all($structRegex, $code, $structMatches, PREG_SET_ORDER)) {
        foreach ($structMatches as $match) {
            $hasApi = true;
            $type = ucfirst($match['type']);
            $name = trim($match['name']);
            $mod = trim($match['mod']);
            $extends = trim(preg_replace('/\s+/', ' ', $match['extends']));

            $fileOutput .= "#### {$type}: `{$mod} {$name} {$extends}`\n";

            if (!empty($match['attr'])) {
                $attr = trim(preg_replace('/\s+/', ' ', $match['attr']));
                $fileOutput .= "> **Attributes:** `{$attr}`\n";
            }
            if (!empty($match['doc'])) {
                $doc = cleanDocBlock($match['doc']);
                $fileOutput .= "> **Docs:** `{$doc}`\n";
            }
            $fileOutput .= "\n";
        }
    }

    // Extract Public Methods
    // Grabs Docblocks, Attributes, looks for the word "public", and grabs the signature up to the opening bracket '{' or semicolon ';'
    $methodRegex = '/(?:(?P<doc>\/\*\*[\s\S]*?\*\/)\s+)?(?:(?P<attr>#\[[\s\S]*?\])\s+)?(?P<mod>(?:(?:public|static|final|abstract)\s+)*public(?:\s+(?:static|final|abstract))*\s+)function\s+(?P<name>[a-zA-Z0-9_]+)\s*(?P<sig>[^;{]+)/';

    if (preg_match_all($methodRegex, $code, $methodMatches, PREG_SET_ORDER)) {
        $hasApi = true;
        $fileOutput .= "**Public Methods:**\n\n";
        foreach ($methodMatches as $match) {
            $mod = trim(preg_replace('/\s+/', ' ', $match['mod']));
            $name = trim($match['name']);
            $sig = trim(preg_replace('/\s+/', ' ', $match['sig']));

            $fileOutput .= "- `{$mod} function {$name}{$sig}`\n";

            if (!empty($match['attr'])) {
                $attr = trim(preg_replace('/\s+/', ' ', $match['attr']));
                $fileOutput .= "  - *Attributes:* `{$attr}`\n";
            }
            if (!empty($match['doc'])) {
                $doc = cleanDocBlock($match['doc']);
                $fileOutput .= "  - *Docs:* `{$doc}`\n";
            }
        }
        $fileOutput .= "\n";
    }

    if ($hasApi) {
        $output .= "### File: `{$file}`\n";
        $output .= $fileOutput;
        $output .= "---\n\n";
    }
}

file_put_contents($outputFile, $output);
echo "Successfully extracted API documentation to {$outputFile}\n";

?>
