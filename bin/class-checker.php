#!/usr/bin/env php
<?php

use DouglasGreen\PHPProjectChecker\ClassAnalyzer;

// Main execution
try {
    $gitRoot = $argv[1] ?? null;
    $analyzer = new ClassAnalyzer($gitRoot);
    $analyzer->analyze();
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
    exit(1);
}
