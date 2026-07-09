<?php

declare(strict_types=1);

namespace DouglasGreen\PHPProjectChecker;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class RepoMapBuilder
{
    private readonly ?string $gitRoot;

    /** @var array<int, string>|null */
    private ?array $files = null;

    public function __construct(?string $gitRoot = null)
    {
        $this->gitRoot = $gitRoot ?? $this->findGitRoot();
    }

    public function getGitRoot(): ?string
    {
        return $this->gitRoot;
    }

    /**
     * Get all files tracked by git (relative paths).
     *
     * @return array<int, string>
     */
    public function getAllFiles(): array
    {
        if ($this->files !== null) {
            return $this->files;
        }

        if ($this->gitRoot === null) {
            $this->files = [];
            return $this->files;
        }

        $command = 'git -C ' . escapeshellarg($this->gitRoot) . ' ls-files 2>&1';
        exec($command, $output, $returnCode);

        if ($returnCode !== 0 || $output === []) {
            // Fallback to recursive directory scan
            $this->files = [];
            $this->scanDirectory($this->gitRoot, $this->files);
        } else {
            $this->files = $output;
        }

        return $this->files;
    }

    /**
     * Get PHP files (full paths).
     * Includes files with .php extension and extensionless files with PHP shebang.
     *
     * @return array<int, string>
     */
    public function getPhpFiles(): array
    {
        $allFiles = $this->getAllFiles();
        $phpFiles = [];

        foreach ($allFiles as $file) {
            // Skip tests
            if (preg_match('#^tests/#', $file)) {
                continue;
            }

            if ($this->gitRoot === null) {
                continue;
            }

            $fullPath = $this->gitRoot . '/' . $file;

            if (str_ends_with($file, '.php')) {
                if (file_exists($fullPath)) {
                    $phpFiles[] = $fullPath;
                }

                continue;
            }

            // Check for extensionless PHP files
            $basename = basename($file);
            // Check if it has no extension (no dot in basename)
            if (strpos($basename, '.') === false && $this->isPhpShebang($fullPath)) {
                $phpFiles[] = $fullPath;
            }
        }

        return $phpFiles;
    }

    private function isPhpShebang(string $filePath): bool
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return false;
        }

        $handle = @fopen($filePath, 'r');
        if (!$handle) {
            return false;
        }

        $line = fgets($handle);
        fclose($handle);

        if ($line === false) {
            return false;
        }

        $line = trim($line);
        return $line === '#!/usr/bin/php' || $line === '#!/usr/bin/env php';
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
     * @param array<int, string> $files
     */
    private function scanDirectory(string $dir, array &$files): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $path = $file->getPathname();
                // Skip vendor and common excluded directories
                if (!preg_match('#/(vendor|node_modules|\.git)/#', (string) $path)) {
                    // Return relative paths to match git ls-files behavior
                    $relativePath = str_replace($this->gitRoot . '/', '', $path);
                    $files[] = $relativePath;
                }
            }
        }
    }
}
