#!/usr/bin/env php
<?php

/**
 * PHP Public API Extractor for Chatbot Context
 *
 * Extracts namespaces, classes, interfaces, traits, enums, public methods/functions,
 * attributes, and docblocks from tracked PHP files and outputs Markdown.
 *
 * Uses nikic/php-parser for robust AST-based parsing.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use DouglasGreen\PHPProjectChecker\ApiExtractorVisitor;

// ==========================================
// 0. Bootstrap & Setup
// ==========================================
$autoloadPaths = [
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
    getcwd() . '/vendor/autoload.php'
];

$autoloaderFound = false;
foreach ($autoloadPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $autoloaderFound = true;
        break;
    }
}

if (!$autoloaderFound) {
    die("Error: vendor/autoload.php not found. Please run 'composer install'.\n");
}

if (!class_exists(\PhpParser\ParserFactory::class)) {
    die("Error: nikic/php-parser is required. Please run 'composer require --dev nikic/php-parser'.\n");
}

use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter;
use PhpParser\NodeVisitor\NameResolver;

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

    $deps = $composerData['require'] ?? [];

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
    if (!$doc) return '';
    $lines = explode("\n", $doc);
    $cleaned = [];
    foreach ($lines as $line) {
        $line = trim($line);
        $line = ltrim($line, "/* \t");
        $line = rtrim($line, "*/ \t");
        if ($line !== '') {
            $cleaned[] = $line;
        }
    }
    return implode(' ', $cleaned);
}

// ==========================================
// 3. Parse PHP Files using AST
// ==========================================
$output .= "## Source Files API\n\n";

// Compatibility for PHP-Parser v4 and v5
$factory = new ParserFactory();
if (method_exists($factory, 'createForNewestSupportedVersion')) {
    $parser = $factory->createForNewestSupportedVersion();
} else {
    $parser = $factory->create(ParserFactory::PREFER_PHP7);
}

$printer = new PrettyPrinter\Standard();

// Process Each File
foreach ($phpFiles as $file) {
    $code = file_get_contents($file);

    try {
        $stmts = $parser->parse($code);
    } catch (PhpParser\Error $e) {
        echo "Parse error in $file: {$e->getMessage()}\n";
        continue; // Skip file and continue gracefully
    }

    if ($stmts === null) {
        continue;
    }

    $visitor = new ApiExtractorVisitor($printer);
    $traverser = new NodeTraverser();

    // NameResolver ensures types in signatures are properly mapped to Fully Qualified Class Names
    $traverser->addVisitor(new NameResolver());
    $traverser->addVisitor($visitor);

    $traverser->traverse($stmts);

    if (!empty($visitor->classes) || !empty($visitor->functions)) {
        $fileOutput = "";

        if ($visitor->namespace) {
            $fileOutput .= "**Namespace**: `{$visitor->namespace}`\n\n";
        }

        foreach ($visitor->classes as $classData) {
            $fileOutput .= "#### {$classData['type']}: `{$classData['signature']}`\n";

            if (!empty($classData['attributes'])) {
                $fileOutput .= "> **Attributes:** `{$classData['attributes']}`\n";
            }

            $cleanDoc = cleanDocBlock($classData['docblock']);
            if (!empty($cleanDoc)) {
                $fileOutput .= "> **Docs:** `{$cleanDoc}`\n";
            }
            $fileOutput .= "\n";

            if (!empty($classData['methods'])) {
                $fileOutput .= "**Public Methods:**\n\n";
                foreach ($classData['methods'] as $method) {
                    $fileOutput .= "- `{$method['signature']}`\n";

                    if (!empty($method['attributes'])) {
                        $fileOutput .= "  - *Attributes:* `{$method['attributes']}`\n";
                    }

                    $cleanMethodDoc = cleanDocBlock($method['docblock']);
                    if (!empty($cleanMethodDoc)) {
                        $fileOutput .= "  - *Docs:* `{$cleanMethodDoc}`\n";
                    }
                }
                $fileOutput .= "\n";
            }
        }

        if (!empty($visitor->functions)) {
            $fileOutput .= "**Public Functions:**\n\n";
            foreach ($visitor->functions as $func) {
                $fileOutput .= "- `{$func['signature']}`\n";

                if (!empty($func['attributes'])) {
                    $fileOutput .= "  - *Attributes:* `{$func['attributes']}`\n";
                }

                $cleanFuncDoc = cleanDocBlock($func['docblock']);
                if (!empty($cleanFuncDoc)) {
                    $fileOutput .= "  - *Docs:* `{$cleanFuncDoc}`\n";
                }
            }
            $fileOutput .= "\n";
        }

        $output .= "### File: `{$file}`\n";
        $output .= $fileOutput;
        $output .= "---\n\n";
    }
}

file_put_contents($outputFile, $output);
echo "Successfully extracted API documentation to {$outputFile}\n";
