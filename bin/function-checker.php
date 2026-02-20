#!/usr/bin/env php
<?php

use DouglasGreen\PHPProjectChecker\FunctionAnalyzer;

// Main execution
try {
    $gitRoot = $argv[1] ?? null;
    $analyzer = new FunctionAnalyzer($gitRoot);
    $analyzer->analyze();
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
    exit(1);
}
