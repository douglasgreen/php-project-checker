<?php

/**
 * package.json Standards Compliance Checker
 *
 * Validates package.json against project standards, security best practices,
 * and cross-file consistency with composer.json.
 */

namespace DouglasGreen\PHPProjectChecker;

declare(strict_types=1);

class PackageJsonChecker
{
    private string $rootDir;
    private array $config;
    private array $package;
    private ?array $composer = null;
    private array $issues = [];
    private array $fileInventory = [];

    // RFC 2119 levels
    const MUST = 'MUST';
    const SHOULD = 'SHOULD';
    const MAY = 'MAY';

    private array $allowedTypes = [
        'module', 'commonjs', 'module-commonjs', 'esm', 'cjs'
    ];

    private array $minimumVersions = [
        'husky' => '>=9.0.0',
        'prettier' => '>=3.0.0',
        'eslint' => '>=9.0.0',
        'stylelint' => '>=16.0.0',
        'jest' => '>=29.0.0',
        'typescript' => '>=5.0.0',
        'vite' => '>=5.0.0',
        'webpack' => '>=5.0.0'
    ];

    private array $deprecatedConfigs = [
        '.eslintrc.js' => 'Migrate to eslint.config.js (flat config)',
        '.eslintrc.cjs' => 'Migrate to eslint.config.js (flat config)',
        '.eslintrc.yaml' => 'Migrate to eslint.config.js (flat config)',
        '.eslintrc.yml' => 'Migrate to eslint.config.js (flat config)',
        '.eslintrc.json' => 'Migrate to eslint.config.js (flat config)',
        '.prettierrc.yaml' => 'Convert to .prettierrc.json',
        '.prettierrc.yml' => 'Convert to .prettierrc.json',
        '.mocharc.cjs' => 'Switch to Jest config in package.json',
        '.mocharc.js' => 'Switch to Jest config in package.json',
        'phpcs.xml' => 'Replace with ecs.php for EasyCodingStandard',
        'phpcs.xml.dist' => 'Replace with ecs.php for EasyCodingStandard',
        'tslint.json' => 'TSLint is deprecated; migrate to ESLint with @typescript-eslint'
    ];

    private array $fileTypeLocations = [
        'bin/' => ['*.sh', '*.bash', '*.zsh'],
        'assets/styles/' => ['*.css', '*.scss', '*.sass', '*.less', '*.styl'],
        'assets/data/' => ['*.json', '*.yaml', '*.yml', '*.csv', '*.tsv'],
        'assets/images/' => ['*.png', '*.jpg', '*.jpeg', '*.gif', '*.svg', '*.webp', '*.ico'],
        'assets/scripts/' => ['*.js', '*.ts', '*.mjs', '*.cjs', '*.jsx', '*.tsx'],
        'config/' => ['*.json', '*.yaml', '*.yml', '*.xml', '*.ini', '*.conf', '*.dist'],
        'assets/sql/' => ['*.sql', '*.ddl', '*.dml'],
        'assets/xml/' => ['*.xml', '*.xsd', '*.xsl', '*.xslt', '*.wsdl']
    ];

    public function __construct(string $directory, string $configFile = '')
    {
        $this->rootDir = realpath($directory) ?: getcwd();
        $this->loadPackageJson();
        $this->loadComposerJson();
        $this->scanFileInventory();
        $this->loadConfig($configFile);
    }

    private function loadPackageJson(): void
    {
        $path = $this->rootDir . '/package.json';

        if (!file_exists($path)) {
            fwrite(STDERR, "\033[31mError: package.json not found in {$this->rootDir}\033[0m\n");
            exit(1);
        }

        $content = file_get_contents($path);
        $this->package = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            fwrite(STDERR, "\033[31mError: Invalid JSON in package.json: " . json_last_error_msg() . "\033[0m\n");
            exit(1);
        }

