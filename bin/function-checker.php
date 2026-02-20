#!/usr/bin/env php
<?php

use DouglasGreen\PHPProjectChecker\FunctionAnalyzer;

// Main execution
try {
    $gitRoot = $argv[1] ?? null;
    $analyzer = new FunctionAnalyzer($gitRoot);
    $analyzer->analyze();
} catch (Exception $exception) {
    echo 'Error: ' . $exception->getMessage() . "\n";
    exit(1);
}
