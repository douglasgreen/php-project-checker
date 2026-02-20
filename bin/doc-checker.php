#!/usr/bin/env php
<?php

declare(strict_types=1);

use DouglasGreen\PHPProjectChecker\DocStandardsChecker;

// CLI Entry point
$directory = $argv[1] ?? getcwd();
$checker = new DocStandardsChecker($directory);
$checker->run();
