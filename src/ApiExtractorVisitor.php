<?php

declare(strict_types=1);

namespace DouglasGreen\PHPProjectChecker;

use PhpParser\Node\Identifier;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Name\Namespace_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard;

/**
 * Custom Visitor to extract API contextual structures
 */
class ApiExtractorVisitor extends NodeVisitorAbstract
{
    public string $namespace = '';

    /** @var array<string, array{type: string, signature: string, docblock: string, attributes: string, methods: array<array{signature: string, docblock: string, attributes: string}>}> */
    public array $classes = [];

    /** @var array<array{signature: string, docblock: string, attributes: string}> */
    public array $functions = [];

    private ?string $currentClass = null;

    public function __construct(private readonly Standard $printer)
    {
    }

    public function enterNode(Node $node): ?Node
    {
        // Track namespace
        if ($node instanceof Namespace_) {
            $this->namespace = $node->name ? $node->name->toString() : '';
        }

        // Handle Classes, Interfaces, Traits, Enums
        if ($node instanceof ClassLike) {
            if (!$node->name instanceof Identifier) {
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
            $signature = trim((string) preg_replace('/\{\s*\}\s*$/', '', $signature)); // Strip trailing empty braces

            $this->classes[$this->currentClass] = [
                'type' => $type,
                'signature' => $signature,
                'docblock' => $node->getDocComment() instanceof Doc ? $node->getDocComment()->getText() : '',
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
                'docblock' => $node->getDocComment() instanceof Doc ? $node->getDocComment()->getText() : '',
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
            $signature = trim((string) preg_replace('/\{\s*\}\s*$/', '', $signature)); // Strip trailing empty braces

            $this->functions[] = [
                'signature' => $signature,
                'docblock' => $node->getDocComment() instanceof Doc ? $node->getDocComment()->getText() : '',
                'attributes' => $this->formatAttributes($node->attrGroups),
            ];
        }

        return null;
    }

    public function leaveNode(Node $node): array|int|Node|null
    {
        if ($node instanceof ClassLike && $node->name instanceof Identifier) {
            $this->currentClass = null; // Leaving class scope
        }

        return null;
    }

    /**
     * Cleverly format PHP Attributes by leveraging the PrettyPrinter on a dummy class
     *
     * @param array<int, Node\Attribute> $attrGroups
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
