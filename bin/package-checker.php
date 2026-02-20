#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use DouglasGreen\PHPProjectChecker\PackageJsonChecker;

// CLI Entry point
$options = getopt('d:c:', ['directory:', 'config:']);
$directory = $options['d'] ?? $options['directory'] ?? getcwd();
$configFile = $options['c'] ?? $options['config'] ?? '';

$checker = new PackageJsonChecker($directory, $configFile);
$checker->run();
