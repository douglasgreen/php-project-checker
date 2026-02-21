<?php

/**
 * Documentation Standards Compliance Checker
 *
 * Validates Markdown documentation against RFC 2119, Diátaxis framework,
 * and Docs-as-Code standards.
 */

declare(strict_types=1);

namespace DouglasGreen\PHPProjectChecker;

class DocStandardsChecker
{
    // RFC 2119 levels
    public const MUST = 'MUST';

    public const SHOULD = 'SHOULD';

    public const MAY = 'MAY';

    private readonly string $rootDir;

    /** @var array<int, string> */
    private array $files = [];

    /** @var array<int, string> */
    private array $markdownFiles = [];

    /** @var array<int, array<string, string>> */
    private array $issues = [];

    /** @var array<string, array{outgoing: array<int, string>, incoming: array<int, string>}> */
    private array $linkGraph = [];

    /** @var array<string, array<string, string>> */
    private array $requiredFiles = [
        'root' => [
            'README.md' => 'Project explanation and installation guide',
            'CHANGELOG.md' => 'Version history reference',
            'LICENSE' => 'Legal terms',
        ],
        'docs' => [
            'docs/index.md' => 'Documentation navigation hub',
            'docs/development/setup.md' => 'Development environment setup',
            'docs/development/testing.md' => 'Testing procedures',
            'docs/architecture.md' => 'System architecture explanation',
        ],
    ];

    // Patterns
    /** @var array<int, string> */
    private array $forbiddenWords = ['simply', 'just', 'obviously', 'clearly', 'basically', 'easily'];

    /** @var array<string, string> */
    private array $securityPatterns = [
        '/\b(sk-[a-zA-Z0-9]{20,})/i' => 'Exposed API key (OpenAI format)',
        '/\b(ghp_[a-zA-Z0-9]{36})/i' => 'Exposed GitHub token',
        '/\b(AKIA[0-9A-Z]{16})/' => 'Exposed AWS Access Key ID',
        '/(?:password|passwd|pwd)\s*[=:]\s*["\'][^"\'<>]+["\']/i' => 'Hardcoded password',
        '/(?:api[_-]?key|apikey)\s*[=:]\s*["\'][^"\'<>]+["\']/i' => 'Hardcoded API key',
        '/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/' => 'IP address (verify not sensitive)',
    ];

    public function __construct(string $directory)
    {
        $this->rootDir = (string) (realpath($directory) ?: getcwd());
        if (!$this->isGitRepository()) {
            fwrite(STDERR, sprintf('Error: Not a git repository: %s%s', $this->rootDir, PHP_EOL));
            exit(1);
        }
    }

    public function run(): void
    {
        echo sprintf('Scanning documentation in: %s%s', $this->rootDir, PHP_EOL);
        echo str_repeat('=', 60) . "\n\n";

        $this->loadGitFiles();
        $this->checkRequiredFiles();
        $this->checkFileNaming();
        $this->checkFileEncoding();
        $this->analyzeMarkdownContent();
        $this->detectOrphanedFiles();
        $this->checkDirectoryStructure();

        $this->printReport();
    }

    private function isGitRepository(): bool
    {
        return is_dir($this->rootDir . '/.git') ||
               file_exists($this->rootDir . '/.git');
    }

    private function loadGitFiles(): void
    {
        $command = 'cd ' . escapeshellarg($this->rootDir) . ' && git ls-files';
        exec($command, $this->files, $returnCode);

        if ($returnCode !== 0) {
            fwrite(STDERR, "Error: Failed to run git ls-files\n");
            exit(1);
        }

        $this->markdownFiles = array_filter($this->files, fn (string $file): bool => (bool) preg_match('/\.md$/i', $file));

        echo 'Found ' . count($this->markdownFiles) . " Markdown files\n";
    }

