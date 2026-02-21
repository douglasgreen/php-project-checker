#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use DouglasGreen\PHPProjectChecker\GitLabCiChecker;

// CLI Entry point
$options = getopt('d:c:', ['directory:', 'config:']);
/** @var string $directory */
$directory = $options['d'] ?? $options['directory'] ?? getcwd();
/** @var string $configFile */
$configFile = $options['c'] ?? $options['config'] ?? '';

$checker = new GitLabCiChecker($directory, $configFile);
$checker->run();
