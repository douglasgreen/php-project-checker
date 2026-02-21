<?php

declare(strict_types=1);

namespace DouglasGreen\PHPProjectChecker;

use Exception;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class ClassAnalyzer
{
    /** @var array<string, array{file: string, line: int, type: string}> */
    private array $definitions = []; // ['FQCN' => ['file' => ..., 'line' => ..., 'type' => ...]]

    /** @var array<string, int> */
    private array $usages = []; // ['FQCN' => count]

    private readonly string $gitRoot;

    // Context for the current file being parsed
    private string $currentNamespace = '';

    /** @var array<string, string> */
    private array $useMap = []; // [Alias => FQCN]

    public function __construct(?string $gitRoot = null)
    {
        $root = $gitRoot ?: $this->findGitRoot();
        if ($root === null) {
            throw new Exception('Not a git repository (or any parent up to mount point)');
        }

        $this->gitRoot = $root;
    }

    public function analyze(): void
    {
        echo "Analyzing Git repository at: {$this->gitRoot}\n\n";

        $files = $this->getPhpFiles();
        echo 'Found ' . count($files) . " PHP files\n\n";

        foreach ($files as $file) {
            echo sprintf('Parsing: %s%s', $file, PHP_EOL);
            $this->parseFile($file);
        }

        echo "\n" . str_repeat('=', 80) . "\n";
        echo "ANALYSIS RESULTS\n";
        echo str_repeat('=', 80) . "\n\n";

        $this->printReport();
    }

    private function findGitRoot(): ?string
    {
        $dir = getcwd();
        if ($dir === false) {
            return null;
        }

        while ($dir !== '/') {
            if (is_dir($dir . '/.git')) {
                return $dir;
            }

            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }

            $dir = $parent;
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function getPhpFiles(): array
    {
        $files = [];
        $command = 'git -C ' . escapeshellarg($this->gitRoot) . " ls-files '*.php' 2>&1";
        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            // Fallback to recursive directory scan
            $this->scanDirectory($this->gitRoot, $files);
        } else {
            foreach ($output as $file) {
                // Skip unit tests
                if (preg_match('#^tests/#', $file)) {
                    continue;
                }

                $fullPath = $this->gitRoot . '/' . $file;
                if (file_exists($fullPath)) {
                    $files[] = $fullPath;
                }
            }
        }

        return $files;
    }

    /**
     * @param array<int, mixed> $files
     */
    private function scanDirectory(string $dir, array &$files): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                // Skip vendor and common excluded directories
                $path = $file->getPathname();
                if (!preg_match('#/(vendor|node_modules|\.git)/#', (string) $path)) {
                    $files[] = $path;
                }
            }
        }
    }

    private function parseFile(string $file): void
    {
        $content = (string) file_get_contents($file);
        $tokens = token_get_all($content);
        $tokenCount = count($tokens);

        $this->currentNamespace = '';
        $this->useMap = [];

        // First pass: Collect namespace and use statements
        // We do this in a separate loop or integrated?
        // Integrated is harder because use statements can appear after class in same file (rare but valid in general PHP, though PSR says one class per file).
        // Let's do a single pass assuming standard structure (Namespace -> Use -> Class) for simplicity,
        // or just process tokens sequentially.

        $classBraceLevel = -1; // Tracks if we are inside a class/trait/interface body
        $braceLevel = 0;

        for ($i = 0; $i < $tokenCount; ++$i) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                if ($token === '{') {
                    $braceLevel++;
                } elseif ($token === '}') {
                    if ($braceLevel === $classBraceLevel) {
                        $classBraceLevel = -1; // Exited class
                    }

                    $braceLevel--;
                }

                continue;
            }

            // Track namespace
            if ($token[0] === T_NAMESPACE) {
                $this->currentNamespace = $this->extractNamespaceName($tokens, $i, $tokenCount);
                continue;
            }

            // Track use statements (for imports) - only if outside class
            if ($token[0] === T_USE && $classBraceLevel === -1) {
                $this->parseUseStatement($tokens, $i, $tokenCount);
                continue;
            }

            // Detect Class/Trait/Interface Definition
            if (in_array($token[0], [T_CLASS, T_TRAIT, T_INTERFACE])) {
                // Check for ::class syntax
                if ($token[0] === T_CLASS) {
                    $prev = $this->getPreviousNonWhitespaceToken($tokens, $i);
                    if ($prev && is_array($prev) && $prev[0] === T_DOUBLE_COLON) {
                        continue;
                    }
                }

                $name = $this->extractNameAfterKeyword($tokens, $i, $tokenCount);
                if ($name) {
                    $fqcn = $this->resolveName($name);
                    $type = $this->getTokenName($token[0]);

                    $this->definitions[$fqcn] = [
                        'file' => $file,
                        'line' => $token[2],
                        'type' => $type,
                    ];

                    // Parse extends/implements for usage tracking
                    $this->parseInheritance($tokens, $i, $tokenCount);

                    // Find the opening brace to track scope for 'use' inside classes
                    // We look for '{' after the definition
                    for ($j = $i + 1; $j < $tokenCount; ++$j) {
                        if (!is_array($tokens[$j]) && $tokens[$j] === '{') {
                            $classBraceLevel = $braceLevel + 1; // The level this class body will exist at
                            break;
                        }

                        // If we hit ';' before '{', it's an abstract class or interface without body?
                        // No, interfaces have braces. Abstract classes have braces.
                        // But traits might be composed?
                        // Actually, if we don't find '{', we aren't entering a body.
                        if (!is_array($tokens[$j]) && $tokens[$j] === ';') {
                            break;
                        }
                    }
                }

                continue;
            }

            // Detect Trait Usage inside classes (T_USE inside class scope)
            if ($token[0] === T_USE && $classBraceLevel !== -1) {
                $this->parseTraitUsage($tokens, $i, $tokenCount);
                continue;
            }

            // Detect Usage: New, Static, Instanceof
            if ($token[0] === T_NEW) {
                $this->parseNewUsage($tokens, $i, $tokenCount);
                continue;
            }

            if ($token[0] === T_INSTANCEOF) {
                $this->parseInstanceofUsage($tokens, $i, $tokenCount);
                continue;
            }

            // Static calls: Class::method or Class::CONST
            // We look for T_STRING followed by T_DOUBLE_COLON
            if ($token[0] === T_STRING) {
                $next = $this->getNextNonWhitespaceToken($tokens, $i, $tokenCount);
                // Check it's not self, static, parent
                if ($next && is_array($next) && $next[0] === T_DOUBLE_COLON && !in_array($token[1], ['self', 'static', 'parent'])) {
                    $this->incrementUsage($this->resolveName($token[1]));
                }
            }
        }
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function extractNamespaceName(array $tokens, int &$i, int $tokenCount): string
    {
        $namespace = '';
        for ($j = $i + 1; $j < $tokenCount; ++$j) {
            if (!is_array($tokens[$j])) {
                if ($tokens[$j] === ';' || $tokens[$j] === '{') {
                    break;
                }

                continue;
            }

            if ($tokens[$j][0] === T_STRING || $tokens[$j][0] === T_NS_SEPARATOR) {
                $namespace .= $tokens[$j][1];
            } elseif ($tokens[$j][0] === T_WHITESPACE) {
                continue;
            }
        }

        return $namespace;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function parseUseStatement(array $tokens, int &$i, int $tokenCount): void
    {
        // use A\B\C;
        // use A\B\C as D;
        // use A\B\{C, D as E}; // Group use - simplified handling

        $name = '';
        $alias = '';
        $inGroup = false;

        for ($j = $i + 1; $j < $tokenCount; ++$j) {
            $t = $tokens[$j];

            if (!is_array($t)) {
                if ($t === ';') {
                    break;
                }

                if ($t === '{') {
                    $inGroup = true;
                    $name = '';
                    continue;
                }

                if ($t === '}') {
                    $inGroup = false;
                    break;
                }

                if ($t === ',') {
                    $finalAlias = $alias ?: basename(str_replace('\\', '/', $name));
                    $this->useMap[$finalAlias] = $name;
                    $name = '';
                    $alias = '';
                    continue;
                }

                continue;
            }

            if ($t[0] === T_STRING || $t[0] === T_NS_SEPARATOR) {
                /** @var array{0: int, 1: string, 2: int} $t */
                $name .= $t[1];
            } elseif ($t[0] === T_AS) {
                $alias = ''; // Prepare to capture alias
            } elseif ($t[0] === T_WHITESPACE) {
                continue;
            }
        }

        // Register the last found name
        if ($name !== '') {
            $finalAlias = $alias ?: basename(str_replace('\\', '/', $name));
            $this->useMap[$finalAlias] = $name;
        }
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function parseInheritance(array $tokens, int &$i, int $tokenCount): void
    {
        // class Foo extends Bar implements Baz
        // Look for T_EXTENDS and T_IMPLEMENTS

        for ($j = $i + 1; $j < $tokenCount; ++$j) {
            if (!is_array($tokens[$j])) {
                if ($tokens[$j] === '{') {
                    break;
                }

                // Start of body, stop looking
                continue;
            }

            if ($tokens[$j][0] === T_EXTENDS) {
                $name = $this->extractNextName($tokens, $j, $tokenCount);
                if ($name) {
                    $this->incrementUsage($this->resolveName($name));
                }
            }

            if ($tokens[$j][0] === T_IMPLEMENTS) {
                // Can be a list
                for ($k = $j + 1; $k < $tokenCount; ++$k) {
                    if (!is_array($tokens[$k])) {
                        if ($tokens[$k] === '{') {
                            break 2;
                        }

                        if ($tokens[$k] === ',') {
                            continue;
                        }

                        continue;
                    }

                    if ($tokens[$k][0] === T_STRING) {
                        $this->incrementUsage($this->resolveName($tokens[$k][1]));
                    }
                }
            }
        }
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function parseTraitUsage(array $tokens, int &$i, int $tokenCount): void
    {
        // use Trait1, Trait2;
        for ($j = $i + 1; $j < $tokenCount; ++$j) {
            if (!is_array($tokens[$j])) {
                if ($tokens[$j] === ';') {
                    break;
                }

                if ($tokens[$j] === ',') {
                    continue;
                }

                // Skip complex trait adaptations rules for now (instead of insteadof/as)
                if ($tokens[$j] === '{') {
                    // Skip block inside trait use
                    $braceCount = 1;
                    for ($k = $j + 1; $k < $tokenCount; ++$k) {
                        if (!is_array($tokens[$k])) {
                            if ($tokens[$k] === '{') {
                                $braceCount++;
                            }

                            if ($tokens[$k] === '}') {
                                $braceCount--;
                                if ($braceCount === 0) {
                                    break;
                                }
                            }
                        }
                    }

                    break;
                }

                continue;
            }

            if ($tokens[$j][0] === T_STRING) {
                $this->incrementUsage($this->resolveName($tokens[$j][1]));
            }
        }
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function parseNewUsage(array $tokens, int &$i, int $tokenCount): void
    {
        // new ClassName()
        // new class () - anonymous
        for ($j = $i + 1; $j < $tokenCount; ++$j) {
            if (!is_array($tokens[$j])) {
                continue;
            }

            if ($tokens[$j][0] === T_WHITESPACE) {
                continue;
            }

            if ($tokens[$j][0] === T_CLASS) {
                // Anonymous class, check extends/implements?
                // For now, skip anonymous class usage tracking to keep it simple.
                return;
            }

            if ($tokens[$j][0] === T_STRING) {
                $this->incrementUsage($this->resolveName($tokens[$j][1]));
                return;
            }
        }
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function parseInstanceofUsage(array $tokens, int &$i, int $tokenCount): void
    {
        // instanceof ClassName
        for ($j = $i + 1; $j < $tokenCount; ++$j) {
            if (!is_array($tokens[$j])) {
                continue;
            }

            if ($tokens[$j][0] === T_WHITESPACE) {
                continue;
            }

            if ($tokens[$j][0] === T_STRING) {
                $this->incrementUsage($this->resolveName($tokens[$j][1]));
                return;
            }
        }
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function extractNameAfterKeyword(array $tokens, int &$i, int $tokenCount): ?string
    {
        for ($j = $i + 1; $j < $tokenCount; ++$j) {
            if (!is_array($tokens[$j])) {
                continue;
            }

            if ($tokens[$j][0] === T_WHITESPACE) {
                continue;
            }

            if ($tokens[$j][0] === T_STRING) {
                return $tokens[$j][1];
            }

            // If we hit another keyword or operator, stop
            break;
        }

        return null;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function extractNextName(array $tokens, int &$i, int $tokenCount): ?string
    {
        for ($j = $i + 1; $j < $tokenCount; ++$j) {
            if (!is_array($tokens[$j])) {
                continue;
            }

            if ($tokens[$j][0] === T_WHITESPACE) {
                continue;
            }

            if ($tokens[$j][0] === T_STRING) {
                return $tokens[$j][1];
            }
        }

        return null;
    }

    private function resolveName(string $name): string
    {
        // If fully qualified
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        // Check use map
        if (isset($this->useMap[$name])) {
            return $this->useMap[$name];
        }

        // Check if it's a built-in type (ignore for usage tracking or treat as root)
        // For this report, we just namespace it if we are in a namespace
        if ($this->currentNamespace !== '') {
            // Check if it's already FQCN relative to namespace or imported
            // If not imported, assume it's in the current namespace
            return $this->currentNamespace . '\\' . $name;
        }

        return $name;
    }

    private function incrementUsage(string $fqcn): void
    {
        if (!isset($this->usages[$fqcn])) {
            $this->usages[$fqcn] = 0;
        }

        $this->usages[$fqcn]++;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array<int, mixed>|null
     */
    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     * @return array<int, mixed>|null
     */
    private function getPreviousNonWhitespaceToken(array $tokens, int $index): ?array
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
                continue;
            }

            return is_array($tokens[$i]) ? $tokens[$i] : [(string) $tokens[$i]];
        }

        return null;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array<int, mixed>|null
     */
    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     * @return array<int, mixed>|null
     */
    private function getNextNonWhitespaceToken(array $tokens, int $index, int $tokenCount): ?array
    {
        for ($i = $index + 1; $i < $tokenCount; $i++) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
                continue;
            }

            return is_array($tokens[$i]) ? $tokens[$i] : [(string) $tokens[$i]];
        }

        return null;
    }

    private function getTokenName(int $token): string
    {
        return match ($token) {
            T_CLASS => 'class',
            T_TRAIT => 'trait',
            T_INTERFACE => 'interface',
            default => 'unknown',
        };
    }

    private function printReport(): void
    {
        $unused = [];
        $used = [];

        foreach ($this->definitions as $fqcn => $info) {
            $count = $this->usages[$fqcn] ?? 0;

            if ($count === 0) {
                $unused[] = [
                    'name' => $fqcn,
                    'info' => $info,
                ];
            } else {
                $used[] = [
                    'name' => $fqcn,
                    'info' => $info,
                    'count' => $count,
                ];
            }
        }

        // Print unused
        echo 'UNUSED CLASSES/TRAIT/INTERFACES (' . count($unused) . "):\n";
        echo str_repeat('-', 80) . "\n";
        if ($unused === []) {
            echo "None found! All defined structures are being used.\n";
        } else {
            foreach ($unused as $item) {
                echo sprintf(
                    "%-50s [%s]\n  File: %s:%d\n\n",
                    $item['name'],
                    $item['info']['type'],
                    $item['info']['file'],
                    $item['info']['line'],
                );
            }
        }

        // Print used
        echo "\n" . str_repeat('=', 80) . "\n";
        echo 'USED CLASSES/TRAIT/INTERFACES (' . count($used) . "):\n";
        echo str_repeat('-', 80) . "\n";

        // Sort by usage count
        usort($used, fn (array $a, array $b): int => $b['count'] - $a['count']);

        foreach ($used as $item) {
            echo sprintf(
                "%-50s Used: %4d times\n",
                $item['name'],
                $item['count'],
            );
        }

        echo "\n" . str_repeat('=', 80) . "\n";
        echo "SUMMARY:\n";
        echo '  Total defined: ' . count($this->definitions) . "\n";
        echo '  Used: ' . count($used) . "\n";
        echo '  Unused: ' . count($unused) . "\n";
        echo str_repeat('=', 80) . "\n";
    }
}
