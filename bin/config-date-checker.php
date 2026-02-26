#!/usr/bin/env php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use DouglasGreen\PHPProjectChecker\ConfigDateChecker;

$options = getopt('', ['fix']);
$fix = isset($options['fix']);

$targetDir = realpath(__DIR__ . '/../templates');
$checker = new ConfigDateChecker($targetDir, $fix);
$checker->run();
