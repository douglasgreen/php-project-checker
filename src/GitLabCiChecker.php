<?php

/**
 * GitLab CI/CD Standards Compliance Checker
 *
 * Validates .gitlab-ci.yml and included configurations against
 * GitLab CI/CD engineering standards and security best practices.
 */

declare(strict_types=1);

namespace DouglasGreen\PHPProjectChecker;

use Exception;
use Symfony\Component\Yaml\Yaml;

class GitLabCiChecker
{
    // RFC 2119 levels
    public const MUST = 'MUST';

    public const SHOULD = 'SHOULD';

    public const MAY = 'MAY';

    private readonly string $rootDir;

    private array $config;

    private array $ciConfig = [];

    private array $includedFiles = [];

    private array $allJobs = [];

    private array $issues = [];

    private ?array $composer = null;

    private string $primaryFile = ''; // Minimum recommended

    private array $deprecatedComposerFlags = ['--no-suggest'];

    private array $securityPatterns = [
        '/\b(password|token|secret|key)\s*[=:]\s*["\'][^"\']+["\']/i' => 'Hardcoded secret',
        '/\b(AKIA[0-9A-Z]{16})/' => 'AWS Access Key ID',
        '/\bghp_[a-zA-Z0-9]{36}/' => 'GitHub Personal Access Token',
        '/\beyJ[a-zA-Z0-9_-]*\.eyJ[a-zA-Z0-9_-]*/' => 'JWT Token pattern',
        '/\bBEGIN (RSA |DSA |EC |OPENSSH )?PRIVATE KEY/' => 'Private key block',
    ];

    public function __construct(string $directory, string $configFile = '')
    {
        $this->rootDir = realpath($directory) ?: getcwd();
        $this->loadConfig($configFile);
        $this->loadComposerJson();
        $this->loadCiConfiguration();
    }

    public function run(): void
    {
        echo str_repeat('=', 60) . "\n";
        echo "GitLab CI/CD Standards Compliance Check\n";
        echo str_repeat('=', 60) . "\n\n";

        $this->validateFileStructure();
        $this->validateYamlSyntax();
        $this->validateStages();
        $this->validateWorkflow();
        $this->validateJobs();
        $this->validateScripts();
        $this->validateCaching();
        $this->validateArtifacts();
        $this->validateImages();
        $this->validateSecurity();
        $this->validatePhpVersionConsistency();
        $this->validateDeprecatedFeatures();
        $this->validateRules();
        $this->validateEnvironment();

        $this->printReport();
    }

    private function loadConfig(string $configFile): void
    {
        $defaults = [
            'minimumPhpVersion' => '8.3',
            'requireUntrackedCache' => true,
            'requireStrictMode' => true,
            'requirePinnedImages' => true,
            'maxLineLength' => 120,
            'forbiddenKeywords' => ['only', 'except'],
            'requiredWorkflowRules' => true,
            'requireInterruptible' => true,
        ];

        if ($configFile && file_exists($configFile)) {
            $userConfig = json_decode(file_get_contents($configFile), true);
            if ($userConfig === null) {
                fwrite(STDERR, "\033[33mWarning: Invalid config JSON, using defaults\033[0m\n");
                $this->config = $defaults;
            } else {
                $this->config = array_merge($defaults, $userConfig);
                echo sprintf('Loaded configuration from %s%s', $configFile, PHP_EOL);
            }
        } else {
            $this->config = $defaults;
        }
    }