        echo "Loaded package.json\n";
    }

    private function loadComposerJson(): void
    {
        $path = $this->rootDir . '/composer.json';
        if (file_exists($path)) {
            $content = file_get_contents($path);
            $this->composer = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                echo "Loaded composer.json for cross-validation\n";
            }
        }
    }

    private function scanFileInventory(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->rootDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relative = str_replace($this->rootDir . '/', '', $file->getPathname());
                $this->fileInventory[] = $relative;
            }
        }

        echo "Scanned " . count($this->fileInventory) . " files\n";
    }

    private function loadConfig(string $configFile): void
    {
        $defaults = [
            'owner' => '',
            'isPublic' => false,
            'expectedLicense' => 'MIT',
            'minimumPackageVersions' => [],
            'phpMinimumVersion' => '>=8.3',
            'requireKeywords' => true,
            'checkSchema' => false,
            'allowedKeywords' => [],
            'forbiddenKeywords' => ['tool', 'utility', 'helper']
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
        echo "Running package.json Standards Checks\n";
        echo str_repeat("=", 60) . "\n\n";

        $this->validateBasicStructure();
        $this->validatePackageName();
        $this->validateType();
        $this->validateDescription();
        $this->validateLicense();
        $this->validateKeywords();
        $this->validateVersion();
        $this->validateEngines();
        $this->validateDependencies();
        $this->validateScripts();
        $this->validateConfig();
        $this->validateBin();
        $this->validateFiles();
        $this->validateExports();
        $this->validateDeprecatedConfigs();
        $this->validateFileLocations();
        $this->validateToolingConfigs();
        $this->validateCrossFileConsistency();

        if ($this->config['isPublic']) {
            $this->validatePublicFields();
        }

        $this->printReport();
    }

    private function validateBasicStructure(): void
    {
        $required = ['name', 'version', 'description'];
        foreach ($required as $field) {
            if (empty($this->package[$field])) {
                $this->addIssue(self::MUST, "Missing required field", $field,
                    "Field '{$field}' is required in package.json");
            }
        }
    }

    private function validatePackageName(): void
    {
        $name = $this->package['name'] ?? '';

        // Check scoped or unscoped pattern
        if (str_starts_with($name, '@')) {
            // Scoped package: @owner/name
            if (!preg_match('/^@[a-z0-9_-]+\/[a-z0-9_-]+$/', $name)) {
                $this->addIssue(self::MUST, "Invalid scoped name", "name: {$name}",
                    "Scoped packages must match @owner/name with lowercase, hyphens, underscores");
            }

            // Extract owner from scope
            $parts = explode('/', $name);
            $scope = substr($parts[0], 1); // Remove @

            if (!empty($this->config['owner']) && $scope !== $this->config['owner']) {
                $this->addIssue(self::MUST, "Scope/owner mismatch", "name: {$name}",
                    "Scope '{$scope}' does not match expected owner '{$this->config['owner']}'");
            }
        } else {
            // Unscoped: check if it matches owner/project pattern
            if (!preg_match('/^[a-z0-9_-]+$/', $name)) {
                $this->addIssue(self::MUST, "Invalid package name", "name: {$name}",
                    "Unscoped packages must be lowercase with hyphens/underscores only");
            }

            // For cross-project consistency, suggest scoped names
            if (!empty($this->config['owner'])) {
                $this->addIssue(self::SHOULD, "Consider scoped package", "name: {$name}",
                    "For consistency with Composer, consider using @{$this->config['owner']}/{$name}");
            }
        }
    }

    private function validateType(): void
    {
        $type = $this->package['type'] ?? 'commonjs';

        // NPM types are different from Composer, but we can suggest consistency
        if (!in_array($type, $this->allowedTypes, true)) {
            $this->addIssue(self::MAY, "Non-standard type", "type: {$type}",
                "Type '{$type}' is not in standard list: " . implode(', ', $this->allowedTypes));
        }

        // Check for module consistency
        if ($type === 'module' && !empty($this->package['main'])) {
            if (!str_ends_with($this->package['main'], '.mjs') &&
                !str_ends_with($this->package['main'], '.js')) {
                $this->addIssue(self::SHOULD, "Module extension", "main",
                    "ES modules should use .mjs extension or specify exports field");
            }
        }
    }

    private function validateDescription(): void
    {
        $desc = $this->package['description'] ?? '';

        if (empty($desc)) {
            $this->addIssue(self::MUST, "Missing description", "description",
                "Description is required for NPM packages");
        } elseif (strlen($desc) < 20) {
            $this->addIssue(self::SHOULD, "Description too short", "description",
                "Description should be meaningful (currently " . strlen($desc) . " chars)");
        }
    }

    private function validateLicense(): void
    {
        $license = $this->package['license'] ?? '';

        if (empty($license)) {
            $this->addIssue(self::MUST, "Missing license", "license",
                "License field is required");
            return;
        }

        // Normalize for comparison
        $licenseStr = is_array($license) ? implode(', ', $license) : $license;

        // Cross-file consistency with composer.json
        if ($this->composer !== null) {
            $composerLicense = $this->composer['license'] ?? '';
            if (is_array($composerLicense)) {
                $composerLicense = implode(', ', $composerLicense);
            }

            // Simple comparison (may need normalization for complex cases)
            if (strtolower($composerLicense) !== strtolower($licenseStr)) {
                $this->addIssue(self::SHOULD, "License mismatch", "license: {$licenseStr}",
                    "License '{$licenseStr}' differs from composer.json '{$composerLicense}'");
            }
        }

        // Check expected license
        if (!empty($this->config['expectedLicense'])) {
            $expected = $this->config['expectedLicense'];
            if (strtolower($licenseStr) !== strtolower($expected)) {
                $this->addIssue(self::SHOULD, "Unexpected license", "license: {$licenseStr}",
                    "Expected '{$expected}' per configuration");
            }
        }

        // SPDX license list validation (simplified check)
        $validLicenses = ['MIT', 'Apache-2.0', 'BSD-2-Clause', 'BSD-3-Clause', 'GPL-2.0', 'GPL-3.0', 'LGPL-2.1', 'LGPL-3.0', 'ISC', 'MPL-2.0', 'Unlicense', 'Proprietary'];

        $licenses = is_array($license) ? $license : [$license];
        foreach ($licenses as $lic) {
            if (!in_array($lic, $validLicenses, true) && !preg_match('/^proprietary|commercial|custom:/i', $lic)) {
                $this->addIssue(self::MAY, "Non-standard license", "license: {$lic}",
                    "Consider using SPDX standard identifier");
            }
        }
    }

    private function validateKeywords(): void
    {
        $keywords = $this->package['keywords'] ?? [];

        if ($this->config['requireKeywords'] && (empty($keywords) || !is_array($keywords))) {
            $this->addIssue(self::MUST, "Missing keywords", "keywords",
                "Keywords array is required");
            return;
        }

        if (!is_array($keywords)) {
            $this->addIssue(self::MUST, "Invalid keywords", "keywords",
                "Keywords must be an array of strings");
            return;
        }

        // Check for duplicates
        $unique = array_unique($keywords);
        if (count($unique) !== count($keywords)) {
            $dups = array_diff_assoc($keywords, $unique);
            $this->addIssue(self::SHOULD, "Duplicate keywords", "keywords",
                "Duplicate entries: " . implode(', ', array_unique($dups)));
        }

        // Check all are strings
        foreach ($keywords as $kw) {
            if (!is_string($kw) || empty(trim($kw))) {
                $this->addIssue(self::MUST, "Invalid keyword", "keywords",
                    "Keywords must be non-empty strings");
                break;
            }
        }

        // Check forbidden keywords
        $forbidden = $this->config['forbiddenKeywords'] ?? [];
        foreach ($keywords as $kw) {
            if (in_array(strtolower($kw), $forbidden, true)) {
                $this->addIssue(self::SHOULD, "Generic keyword", "keywords: {$kw}",
                    "Avoid generic terms; use specific, descriptive keywords");
            }
        }
    }

    private function validateVersion(): void
    {
        if (isset($this->package['version'])) {
            $version = $this->package['version'];

            if (!preg_match('/^\d+\.\d+\.\d+(-.+)?$/', $version)) {
                $this->addIssue(self::SHOULD, "Non-semver version", "version: {$version}",
                    "Version should follow semantic versioning");
            }

            $this->addIssue(self::MAY, "Version field present", "version",
                "Version field is typically managed by npm publish/git tags; consider removing");
        }
    }

    private function validateEngines(): void
    {
        $engines = $this->package['engines'] ?? [];

        if (empty($engines)) {
            $this->addIssue(self::MUST, "Missing engines", "engines",
                "Engines field is required to specify Node.js and npm versions");
            return;
        }

        $node = $engines['node'] ?? '';
        $npm = $engines['npm'] ?? '';

        if (empty($node)) {
            $this->addIssue(self::MUST, "Missing Node.js version", "engines.node",
                "Node.js version constraint is required");
        } else {
            // Check for >= 20
            if (!preg_match('/>=?\s*2[0-9]|>=?\s*20|^\^2[0-9]|^~2[0-9]/', $node)) {
                $this->addIssue(self::MUST, "Node.js version too low", "engines.node: {$node}",
                    "Requires Node.js >= 20 (current LTS)");
            }
        }

        if (empty($npm)) {
            $this->addIssue(self::MUST, "Missing npm version", "engines.npm",
                "npm version constraint is required");
        } else {
            if (!preg_match('/>=?\s*1[0-9]|>=?\s*10|^\^1[0-9]/', $npm)) {
                $this->addIssue(self::MUST, "npm version too low", "engines.npm: {$npm}",
                    "Requires npm >= 10");
            }
        }
    }

    private function validateDependencies(): void
    {
        $deps = $this->package['dependencies'] ?? [];
        $devDeps = $this->package['devDependencies'] ?? [];
        $peerDeps = $this->package['peerDependencies'] ?? [];

        // Check for dev tools in production dependencies
        $devTools = ['eslint', 'prettier', 'stylelint', 'jest', 'mocha', 'vitest', 'cypress',
                     'playwright', '@types/', 'typescript', 'ts-node', 'nodemon', 'webpack-cli'];

        foreach ($deps as $pkg => $version) {
            foreach ($devTools as $tool) {
                if (str_starts_with($pkg, $tool) || $pkg === $tool) {
                    $this->addIssue(self::MUST, "Dev tool in dependencies", "dependencies: {$pkg}",
                        "'{$pkg}' should be in devDependencies, not dependencies");
                    break;
                }
            }
        }

        // Check for lockfile
        if (!file_exists($this->rootDir . '/package-lock.json') &&
            !file_exists($this->rootDir . '/yarn.lock') &&
            !file_exists($this->rootDir . '/pnpm-lock.yaml')) {
            $this->addIssue(self::MUST, "Missing lockfile", "package-lock.json",
                "Lockfile required for reproducible installs (package-lock.json, yarn.lock, or pnpm-lock.yaml)");
        }

        // Check dependency consistency across sections
        $allDeps = array_merge(array_keys($deps), array_keys($devDeps), array_keys($peerDeps));
        $checked = [];

        foreach ($allDeps as $pkg) {
            if (in_array($pkg, $checked)) continue;
            $checked[] = $pkg;

            $inDeps = isset($deps[$pkg]);
            $inDev = isset($devDeps[$pkg]);
            $inPeer = isset($peerDeps[$pkg]);

            // Check version consistency if in multiple sections
            if (($inDeps && $inDev) || ($inDeps && $inPeer) || ($inDev && $inPeer)) {
                $versions = [];
                if ($inDeps) $versions[] = $deps[$pkg];
                if ($inDev) $versions[] = $devDeps[$pkg];
                if ($inPeer) $versions[] = $peerDeps[$pkg];

                $unique = array_unique($versions);
                if (count($unique) > 1) {
                    $this->addIssue(self::SHOULD, "Version mismatch", $pkg,
                        "Package '{$pkg}' has different versions: " . implode(', ', $versions));
                }
            }
        }
    }

    private function validateMinimumVersions(): void
    {
        $allDeps = array_merge(
            $this->package['dependencies'] ?? [],
            $this->package['devDependencies'] ?? []
        );

        foreach ($this->minimumVersions as $pkg => $minimum) {
            if (!isset($allDeps[$pkg])) {
                continue; // Not installed, skip
            }

            $version = $allDeps[$pkg];
            $cleanVersion = preg_replace('/^\^|~|>=|>|<=|<|=/', '', $version);
            $cleanMinimum = preg_replace('/^\^|~|>=|>|<=|<|=/', '', $minimum);

            if (version_compare($cleanVersion, $cleanMinimum, '<')) {
                $this->addIssue(self::MUST, "Version below minimum", "{$pkg}: {$version}",
                    "Requires {$minimum} (configured minimum)");
            }
        }
    }

    private function validateScripts(): void
    {
        $scripts = $this->package['scripts'] ?? [];
        $hasLint = false;
        $hasTest = false;
        $hasFormat = false;

        foreach ($scripts as $name => $command) {
            // Check for standard scripts
            if (str_contains($name, 'lint')) $hasLint = true;
            if ($name === 'test' || str_contains($name, 'test:')) $hasTest = true;
            if (str_contains($name, 'format')) $hasFormat = true;

            // Security checks on commands
            if (is_string($command)) {
                // Check for hardcoded paths
                if (preg_match('/\.\.\/|^\.\/|^\//', $command) && !str_contains($command, 'node_modules')) {
                    $this->addIssue(self::SHOULD, "Hardcoded path in script", "scripts.{$name}",
                        "Avoid hardcoded paths in scripts: '{$command}'");
                }

                // Check for dangerous commands
                if (preg_match('/\brm\s+-rf\b/', $command)) {
                    $this->addIssue(self::MUST, "Dangerous command", "scripts.{$name}",
                        "Script contains 'rm -rf': '{$command}'");
                }
            }
        }

        // Check for missing standard scripts if tools are installed
        $devDeps = $this->package['devDependencies'] ?? [];

        if (isset($devDeps['eslint']) && !$hasLint) {
            $this->addIssue(self::SHOULD, "Missing lint script", "scripts",
                "ESLint installed but no 'lint' script found");
        }

        if ((isset($devDeps['jest']) || isset($devDeps['vitest'])) && !$hasTest) {
            $this->addIssue(self::SHOULD, "Missing test script", "scripts",
                "Test runner installed but no 'test' script found");
        }

        if (isset($devDeps['prettier']) && !$hasFormat) {
            $this->addIssue(self::SHOULD, "Missing format script", "scripts",
                "Prettier installed but no 'format' script found");
        }
    }

    private function validateConfig(): void
    {
        // Already handled in validateDependencies (sort-packages equivalent is npm's engine-strict)
        $config = $this->package['config'] ?? [];

        if (isset($config['engine-strict']) && $config['engine-strict'] !== true) {
            $this->addIssue(self::SHOULD, "Engine strict disabled", "config.engine-strict",
                "Consider setting engine-strict: true to enforce Node version requirements");
        }
    }

    private function validateBin(): void
    {
        $bin = $this->package['bin'] ?? [];

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
            } elseif (!is_executable($fullPath) && PHP_OS_FAMILY !== 'Windows') {
                $this->addIssue(self::SHOULD, "Non-executable binary", "bin: {$binary}",
                    "Binary should be executable (chmod +x)");
            }
        }
    }

    private function validateFiles(): void
    {
        $files = $this->package['files'] ?? [];
        $include = $this->package['include'] ?? [];

        // Check for common mistakes
        if (!empty($files) && in_array('src', $files) && !in_array('dist', $files)) {
            $this->addIssue(self::MAY, "Source in files", "files",
                "Consider including 'dist' instead of 'src' if distributing compiled code");
        }
    }

    private function validateExports(): void
    {
        $exports = $this->package['exports'] ?? null;

        if ($exports === null) {
            // Not required, but recommended for modern packages
            if (isset($this->package['type']) && $this->package['type'] === 'module') {
                $this->addIssue(self::SHOULD, "Missing exports", "exports",
                    "ES modules should define exports field for explicit entry points");
            }
            return;
        }

        if (!is_array($exports) && !is_string($exports)) {
            $this->addIssue(self::MUST, "Invalid exports format", "exports",
                "Exports must be a string or object");
            return;
        }

        // Check for "." export (main entry)
        if (is_array($exports) && !isset($exports['.']) && !isset($exports['./'])) {
            $this->addIssue(self::SHOULD, "Missing main export", "exports",
                "Consider adding '.' entry for main module export");
        }
    }

    private function validateDeprecatedConfigs(): void
    {
        foreach ($this->fileInventory as $file) {
            $basename = basename($file);

            if (isset($this->deprecatedConfigs[$basename])) {
                $message = $this->deprecatedConfigs[$basename];
                $this->addIssue(self::SHOULD, "Deprecated config file", $file, $message);
            }
        }

        // Check for .prettierrc without .json extension
        if (file_exists($this->rootDir . '/.prettierrc') &&
            !file_exists($this->rootDir . '/.prettierrc.json')) {
            $this->addIssue(self::SHOULD, "Non-JSON Prettier config", ".prettierrc",
                "Use .prettierrc.json for consistency");
        }
    }

    private function validateFileLocations(): void
    {
        $violations = [];

        foreach ($this->fileInventory as $file) {
            $ext = pathinfo($file, PATHINFO_EXTENSION);
            $dirname = dirname($file);

            // Check shell scripts
            if (in_array($ext, ['sh', 'bash', 'zsh'])) {
                if (!str_starts_with($file, 'bin/')) {
                    $violations['bin'][] = $file;
                }
            }

            // Check styles
            if (in_array($ext, ['css', 'scss', 'sass', 'less', 'styl'])) {
                if (!str_starts_with($file, 'assets/styles/') &&
                    !str_starts_with($file, 'src/styles/') &&
                    !str_contains($file, 'node_modules')) {
                    $violations['assets/styles/'][] = $file;
                }
            }

            // Check data files
            if (in_array($ext, ['json', 'yaml', 'yml', 'csv', 'tsv'])) {
                if (str_starts_with($file, 'src/') &&
                    !str_starts_with($file, 'assets/data/') &&
                    !str_starts_with($file, 'config/') &&
                    $file !== 'package.json' &&
                    $file !== 'composer.json' &&
                    !str_contains($file, 'node_modules')) {
                    // Check if it's data vs config
                    if (preg_match('/(data|dataset|fixture|mock|sample)/i', $file)) {
                        $violations['assets/data/'][] = $file;
                    }
                }
            }

            // Check images
            if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'ico'])) {
                if (!str_starts_with($file, 'assets/images/') &&
                    !str_starts_with($file, 'public/') &&
                    !str_starts_with($file, 'static/') &&
                    !str_contains($file, 'node_modules')) {
                    $violations['assets/images/'][] = $file;
                }
            }

            // Check JS/TS
            if (in_array($ext, ['js', 'ts', 'mjs', 'cjs', 'jsx', 'tsx'])) {
                if (!str_starts_with($file, 'assets/scripts/') &&
                    !str_starts_with($file, 'src/') &&
                    !str_contains($file, 'node_modules') &&
                    !str_contains($file, 'dist/') &&
                    !str_contains($file, 'build/')) {
                    $violations['assets/scripts/ or src/'][] = $file;
                }
            }

            // Check SQL
            if ($ext === 'sql') {
                if (!str_starts_with($file, 'assets/sql/')) {
                    $violations['assets/sql/'][] = $file;
                }
            }

            // Check XML
            if (in_array($ext, ['xml', 'xsd', 'xsl', 'xslt', 'wsdl'])) {
                if (!str_starts_with($file, 'assets/xml/') &&
                    !str_starts_with($file, 'config/')) {
                    $violations['assets/xml/'][] = $file;
                }
            }

            // Check .dist files
            if (str_ends_with($file, '.dist')) {
                $base = substr($file, 0, -5);
                $allowedDist = ['.env', '.env.local', '.env.production', 'phpunit.xml',
                               'phpcs.xml', 'ecs.php', 'phpstan.neon', 'psalm.xml'];

                $isAllowed = false;
                foreach ($allowedDist as $allowed) {
                    if (str_ends_with($base, $allowed)) {
                        $isAllowed = true;
                        break;
                    }
                }

                if (!$isAllowed) {
                    $this->addIssue(self::SHOULD, "Suspicious .dist file", $file,
                        ".dist files should only be used for configuration templates");
                }
            }
        }

        // Report violations
        foreach ($violations as $expectedDir => $files) {
            $fileList = implode(', ', array_slice($files, 0, 3));
            if (count($files) > 3) {
                $fileList .= ' and ' . (count($files) - 3) . ' more';
            }

            $this->addIssue(self::SHOULD, "File location", $fileList,
                "Files should be in '{$expectedDir}' directory");
        }
    }

    private function validateToolingConfigs(): void
    {
        // Prettier configuration
        if (isset($this->package['devDependencies']['prettier'])) {
            $this->validatePrettierConfig();
        }

        // ESLint configuration
        if (isset($this->package['devDependencies']['eslint'])) {
            $this->validateEslintConfig();
        }

        // Stylelint configuration
        if (isset($this->package['devDependencies']['stylelint'])) {
            $this->validateStylelintConfig();
        }
    }

    private function validatePrettierConfig(): void
    {
        $prettierField = $this->package['prettier'] ?? null;
        $configFile = null;

        // Check for config files
        $possibleFiles = ['.prettierrc.json', '.prettierrc', '.prettierrc.js', '.prettierrc.cjs', 'prettier.config.js'];
        foreach ($possibleFiles as $file) {
            if (file_exists($this->rootDir . '/' . $file)) {
                $configFile = $file;
                break;
            }
        }

        if ($configFile === null && $prettierField === null) {
            $this->addIssue(self::SHOULD, "Missing Prettier config", "prettier",
                "Create .prettierrc.json or add 'prettier' field to package.json");
            return;
        }

        // Check for plugins in config
        $plugins = [];
        if ($prettierField !== null && isset($prettierField['plugins'])) {
            $plugins = $prettierField['plugins'];
        } elseif ($configFile !== null && str_ends_with($configFile, '.json')) {
            $content = json_decode(file_get_contents($this->rootDir . '/' . $configFile), true);
            $plugins = $content['plugins'] ?? [];
        }

        // Check installed plugins match config
        $devDeps = $this->package['devDependencies'] ?? [];
        foreach ($devDeps as $pkg => $version) {
            if (str_starts_with($pkg, 'prettier-plugin-')) {
                $pluginName = str_replace('prettier-plugin-', '', $pkg);
                if (!in_array($pkg, $plugins) && !in_array($pluginName, $plugins)) {
                    $this->addIssue(self::SHOULD, "Unconfigured Prettier plugin", $pkg,
                        "Plugin '{$pkg}' installed but not listed in Prettier config plugins array");
                }
            }
        }
    }

    private function validateEslintConfig(): void
    {
        $eslintVersion = $this->package['devDependencies']['eslint'] ?? '';
        $isV9 = false;

        if (preg_match('/^\^?9\./', $eslintVersion) || preg_match('/>=9/', $eslintVersion)) {
            $isV9 = true;
        }

        // Check for legacy config files
        $legacyConfigs = ['.eslintrc.js', '.eslintrc.cjs', '.eslintrc.yaml', '.eslintrc.yml', '.eslintrc.json', '.eslintrc'];
        $flatConfigs = ['eslint.config.js', 'eslint.config.mjs', 'eslint.config.cjs'];

        $hasLegacy = false;
        $hasFlat = false;
        $foundLegacyFile = '';

        foreach ($legacyConfigs as $file) {
            if (file_exists($this->rootDir . '/' . $file)) {
                $hasLegacy = true;
                $foundLegacyFile = $file;
                break;
            }
        }

        foreach ($flatConfigs as $file) {
            if (file_exists($this->rootDir . '/' . $file)) {
                $hasFlat = true;
                break;
            }
        }

        if ($hasLegacy) {
            $this->addIssue(self::MUST, "Legacy ESLint config", $foundLegacyFile,
                "Migrate to flat config format (eslint.config.js) for ESLint v9+ compatibility");
        }

        if (!$hasFlat && !$hasLegacy) {
            $this->addIssue(self::SHOULD, "Missing ESLint config", "eslint",
                "Create eslint.config.js for ESLint v9+");
        }

        // Check plugins are configured
        if ($hasFlat) {
            $configFile = $this->rootDir . '/eslint.config.js';
            if (!file_exists($configFile)) {
                $configFile = $this->rootDir . '/eslint.config.mjs';
            }

            if (file_exists($configFile)) {
                $content = file_get_contents($configFile);
                $devDeps = array_keys($this->package['devDependencies'] ?? []);

                foreach ($devDeps as $pkg) {
                    if (str_starts_with($pkg, 'eslint-plugin-') || $pkg === '@eslint/js') {
                        $shortName = str_replace('eslint-plugin-', '', $pkg);
                        if (!str_contains($content, $shortName) && !str_contains($content, $pkg)) {
                            $this->addIssue(self::SHOULD, "Unconfigured ESLint plugin", $pkg,
                                "Plugin '{$pkg}' installed but may not be imported in eslint.config.js");
                        }
                    }
                }
            }
        }
    }

    private function validateStylelintConfig(): void
    {
        $configFiles = ['.stylelintrc.json', '.stylelintrc', '.stylelintrc.js', 'stylelint.config.js'];
        $hasConfig = false;
        $configFile = '';

        foreach ($configFiles as $file) {
            if (file_exists($this->rootDir . '/' . $file)) {
                $hasConfig = true;
                $configFile = $file;
                break;
            }
        }

        // Check package.json field
        $pkgConfig = $this->package['stylelint'] ?? null;

        if (!$hasConfig && $pkgConfig === null) {
            $this->addIssue(self::SHOULD, "Missing Stylelint config", "stylelint",
                "Create .stylelintrc.json or add 'stylelint' field to package.json");
            return;
        }

        // Check plugins are configured
        $config = $pkgConfig ?? [];
        if ($hasConfig && str_ends_with($configFile, '.json')) {
            $content = json_decode(file_get_contents($this->rootDir . '/' . $configFile), true);
            $config = $content ?? [];
        }

        $plugins = $config['plugins'] ?? [];
        $extends = $config['extends'] ?? [];

        $devDeps = array_keys($this->package['devDependencies'] ?? []);
        foreach ($devDeps as $pkg) {
            if (str_starts_with($pkg, 'stylelint-')) {
                $shortName = str_replace('stylelint-', '', $pkg);
                // Check if it's a plugin (stylelint-plugin-*)
                if (str_starts_with($pkg, 'stylelint-plugin-')) {
                    if (!in_array($pkg, $plugins) && !in_array($shortName, $plugins)) {
                        $this->addIssue(self::SHOULD, "Unconfigured Stylelint plugin", $pkg,
                            "Plugin '{$pkg}' installed but not in stylelint config plugins");
                    }
                } else {
                    // It's likely a config or other tool
                    if (!in_array($pkg, $extends) && !str_contains($shortName, 'config')) {
                        $this->addIssue(self::MAY, "Stylelint tool check", $pkg,
                            "Verify '{$pkg}' is properly configured");
                    }
                }
            }
        }
    }

    private function validateFileLocations(): void
    {
        foreach ($this->fileInventory as $file) {
            $ext = pathinfo($file, PATHINFO_EXTENSION);
            $dirname = dirname($file);

            // Skip node_modules and hidden files
            if (str_contains($file, 'node_modules') || str_contains($file, '/.')) {
                continue;
            }

            // Check each file type
            foreach ($this->fileTypeLocations as $expectedDir => $patterns) {
                foreach ($patterns as $pattern) {
                    if (fnmatch($pattern, basename($file))) {
                        if (!str_starts_with($file, $expectedDir) &&
                            !str_starts_with($file, 'src/' . $expectedDir) &&
                            !str_starts_with($file, 'tests/') &&
                            !str_starts_with($file, 'vendor/')) {
                            $this->addIssue(self::SHOULD, "File location", $file,
                                "File should be in '{$expectedDir}' directory");
                        }
                        break 2;
                    }
                }
            }
        }
    }

    private function validateToolingConfigs(): void
    {
        // Check for Prettier, ESLint, Stylelint configs (already done in specific methods)
        // This method serves as a coordinator

        // Check if tools are installed but configs missing
        $devDeps = $this->package['devDependencies'] ?? [];

        if (isset($devDeps['prettier'])) {
            $this->validatePrettierConfig();
        }

        if (isset($devDeps['eslint'])) {
            $this->validateEslintConfig();
        }

        if (isset($devDeps['stylelint'])) {
            $this->validateStylelintConfig();
        }
    }

    private function validateProjectFiles(): void
    {
        $hasJs = false;
        $hasCss = false;
        $hasDist = false;

        foreach ($this->fileInventory as $file) {
            if (str_contains($file, 'node_modules') || str_contains($file, '/.')) {
                continue;
            }

            $ext = pathinfo($file, PATHINFO_EXTENSION);

            if (in_array($ext, ['js', 'ts', 'mjs', 'cjs', 'jsx', 'tsx'])) {
                $hasJs = true;
            }

            if (in_array($ext, ['css', 'scss', 'sass', 'less'])) {
                $hasCss = true;
            }

            if (str_ends_with($file, '.dist') && !str_contains($file, 'config/')) {
                $hasDist = true;
                $this->addIssue(self::SHOULD, "Misplaced .dist file", $file,
                    ".dist files should be in config/ directory or be configuration templates");
            }
        }

        $devDeps = $this->package['devDependencies'] ?? [];

        if ($hasJs && !isset($devDeps['eslint'])) {
            $this->addIssue(self::SHOULD, "Missing ESLint", "devDependencies",
                "JavaScript/TypeScript files found but ESLint not installed");
        }

        if ($hasCss && !isset($devDeps['stylelint'])) {
            $this->addIssue(self::SHOULD, "Missing Stylelint", "devDependencies",
                "CSS/SCSS files found but Stylelint not installed");
        }
    }

    private function validateSecurityAndBestPractices(): void
    {
        // Check for lockfile (already done in validateDependencies)

        // Check for .npmrc or .yarnrc with security settings
        if (file_exists($this->rootDir . '/.npmrc')) {
            $content = file_get_contents($this->rootDir . '/.npmrc');
            if (!str_contains($content, 'package-lock=true') && !str_contains($content, 'package-lock')) {
                $this->addIssue(self::MAY, "npmrc check", ".npmrc",
                    "Consider explicitly setting package-lock=true for reproducible installs");
            }
        }

        // Check for engines strict
        if (!isset($this->package['engines']['node'])) {
            $this->addIssue(self::MUST, "Missing Node engine", "engines.node",
                "Node.js version constraint required");
        }
    }

    private function validateCrossFileConsistency(): void
    {
        if ($this->composer === null) {
            return;
        }

        // Compare names (project part only)
        $pkgName = $this->package['name'] ?? '';
        $composerName = $this->composer['name'] ?? '';

        // Remove scope from package name
        $pkgProject = str_contains($pkgName, '/') ? explode('/', $pkgName)[1] : $pkgName;
        $composerProject = str_contains($composerName, '/') ? explode('/', $composerName)[1] : $composerName;

        // Convert to kebab-case for comparison
        $pkgKebab = strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $pkgProject));
        $composerKebab = strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $composerProject));

        if ($pkgKebab !== $composerKebab && !empty($pkgProject) && !empty($composerProject)) {
            $this->addIssue(self::SHOULD, "Name mismatch", "name",
                "package.json name '{$pkgProject}' differs from composer.json '{$composerProject}'");
        }

        // Compare descriptions
        $pkgDesc = $this->package['description'] ?? '';
        $composerDesc = $this->composer['description'] ?? '';

        if (!empty($pkgDesc) && !empty($composerDesc) &&
            strtolower(trim($pkgDesc)) !== strtolower(trim($composerDesc))) {
            $this->addIssue(self::MAY, "Description mismatch", "description",
                "Descriptions differ between package.json and composer.json");
        }

        // Compare licenses
        $pkgLicense = $this->package['license'] ?? '';
        $composerLicense = $this->composer['license'] ?? '';

        if (is_array($composerLicense)) {
            $composerLicense = implode(', ', $composerLicense);
        }

        if (!empty($pkgLicense) && !empty($composerLicense) &&
            strtolower($pkgLicense) !== strtolower($composerLicense)) {
            $this->addIssue(self::SHOULD, "License mismatch", "license",
                "License '{$pkgLicense}' differs from composer.json '{$composerLicense}'");
        }
    }

    private function validatePublicFields(): void
    {
        // Homepage check
        $homepage = $this->package['homepage'] ?? '';
        $name = $this->package['name'] ?? '';

        if (empty($homepage)) {
            $this->addIssue(self::MUST, "Missing homepage", "homepage",
                "Public packages require homepage URL");
        } else {
            $expectedPattern = 'https://github.com/' . $this->config['owner'] . '/';
            if (!str_starts_with($homepage, $expectedPattern)) {
                $this->addIssue(self::MUST, "Invalid homepage", "homepage: {$homepage}",
                    "Must match: https://github.com/{$this->config['owner']}/<project>");
            }
        }

        // Repository check
        $repo = $this->package['repository'] ?? [];
        if (is_string($repo)) {
            $repo = ['url' => $repo];
        }

        if (empty($repo['url'])) {
            $this->addIssue(self::MUST, "Missing repository", "repository",
                "Public packages require repository URL");
        } else {
            $expectedPattern = 'github.com/' . $this->config['owner'] . '/';
            if (!str_contains($repo['url'], $expectedPattern) && !str_contains($repo['url'], 'github.com')) {
                $this->addIssue(self::SHOULD, "Repository mismatch", "repository.url",
                    "Repository should be under {$this->config['owner']} organization");
            }
        }

        // Bugs URL
        $bugs = $this->package['bugs'] ?? [];
        if (is_string($bugs)) {
            $bugs = ['url' => $bugs];
        }

        if (!empty($bugs['url'])) {
            $expectedPattern = 'github.com/' . $this->config['owner'] . '/';
            if (!str_contains($bugs['url'], $expectedPattern)) {
                $this->addIssue(self::SHOULD, "Bugs URL mismatch", "bugs.url",
                    "Issues URL should match GitHub repo pattern");
            }
        }

        // Authors/Maintainers
        $author = $this->package['author'] ?? [];
        $contributors = $this->package['contributors'] ?? [];

        if (is_string($author)) {
            // Parse "Name <email> (url)" format
            if (!str_contains($author, 'Douglas Green')) {
                $this->addIssue(self::MUST, "Missing required author", "author",
                    "Must include 'Douglas Green' as author");
            }
        } elseif (is_array($author)) {
            if (($author['name'] ?? '') !== 'Douglas Green') {
                $this->addIssue(self::MUST, "Missing required author", "author.name",
                    "Author name must be 'Douglas Green'");
            }
            if (($author['email'] ?? '') !== 'douglas@nurd.site') {
                $this->addIssue(self::MUST, "Invalid author email", "author.email",
                    "Author email must be 'douglas@nurd.site'");
            }
            if (($author['url'] ?? '') !== 'https://nurd.site/') {
                $this->addIssue(self::MUST, "Invalid author URL", "author.url",
                    "Author URL must be 'https://nurd.site/'");
            }
        }

        // Keywords
        $keywords = $this->package['keywords'] ?? [];
        if (empty($keywords) || !is_array($keywords)) {
            $this->addIssue(self::MUST, "Missing keywords (public)", "keywords",
                "Public packages must include keywords array");
        }
    }

    private function validateEngines(): void
    {
        $engines = $this->package['engines'] ?? [];

        if (empty($engines)) {
            $this->addIssue(self::MUST, "Missing engines", "engines",
                "Engines field is required to specify Node.js and npm versions");
            return;
        }

        $node = $engines['node'] ?? '';
        $npm = $engines['npm'] ?? '';

        if (empty($node)) {
            $this->addIssue(self::MUST, "Missing Node.js version", "engines.node",
                "Node.js version constraint is required");
        } else {
            // Check for >= 20
            if (!preg_match('/>=?\s*2[0-9]|>=?\s*20|^\^2[0-9]|^~2[0-9]/', $node)) {
                $this->addIssue(self::MUST, "Node.js version too low", "engines.node: {$node}",
                    "Requires Node.js >= 20 (current LTS)");
            }
        }

        if (empty($npm)) {
            $this->addIssue(self::MUST, "Missing npm version", "engines.npm",
                "npm version constraint is required");
        } else {
            if (!preg_match('/>=?\s*1[0-9]|>=?\s*10|^\^1[0-9]/', $npm)) {
                $this->addIssue(self::MUST, "npm version too low", "engines.npm: {$npm}",
                    "Requires npm >= 10");
            }
        }
    }

    private function validateDependencies(): void
    {
        $deps = $this->package['dependencies'] ?? [];
        $devDeps = $this->package['devDependencies'] ?? [];
        $peerDeps = $this->package['peerDependencies'] ?? [];

        // Check for dev tools in production dependencies
        $devTools = ['eslint', 'prettier', 'stylelint', 'jest', 'vitest', 'cypress',
                     'playwright', '@types/', 'typescript', 'ts-node', 'nodemon',
                     'webpack-cli', 'vite', 'husky', 'lint-staged'];

        foreach ($deps as $pkg => $version) {
            foreach ($devTools as $tool) {
                if (str_starts_with($pkg, $tool) || $pkg === $tool) {
                    $this->addIssue(self::MUST, "Dev tool in dependencies", "dependencies: {$pkg}",
                        "'{$pkg}' should be in devDependencies, not dependencies");
                    break;
                }
            }
        }

        // Check for wildcards
        foreach (array_merge($deps, $devDeps) as $pkg => $version) {
            if ($version === '*' || $version === 'latest') {
                $this->addIssue(self::MUST, "Wildcard dependency", "{$pkg}: {$version}",
                    "Avoid '*' or 'latest'; use explicit version constraints");
            }
        }

        // Check for inconsistent versions across dependency types
        $allPackages = array_merge($deps, $devDeps, $peerDeps);
        $seen = [];

        foreach ($deps as $pkg => $ver) {
            $seen[$pkg] = ['ver' => $ver, 'type' => 'dependencies'];
        }

        foreach ($devDeps as $pkg => $ver) {
            if (isset($seen[$pkg]) && $seen[$pkg]['ver'] !== $ver) {
                $this->addIssue(self::SHOULD, "Version inconsistency", $pkg,
                    "Version '{$ver}' in devDependencies differs from '{$seen[$pkg]['ver']}' in dependencies");
            }
        }

        foreach ($peerDeps as $pkg => $ver) {
            if (isset($seen[$pkg]) && $seen[$pkg]['ver'] !== $ver) {
                $this->addIssue(self::SHOULD, "Peer dependency mismatch", $pkg,
                    "Peer dependency version '{$ver}' differs from installed '{$seen[$pkg]['ver']}'");
            }
        }
    }

    private function validateMinimumVersions(): void
    {
        $allDeps = array_merge(
            $this->package['dependencies'] ?? [],
            $this->package['devDependencies'] ?? []
        );

        foreach ($this->minimumVersions as $pkg => $minimum) {
            if (!isset($allDeps[$pkg])) {
                continue; // Not installed, skip
            }

            $version = $allDeps[$pkg];
            $cleanVersion = preg_replace('/^\^|~|>=|>|<=|<|=/', '', $version);
            $cleanMinimum = preg_replace('/^\^|~|>=|>|<=|<|=/', '', $minimum);

            if (version_compare($cleanVersion, $cleanMinimum, '<')) {
                $this->addIssue(self::MUST, "Version below minimum", "{$pkg}: {$version}",
                    "Requires {$minimum} (configured minimum)");
            }
        }
    }

    private function validateScripts(): void
    {
        $scripts = $this->package['scripts'] ?? [];
        $devDeps = $this->package['devDependencies'] ?? [];

        // Check for standard scripts if tools are installed
        if (isset($devDeps['eslint']) && !isset($scripts['lint'])) {
            $this->addIssue(self::SHOULD, "Missing lint script", "scripts",
                "ESLint installed but 'lint' script not found");
        }

        if ((isset($devDeps['jest']) || isset($devDeps['vitest'])) && !isset($scripts['test'])) {
            $this->addIssue(self::SHOULD, "Missing test script", "scripts",
                "Test runner installed but 'test' script not found");
        }

        if (isset($devDeps['prettier']) && !isset($scripts['format'])) {
            $this->addIssue(self::SHOULD, "Missing format script", "scripts",
                "Prettier installed but 'format' script not found");
        }

        // Security checks on scripts
        foreach ($scripts as $name => $command) {
            if (is_array($command)) {
                $command = implode(' ', $command);
            }

            // Check for rm -rf
            if (preg_match('/\brm\s+-rf\b/', $command)) {
                $this->addIssue(self::MUST, "Dangerous script", "scripts.{$name}",
                    "Script contains 'rm -rf': '{$command}'");
            }

            // Check for sudo
            if (preg_match('/\bsudo\b/', $command)) {
                $this->addIssue(self::MUST, "Sudo in script", "scripts.{$name}",
                    "Avoid sudo in npm scripts: '{$command}'");
            }

            // Check for hardcoded paths
            if (preg_match('/\.\.\/|^\.\/bin\/|^\.\/scripts\//', $command)) {
                $this->addIssue(self::SHOULD, "Hardcoded path", "scripts.{$name}",
                    "Use npx or node_modules/.bin/ instead of relative paths: '{$command}'");
            }
        }
    }

    private function validateConfig(): void
    {
        // General config validation
        $config = $this->package['config'] ?? [];

        // Check for engine-strict
        if (isset($config['engine-strict']) && $config['engine-strict'] !== true) {
            $this->addIssue(self::SHOULD, "Engine strict disabled", "config.engine-strict",
                "Set engine-strict: true to enforce Node version requirements");
        }
    }

    private function validateBin(): void
    {
        $bin = $this->package['bin'] ?? [];

        if (empty($bin)) {
            return;
        }

        if (!is_array($bin)) {
            $this->addIssue(self::MUST, "Invalid bin format", "bin",
                "Bin must be an object mapping command names to paths");
            return;
        }

        foreach ($bin as $command => $path) {
            $fullPath = $this->rootDir . '/' . $path;

            if (!file_exists($fullPath)) {
                $this->addIssue(self::MUST, "Missing binary", "bin.{$command}",
                    "Binary file does not exist: {$fullPath}");
            } elseif (!is_executable($fullPath) && PHP_OS_FAMILY !== 'Windows') {
                $this->addIssue(self::SHOULD, "Non-executable binary", "bin.{$command}",
                    "Binary should be executable (chmod +x)");
            }

            // Check if binary is in bin/ directory
            if (!str_starts_with($path, 'bin/')) {
                $this->addIssue(self::SHOULD, "Binary location", "bin.{$command}",
                    "Binary should be in 'bin/' directory");
            }
        }
    }

    private function validateDeprecatedConfigs(): void
    {
        foreach ($this->fileInventory as $file) {
            $basename = basename($file);

            if (isset($this->deprecatedConfigs[$basename])) {
                $message = $this->deprecatedConfigs[$basename];
                $this->addIssue(self::SHOULD, "Deprecated config", $file, $message);
            }
        }
    }

    private function validateProjectFiles(): void
    {
        $hasJs = false;
        $hasCss = false;

        foreach ($this->fileInventory as $file) {
            if (str_contains($file, 'node_modules') || str_contains($file, '/.')) {
                continue;
            }

            $ext = pathinfo($file, PATHINFO_EXTENSION);

            if (in_array($ext, ['js', 'ts', 'mjs', 'cjs', 'jsx', 'tsx'])) {
                $hasJs = true;
            }

            if (in_array($ext, ['css', 'scss', 'sass', 'less'])) {
                $hasCss = true;
            }
        }

        $devDeps = $this->package['devDependencies'] ?? [];

        if ($hasJs && !isset($devDeps['eslint'])) {
            $this->addIssue(self::SHOULD, "Missing ESLint", "devDependencies",
                "JavaScript/TypeScript files found but ESLint not installed");
        }

        if ($hasCss && !isset($devDeps['stylelint'])) {
            $this->addIssue(self::SHOULD, "Missing Stylelint", "devDependencies",
                "CSS/SCSS files found but Stylelint not installed");
        }

        if (($hasJs || $hasCss) && !isset($devDeps['prettier'])) {
            $this->addIssue(self::SHOULD, "Missing Prettier", "devDependencies",
                "Code files found but Prettier not installed");
        }
    }

    private function validateCrossFileConsistency(): void
    {
        if ($this->composer === null) {
            return;
        }

        // Compare project names
        $pkgName = $this->package['name'] ?? '';
        $composerName = $this->composer['name'] ?? '';

        // Remove npm scope for comparison
        $pkgParts = explode('/', $pkgName);
        $pkgProject = end($pkgParts);

        $composerParts = explode('/', $composerName);
        $composerProject = end($composerParts);

        // Normalize to kebab-case
        $pkgKebab = strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $pkgProject));
        $composerKebab = strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $composerProject));

        if ($pkgKebab !== $composerKebab) {
            $this->addIssue(self::SHOULD, "Project name mismatch", "name",
                "package.json project '{$pkgProject}' differs from composer.json '{$composerProject}'");
        }

        // Compare descriptions
        $pkgDesc = $this->package['description'] ?? '';
        $composerDesc = $this->composer['description'] ?? '';

        if (!empty($pkgDesc) && !empty($composerDesc) &&
            strtolower(trim($pkgDesc)) !== strtolower(trim($composerDesc))) {
            $this->addIssue(self::MAY, "Description mismatch", "description",
                "Descriptions differ between package.json and composer.json");
        }

        // Compare licenses
        $pkgLicense = $this->package['license'] ?? '';
        $composerLicense = $this->composer['license'] ?? '';

        if (is_array($composerLicense)) {
            $composerLicense = implode(', ', $composerLicense);
        }

        if (!empty($pkgLicense) && !empty($composerLicense) &&
            strtolower($pkgLicense) !== strtolower($composerLicense)) {
            $this->addIssue(self::SHOULD, "License mismatch", "license",
                "package.json license '{$pkgLicense}' differs from composer.json '{$composerLicense}'");
        }
    }

    private function validatePublicFields(): void
    {
        // Homepage check
        $homepage = $this->package['homepage'] ?? '';
        $name = $this->package['name'] ?? '';

        // Extract project name from scoped or unscoped
        $parts = explode('/', $name);
        $project = end($parts);

        if (empty($homepage)) {
            $this->addIssue(self::MUST, "Missing homepage", "homepage",
                "Public packages require homepage URL");
        } else {
            $expected = "https://github.com/{$this->config['owner']}/{$project}";
            if (!str_starts_with($homepage, $expected)) {
                $this->addIssue(self::MUST, "Invalid homepage", "homepage: {$homepage}",
                    "Must match: https://github.com/{$this->config['owner']}/{$project}");
            }
        }

        // Repository check
        $repo = $this->package['repository'] ?? [];
        if (is_string($repo)) {
            $repo = ['url' => $repo];
        }

        if (empty($repo) || empty($repo['url'])) {
            $this->addIssue(self::MUST, "Missing repository", "repository",
                "Public packages require repository");
        } else {
            $url = $repo['url'];
            if (!str_contains($url, "github.com/{$this->config['owner']}/")) {
                $this->addIssue(self::SHOULD, "Repository mismatch", "repository.url",
                    "Repository should be under {$this->config['owner']} organization");
            }
        }

        // Author check
        $author = $this->package['author'] ?? [];
        if (is_string($author)) {
            // Parse "Name <email> (url)" format
            if (!str_contains($author, 'Douglas Green')) {
                $this->addIssue(self::MUST, "Missing required author", "author",
                    "Must include 'Douglas Green' as author");
            }
        } elseif (is_array($author)) {
            if (($author['name'] ?? '') !== 'Douglas Green') {
                $this->addIssue(self::MUST, "Missing required author", "author.name",
                    "Author name must be 'Douglas Green'");
            }
            if (($author['email'] ?? '') !== 'douglas@nurd.site') {
                $this->addIssue(self::MUST, "Invalid author email", "author.email",
                    "Author email must be 'douglas@nurd.site'");
            }
            if (($author['url'] ?? '') !== 'https://nurd.site/') {
                $this->addIssue(self::MUST, "Invalid author URL", "author.url",
                    "Author URL must be 'https://nurd.site/'");
            }
        }

        // Contributors check (optional but recommended)
        $contributors = $this->package['contributors'] ?? [];
        if (!empty($contributors) && is_array($contributors)) {
            $foundDouglas = false;
            foreach ($contributors as $contributor) {
                if (is_array($contributor) && ($contributor['name'] ?? '') === 'Douglas Green') {
                    $foundDouglas = true;
                    if (($contributor['role'] ?? '') !== 'Developer') {
                        $this->addIssue(self::MUST, "Invalid contributor role", "contributors",
                            "Douglas Green role must be 'Developer'");
                    }
                }
            }

            if (!$foundDouglas && $this->config['isPublic']) {
                $this->addIssue(self::SHOULD, "Missing contributor", "contributors",
                    "Consider adding Douglas Green to contributors list");
            }
        }

        // Keywords
        $keywords = $this->package['keywords'] ?? [];
        if (empty($keywords) || !is_array($keywords)) {
            $this->addIssue(self::MUST, "Missing keywords (public)", "keywords",
                "Public packages must include keywords array");
        }
    }

    private function printReport(): void
    {
        $mustIssues = array_filter($this->issues, fn($i) => $i['level'] === self::MUST);
        $shouldIssues = array_filter($this->issues, fn($i) => $i['level'] === self::SHOULD);
        $mayIssues = array_filter($this->issues, fn($i) => $i['level'] === self::MAY);

        echo "\n" . str_repeat("=", 60) . "\n";
        echo "package.json Standards Compliance Report\n";
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
            echo "\033[32m✓ All package.json standards met\033[0m\n";
        }

        // Summary statistics
        echo "\nSummary:\n";
        echo "--------\n";
        printf("Project: %s\n", $this->package['name'] ?? 'unknown');
        printf("Type: %s\n", $this->package['type'] ?? 'commonjs');
        printf("Total issues: %d (MUST: %d, SHOULD: %d, MAY: %d)\n",
            count($this->issues), count($mustIssues), count($shouldIssues), count($mayIssues));

        // Compliance score
        $totalChecks = 30; // Approximate number of validation rules
        $deduction = (count($mustIssues) * 3) + (count($shouldIssues) * 1);
        $compliance = max(0, 100 - ($deduction * 100 / $totalChecks));
        printf("Compliance score: %d%%\n", $compliance);

        echo "\n";

        if (!empty($mustIssues)) {
            echo "\033[31m⚠️  Critical violations detected - fix before committing\033[0m\n";
            exit(1);
        }

        exit(0);
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
}
