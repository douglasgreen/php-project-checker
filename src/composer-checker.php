<?php

/**
 * Composer.json Standards Compliance Checker
 *
 * Validates composer.json against project standards and Composer best practices.
 */

namespace DouglasGreen\PHPProjectChecker;

declare(strict_types=1);

class ComposerChecker
{
    private string $rootDir;
    private array $config;
    private array $composer;
    private array $issues = [];
    private ?array $lockData = null;

    // RFC 2119 levels
    const MUST = 'MUST';
    const SHOULD = 'SHOULD';
    const MAY = 'MAY';

    private array $allowedTypes = [
        'library', 'project', 'composer-plugin', 'metapackage',
        'symfony-bundle', 'wordpress-plugin', 'wordpress-theme'
    ];

    private array $securityPatterns = [
        '/\brm\s+-rf\s+/' => 'Dangerous rm -rf command detected',
        '/\bsudo\b/' => 'Sudo command detected',
        '/>\s*\/etc\//' => 'System file modification detected',
        '/\bcurl\b.*\|\s*sh/' => 'Pipe to shell detected',
        '/\bwget\b.*\|\s*sh/' => 'Pipe to shell detected',
        '/;\s*rm\s+/' => 'Command chaining with removal',
        '/\brm\s+-[a-z]*f/' => 'Force remove command detected',
    ];

    private array $insecurePaths = [
        '/\.\.\//',  // Parent directory traversal
        '/\/etc\//', // System config
        '/\/usr\/bin\//', // System binaries (should use vendor/bin)
    ];

    public function __construct(string $directory, string $configFile = '')
    {
        $this->rootDir = realpath($directory) ?: getcwd();
        $this->loadComposerJson();
        $this->loadComposerLock();
        $this->loadConfig($configFile);
    }

    private function loadComposerJson(): void
    {
        $path = $this->rootDir . '/composer.json';

        if (!file_exists($path)) {
            fwrite(STDERR, "\033[31mError: composer.json not found in {$this->rootDir}\033[0m\n");
            exit(1);
        }

        $content = file_get_contents($path);
        $this->composer = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            fwrite(STDERR, "\033[31mError: Invalid JSON in composer.json: " . json_last_error_msg() . "\033[0m\n");
            exit(1);
        }

