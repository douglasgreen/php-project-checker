<?php
// modified: 2026-02-26

declare(strict_types=1);

use TwigCsFixer\Config\Config;
use TwigCsFixer\File\Finder;
use TwigCsFixer\Ruleset\Ruleset;
use TwigCsFixer\Standard\TwigCsFixer;

$ruleset = new Ruleset();
$ruleset->addStandard(new TwigCsFixer());

// Customize specific rules
$ruleset->overrideRule(new \TwigCsFixer\Rules\Delimiter\SpacingRule(1, 1));
$ruleset->overrideRule(new \TwigCsFixer\Rules\Whitespace\IndentRule(2, 'space', true));

$config = new Config();
$config->setRuleset($ruleset);
$config->setFinder(
    Finder::create()->in(__DIR__ . '/templates')
);

return $config;