    private function checkRequiredFiles(): void
    {
        // Check root files (2.3.1)
        foreach ($this->requiredFiles['root'] as $file => $description) {
            if (!in_array($file, $this->files)) {
                $this->addIssue(
                    self::MUST,
                    'Missing required file',
                    $file,
                    'Required per section 2.3.1: ' . $description,
                );
            }
        }

        // Check docs structure (2.3.2, 2.3.3)
        foreach ($this->requiredFiles['docs'] as $file => $description) {
            if (!in_array($file, $this->files)) {
                $this->addIssue(
                    self::MUST,
                    'Missing required documentation',
                    $file,
                    'Required per sections 2.3.2/2.3.3: ' . $description,
                );
            }
        }

        // Check for ADR directory (2.3.3)
        $hasAdr = false;
        foreach ($this->files as $file) {
            if (str_starts_with((string) $file, 'docs/adr/') && preg_match('/^\d{4}-/', basename((string) $file))) {
                $hasAdr = true;
                break;
            }
        }

        if (!$hasAdr && in_array('docs/architecture.md', $this->files)) {
            $this->addIssue(
                self::SHOULD,
                'Missing ADR directory',
                'docs/adr/',
                'Architecture Decision Records recommended per 2.3.3 (pattern: NNNN-decision-title.md)',
            );
        }
    }

    private function checkDirectoryStructure(): void
    {
        // Check for docs/ index.md (2.2.1)
        if (is_dir($this->rootDir . '/docs') && !in_array('docs/index.md', $this->files)) {
            $this->addIssue(
                self::MUST,
                'Missing navigation hub',
                'docs/index.md',
                'Required per 2.2.1: docs/ directory must have index.md as navigation hub',
            );
        }

        // Check for CHANGELOG format
        if (in_array('CHANGELOG.md', $this->files)) {
            $content = $this->getFileContent('CHANGELOG.md');
            if (!preg_match('/## \[\d+\.\d+/', $content) && !preg_match('/## \d+\.\d+/', $content)) {
                $this->addIssue(
                    self::SHOULD,
                    'Changelog format',
                    'CHANGELOG.md',
                    'Consider using Keep a Changelog format (## [1.0.0])',
                );
            }
        }
    }

