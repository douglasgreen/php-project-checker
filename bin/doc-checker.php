#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use DouglasGreen\PHPProjectChecker\DocStandardsChecker;

// CLI Entry point
$directory = $argv[1] ?? getcwd();
$checker = new DocStandardsChecker($directory);
$checker->run();
