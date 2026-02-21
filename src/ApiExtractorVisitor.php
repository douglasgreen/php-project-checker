<?php

declare(strict_types=1);

namespace DouglasGreen\PHPProjectChecker;

/**
 * Custom Visitor to extract API contextual structures
 */
class ApiExtractorVisitor extends NodeVisitorAbstract {
    public string $namespace = '';
    public array $classes = [];
    public array $functions = [];

    private ?string $currentClass = null;
    private PrettyPrinter\Standard $printer;

    public function __construct(PrettyPrinter\Standard $printer) {
        $this->printer = $printer;
    }

    public function enterNode(Node $node) {
        // Track namespace
        if ($node instanceof Stmt\Namespace_) {
            $this->namespace = $node->name ? $node->name->toString() : '';
        }

        // Handle Classes, Interfaces, Traits, Enums
        if ($node instanceof Stmt\ClassLike) {
            if ($node->name === null) return null; // Skip anonymous classes

            $this->currentClass = $node->name->toString();

            $type = match(true) {
                $node instanceof Stmt\Interface_ => 'Interface',
                $node instanceof Stmt\Trait_ => 'Trait',
                $node instanceof Stmt\Enum_ => 'Enum',
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
                'methods' => []
            ];
        }

        // Handle Public Class Methods
        if ($node instanceof Stmt\ClassMethod && $this->currentClass) {
            // Include public methods (or those in interfaces where visibility isn't explicitly declared)
            if (!$node->isPrivate() && !$node->isProtected()) {
                $cleanNode = clone $node;
                $cleanNode->stmts = null; // Strip method body
                $cleanNode->setAttribute('comments', []);
                $cleanNode->attrGroups = [];

                $signature = $this->printer->prettyPrint([$cleanNode]);
                $signature = trim(rtrim($signature, ';')); // Strip trailing semicolon

                $this->classes[$this->currentClass]['methods'][] = [
                    'signature' => $signature,
                    'docblock' => $node->getDocComment() ? $node->getDocComment()->getText() : '',
                    'attributes' => $this->formatAttributes($node->attrGroups),
                ];
            }
        }

        // Handle Standalone Public Functions
        if ($node instanceof Stmt\Function_) {
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
    }

    public function leaveNode(Node $node) {
        if ($node instanceof Stmt\ClassLike && $node->name !== null) {
            $this->currentClass = null; // Leaving class scope
        }
    }

    /**
     * Cleverly format PHP Attributes by leveraging the PrettyPrinter on a dummy class
     */
    private function formatAttributes(array $attrGroups): string {
        if (empty($attrGroups)) return '';
        $dummy = new Stmt\Class_('Dummy', ['attrGroups' => $attrGroups]);
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
