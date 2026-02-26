#!/usr/bin/env php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use DouglasGreen\PHPProjectChecker\ConfigDateChecker;

$targetDir = realpath(__DIR__ . '/../templates');
$checker = new ConfigDateChecker($targetDir);
$checker->run();