    private function checkFileNaming(): void
    {
        foreach ($this->markdownFiles as $file) {
            $basename = basename((string) $file);

            // Check kebab-case (2.2.2)
            if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*\.md$/', $basename) &&
                !in_array($basename, ['README.md', 'CHANGELOG.md', 'LICENSE.md', 'CONTRIBUTING.md'])) {
                $this->addIssue(
                    self::MUST,
                    'Invalid filename',
                    $file,
                    'Filenames must use kebab-case (e.g., deployment-guide.md) per 2.2.2',
                );
            }

            // Check for spaces or underscores
            if (preg_match('/[ _]/', $basename)) {
                $this->addIssue(
                    self::MUST,
                    'Invalid filename characters',
                    $file,
                    'Use hyphens, not spaces or underscores per 2.2.2',
                );
            }
        }
    }

    private function checkFileEncoding(): void
    {
        foreach ($this->markdownFiles as $file) {
            $fullPath = $this->rootDir . '/' . $file;

            // Check for UTF-8 (2.2.3)
            $content = (string) file_get_contents($fullPath);
            if (!mb_check_encoding($content, 'UTF-8')) {
                $this->addIssue(
                    self::MUST,
                    'Invalid encoding',
                    $file,
                    'Files must be UTF-8 encoded per 2.2.3',
                );
            }

            // Check for CRLF line endings (2.2.3)
            if (str_contains($content, "\r\n")) {
                $this->addIssue(
                    self::MUST,
                    'Invalid line endings',
                    $file,
                    'Files must use Unix line endings (LF), not CRLF per 2.2.3',
                );
            }

            // Check for trailing whitespace (6.1.5)
            if (preg_match('/[ \t]+$/m', $content)) {
                $this->addIssue(
                    self::MUST,
                    'Trailing whitespace',
                    $file,
                    'Remove trailing whitespace at end of lines per 6.1.5',
                );
            }
        }
    }

    private function analyzeMarkdownContent(): void
    {
        foreach ($this->markdownFiles as $file) {
            $content = $this->getFileContent($file);
            $lines = explode("\n", $content);

            $this->checkHeadingStructure($file, $lines);
            $this->checkCodeBlocks($file, $content);
            $this->checkWritingStyle($file, $content, $lines);
            $this->checkLinks($file, $content);
            $this->checkSecurity($file, $content);
            $this->checkFrontmatter($file, $content);
            $this->buildLinkGraph($file, $content);
        }
    }

    /**
     * @param array<int, string> $lines
     */
    private function checkHeadingStructure(string $file, array $lines): void
    {
        $hasH1 = false;
        $prevLevel = 0;

        foreach ($lines as $i => $line) {
            if (!preg_match('/^(#{1,6})\s+(.+)$/', (string) $line, $matches)) {
                continue;
            }

            $level = strlen($matches[1]);
            $text = $matches[2];

            // Check single H1 (6.1.1)
            if ($level === 1) {
                if ($hasH1) {
                    $this->addIssue(
                        self::MUST,
                        'Multiple H1 headings',
                        $file,
                        'Line ' . ($i + 1) . ': Document must have exactly one H1 per 6.1.1',
                    );
                }

                $hasH1 = true;
            }

            // Check no skipped levels (6.2.2)
            if ($prevLevel > 0 && $level > $prevLevel + 1) {
                $this->addIssue(
                    self::MUST,
                    'Skipped heading level',
                    $file,
                    'Line ' . ($i + 1) . ': H' . ($prevLevel + 1) . sprintf(' skipped (H%d -> H%d) per 6.2.2', $prevLevel, $level),
                );
            }

            $prevLevel = $level;

            // Check sentence case (3.1.5)
            // Allow acronyms and proper nouns, but flag Title Case
            if ($level <= 3 && preg_match('/[A-Z]{2,}/', $text) && !preg_match('/^[A-Z][a-z]+( [a-z]+)*$/', $text) && preg_match('/^[A-Z][a-z]+ [A-Z]/', $text)) {
                $this->addIssue(
                    self::MUST,
                    'Heading case',
                    $file,
                    'Line ' . ($i + 1) . ": Use sentence case ('" . strtolower($text) . "') not Title Case per 3.1.5",
                );
            }
        }

        if (!$hasH1) {
            $this->addIssue(
                self::MUST,
                'Missing H1',
                $file,
                'Document must have exactly one H1 heading per 6.1.1',
            );
        }
    }

    private function checkCodeBlocks(string $file, string $content): void
    {
        // Check for fenced code blocks without language (4.2.2, 6.1.2)
        if (preg_match_all('/^```\s*$/m', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $line = substr_count(substr($content, 0, $match[1]), "\n") + 1;
                $this->addIssue(
                    self::MUST,
                    'Missing syntax highlighting',
                    $file,
                    sprintf('Line %d: Code blocks must specify language (e.g., ```php) per 4.2.2', $line),
                );
            }
        }

        // Check for indented code blocks (discouraged in favor of fences)
        if (preg_match('/\n    [^\s*]/', $content)) {
            $this->addIssue(
                self::SHOULD,
                'Indented code block',
                $file,
                'Use fenced code blocks (```) instead of indentation per 6.1.2',
            );
        }
    }

    /**
     * @param array<int, string> $lines
     */
    private function checkWritingStyle(string $file, string $content, array $lines): void
    {
        // Check for fluff words (3.2.2)
        foreach ($this->forbiddenWords as $word) {
            if (preg_match(sprintf('/\b%s\b/i', $word), $content)) {
                $this->addIssue(
                    self::MUST,
                    'Fluff words',
                    $file,
                    sprintf("Remove '%s' per 3.2.2 (avoid words that alienate struggling users)", $word),
                );
            }
        }

        // Check for passive voice indicators (3.1.1) - heuristic
        if (preg_match('/\b(is|was|were|been|be|being)\s+(?:configured|installed|created|updated|deleted|processed|generated|used|done|made)\b/i', $content)) {
            $this->addIssue(
                self::SHOULD,
                'Passive voice detected',
                $file,
                "Use active voice ('Click Save') not passive ('Save should be clicked') per 3.1.1",
            );
        }

        // Check for future tense (3.1.2)
        if (preg_match('/\b(will|shall)\s+(?:return|display|show|create|update)\b/i', $content)) {
            $this->addIssue(
                self::MUST,
                'Future tense',
                $file,
                "Use present tense ('The API returns') not future ('The API will return') per 3.1.2",
            );
        }

        // Check list punctuation consistency (3.1.7)
        $this->checkListPunctuation($file, $lines);
    }

    /**
     * @param array<int, string> $lines
     */
    private function checkListPunctuation(string $file, array $lines): void
    {
        $inList = false;
        $listType = null; // 'fragment' or 'sentence'

        foreach ($lines as $i => $line) {
            if (preg_match('/^[\s]*[-*+]\s+(.+)$/', (string) $line, $matches)) {
                $text = $matches[1];
                $hasPeriod = str_ends_with($text, '.');

                if (!$inList) {
                    $inList = true;
                    $listType = $hasPeriod ? 'sentence' : 'fragment';
                } else {
                    $currentType = $hasPeriod ? 'sentence' : 'fragment';
                    if ($currentType !== $listType) {
                        $this->addIssue(
                            self::MUST,
                            'Inconsistent list punctuation',
                            $file,
                            'Line ' . ($i + 1) . ': Mixed periods and no periods in list per 3.1.7',
                        );
                        break;
                    }
                }
            } elseif (!preg_match('/^\s*$/', (string) $line)) {
                $inList = false;
            }
        }
    }

    private function checkLinks(string $file, string $content): void
    {
        // Check for "click here" (6.1.4, 7.1.3)
        if (preg_match('/\[click here\]/i', $content)) {
            $this->addIssue(
                self::MUST,
                'Non-descriptive link',
                $file,
                "Use descriptive link text, not 'click here' per 6.1.4/7.1.3",
            );
        }

        // Check for bare URLs (6.1.4)
        if (preg_match('/(?<!\[)[^(\[]https?:\/\/\S+/i', $content)) {
            $this->addIssue(
                self::SHOULD,
                'Bare URL',
                $file,
                'Use [text](url) format, not bare URLs per 6.1.4',
            );
        }

        // Check relative links for local files (9.3.1)
        preg_match_all('/\[([^\]]+)\]\(([^)]+)\)/', $content, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $link = $match[2];

            // Skip external and anchor links
            if (preg_match('/^(https?:|mailto:|#)/', $link)) {
                continue;
            }

            // Remove anchor
            $linkPath = preg_replace('/#.*/', '', $link);
            if (empty($linkPath)) {
                continue;
            }

            // Resolve relative to file
            $dir = dirname($file);
            $targetPath = $dir === '.' ? $linkPath : $dir . '/' . $linkPath;
            $targetPath = preg_replace('#/+#', '/', $targetPath); // normalize

            // Check if exists
            if (!in_array($targetPath, $this->files) &&
                !in_array($targetPath . '.md', $this->files)) {
                $this->addIssue(
                    self::MUST,
                    'Broken internal link',
                    $file,
                    sprintf("Link to '%s' points to non-existent file", $link),
                );
            }
        }
    }

    private function checkSecurity(string $file, string $content): void
    {
        foreach ($this->securityPatterns as $pattern => $description) {
            if (preg_match($pattern, $content)) {
                $this->addIssue(
                    self::MUST,
                    'Security risk: ' . $description,
                    $file,
                    'Use placeholder variables like <YOUR_API_KEY> per 4.2.4/8.1.2',
                );
            }
        }
    }

    private function checkFrontmatter(string $file, string $content): void
    {
        // Check for YAML frontmatter (1.3.3)
        if (preg_match('/^---\s*\n/', $content)) {
            if (!preg_match('/^---\s*\n.*?\n---\s*\n/s', $content)) {
                $this->addIssue(
                    self::SHOULD,
                    'Invalid frontmatter',
                    $file,
                    'YAML frontmatter must be closed with ---',
                );
            } elseif (!preg_match('/^title:/m', $content)) {
                // Check for recommended fields
                $this->addIssue(
                    self::SHOULD,
                    'Missing frontmatter',
                    $file,
                    "Add 'title' to YAML frontmatter per 1.3.3",
                );
            }
        } elseif (str_word_count($content) > 300 && !preg_match('/^# /', $content)) {
            // For long docs, recommend frontmatter
            $this->addIssue(
                self::MAY,
                'Consider frontmatter',
                $file,
                'Add YAML frontmatter with title, description, last_reviewed per 1.3.3',
            );
        }
    }

    private function buildLinkGraph(string $file, string $content): void
    {
        $this->linkGraph[$file] = ['outgoing' => [], 'incoming' => []];

        preg_match_all('/\[([^\]]+)\]\(([^)]+)\)/', $content, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $link = $match[2];

            if (preg_match('/^(https?:|mailto:|#)/', $link)) {
                continue;
            }

            $linkPath = preg_replace('/#.*/', '', $link);
            if (empty($linkPath)) {
                continue;
            }

            $dir = dirname($file);
            $targetPath = $dir === '.' ? $linkPath : $dir . '/' . $linkPath;
            $targetPath = preg_replace('#/+#', '/', $targetPath);

            // Try with .md extension
            if (!in_array($targetPath, $this->files) && in_array($targetPath . '.md', $this->files)) {
                $targetPath .= '.md';
            }

            $this->linkGraph[$file]['outgoing'][] = $targetPath;

            if (!isset($this->linkGraph[$targetPath])) {
                $this->linkGraph[$targetPath] = ['outgoing' => [], 'incoming' => []];
            }

            $this->linkGraph[$targetPath]['incoming'][] = $file;
        }
    }

    private function detectOrphanedFiles(): void
    {
        $entryPoints = ['README.md', 'docs/index.md', 'CHANGELOG.md'];

        foreach ($this->markdownFiles as $file) {
            // Skip entry points
            if (in_array($file, $entryPoints)) {
                continue;
            }

            $incoming = $this->linkGraph[$file]['incoming'] ?? [];

            // Check if linked from any markdown file
            if (empty($incoming)) {
                $this->addIssue(
                    self::SHOULD,
                    'Orphaned document',
                    $file,
                    'Not linked from any other Markdown file; add cross-references per 1.3.1',
                );
            }
        }
    }

    private function getFileContent(string $file): string
    {
        return (string) file_get_contents($this->rootDir . '/' . $file);
    }

    private function addIssue(string $level, string $category, string $file, string $message): void
    {
        $this->issues[] = [
            'level' => $level,
            'category' => $category,
            'file' => $file,
            'message' => $message,
        ];
    }

    private function printReport(): void
    {
        $mustIssues = array_filter($this->issues, fn (array $i): bool => $i['level'] === self::MUST);
        $shouldIssues = array_filter($this->issues, fn (array $i): bool => $i['level'] === self::SHOULD);
        $mayIssues = array_filter($this->issues, fn (array $i): bool => $i['level'] === self::MAY);

        echo "\n";

        if ($this->issues === []) {
            echo "\033[32m✓ All documentation standards met\033[0m\n";
            return;
        }

        // Print MUST issues (red)
        if ($mustIssues !== []) {
            echo "\033[31mMUST (Critical Violations): " . count($mustIssues) . "\033[0m\n";
            echo str_repeat('-', 60) . "\n";
            foreach ($mustIssues as $issue) {
                echo sprintf("\033[31m[%s]\033[0m %s\n", $issue['category'], $issue['file']);
                echo "  → {$issue['message']}\n\n";
            }
        }

        // Print SHOULD issues (yellow)
        if ($shouldIssues !== []) {
            echo "\033[33mSHOULD (Recommendations): " . count($shouldIssues) . "\033[0m\n";
            echo str_repeat('-', 60) . "\n";
            foreach ($shouldIssues as $issue) {
                echo sprintf("\033[33m[%s]\033[0m %s\n", $issue['category'], $issue['file']);
                echo "  → {$issue['message']}\n\n";
            }
        }

        // Print MAY issues (cyan)
        if ($mayIssues !== []) {
            echo "\033[36mMAY (Suggestions): " . count($mayIssues) . "\033[0m\n";
            echo str_repeat('-', 60) . "\n";
            foreach ($mayIssues as $issue) {
                echo sprintf("\033[36m[%s]\033[0m %s\n", $issue['category'], $issue['file']);
                echo "  → {$issue['message']}\n\n";
            }
        }

        // Summary statistics
        echo "\nSummary:\n";
        echo "--------\n";
        printf("Total files scanned: %d\n", count($this->markdownFiles));
        printf(
            "Issues found: %d (MUST: %d, SHOULD: %d, MAY: %d)\n",
            count($this->issues),
            count($mustIssues),
            count($shouldIssues),
            count($mayIssues),
        ); // Approximate checks per file
        $compliance = max(0, 100 - (count($mustIssues) * 5) - (count($shouldIssues) * 2));
        printf("Compliance score: %d%%\n", $compliance);

        if ($mustIssues !== []) {
            echo "\n\033[31m⚠️  SECURITY WARNING: Critical violations detected\033[0m\n";
            exit(1);
        }

        exit(0);
    }
}
