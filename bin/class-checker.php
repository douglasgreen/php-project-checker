#!/usr/bin/env php
<?php

use DouglasGreen\PHPProjectChecker\ClassAnalyzer;

// Main execution
try {
    $gitRoot = $argv[1] ?? null;
    $analyzer = new ClassAnalyzer($gitRoot);
    $analyzer->analyze();
} catch (Exception $exception) {
    echo 'Error: ' . $exception->getMessage() . "\n";
    exit(1);
}
