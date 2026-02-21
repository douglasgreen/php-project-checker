<?php

declare(strict_types=1);

namespace DouglasGreen\PHPProjectChecker;

use Exception;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class FunctionAnalyzer
{
    private array $definitions = []; // ['functionName' => ['file' => ..., 'line' => ..., 'type' => ...]]

    private array $calls = []; // ['functionName' => count]

    private $gitRoot;

    public function __construct($gitRoot = null)
    {
        $this->gitRoot = $gitRoot ?: $this->findGitRoot();
        if (!$this->gitRoot) {
            throw new Exception('Not a git repository (or any parent up to mount point)');
        }
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

    private function findGitRoot(): string|false|null
    {
        $dir = getcwd();
        while ($dir !== '/') {
            if (is_dir($dir . '/.git')) {
                return $dir;
            }

            $dir = dirname($dir);
        }

        return null;
    }

    /**
     * @return mixed[]
     */
    private function getPhpFiles(): array
    {
        $files = [];
        $command = 'git -C ' . escapeshellarg((string) $this->gitRoot) . " ls-files '*.php' 2>&1";
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

    private function parseFile($file): void
    {
        $content = file_get_contents($file);
        $tokens = token_get_all($content);
        $tokenCount = count($tokens);

        $currentClass = null;
        $currentNamespace = '';

        for ($i = 0; $i < $tokenCount; ++$i) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                continue;
            }

            // Track namespace
            if ($token[0] === T_NAMESPACE) {
                $currentNamespace = $this->extractNamespace($tokens, $i);
            }

            // Track class name
            if ($token[0] === T_CLASS || $token[0] === T_TRAIT) {
                $currentClass = $this->extractClassName($tokens, $i, $currentNamespace);
            }

            // Find function definitions
            if ($token[0] === T_FUNCTION) {
                $funcName = $this->extractFunctionName($tokens, $i);
                if ($funcName) {
                    $fullName = $currentClass ? sprintf('%s::%s', $currentClass, $funcName) : $funcName;
                    if ($currentNamespace && !$currentClass) {
                        $fullName = sprintf('%s\%s', $currentNamespace, $funcName);
                    }

                    $this->definitions[$fullName] = [
                        'file' => $file,
                        'line' => $token[2],
                        'type' => $currentClass ? 'method' : 'function',
                    ];
                }
            }

            // Find function calls
            if ($token[0] === T_STRING) {
                // Look back to ensure this isn't a function definition
                $prevTokenIndex = $i - 1;
                while ($prevTokenIndex >= 0 && is_array($tokens[$prevTokenIndex]) && $tokens[$prevTokenIndex][0] === T_WHITESPACE) {
                    $prevTokenIndex--;
                }

                if ($prevTokenIndex >= 0 && is_array($tokens[$prevTokenIndex]) && $tokens[$prevTokenIndex][0] === T_FUNCTION) {
                    // This is a function definition, not a call - skip it
                    continue;
                }

                $this->extractFunctionCalls($tokens, $i, $tokenCount);
            }

            // Find method calls
            if ($token[0] === T_OBJECT_OPERATOR || $token[0] === T_DOUBLE_COLON) {
                $this->extractMethodCalls($tokens, $i, $tokenCount);
            }
        }
    }

    private function extractNamespace(array $tokens, int|float &$i): string
    {
        $namespace = '';
        $counter = count($tokens);
        for ($j = $i + 1; $j < $counter; ++$j) {
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

    private function extractClassName(array $tokens, float|int &$i, string $namespace)
    {
        $counter = count($tokens);
        for ($j = $i + 1; $j < $counter; ++$j) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                $className = $tokens[$j][1];

                return $namespace !== '' && $namespace !== '0' ? sprintf('%s\%s', $namespace, $className) : $className;
            }
        }
    }

    private function extractFunctionName(array $tokens, float|int &$i)
    {
        $counter = count($tokens);
        for ($j = $i + 1; $j < $counter; ++$j) {
            $token = $tokens[$j];
            if (is_array($token)) {
                if ($token[0] === T_STRING) {
                    return $token[1];
                }

                if ($token[0] === T_WHITESPACE) {
                    continue;
                }
            }

            // If we hit ( before finding a name, it's an anonymous function
            if ($token === '(') {
                return;
            }
        }
    }

    private function extractFunctionCalls(array $tokens, float|int &$i, int $tokenCount): void
    {
        $funcName = $tokens[$i][1];

        // Look ahead to see if next non-whitespace token is (
        for ($j = $i + 1; $j < $tokenCount; ++$j) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                continue;
            }

            if ($tokens[$j] === '(') {
                // This is a function call
                $this->incrementCall($funcName);

                return;
            }

            break;
        }
    }

    private function extractMethodCalls(array $tokens, &$i, int $tokenCount): void
    {
        // Look ahead for the method name
        for ($j = $i + 1; $j < $tokenCount; ++$j) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                continue;
            }

            if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                $methodName = $tokens[$j][1];

                // Check if followed by (
                for ($k = $j + 1; $k < $tokenCount; ++$k) {
                    if (is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) {
                        continue;
                    }

                    if ($tokens[$k] === '(') {
                        // This is a method call
                        $this->incrementCall($methodName);
                        $this->incrementCall('*::' . $methodName); // Wildcard for any class

                        return;
                    }

                    break;
                }
            }

            break;
        }
    }

    private function incrementCall($name): void
    {
        if (!isset($this->calls[$name])) {
            $this->calls[$name] = 0;
        }

        ++$this->calls[$name];
    }

    private function printReport(): void
    {
        $unused = [];
        $used = [];

        foreach ($this->definitions as $funcName => $info) {
            $callCount = 0;

            // Check for direct calls
            if (isset($this->calls[$funcName])) {
                $callCount += $this->calls[$funcName];
            }

            // For methods, check wildcard calls
            if ($info['type'] === 'method') {
                $parts = explode('::', (string) $funcName);
                if (count($parts) === 2) {
                    $methodOnly = $parts[1];
                    if (isset($this->calls['*::' . $methodOnly])) {
                        $callCount += $this->calls['*::' . $methodOnly];
                    }
                }
            }

            // Check for special methods (constructors, magic methods, etc.)
            $isSpecial = $this->isSpecialMethod($funcName);

            if ($callCount === 0 && !$isSpecial) {
                $unused[] = [
                    'name' => $funcName,
                    'info' => $info,
                    'calls' => 0,
                ];
            } else {
                $used[] = [
                    'name' => $funcName,
                    'info' => $info,
                    'calls' => $callCount,
                    'special' => $isSpecial,
                ];
            }
        }

        // Print unused functions
        echo 'UNUSED FUNCTIONS (' . count($unused) . "):\n";
        echo str_repeat('-', 80) . "\n";
        if ($unused === []) {
            echo "None found! All functions are being used.\n";
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

        // Print used functions
        echo "\n" . str_repeat('=', 80) . "\n";
        echo 'USED FUNCTIONS (' . count($used) . "):\n";
        echo str_repeat('-', 80) . "\n";

        // Sort by call count (descending)
        usort($used, fn (array $a, array $b): float|int => $b['calls'] - $a['calls']);

        foreach ($used as $item) {
            $special = $item['special'] ? ' [SPECIAL/MAGIC METHOD]' : '';
            echo sprintf(
                "%-50s Called: %4d times%s\n",
                $item['name'],
                $item['calls'],
                $special,
            );
        }

        echo "\n" . str_repeat('=', 80) . "\n";
        echo "SUMMARY:\n";
        echo '  Total functions/methods defined: ' . count($this->definitions) . "\n";
        echo '  Used: ' . count($used) . "\n";
        echo '  Unused: ' . count($unused) . "\n";
        echo str_repeat('=', 80) . "\n";
    }

    private function isSpecialMethod(int|string $funcName): bool
    {
        // Check for magic methods and constructors
        $specialMethods = [
            '__construct', '__destruct', '__call', '__callStatic',
            '__get', '__set', '__isset', '__unset', '__sleep',
            '__wakeup', '__serialize', '__unserialize', '__toString',
            '__invoke', '__set_state', '__clone', '__debugInfo',
        ];

        foreach ($specialMethods as $method) {
            if (str_contains((string) $funcName, '::' . $method)) {
                return true;
            }
        }

        return false;
    }
}
