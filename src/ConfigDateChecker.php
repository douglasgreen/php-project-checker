<?php

namespace DouglasGreen\PHPProjectChecker;

class ConfigDateChecker
{
    private string $targetDir;
    private bool $fix;
    private RepoMapBuilder $repoMapBuilder;

    public function __construct(string $targetDir, bool $fix = false)
    {
        $this->targetDir = $targetDir;
        $this->fix = $fix;
        $this->repoMapBuilder = new RepoMapBuilder();
    }

    public function run(): void
    {
        if (!is_dir($this->targetDir)) {
            echo "Error: Directory '{$this->targetDir}' not found.\n";
            exit(1);
        }

        $dirFiles = $this->getRecursiveFiles($this->targetDir);
        $repoFiles = $this->repoMapBuilder->getAllFiles();
        $repoMap = [];
        foreach ($repoFiles as $repoPath) {
            if (empty($repoPath)) {
                continue;
            }
            $name = basename($repoPath);
            $repoMap[$name][] = $repoPath;
        }

        echo "Checking files in '{$this->targetDir}' against repository...\n\n";

        foreach ($dirFiles as $dirFilePath) {
            $fileName = basename($dirFilePath);

            if (isset($repoMap[$fileName])) {
                $newDate = $this->getModificationDate($dirFilePath);

                foreach ($repoMap[$fileName] as $repoFilePath) {
                    // Don't compare a file against itself
                    if (realpath($dirFilePath) === realpath(getcwd() . '/' . $repoFilePath)) {
                        continue;
                    }

                    $oldDate = $this->getModificationDate($repoFilePath);

                    if ($oldDate === null || $newDate === null || $oldDate !== $newDate) {
                        $displayOldDate = $oldDate ?? "[date missing]";
                        $displayNewDate = $newDate ?? "[date missing]";

                        echo "Outdated or Missing Date Found:\n";
                        echo "  File in Repo: $repoFilePath\n";
                        echo "  Old Date:     $displayOldDate\n";
                        echo "  New Date:     $displayNewDate\n";

                        if ($this->fix) {
                            if (copy($dirFilePath, $repoFilePath)) {
                                echo "  Action:       Updated successfully.\n";
                            } else {
                                echo "  Action:       Failed to update file.\n";
                            }
                        }

                        echo "--------------------------------------------------\n";
                    }
                }
            }
        }
    }

    private function getRecursiveFiles(string $dir): array
    {
        $files = [];
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $files = array_merge($files, $this->getRecursiveFiles($path));
            } else {
                $files[] = $path;
            }
        }
        return $files;
    }

    private function getModificationDate(string $filePath): ?string
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            return null;
        }

        $handle = fopen($filePath, 'r');
        if (!$handle) {
            return null;
        }

        $lineCount = 0;
        $foundDate = null;

        while (($line = fgets($handle)) !== false && $lineCount < 10) {
            $lineCount++;
            if (preg_match('/modified.*(\d{4}-\d{2}-\d{2})/i', $line, $matches)) {
                $foundDate = $matches[1];
                break;
            }
        }
        fclose($handle);
        return $foundDate;
    }
}
