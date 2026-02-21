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
// 3. Parse PHP Files using Tokenizer
// ==========================================
$output .= "## Source Files API\n\n";

foreach ($phpFiles as $file) {
    $code = file_get_contents($file);
    $fileOutput = "";
    $hasApi = false;

    // Extract Namespace using regex (simple and reliable)
    // Must be at start of line (or after <?php) and followed by whitespace and the namespace name
    $namespace = '';
    if (preg_match('/^\s*namespace\s+([a-zA-Z_][a-zA-Z0-9_\\\]*)/m', $code, $nsMatch)) {
        $namespace = $nsMatch[1];
        $fileOutput .= "**Namespace**: `{$namespace}`\n\n";
    }

    // Use PHP tokenizer to properly parse the file
    $tokens = token_get_all($code);
    $tokenCount = count($tokens);

    $i = 0;
    while ($i < $tokenCount) {
        $token = $tokens[$i];

        // Skip non-array tokens (single character tokens like '{', '}', etc.)
        if (is_array($token) === false) {
            $i++;
            continue;
        }

        $tokenType = $token[0];
        $tokenContent = $token[1];
        $tokenLine = $token[2];

        // Look for class, interface, trait, or enum keywords ONLY (not usage)
        if (in_array($tokenType, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
            $hasApi = true;

            // Determine the type
            $type = match ($tokenType) {
                T_CLASS => 'Class',
                T_INTERFACE => 'Interface',
                T_TRAIT => 'Trait',
                T_ENUM => 'Enum',
                default => 'Class',
            };

            // Look backwards for docblock, attributes, and modifiers
            $docBlock = '';
            $attributes = '';
            $modifiers = '';

            $j = $i - 1;
            while ($j >= 0) {
                $prevToken = $tokens[$j];

                // Skip non-array tokens
                if (is_array($prevToken) === false) {
                    // Skip whitespace and single-char tokens
                    if (is_string($prevToken) && trim($prevToken) === '') {
                        $j--;
                        continue;
                    }
                    $j--;
                    continue;
                }

                $prevType = $prevToken[0];

                // Check for docblock
                if ($prevType === T_DOC_COMMENT) {
                    $docBlock = cleanDocBlock($prevToken[1]);
                    $j--;
                    continue;
                }

                // Check for attribute (PHP 8+)
                if ($prevType === T_ATTRIBUTE) {
                    // Collect all attributes going backwards
                    $attrParts = [];
                    $k = $j;
                    while ($k >= 0) {
                        $attrToken = $tokens[$k];
                        if (is_array($attrToken)) {
                            if ($attrToken[0] === T_ATTRIBUTE) {
                                $attrParts[] = $attrToken[1];
                            }
                        } elseif (is_string($attrToken) && $attrToken === ',') {
                            $attrParts[] = ',';
                        } elseif (is_string($attrToken) && trim($attrToken) === '') {
                            // skip
                        } else {
                            break;
                        }
                        $k--;
                    }
                    if (!empty($attrParts)) {
                        $attributes = implode(' ', array_reverse($attrParts));
                    }
                    $j = $k;
                    continue;
                }

                // Check for modifiers (abstract, final, readonly)
                if (in_array($prevType, [T_ABSTRACT, T_FINAL, T_READONLY], true)) {
                    $modifiers = $prevToken[1] . ' ' . $modifiers;
                    $j--;
                    continue;
                }

                // Check for visibility (public, private, protected) - though not typical before class
                if (in_array($prevType, [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC], true)) {
                    $modifiers = $prevToken[1] . ' ' . $modifiers;
                    $j--;
                    continue;
                }

                // Stop when we hit something else
                break;
            }

            // Look forwards for the class name and extends/implements
            $name = '';
            $extends = '';

            $k = $i + 1;
            while ($k < $tokenCount) {
                $nextToken = $tokens[$k];

                // Skip non-array tokens
                if (is_array($nextToken) === false) {
                    if (is_string($nextToken)) {
                        // If we hit '{', we've gone past the declaration line
                        if ($nextToken === '{') {
                            break;
                        }
                        // Collect extends/implements text
                        if (in_array($nextToken, ['extends', 'implements', ':', ','], true)) {
                            $extends .= ' ' . $nextToken;
                        }
                    }
                    $k++;
                    continue;
                }

                $nextType = $nextToken[0];

                // The next string token after 'class' should be the name
                if ($nextType === T_STRING) {
                    if ($name === '') {
                        $name = $nextToken[1];
                    } else {
                        // This is part of extends/implements
                        $extends .= ' ' . $nextToken[1];
                    }
                } elseif (in_array($nextType, [T_EXTENDS, T_IMPLEMENTS], true)) {
                    $extends .= ' ' . $nextToken[1];
                } elseif ($nextType === T_NS_SEPARATOR) {
                    $extends .= '\\';
                } elseif ($nextType === T_NAME_QUALIFIED || $nextType === T_NAME_FULLY_QUALIFIED) {
                    $extends .= ' ' . $nextToken[1];
                }

                $k++;
            }

            // Clean up extends
            $extends = trim(preg_replace('/\s+/', ' ', $extends));

            // Output the struct info only if we found an actual name
            if ($name !== '') {
                $fileOutput .= "#### {$type}: `{$modifiers}{$name} {$extends}`\n";

                if (!empty($attributes)) {
                    $fileOutput .= "> **Attributes:** `{$attributes}`\n";
                }
                if (!empty($docBlock)) {
                    $fileOutput .= "> **Docs:** `{$docBlock}`\n";
                }
                $fileOutput .= "\n";
            }
        }

        $i++;
    }

    // Extract Public Methods using tokenizer
    $i = 0;
    $foundMethods = [];
    while ($i < $tokenCount) {
        $token = $tokens[$i];

        if (is_array($token) === false) {
            $i++;
            continue;
        }

        // Look for function keyword
        if ($token[0] === T_FUNCTION) {
            // Look backwards for docblock, attributes, and visibility/static
            $docBlock = '';
            $attributes = '';
            $modifiers = '';

            $j = $i - 1;
            while ($j >= 0) {
                $prevToken = $tokens[$j];

                if (is_array($prevToken) === false) {
                    if (is_string($prevToken) && trim($prevToken) === '') {
                        $j--;
                        continue;
                    }
                    $j--;
                    continue;
                }

                $prevType = $prevToken[0];

                // Check for docblock
                if ($prevType === T_DOC_COMMENT) {
                    $docBlock = cleanDocBlock($prevToken[1]);
                    $j--;
                    continue;
                }

                // Check for attribute
                if ($prevType === T_ATTRIBUTE) {
                    $attrParts = [];
                    $k = $j;
                    while ($k >= 0) {
                        $attrToken = $tokens[$k];
                        if (is_array($attrToken)) {
                            if ($attrToken[0] === T_ATTRIBUTE) {
                                $attrParts[] = $attrToken[1];
                            }
                        } elseif (is_string($attrToken) && $attrToken === ',') {
                            $attrParts[] = ',';
                        } elseif (is_string($attrToken) && trim($attrToken) === '') {
                            // skip
                        } else {
                            break;
                        }
                        $k--;
                    }
                    if (!empty($attrParts)) {
                        $attributes = implode(' ', array_reverse($attrParts));
                    }
                    $j = $k;
                    continue;
                }

                // Check for modifiers
                if (in_array($prevType, [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL], true)) {
                    $modifiers = $prevToken[1] . ' ' . $modifiers;
                    $j--;
                    continue;
                }

                // Stop when we hit something else
                break;
            }

            // Look forwards for the function name and signature
            $funcName = '';
            $signature = '';

            $k = $i + 1;
            while ($k < $tokenCount) {
                $nextToken = $tokens[$k];

                if (is_array($nextToken) === false) {
                    if (is_string($nextToken)) {
                        if ($nextToken === '(') {
                            // Start collecting signature
                            $sigStart = $k;
                            $braceCount = 1;
                            $sigK = $k + 1;
                            while ($sigK < $tokenCount && $braceCount > 0) {
                                $sigToken = $tokens[$sigK];
                                if (is_string($sigToken)) {
                                    if ($sigToken === '(') {
                                        $braceCount++;
                                    } elseif ($sigToken === ')') {
                                        $braceCount--;
                                        if ($braceCount === 0) {
                                            // Get everything from '(' to ')'
                                            for ($s = $sigStart; $s <= $sigK; $s++) {
                                                $signature .= is_array($tokens[$s]) ? $tokens[$s][1] : $tokens[$s];
                                            }
                                            break;
                                        }
                                    }
                                }
                                $sigK++;
                            }
                            $k = $sigK;
                            continue;
                        } elseif ($nextToken === '{' || $nextToken === ';') {
                            break;
                        }
                    }
                    $k++;
                    continue;
                }

                $nextType = $nextToken[0];

                if ($nextType === T_STRING && $funcName === '') {
                    $funcName = $nextToken[1];
                }

                $k++;
            }

            // Only include public methods
            if (str_contains($modifiers, 'public') || $modifiers === '') {
                $foundMethods[] = [
                    'name' => $funcName,
                    'signature' => $signature,
                    'modifiers' => trim($modifiers),
                    'attributes' => $attributes,
                    'docblock' => $docBlock,
                ];
            }
        }

        $i++;
    }

    if (!empty($foundMethods)) {
        $hasApi = true;
        $fileOutput .= "**Public Methods:**\n\n";
        foreach ($foundMethods as $method) {
            $mod = $method['modifiers'] ?: 'public';
            $fileOutput .= "- `{$mod} function {$method['name']}{$method['signature']}`\n";

            if (!empty($method['attributes'])) {
                $fileOutput .= "  - *Attributes:* `{$method['attributes']}`\n";
            }
            if (!empty($method['docblock'])) {
                $fileOutput .= "  - *Docs:* `{$method['docblock']}`\n";
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
