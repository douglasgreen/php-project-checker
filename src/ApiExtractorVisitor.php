<?php

declare(strict_types=1);

namespace DouglasGreen\PHPProjectChecker;

use DouglasGreen\PHPProjectChecker\PrettyPrinter\Standard;
use DouglasGreen\PHPProjectChecker\Stmt\Class_;
use DouglasGreen\PHPProjectChecker\Stmt\ClassLike;
use DouglasGreen\PHPProjectChecker\Stmt\ClassMethod;
use DouglasGreen\PHPProjectChecker\Stmt\Enum_;
use DouglasGreen\PHPProjectChecker\Stmt\Function_;
use DouglasGreen\PHPProjectChecker\Stmt\Interface_;
use DouglasGreen\PHPProjectChecker\Stmt\Namespace_;
use DouglasGreen\PHPProjectChecker\Stmt\Trait_;

/**
 * Custom Visitor to extract API contextual structures
 */
class ApiExtractorVisitor extends NodeVisitorAbstract
{
    public string $namespace = '';

    public array $classes = [];

    public array $functions = [];

    private ?string $currentClass = null;

    private readonly Standard $printer;

    public function __construct(Standard $printer)
    {
        $this->printer = $printer;
    }

    public function enterNode(Node $node)
    {
        // Track namespace
        if ($node instanceof Namespace_) {
            $this->namespace = $node->name ? $node->name->toString() : '';
        }

        // Handle Classes, Interfaces, Traits, Enums
        if ($node instanceof ClassLike) {
            if ($node->name === null) {
                return null;
            } // Skip anonymous classes

            $this->currentClass = $node->name->toString();

            $type = match (true) {
                $node instanceof Interface_ => 'Interface',
                $node instanceof Trait_ => 'Trait',
                $node instanceof Enum_ => 'Enum',
                default => 'Class',
            };

            // Clone node to safely strip its contents before pretty-printing the signature
            $cleanNode = clone $node;
            $cleanNode->stmts = []; // Remove internal statements (methods/properties)
            $cleanNode->setAttribute('comments', []);
            $cleanNode->attrGroups = [];

            $signature = $this->printer->prettyPrint([$cleanNode]);
            $signature = trim(preg_replace('/\{\s*\}\s*$/', '', $signature)); // Strip trailing empty braces

            $this->classes[$this->currentClass] = [
                'type' => $type,
                'signature' => $signature,
                'docblock' => $node->getDocComment() ? $node->getDocComment()->getText() : '',
                'attributes' => $this->formatAttributes($node->attrGroups),
                'methods' => [],
            ];
        }

        // Handle Public Class Methods
        // Include public methods (or those in interfaces where visibility isn't explicitly declared)
        if ($node instanceof ClassMethod && $this->currentClass && (!$node->isPrivate() && !$node->isProtected())) {
            $cleanNode = clone $node;
            $cleanNode->stmts = null;
            // Strip method body
            $cleanNode->setAttribute('comments', []);
            $cleanNode->attrGroups = [];
            $signature = $this->printer->prettyPrint([$cleanNode]);
            $signature = trim(rtrim($signature, ';'));
            // Strip trailing semicolon
            $this->classes[$this->currentClass]['methods'][] = [
                'signature' => $signature,
                'docblock' => $node->getDocComment() ? $node->getDocComment()->getText() : '',
                'attributes' => $this->formatAttributes($node->attrGroups),
            ];
        }

        // Handle Standalone Public Functions
        if ($node instanceof Function_) {
            $cleanNode = clone $node;
            $cleanNode->stmts = []; // Fix: Use empty array instead of null for PHP-Parser v5 compatibility
            $cleanNode->setAttribute('comments', []);
            $cleanNode->attrGroups = [];

            $signature = $this->printer->prettyPrint([$cleanNode]);
            $signature = trim(preg_replace('/\{\s*\}\s*$/', '', $signature)); // Strip trailing empty braces

            $this->functions[] = [
                'signature' => $signature,
                'docblock' => $node->getDocComment() ? $node->getDocComment()->getText() : '',
                'attributes' => $this->formatAttributes($node->attrGroups),
            ];
        }

        return null;
    }

    public function leaveNode(Node $node): void
    {
        if ($node instanceof ClassLike && $node->name !== null) {
            $this->currentClass = null; // Leaving class scope
        }
    }

    /**
     * Cleverly format PHP Attributes by leveraging the PrettyPrinter on a dummy class
     */
    private function formatAttributes(array $attrGroups): string
    {
        if ($attrGroups === []) {
            return '';
        }

        $dummy = new Class_('Dummy', ['attrGroups' => $attrGroups]);
        $printed = $this->printer->prettyPrint([$dummy]);
        $lines = explode("\n", $printed);

        $attrs = [];
        foreach ($lines as $line) {
            if (str_starts_with(trim($line), '#[')) {
                $attrs[] = trim($line);
            }
        }

        return implode(' ', $attrs);
    }
}