    private function loadComposerJson(): void
    {
        $path = $this->rootDir . '/composer.json';
        if (file_exists($path)) {
            $content = file_get_contents($path);
            $this->composer = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                echo "Loaded composer.json for PHP version validation\n";
            }
        }
    }

    private function loadCiConfiguration(): void
    {
        $this->primaryFile = $this->rootDir . '/.gitlab-ci.yml';

        if (!file_exists($this->primaryFile)) {
            fwrite(STDERR, "\033[31mError: .gitlab-ci.yml not found in {$this->rootDir}\033[0m\n");
            exit(1);
        }

        // Parse primary file
        $content = file_get_contents($this->primaryFile);
        $this->ciConfig = $this->parseYaml($content);

        if ($this->ciConfig === null) {
            fwrite(STDERR, "\033[31mError: Failed to parse .gitlab-ci.yml\033[0m\n");
            exit(1);
        }

        echo "Loaded .gitlab-ci.yml\n";

        // Load included files
        $this->loadIncludedFiles($this->ciConfig, dirname($this->primaryFile));

        // Collect all jobs
        $this->collectJobs();
    }

    private function parseYaml(string $content): ?array
    {
        if (function_exists('yaml_parse')) {
            $result = yaml_parse($content);
            return $result !== false ? $result : null;
        }

        // Fallback to Symfony YAML if available
        if (class_exists('Symfony\Component\Yaml\Yaml')) {
            try {
                return Yaml::parse($content);
            } catch (Exception) {
                return null;
            }
        }

        // Basic regex parser for includes if no YAML parser available
        fwrite(STDERR, "\033[33mWarning: No YAML parser found (install php-yaml or symfony/yaml)\033[0m\n");
        return null;
    }

    private function loadIncludedFiles(array $config, string $basePath): void
    {
        if (!isset($config['include'])) {
            return;
        }

        $includes = is_array($config['include']) ? $config['include'] : [$config['include']];

        foreach ($includes as $include) {
            if (is_string($include)) {
                $this->processInclude($include, $basePath);
            } elseif (is_array($include) && isset($include['local'])) {
                $this->processInclude($include['local'], $basePath);
            }
        }
    }

    private function processInclude(string $path, string $basePath): void
    {
        // Handle variables and templates
        if (str_starts_with($path, 'https://') || str_starts_with($path, 'http://')) {
            return; // Remote includes skipped for local checking
        }

        $fullPath = $basePath . '/' . $path;
        if (file_exists($fullPath)) {
            $content = file_get_contents($fullPath);
            $parsed = $this->parseYaml($content);
            if ($parsed !== null) {
                $this->includedFiles[$path] = $parsed;
                echo sprintf('Loaded included file: %s%s', $path, PHP_EOL);

                // Recursively load includes
                $this->loadIncludedFiles($parsed, dirname($fullPath));
            }
        }
    }

    private function collectJobs(): void
    {
        $allConfigs = array_merge([$this->ciConfig], $this->includedFiles);

        foreach ($allConfigs as $source => $config) {
            foreach ($config as $key => $value) {
                // Skip special keys and hidden jobs
                if (str_starts_with((string) $key, '.')) {
                    continue;
                }

                if (in_array($key, ['stages', 'variables', 'workflow', 'include', 'default'])) {
                    continue;
                }

                if (is_array($value) && (isset($value['script']) || isset($value['extends']))) {
                    $this->allJobs[$key] = [
                        'config' => $value,
                        'source' => is_int($source) ? '.gitlab-ci.yml' : $source,
                    ];
                }
            }
        }

        echo 'Found ' . count($this->allJobs) . " job definitions\n";
    }

    private function validateFileStructure(): void
    {
        // Check primary file location (1.1.1)
        if (!file_exists($this->rootDir . '/.gitlab-ci.yml')) {
            $this->addIssue(
                self::MUST,
                'Missing CI file',
                'root',
                '.gitlab-ci.yml must be at project root per 1.1.1',
            );
        }

        // Check for circular includes (1.1.3) - simplified check
        $includePaths = [];
        foreach (array_keys($this->includedFiles) as $path) {
            if (in_array($path, $includePaths)) {
                $this->addIssue(
                    self::MUST,
                    'Circular include',
                    $path,
                    'File included multiple times - check for circular dependencies per 1.1.3',
                );
            }

            $includePaths[] = $path;
        }

        // Check organization by stage (1.1.4)
        $hasOrganizedIncludes = false;
        foreach (array_keys($this->includedFiles) as $path) {
            if (preg_match('/\.gitlab\/ci\/(build|test|deploy|lint|security)\.yml$/', (string) $path)) {
                $hasOrganizedIncludes = true;
            }
        }

        if ($this->includedFiles !== [] && !$hasOrganizedIncludes) {
            $this->addIssue(
                self::SHOULD,
                'Include organization',
                'include',
                'Organize includes by stage/domain under .gitlab/ci/ per 1.1.4 (e.g., build.yml, test.yml)',
            );
        }
    }

    private function validateYamlSyntax(): void
    {
        $content = file_get_contents($this->primaryFile);

        // Check 2-space indentation (2.1)
        if (preg_match('/^\t+/m', $content)) {
            $this->addIssue(
                self::MUST,
                'Tab indentation',
                '.gitlab-ci.yml',
                'Use 2 spaces, not tabs per 2.1',
            );
        }

        // Check line length (2.4)
        $lines = explode("\n", $content);
        foreach ($lines as $i => $line) {
            if (strlen($line) > $this->config['maxLineLength'] && !str_starts_with(trim($line), '#')) {
                $this->addIssue(
                    self::SHOULD,
                    'Line too long',
                    '.gitlab-ci.yml:' . ($i + 1),
                    sprintf('Line exceeds %s characters per 2.4', $this->config['maxLineLength']),
                );
            }
        }

        // Check for block scalars usage (2.3)
        $hasBlockScalars = preg_match('/^\s*script:\s*\|[+-]?/m', $content);
        if (!$hasBlockScalars && preg_match('/script:\s*\["[^"]{100,}"\]/', $content)) {
            $this->addIssue(
                self::SHOULD,
                'Inline script',
                '.gitlab-ci.yml',
                'Use block scalars (|) for multi-line scripts per 2.3',
            );
        }
    }

    private function validateStages(): void
    {
        // Check explicit stages declaration (1.2.1)
        if (!isset($this->ciConfig['stages'])) {
            $this->addIssue(
                self::MUST,
                'Missing stages',
                'stages',
                'Explicit stages declaration required per 1.2.1',
            );
            return;
        }

        $stages = $this->ciConfig['stages'];

        // Check fail-fast ordering (1.2.4)
        $expectedOrder = ['build', 'test', 'security', 'deploy', 'verify'];
        $normalizedStages = array_map(strtolower(...), $stages);

        $lastIndex = -1;
        foreach ($expectedOrder as $stage) {
            $pos = array_search($stage, $normalizedStages, true);
            if ($pos !== false) {
                if ($pos < $lastIndex) {
                    $this->addIssue(
                        self::SHOULD,
                        'Stage ordering',
                        'stages',
                        'Consider fail-fast ordering: build → test → security → deploy → verify per 1.2.4',
                    );
                    break;
                }

                $lastIndex = $pos;
            }
        }

        // Check all jobs are assigned to stages
        foreach ($this->allJobs as $name => $job) {
            $stage = $job['config']['stage'] ?? null;
            if (empty($stage)) {
                $this->addIssue(
                    self::MUST,
                    'Missing stage',
                    $name . ': stage',
                    'Every job must have explicit stage assignment per 1.2.2',
                );
            } elseif (!in_array($stage, $stages)) {
                $this->addIssue(
                    self::MUST,
                    'Invalid stage',
                    sprintf('%s: %s', $name, $stage),
                    sprintf("Stage '%s' not defined in stages list per 1.2.2", $stage),
                );
            }
        }
    }

    private function validateWorkflow(): void
    {
        // Check workflow rules (3.1.1)
        if (!isset($this->ciConfig['workflow']['rules'])) {
            $this->addIssue(
                self::SHOULD,
                'Missing workflow rules',
                'workflow',
                'Use workflow: rules to control pipeline creation per 3.1.1',
            );
        }

        // Check for only/except usage (3.1.2, 3.1.3)
        $content = file_get_contents($this->primaryFile);
        foreach (array_keys($this->includedFiles) as $path) {
            $content .= "\n" . file_get_contents($this->rootDir . '/' . $path);
        }

        if (preg_match('/^\s*(only|except):\s*$/m', $content)) {
            $this->addIssue(
                self::MUST,
                'Deprecated keywords',
                'control flow',
                "Use 'rules:' not 'only/except' per 3.1.2, 3.1.3",
            );
        }
    }

    private function validateJobs(): void
    {
        foreach ($this->allJobs as $name => $job) {
            // Check naming convention (1.3.1)
            if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', (string) $name)) {
                $this->addIssue(
                    self::MUST,
                    'Invalid job name',
                    $name,
                    'Use kebab-case (e.g., lint:yaml, test:unit) per 1.3.1',
                );
            }

            // Check for tags (5.1)
            if (empty($job['config']['tags'])) {
                $this->addIssue(
                    self::MUST,
                    'Missing tags',
                    $name . ': tags',
                    'Every job must specify tags for runner selection per 5.1',
                );
            }

            // Check timeout (4.4)
            if (empty($job['config']['timeout'])) {
                $this->addIssue(
                    self::SHOULD,
                    'Missing timeout',
                    $name . ': timeout',
                    'Define timeout for long-running jobs per 4.4',
                );
            }

            // Check interruptible (5.4, 5.5)
            $isDeployment = str_contains((string) $name, 'deploy') || str_contains((string) $name, 'prod');
            if ($isDeployment) {
                if (!empty($job['config']['interruptible']) && $job['config']['interruptible'] !== false) {
                    $this->addIssue(
                        self::MUST,
                        'Unsafe interruptible',
                        $name . ': interruptible',
                        'Deployment jobs should have interruptible: false per 5.5',
                    );
                }
            } elseif ($this->config['requireInterruptible'] && !isset($job['config']['interruptible'])) {
                $this->addIssue(
                    self::SHOULD,
                    'Missing interruptible',
                    $name . ': interruptible',
                    'Set interruptible: true for non-mutating jobs per 5.4',
                );
            }

            // Check extends usage (1.3.4)
            if (!isset($job['config']['extends']) && count($job['config']) > 10) {
                $this->addIssue(
                    self::MAY,
                    'Reusable template',
                    $name,
                    "Consider using 'extends' with hidden jobs per 1.3.4",
                );
            }

            // Check retry configuration (4.5)
            if (isset($job['config']['retry'])) {
                $retry = $job['config']['retry'];
                if (is_int($retry) && $retry > 3) {
                    $this->addIssue(
                        self::SHOULD,
                        'Excessive retries',
                        $name . ': retry',
                        'Limit retries to 2-3 attempts per 4.5',
                    );
                }
            }
        }
    }

    private function validateScripts(): void
    {
        foreach ($this->allJobs as $name => $job) {
            $script = $job['config']['script'] ?? [];
            if (!is_array($script)) {
                $script = [$script];
            }

            $beforeScript = $job['config']['before_script'] ?? [];
            if (!is_array($beforeScript)) {
                $beforeScript = [$beforeScript];
            }

            $allScripts = array_merge($beforeScript, $script);
            $scriptText = implode("\n", $allScripts);

            // Check for strict mode (4.1, 4.2)
            if ($this->config['requireStrictMode']) {
                $hasStrictMode = preg_match('/set\s+(-euo\s+pipefail|-eu\s+pipefail|-euo)/', $scriptText);
                if (!$hasStrictMode && count($allScripts) > 1) {
                    $this->addIssue(
                        self::MUST,
                        'Missing strict mode',
                        $name . ': script',
                        "Prepend 'set -euo pipefail' (Bash) or 'set -eu' (POSIX sh) per 4.1, 4.2",
                    );
                }
            }

            // Check script length (4.6)
            if (count($allScripts) > 50) {
                $this->addIssue(
                    self::SHOULD,
                    'Long script',
                    $name . ': script',
                    'Extract long scripts to separate files per 4.6 (scripts/*.sh)',
                );
            }

            // Check for direct vendor/bin commands (user requirement)
            if (preg_match('/\.\/vendor\/bin\//', $scriptText)) {
                $this->addIssue(
                    self::SHOULD,
                    'Direct vendor/bin usage',
                    $name . ': script',
                    "Use 'script' entries instead of direct vendor/bin commands",
                );
            }

            // Check for bash script files in bin/ directory
            if (preg_match('/\.\/bin\/\w+\.sh/', $scriptText)) {
                // This is preferred per file location standards, but check if file exists
                preg_match_all('/\.\/bin\/(\w+\.sh)/', $scriptText, $matches);
                foreach ($matches[1] as $scriptFile) {
                    $fullPath = $this->rootDir . '/bin/' . $scriptFile;
                    if (!file_exists($fullPath)) {
                        $this->addIssue(
                            self::MUST,
                            'Missing script file',
                            $name . ': script',
                            sprintf('Referenced script bin/%s not found per file location standards', $scriptFile),
                        );
                    }
                }
            }

            // Check for deprecated composer flags
            foreach ($this->deprecatedComposerFlags as $flag) {
                if (str_contains($scriptText, (string) $flag)) {
                    $this->addIssue(
                        self::MUST,
                        'Deprecated flag',
                        $name . ': script',
                        sprintf("Remove deprecated '%s' from composer commands per user requirements", $flag),
                    );
                }
            }

            // Check for error handling (4.3)
            if (!preg_match('/echo\s*>\&2/', $scriptText) && preg_match('/exit\s+1/', $scriptText)) {
                $this->addIssue(
                    self::MAY,
                    'Error messaging',
                    $name . ': script',
                    "Use 'echo >&2 \"...\"' then 'exit 1' for clear errors per 4.3",
                );
            }
        }
    }

    private function validateCaching(): void
    {
        // Check cache configuration in jobs
        foreach ($this->allJobs as $name => $job) {
            if (!isset($job['config']['cache'])) {
                continue;
            }

            $cache = $job['config']['cache'];

            // Check for untracked: true (user requirement)
            if ($this->config['requireUntrackedCache'] && !isset($cache['untracked'])) {
                $this->addIssue(
                    self::MUST,
                    'Missing untracked cache',
                    $name . ': cache',
                    "Add 'untracked: true' to cache configuration per user requirements",
                );
            }

            // Check cache key with lockfile (6.1.2)
            if (isset($cache['key'])) {
                $key = $cache['key'];
                if (is_string($key) && !preg_match('/(composer|package)-?lock|yarn\.lock|package-lock\.json/', $key)) {
                    // If key doesn't reference lockfile, check if files key exists
                    if (!isset($cache['key']['files'])) {
                        $this->addIssue(
                            self::MUST,
                            'Unsafe cache key',
                            $name . ': cache.key',
                            'Cache key must incorporate lockfiles to prevent poisoning per 6.1.2',
                        );
                    } else {
                        $files = $cache['key']['files'];
                        $lockfiles = ['composer.lock', 'package-lock.json', 'yarn.lock', 'go.sum'];
                        $hasLockfile = false;
                        foreach ($lockfiles as $lock) {
                            if (in_array($lock, (array) $files)) {
                                $hasLockfile = true;
                                break;
                            }
                        }

                        if (!$hasLockfile) {
                            $this->addIssue(
                                self::MUST,
                                'Missing lockfile in cache key',
                                $name . ': cache.key.files',
                                'Cache key must include lockfile for dependency integrity per 6.1.2',
                            );
                        }
                    }
                }
            }

            // Check policy for pull-only jobs
            // This job updates cache, check if it should
            if (isset($cache['policy']) && $cache['policy'] === 'push' && (str_contains((string) $name, 'lint') || str_contains((string) $name, 'test'))) {
                $this->addIssue(
                    self::MAY,
                    'Cache push policy',
                    $name . ': cache.policy',
                    "Consider 'policy: pull' for jobs that only consume cache per 6.1.3",
                );
            }
        }
    }

    private function validateArtifacts(): void
    {
        foreach ($this->allJobs as $name => $job) {
            if (!isset($job['config']['artifacts'])) {
                continue;
            }

            $artifacts = $job['config']['artifacts'];

            // Check expire_in (6.2.2)
            if (empty($artifacts['expire_in']) && !isset($artifacts['expire_in'])) {
                $this->addIssue(
                    self::MUST,
                    'Missing artifact expiry',
                    $name . ': artifacts',
                    "Set 'expire_in' for all artifacts per 6.2.2",
                );
            } elseif (isset($artifacts['expire_in']) && $artifacts['expire_in'] === 'never') {
                $this->addIssue(
                    self::SHOULD,
                    ' Permanent artifacts',
                    $name . ': artifacts.expire_in',
                    "Avoid 'never' without documented justification per 6.2.2",
                );
            }

            // Check for secrets in artifacts (6.2.3)
            $paths = $artifacts['paths'] ?? [];
            $sensitivePatterns = ['.env', 'config.php', 'secrets', 'credentials', 'token'];
            foreach ($paths as $path) {
                foreach ($sensitivePatterns as $pattern) {
                    if (str_contains(strtolower((string) $path), $pattern)) {
                        $this->addIssue(
                            self::MUST,
                            'Sensitive artifact',
                            $name . ': artifacts.paths',
                            'Do not upload secrets/credentials as artifacts per 6.2.3: ' . $path,
                        );
                    }
                }
            }

            // Check reports (8.1, 10.1)
            if (isset($artifacts['reports']) && (isset($artifacts['reports']['junit']) || isset($artifacts['reports']['coverage']))) {
                // Good, these enable GitLab UI integration
            }
        }
    }

    private function validateImages(): void
    {
        // Check default image
        $defaultImage = $this->ciConfig['default']['image'] ?? '';

        if ($this->config['requirePinnedImages']) {
            // Check for 'latest' tag (5.2)
            foreach ($this->allJobs as $name => $job) {
                $image = $job['config']['image'] ?? $defaultImage;

                $imageName = is_array($image) ? $image['name'] ?? '' : $image;

                if (str_ends_with($imageName, ':latest') || !str_contains($imageName, ':')) {
                    $this->addIssue(
                        self::MUST,
                        'Unpinned image',
                        $name . ': image',
                        "Pin to specific version, not 'latest' per 5.2: " . $imageName,
                    );
                }

                // PHP version consistency check
                if (preg_match('/php:(\d+\.\d+)/', $imageName, $matches)) {
                    $ciPhpVersion = $matches[1];

                    // Compare with composer.json
                    if ($this->composer !== null) {
                        $composerPhp = $this->composer['require']['php'] ?? '';
                        $cleanComposerPhp = preg_replace('/[^\d.]/', '', $composerPhp);

                        if (!empty($cleanComposerPhp) && version_compare($ciPhpVersion, $cleanComposerPhp, '<')) {
                            $this->addIssue(
                                self::MUST,
                                'PHP version mismatch',
                                $name . ': image',
                                sprintf('CI PHP %s < Composer PHP %s per user requirements', $ciPhpVersion, $cleanComposerPhp),
                            );
                        }
                    }
                }
            }
        }
    }

    private function validatePhpVersionConsistency(): void
    {
        // Additional check specifically for PHP version references
        if ($this->composer === null) {
            return;
        }

        $composerPhp = $this->composer['require']['php'] ?? '';
        if (empty($composerPhp)) {
            return;
        }

        $cleanComposerPhp = preg_replace('/[^\d.]/', '', (string) $composerPhp);

        // Scan all YAML content for PHP version references
        $content = file_get_contents($this->primaryFile);
        foreach (array_keys($this->includedFiles) as $path) {
            $content .= file_get_contents($this->rootDir . '/' . $path);
        }

        // Find PHP version references that don't match
        if (preg_match_all('/php[:\-]?(\d+\.\d+)/i', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $foundVersion = $match[1];
                if (version_compare($foundVersion, $cleanComposerPhp, '<')) {
                    $this->addIssue(
                        self::MUST,
                        'PHP version outdated',
                        'CI configuration',
                        sprintf('Found PHP %s reference, but composer.json requires %s per user requirements', $foundVersion, $cleanComposerPhp),
                    );
                }
            }
        }
    }

    private function validateSecurity(): void
    {
        $allContent = file_get_contents($this->primaryFile);
        foreach (array_keys($this->includedFiles) as $path) {
            $allContent .= file_get_contents($this->rootDir . '/' . $path);
        }

        // Check for hardcoded secrets (7.1)
        foreach ($this->securityPatterns as $pattern => $desc) {
            if (preg_match($pattern, $allContent)) {
                $this->addIssue(
                    self::MUST,
                    'Security violation',
                    'YAML content',
                    $desc . ' detected - use CI/CD Variables per 7.1',
                );
            }
        }

        // Check for echo of variables (10.5)
        if (preg_match('/echo\s+\$[A-Z_]*(?:TOKEN|KEY|SECRET|PWD|PASS)/i', $allContent)) {
            $this->addIssue(
                self::MUST,
                'Secret exposure risk',
                'scripts',
                'Do not echo sensitive variables to logs per 10.5',
            );
        }

        // Check for privileged mode (5.3)
        foreach ($this->allJobs as $name => $job) {
            if (!empty($job['config']['privileged'])) {
                $this->addIssue(
                    self::MUST,
                    'Privileged container',
                    $name . ': privileged',
                    'Restrict privileged mode to jobs requiring Docker-in-Docker per 5.3',
                );
            }
        }

        // Check environment protection (7.4, 7.5, 7.6)
        foreach ($this->allJobs as $name => $job) {
            $isDeployment = str_contains((string) $name, 'deploy') || str_contains((string) $name, 'prod');

            if ($isDeployment) {
                if (empty($job['config']['environment'])) {
                    $this->addIssue(
                        self::MUST,
                        'Missing environment',
                        $name,
                        'Deployment jobs require environment block per 7.4',
                    );
                } else {
                    $env = $job['config']['environment'];
                    if (is_array($env)) {
                        if (empty($env['name'])) {
                            $this->addIssue(
                                self::MUST,
                                'Missing environment name',
                                $name . ': environment',
                                'Environment must have name per 7.4',
                            );
                        }

                        if (str_contains(strtolower((string) $env['name']), 'prod') || str_contains((string) $name, 'prod')) {
                            // Check for protection
                            if (empty($job['config']['resource_group'])) {
                                $this->addIssue(
                                    self::MUST,
                                    'Missing resource group',
                                    $name,
                                    'Production deployments need resource_group for serialization per 7.5',
                                );
                            }

                            // Check if protected environment is configured (can't fully validate from file alone)
                            if (!isset($job['config']['environment']['deployment_tier']) ||
                                $job['config']['environment']['deployment_tier'] !== 'production') {
                                $this->addIssue(
                                    self::SHOULD,
                                    'Production tier',
                                    $name . ': environment',
                                    'Mark production environment with deployment_tier per 7.3',
                                );
                            }
                        }
                    }
                }

                // Check for manual gate (7.7)
                if (str_contains(strtolower((string) $name), 'prod') &&
                    (!isset($job['config']['rules']) || !preg_match('/when:\s*manual/i', json_encode($job['config'])))) {
                    $this->addIssue(
                        self::SHOULD,
                        'Missing manual gate',
                        $name . ': rules',
                        'Require manual confirmation for production deployments per 7.7',
                    );
                }
            }
        }
    }

    private function validateDeprecatedFeatures(): void
    {
        // Already checked for only/except in validateWorkflow

        // Check for other deprecated GitLab CI features
        $content = file_get_contents($this->primaryFile);
        foreach (array_keys($this->includedFiles) as $path) {
            $content .= file_get_contents($this->rootDir . '/' . $path);
        }

        // Check for old cache syntax
        if (preg_match('/cache:\s*key:\s*["\']?\$/', $content)) {
            // Key files syntax is preferred but not deprecated
        }
    }

    private function validateRules(): void
    {
        foreach ($this->allJobs as $name => $job) {
            if (!isset($job['config']['rules'])) {
                if (!isset($job['config']['only']) && !isset($job['config']['except'])) {
                    $this->addIssue(
                        self::SHOULD,
                        'Missing rules',
                        $name . ': rules',
                        'Use rules for job conditions per 3.1.3',
                    );
                }

                continue;
            }

            $rules = $job['config']['rules'];
            if (!is_array($rules)) {
                continue;
            }

            // Check rule ordering (3.2.1) - specific to general
            // This is hard to validate statically, but we can check for never at end
            $hasNeverAtEnd = false;
            $lastRule = end($rules);
            if (isset($lastRule['when']) && $lastRule['when'] === 'never') {
                $hasNeverAtEnd = true;
            }

            if (!$hasNeverAtEnd && count($rules) > 1) {
                $this->addIssue(
                    self::MAY,
                    'Rule finalization',
                    $name . ': rules',
                    "Consider 'when: never' as final rule per 3.2.2",
                );
            }

            // Check for MR pipeline rules (8.1)
            $hasMrRule = false;
            foreach ($rules as $rule) {
                $if = $rule['if'] ?? '';
                if (str_contains((string) $if, 'CI_PIPELINE_SOURCE') && str_contains((string) $if, 'merge_request')) {
                    $hasMrRule = true;
                }
            }

            if ((str_contains((string) $name, 'test') || str_contains((string) $name, 'lint')) && (!$hasMrRule && $this->config['requiredWorkflowRules'])) {
                $this->addIssue(
                    self::MUST,
                    'Missing MR rule',
                    $name . ': rules',
                    'Test/lint jobs must run on merge requests per 8.1',
                );
            }

            // Security check: production deployment restrictions (9.1, 7.6)
            if (str_contains((string) $name, 'prod') || str_contains((string) $name, 'production')) {
                $hasBranchProtection = false;
                foreach ($rules as $rule) {
                    $if = $rule['if'] ?? '';
                    if (str_contains((string) $if, 'CI_COMMIT_BRANCH') || str_contains((string) $if, 'CI_DEFAULT_BRANCH')) {
                        $hasBranchProtection = true;
                    }
                }

                if (!$hasBranchProtection) {
                    $this->addIssue(
                        self::MUST,
                        'Unprotected deployment',
                        $name . ': rules',
                        'Production deployments must be restricted to protected branches per 9.1, 7.6',
                    );
                }
            }
        }
    }

    private function validateEnvironment(): void
    {
        // Additional environment checks beyond security
        foreach ($this->allJobs as $name => $job) {
            if (!isset($job['config']['environment'])) {
                continue;
            }

            $env = $job['config']['environment'];
            if (is_string($env)) {
                // Simple environment name
                if (!preg_match('/^[a-z0-9-]+$/', $env)) {
                    $this->addIssue(
                        self::SHOULD,
                        'Environment naming',
                        $name . ': environment',
                        'Use lowercase with hyphens per naming conventions',
                    );
                }
            } elseif (is_array($env)) {
                // Check for URL
                if (empty($env['url'])) {
                    $this->addIssue(
                        self::SHOULD,
                        'Missing environment URL',
                        $name . ': environment',
                        'Add url for GitLab Environments UI integration per 7.4',
                    );
                }
            }
        }
    }

    private function addIssue(string $level, string $category, string $context, string $message): void
    {
        $this->issues[] = [
            'level' => $level,
            'category' => $category,
            'context' => $context,
            'message' => $message,
        ];
    }

    private function printReport(): void
    {
        $mustIssues = array_filter($this->issues, fn (array $i): bool => $i['level'] === self::MUST);
        $shouldIssues = array_filter($this->issues, fn (array $i): bool => $i['level'] === self::SHOULD);
        $mayIssues = array_filter($this->issues, fn (array $i): bool => $i['level'] === self::MAY);

        echo "\n" . str_repeat('=', 60) . "\n";
        echo "GitLab CI/CD Standards Compliance Report\n";
        echo str_repeat('=', 60) . "\n\n";

        // Print MUST issues (red)
        if ($mustIssues !== []) {
            echo "\033[31mMUST (Critical Violations): " . count($mustIssues) . "\033[0m\n";
            echo str_repeat('-', 60) . "\n";
            foreach ($mustIssues as $issue) {
                echo sprintf("\033[31m[%s]\033[0m %s\n", $issue['category'], $issue['context']);
                echo "  → {$issue['message']}\n\n";
            }
        }

        // Print SHOULD issues (yellow)
        if ($shouldIssues !== []) {
            echo "\033[33mSHOULD (Recommendations): " . count($shouldIssues) . "\033[0m\n";
            echo str_repeat('-', 60) . "\n";
            foreach ($shouldIssues as $issue) {
                echo sprintf("\033[33m[%s]\033[0m %s\n", $issue['category'], $issue['context']);
                echo "  → {$issue['message']}\n\n";
            }
        }

        // Print MAY issues (cyan)
        if ($mayIssues !== []) {
            echo "\033[36mMAY (Suggestions): " . count($mayIssues) . "\033[0m\n";
            echo str_repeat('-', 60) . "\n";
            foreach ($mayIssues as $issue) {
                echo sprintf("\033[36m[%s]\033[0m %s\n", $issue['category'], $issue['context']);
                echo "  → {$issue['message']}\n\n";
            }
        }

        if ($this->issues === []) {
            echo "\033[32m✓ All GitLab CI/CD standards met\033[0m\n";
        }

        // Summary statistics
        echo "\nSummary:\n";
        echo "--------\n";
        printf("Primary file: .gitlab-ci.yml\n");
        printf("Included files: %d\n", count($this->includedFiles));
        printf("Job definitions: %d\n", count($this->allJobs));
        printf(
            "Total issues: %d (MUST: %d, SHOULD: %d, MAY: %d)\n",
            count($this->issues),
            count($mustIssues),
            count($shouldIssues),
            count($mayIssues),
        );

        // Compliance score
        $totalChecks = 20 + (count($this->allJobs) * 8);
        $deduction = (count($mustIssues) * 5) + (count($shouldIssues));
        $compliance = max(0, 100 - ($deduction * 100 / $totalChecks));
        printf("Compliance score: %d%%\n", $compliance);

        echo "\n";

        if ($mustIssues !== []) {
            echo "\033[31m⚠️  Critical violations detected - fix before committing\033[0m\n";
            exit(1);
        }

        if ($shouldIssues !== []) {
            echo "\033[33m⚠️  Recommendations found - review suggested improvements\033[0m\n";
        }

        exit(0);
    }
}