        echo "Loaded composer.json\n";
    }

    private function loadComposerLock(): void
    {
        $path = $this->rootDir . '/composer.lock';
        if (file_exists($path)) {
            $content = file_get_contents($path);
            $this->lockData = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                echo "Loaded composer.lock for version validation\n";
            }
        }
    }

    private function loadConfig(string $configFile): void
    {
        $defaults = [
            'owner' => '',
            'isPublic' => false,
            'expectedLicense' => '',
            'minimumPackageVersions' => [],
            'phpMinimumVersion' => '>=8.3',
            'requireKeywords' => true,
            'checkSchema' => false // Requires external JSON schema validator
        ];

        if ($configFile && file_exists($configFile)) {
            $userConfig = json_decode(file_get_contents($configFile), true);
            if ($userConfig === null) {
                fwrite(STDERR, "\033[33mWarning: Invalid config JSON, using defaults\033[0m\n");
                $this->config = $defaults;
            } else {
                $this->config = array_merge($defaults, $userConfig);
                echo "Loaded configuration from {$configFile}\n";
            }
        } else {
            $this->config = $defaults;
            if ($configFile) {
                echo "Config file not found, using defaults\n";
            }
        }
    }

    public function run(): void
    {
        echo str_repeat("=", 60) . "\n";
        echo "Running Composer Standards Checks\n";
        echo str_repeat("=", 60) . "\n\n";

        $this->validateBasicStructure();
        $this->validatePackageName();
        $this->validateType();
        $this->validateDescription();
        $this->validateLicense();
        $this->validateKeywords();
        $this->validateVersion();
        $this->validatePhpVersion();
        $this->validateAutoload();
        $this->validateConfig();
        $this->validateRequireAndDev();
        $this->validateConflictReplaceProvide();
        $this->validateSuggest();
        $this->validateStability();
        $this->validateRepositories();
        $this->validateScripts();
        $this->validateBin();
        $this->validateExtra();
        $this->validateSupport();
        $this->validateFunding();
        $this->validateAbandoned();
        $this->validateArchive();

        if ($this->config['isPublic']) {
            $this->validatePublicFields();
        }

        if (!empty($this->config['minimumPackageVersions'])) {
            $this->validateMinimumVersions();
        }

        $this->runComposerValidate();

        $this->printReport();
    }

    private function validateBasicStructure(): void
    {
        $required = ['name', 'description', 'type'];
        foreach ($required as $field) {
            if (empty($this->composer[$field])) {
                $this->addIssue(self::MUST, "Missing required field", "composer.json",
                    "Field '{$field}' is required");
            }
        }
    }

    private function validatePackageName(): void
    {
        $name = $this->composer['name'] ?? '';

        // Pattern: owner/package with lowercase, numbers, hyphens, underscores
        if (!preg_match('/^[a-z0-9_-]+\/[a-z0-9_-]+$/', $name)) {
            $this->addIssue(self::MUST, "Invalid package name format", "name: {$name}",
                "Must match pattern: ^[a-z0-9_\\-]+\\/[a-z0-9_\\-]+$ (lowercase, hyphenated)");
        }

        // Owner validation
        if (!empty($this->config['owner'])) {
            $parts = explode('/', $name);
            if ($parts[0] !== $this->config['owner']) {
                $this->addIssue(self::MUST, "Owner mismatch", "name: {$name}",
                    "Owner '{$parts[0]}' does not match expected '{$this->config['owner']}'");
            }
        }
    }

    private function validateType(): void
    {
        $type = $this->composer['type'] ?? 'library';

        if (!in_array($type, $this->allowedTypes, true)) {
            $this->addIssue(self::MUST, "Invalid package type", "type: {$type}",
                "Must be one of: " . implode(', ', $this->allowedTypes));
        }
    }

    private function validateDescription(): void
    {
        $desc = $this->composer['description'] ?? '';

        if (empty($desc) || trim($desc) === '') {
            $this->addIssue(self::MUST, "Missing description", "description",
                "Description field must be present and not empty");
        } elseif (strlen($desc) < 20) {
            $this->addIssue(self::SHOULD, "Description too short", "description",
                "Description should be meaningful (currently " . strlen($desc) . " chars)");
        }
    }

    private function validateLicense(): void
    {
        $license = $this->composer['license'] ?? null;

        if (empty($license)) {
            $this->addIssue(self::MUST, "Missing license", "license",
                "License field is required (e.g., MIT, GPL-3.0, proprietary)");
            return;
        }

        $licenseStr = is_array($license) ? implode(', ', $license) : $license;

        if (!empty($this->config['expectedLicense'])) {
            $expected = $this->config['expectedLicense'];
            if ((is_array($license) && !in_array($expected, $license)) ||
                (!is_array($license) && $license !== $expected)) {
                $this->addIssue(self::MUST, "License mismatch", "license: {$licenseStr}",
                    "Expected license '{$expected}'");
            }
        }
    }

    private function validateKeywords(): void
    {
        $keywords = $this->composer['keywords'] ?? null;

        if ($this->config['requireKeywords'] && (empty($keywords) || !is_array($keywords))) {
            $this->addIssue(self::MUST, "Missing keywords", "keywords",
                "Keywords array is required");
            return;
        }

        if (is_array($keywords)) {
            // Check for duplicates
            $unique = array_unique($keywords);
            if (count($unique) !== count($keywords)) {
                $dups = array_diff_key($keywords, $unique);
                $this->addIssue(self::SHOULD, "Duplicate keywords", "keywords",
                    "Duplicate entries found: " . implode(', ', array_intersect_key($keywords, $dups)));
            }

            // Check all are strings
            foreach ($keywords as $kw) {
                if (!is_string($kw) || empty(trim($kw))) {
                    $this->addIssue(self::MUST, "Invalid keyword", "keywords",
                        "Keywords must be non-empty strings");
                    break;
                }
            }
        }
    }

    private function validateVersion(): void
    {
        if (isset($this->composer['version'])) {
            $version = $this->composer['version'];

            // Check format if present (should follow semver)
            if (!preg_match('/^\d+\.\d+\.\d+(-.+)?$/', $version)) {
                $this->addIssue(self::SHOULD, "Non-semver version", "version: {$version}",
                    "Version should follow semantic versioning (1.0.0) or be omitted (managed by git tags)");
            }

            $this->addIssue(self::SHOULD, "Version field present", "version",
                "Version field should typically be omitted for libraries (managed via git tags)");
        }
    }

    private function validatePhpVersion(): void
    {
        $phpConstraint = $this->composer['require']['php'] ?? '';

        if (empty($phpConstraint)) {
            $this->addIssue(self::MUST, "Missing PHP version", "require.php",
                "PHP version constraint is required");
            return;
        }

        // Check for 8.3+
        $minPhp = $this->config['phpMinimumVersion'];
        if (!preg_match('/>=8\.[3-9]|>=9|~\d+\.\d+|^\d+\.\d+/', $phpConstraint)) {
            $this->addIssue(self::MUST, "PHP version too low", "require.php: {$phpConstraint}",
                "Requires {$minPhp} or higher");
        }
    }

    private function validateAutoload(): void
    {
        $autoload = $this->composer['autoload'] ?? [];
        $psr4 = $autoload['psr-4'] ?? [];

        if (empty($psr4)) {
            $this->addIssue(self::MUST, "Missing PSR-4 autoload", "autoload.psr-4",
                "Must use PSR-4 autoloading (not PSR-0 or classmap only)");
            return;
        }

        $projectName = explode('/', $this->composer['name'] ?? '/')[1] ?? '';
        $owner = $this->config['owner'] ?: explode('/', $this->composer['name'] ?? '/')[0];

        // Convert owner to StudlyCase for namespace
        $expectedNamespace = $this->toStudlyCase($owner) . '\\\\' . $this->toStudlyCase($projectName);

        $foundValid = false;
        foreach ($psr4 as $namespace => $path) {
            // Check if namespace matches owner
            if (!empty($this->config['owner'])) {
                $expectedOwnerNs = $this->toStudlyCase($owner);
                if (strpos($namespace, $expectedOwnerNs) !== 0) {
                    $this->addIssue(self::MUST, "Namespace owner mismatch", "autoload.psr-4: {$namespace}",
                        "Top-level namespace should match owner '{$expectedOwnerNs}'");
                }
            }

            // Check if qualified with project name (not just owner)
            $parts = explode('\\\\', trim($namespace, '\\\\'));
            if (count($parts) < 2) {
                $this->addIssue(self::MUST, "Unqualified namespace", "autoload.psr-4: {$namespace}",
                    "Namespace must be qualified with project name, not just '{$parts[0]}\\\\'");
            }

            // Check source path exists
            $fullPath = $this->rootDir . '/' . trim($path, '/');
            if (!is_dir($fullPath)) {
                $this->addIssue(self::MUST, "Missing autoload path", "path: {$path}",
                    "Autoload path '{$fullPath}' does not exist");
            } else {
                $foundValid = true;
            }
        }

        if (!$foundValid) {
            $this->addIssue(self::MUST, "Invalid autoload configuration", "autoload",
                "No valid PSR-4 autoload paths found");
        }
    }

    private function validateConfig(): void
    {
        $config = $this->composer['config'] ?? [];

        if (!isset($config['sort-packages']) || $config['sort-packages'] !== true) {
            $this->addIssue(self::MUST, "Missing sort-packages", "config.sort-packages",
                "Must be set to true for consistent package ordering");
        }

        // Check platform settings if present
        if (isset($config['platform']['php'])) {
            $this->addIssue(self::MAY, "Platform override", "config.platform.php",
                "Platform PHP version override may cause dependency resolution issues");
        }
    }

    private function validateRequireAndDev(): void
    {
        $sections = ['require' => 'require', 'require-dev' => 'require-dev'];

        foreach ($sections as $section => $label) {
            $packages = $this->composer[$section] ?? [];

            foreach ($packages as $package => $version) {
                // Check for wildcards
                if ($version === '*') {
                    $this->addIssue(self::MUST, "Wildcard dependency", "{$label}: {$package}",
                        "Avoid '*' wildcards; use explicit version constraints");
                }

                // Check for dev constraints
                if (preg_match('/^dev-/', $version)) {
                    $this->addIssue(self::SHOULD, "Development dependency", "{$label}: {$package}",
                        "Avoid dev-* constraints in {$label} unless necessary");
                }

                // Check for loose constraints in require (not dev)
                if ($section === 'require' && preg_match('/^~0\.|^>=0\.|\*/', $version)) {
                    $this->addIssue(self::SHOULD, "Loose constraint", "{$label}: {$package} {$version}",
                        "Consider stricter version constraints for stability");
                }
            }
        }
    }

    private function validateMinimumVersions(): void
    {
        $required = $this->config['minimumPackageVersions'] ?? [];
        $require = array_merge(
            $this->composer['require'] ?? [],
            $this->composer['require-dev'] ?? []
        );

        foreach ($required as $package => $minimum) {
            if (!isset($require[$package])) {
                $this->addIssue(self::MUST, "Missing required package", "require: {$package}",
                    "Package '{$package}' is required with minimum version '{$minimum}'");
                continue;
            }

            $constraint = $require[$package];
            // Simple check: does constraint meet minimum?
            // Extract version numbers for comparison
            if ($this->lockData) {
                $installed = $this->getInstalledVersion($package);
                if ($installed && version_compare($installed, ltrim($minimum, '^~>=<!'), '<')) {
                    $this->addIssue(self::MUST, "Version below minimum", "{$package}: {$installed}",
                        "Installed version {$installed} is below required {$minimum}");
                }
            }
        }
    }

    private function getInstalledVersion(string $package): ?string
    {
        if (!$this->lockData) return null;

        foreach ($this->lockData['packages'] ?? [] as $pkg) {
            if ($pkg['name'] === $package) {
                return $pkg['version'];
            }
        }

        foreach ($this->lockData['packages-dev'] ?? [] as $pkg) {
            if ($pkg['name'] === $package) {
                return $pkg['version'];
            }
        }

        return null;
    }

    private function validateConflictReplaceProvide(): void
    {
        foreach (['conflict', 'replace', 'provide'] as $section) {
            if (empty($this->composer[$section])) continue;

            foreach ($this->composer[$section] as $package => $constraint) {
                if (!preg_match('/^[a-z0-9_.-]+\/[a-z0-9_.-]+$/i', $package)) {
                    $this->addIssue(self::MUST, "Invalid package name", "{$section}: {$package}",
                        "Package name must follow vendor/package format");
                }

                if (!empty($constraint) && !preg_match('/^\^|~|>=|<=|>|<|!=|\d+\./', $constraint)) {
                    $this->addIssue(self::SHOULD, "Invalid constraint", "{$section}: {$package} {$constraint}",
                        "Version constraint appears invalid");
                }
            }
        }

        // Check for self-replacement loops
        if (isset($this->composer['replace'][$this->composer['name'] ?? ''])) {
            $this->addIssue(self::MUST, "Self-replacement loop", "replace: {$this->composer['name']}",
                "Package cannot replace itself");
        }
    }

    private function validateSuggest(): void
    {
        $suggest = $this->composer['suggest'] ?? [];

        foreach ($suggest as $package => $reason) {
            if (!preg_match('/^[a-z0-9_.-]+\/[a-z0-9_.-]+$/i', $package)) {
                $this->addIssue(self::SHOULD, "Invalid suggest package", "suggest: {$package}",
                    "Package name format invalid");
            }

            if (empty($reason) || !is_string($reason)) {
                $this->addIssue(self::SHOULD, "Missing suggest reason", "suggest: {$package}",
                    "Suggestion should include reason for suggestion");
            }
        }
    }

    private function validateStability(): void
    {
        $stability = $this->composer['minimum-stability'] ?? 'stable';
        $preferStable = $this->composer['prefer-stable'] ?? false;

        if ($stability !== 'stable') {
            $this->addIssue(self::SHOULD, "Non-stable minimum", "minimum-stability: {$stability}",
                "Minimum stability should be 'stable' for production packages");
        }

        if ($preferStable !== true) {
            $this->addIssue(self::SHOULD, "Missing prefer-stable", "prefer-stable",
                "Should be set to true to prefer stable releases");
        }
    }

    private function validateRepositories(): void
    {
        $repos = $this->composer['repositories'] ?? [];
        $seenUrls = [];

        foreach ($repos as $key => $repo) {
            if (is_string($repo)) {
                // Simple VCS URL
                continue;
            }

            $type = $repo['type'] ?? '';
            $url = $repo['url'] ?? '';

            // Check for old GitLab type
            if ($type === 'gitlab') {
                $this->addIssue(self::SHOULD, "Deprecated repository type", "repositories[{$key}]",
                    "Use 'type: composer' with GitLab instead of legacy 'type: gitlab'");
            }

            // Check valid types
            $validTypes = ['composer', 'vcs', 'git', 'hg', 'perforce', 'artifact', 'path', 'package'];
            if (!in_array($type, $validTypes, true)) {
                $this->addIssue(self::SHOULD, "Invalid repository type", "repositories[{$key}]: {$type}",
                    "Type '{$type}' may not be supported");
            }

            // Check for duplicates
            if (!empty($url)) {
                if (in_array($url, $seenUrls)) {
                    $this->addIssue(self::SHOULD, "Duplicate repository", "repositories[{$key}]",
                        "URL '{$url}' is defined multiple times");
                }
                $seenUrls[] = $url;
            }
        }
    }

    private function validateScripts(): void
    {
        $scripts = $this->composer['scripts'] ?? [];
        $postInstall = $scripts['post-install-cmd'] ?? [];
        $postUpdate = $scripts['post-update-cmd'] ?? [];

        $checkCommands = function($commands, $event) {
            if (!is_array($commands)) {
                $commands = [$commands];
            }

            foreach ($commands as $cmd) {
                // Check for paths in commands (should use bin)
                if (preg_match('/\.\.\/|^\.\/|^\//', $cmd)) {
                    $this->addIssue(self::SHOULD, "Hardcoded path in script", "scripts.{$event}",
                        "Avoid paths in commands: '{$cmd}'. Use @putenv or vendor/bin/");
                }

                // Security checks
                foreach ($this->securityPatterns as $pattern => $desc) {
                    if (preg_match($pattern, $cmd)) {
                        $this->addIssue(self::MUST, "Security risk", "scripts.{$event}",
                            "{$desc}: '{$cmd}'");
                    }
                }

                foreach ($this->insecurePaths as $pattern => $desc) {
                    if (preg_match($pattern, $cmd)) {
                        $this->addIssue(self::MUST, "Insecure path", "scripts.{$event}",
                            "Command contains potentially dangerous path: '{$cmd}'");
                    }
                }
            }
        };

        $checkCommands($postInstall, 'post-install-cmd');
        $checkCommands($postUpdate, 'post-update-cmd');

        // Check for custom scripts with security issues
        foreach ($scripts as $name => $commands) {
            if (in_array($name, ['post-install-cmd', 'post-update-cmd', 'post-package-install'])) {
                continue;
            }

            if (!is_array($commands)) {
                $commands = [$commands];
            }

            foreach ($commands as $cmd) {
                if (preg_match('/\brm\s+-rf\b/', $cmd) ||
                    preg_match('/\bsocket_|exec|system|passthru|shell_exec/', $cmd)) {
                    $this->addIssue(self::MUST, "Dangerous script command", "scripts.{$name}",
                        "Script contains potentially dangerous command: '{$cmd}'");
                }
            }
        }
    }

    private function validateBin(): void
    {
        $bin = $this->composer['bin'] ?? [];

        if (empty($bin)) {
            return;
        }

        if (!is_array($bin)) {
            $this->addIssue(self::MUST, "Invalid bin format", "bin",
                "Bin must be an array of paths");
            return;
        }

        foreach ($bin as $binary) {
            $fullPath = $this->rootDir . '/' . $binary;

            if (!file_exists($fullPath)) {
                $this->addIssue(self::MUST, "Missing binary", "bin: {$binary}",
                    "Binary file does not exist: {$fullPath}");
            } elseif (!is_executable($fullPath)) {
                // Note: Windows doesn't have executable bit, so this is Unix-specific
                if (PHP_OS_FAMILY !== 'Windows') {
                    $this->addIssue(self::SHOULD, "Non-executable binary", "bin: {$binary}",
                        "Binary should be executable (chmod +x)");
                }
            }
        }
    }

    private function validateExtra(): void
    {
        $extra = $this->composer['extra'] ?? [];

        // Check Symfony bundle class if type is symfony-bundle
        if (($this->composer['type'] ?? '') === 'symfony-bundle') {
            if (empty($extra['symfony']['class'])) {
                $this->addIssue(self::SHOULD, "Missing bundle class", "extra.symfony.class",
                    "Symfony bundles should define the bundle class in extra.symfony.class");
            }
        }

        // Check for laravel providers
        if (isset($extra['laravel'])) {
            if (empty($extra['laravel']['providers'])) {
                $this->addIssue(self::MAY, "Missing Laravel providers", "extra.laravel",
                    "Laravel packages typically auto-register providers");
            }
        }
    }

    private function validateSupport(): void
    {
        $support = $this->composer['support'] ?? [];

        if (empty($support['issues'])) {
            $this->addIssue(self::MAY, "Missing support info", "support.issues",
                "Consider adding issues URL for bug reports");
        } elseif ($this->config['isPublic'] && !empty($this->config['owner'])) {
            $expected = "https://github.com/{$this->config['owner']}/";
            if (strpos($support['issues'], $expected) !== 0) {
                $this->addIssue(self::SHOULD, "Issues URL mismatch", "support.issues",
                    "Issues URL should match GitHub repo pattern: {$expected}");
            }
        }

        if (!empty($support['source']) && !filter_var($support['source'], FILTER_VALIDATE_URL)) {
            $this->addIssue(self::SHOULD, "Invalid source URL", "support.source",
                "Source URL appears invalid");
        }
    }

    private function validateFunding(): void
    {
        $funding = $this->composer['funding'] ?? [];

        if (empty($funding)) {
            return; // Optional
        }

        foreach ($funding as $entry) {
            $url = $entry['url'] ?? '';
            $type = $entry['type'] ?? '';

            if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
                $this->addIssue(self::SHOULD, "Invalid funding URL", "funding",
                    "Funding entry has invalid or missing URL");
            }

            if (!in_array($type, ['github', 'open_collective', 'tidelift', 'community_bridge', 'liberapay', 'issuehunt', 'ko_fi', 'other'])) {
                $this->addIssue(self::MAY, "Unknown funding type", "funding",
                    "Funding type '{$type}' may not be recognized by all tools");
            }
        }
    }

    private function validateAbandoned(): void
    {
        if (!empty($this->composer['abandoned'])) {
            $replacement = is_string($this->composer['abandoned']) ? $this->composer['abandoned'] : 'true';
            $this->addIssue(self::SHOULD, "Package abandoned", "abandoned",
                "This package is marked as abandoned. Replacement: {$replacement}");
        }
    }

    private function validateArchive(): void
    {
        $archive = $this->composer['archive'] ?? [];

        if (empty($archive)) {
            return;
        }

        $excludes = $archive['exclude'] ?? [];
        if (!is_array($excludes)) {
            $this->addIssue(self::SHOULD, "Invalid archive exclude", "archive.exclude",
                "Archive exclude must be an array of patterns");
        }
    }

    private function validatePublicFields(): void
    {
        // Homepage check
        $homepage = $this->composer['homepage'] ?? '';
        $name = $this->composer['name'] ?? '';
        $project = explode('/', $name)[1] ?? '';

        if (empty($homepage)) {
            $this->addIssue(self::MUST, "Missing homepage", "homepage",
                "Public packages require homepage URL");
        } elseif (!preg_match('/^https:\/\/github\.com\/' . preg_quote($this->config['owner'], '/') . '\/' . preg_quote($project, '/') . '$/i', $homepage)) {
            $this->addIssue(self::MUST, "Invalid homepage format", "homepage: {$homepage}",
                "Public project homepage must match: https://github.com/{$this->config['owner']}/{$project}");
        }

        // Authors check
        $authors = $this->composer['authors'] ?? [];
        if (empty($authors) || !is_array($authors)) {
            $this->addIssue(self::MUST, "Missing authors", "authors",
                "Public projects must include authors array");
        } else {
            $foundAuthor = false;
            foreach ($authors as $author) {
                if (($author['name'] ?? '') === 'Douglas Green' &&
                    ($author['email'] ?? '') === 'douglas@nurd.site') {
                    $foundAuthor = true;

                    // Validate other fields
                    if (($author['homepage'] ?? '') !== 'https://nurd.site/') {
                        $this->addIssue(self::MUST, "Invalid author homepage", "authors[].homepage",
                            "Author homepage must be 'https://nurd.site/'");
                    }

                    if (($author['role'] ?? '') !== 'Developer') {
                        $this->addIssue(self::MUST, "Invalid author role", "authors[].role",
                            "Author role must be 'Developer'");
                    }
                }
            }

            if (!$foundAuthor) {
                $this->addIssue(self::MUST, "Missing required author", "authors",
                    "Must include author: name='Douglas Green', email='douglas@nurd.site', homepage='https://nurd.site/', role='Developer'");
            }
        }

        // Keywords already checked in validateKeywords but ensure present
        if (empty($this->composer['keywords']) || !is_array($this->composer['keywords'])) {
            $this->addIssue(self::MUST, "Missing keywords (public)", "keywords",
                "Public projects must include keywords array");
        }
    }

    private function runComposerValidate(): void
    {
        // Run composer validate --strict if composer is available
        $composerPath = trim(shell_exec('which composer 2>/dev/null') ?: '');

        if (empty($composerPath)) {
            $this->addIssue(self::MAY, "Composer not found", "system",
                "Cannot run 'composer validate --strict' - composer not in PATH");
            return;
        }

        $command = sprintf('cd %s && %s validate --strict --no-ansi 2>&1',
            escapeshellarg($this->rootDir),
            escapeshellarg($composerPath)
        );

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            foreach ($output as $line) {
                if (preg_match('/(error|warning):\s*(.+)/i', $line, $matches)) {
                    $type = strtolower($matches[1]) === 'error' ? self::MUST : self::SHOULD;
                    $this->addIssue($type, "Composer validation", "composer validate", $matches[2]);
                }
            }
        }

        // Check for schema validation if requested
        if ($this->config['checkSchema'] ?? false) {
            $this->validateJsonSchema();
        }
    }

    private function validateJsonSchema(): void
    {
        $schemaUrl = 'https://getcomposer.org/schema.json';
        $schema = @file_get_contents($schemaUrl);

        if ($schema === false) {
            $this->addIssue(self::MAY, "Schema fetch failed", "validation",
                "Could not fetch Composer schema from {$schemaUrl}");
            return;
        }

        $schemaData = json_decode($schema);
        if (!$schemaData) {
            $this->addIssue(self::MAY, "Invalid schema", "validation",
                "Could not parse Composer schema JSON");
            return;
        }

        // Use justinrainbow/json-schema if available, otherwise skip detailed validation
        if (!class_exists('JsonSchema\Validator')) {
            $this->addIssue(self::MAY, "Schema validator missing", "validation",
                "Install justinrainbow/json-schema for schema validation");
            return;
        }

        $validator = new \JsonSchema\Validator();
        $validator->validate($this->composer, $schemaData);

        if (!$validator->isValid()) {
            foreach ($validator->getErrors() as $error) {
                $property = $error['property'] ?? 'root';
                $this->addIssue(self::MUST, "Schema violation", $property, $error['message']);
            }
        }
    }

    private function toStudlyCase(string $string): string
    {
        return str_replace(['-', '_'], '',
            ucwords(strtolower($string), '-_'));
    }

    private function addIssue(string $level, string $category, string $context, string $message): void
    {
        $this->issues[] = [
            'level' => $level,
            'category' => $category,
            'context' => $context,
            'message' => $message
        ];
    }

    private function printReport(): void
    {
        $mustIssues = array_filter($this->issues, fn($i) => $i['level'] === self::MUST);
        $shouldIssues = array_filter($this->issues, fn($i) => $i['level'] === self::SHOULD);
        $mayIssues = array_filter($this->issues, fn($i) => $i['level'] === self::MAY);

        echo "\n" . str_repeat("=", 60) . "\n";
        echo "Composer Standards Compliance Report\n";
        echo str_repeat("=", 60) . "\n\n";

        // Print MUST issues (red)
        if (!empty($mustIssues)) {
            echo "\033[31mMUST (Critical Violations): " . count($mustIssues) . "\033[0m\n";
            echo str_repeat("-", 60) . "\n";
            foreach ($mustIssues as $issue) {
                echo sprintf("\033[31m[%s]\033[0m %s\n", $issue['category'], $issue['context']);
                echo "  → {$issue['message']}\n\n";
            }
        }

        // Print SHOULD issues (yellow)
        if (!empty($shouldIssues)) {
            echo "\033[33mSHOULD (Recommendations): " . count($shouldIssues) . "\033[0m\n";
            echo str_repeat("-", 60) . "\n";
            foreach ($shouldIssues as $issue) {
                echo sprintf("\033[33m[%s]\033[0m %s\n", $issue['category'], $issue['context']);
                echo "  → {$issue['message']}\n\n";
            }
        }

        // Print MAY issues (cyan)
        if (!empty($mayIssues)) {
            echo "\033[36mMAY (Suggestions): " . count($mayIssues) . "\033[0m\n";
            echo str_repeat("-", 60) . "\n";
            foreach ($mayIssues as $issue) {
                echo sprintf("\033[36m[%s]\033[0m %s\n", $issue['category'], $issue['context']);
                echo "  → {$issue['message']}\n\n";
            }
        }

        if (empty($this->issues)) {
            echo "\033[32m✓ All composer.json standards met\033[0m\n";
        }

        // Summary statistics
        echo "\nSummary:\n";
        echo "--------\n";
        printf("Project: %s\n", $this->composer['name'] ?? 'unknown');
        printf("Type: %s\n", $this->composer['type'] ?? 'library');
        printf("Total issues: %d (MUST: %d, SHOULD: %d, MAY: %d)\n",
            count($this->issues), count($mustIssues), count($shouldIssues), count($mayIssues));

        // Compliance score
        $totalChecks = 25; // Approximate number of validation rules
        $deduction = (count($mustIssues) * 4) + (count($shouldIssues) * 1);
        $compliance = max(0, 100 - ($deduction * 100 / $totalChecks));
        printf("Compliance score: %d%%\n", $compliance);

        echo "\n";

        if (!empty($mustIssues)) {
            echo "\033[31m⚠️  Critical violations detected - fix before committing\033[0m\n";
            exit(1);
        }

        exit(0);
    }
}
