<?php

/**
 * package.json Standards Compliance Checker
 *
 * Validates package.json against project standards, security best practices,
 * and cross-file consistency with composer.json.
 */

declare(strict_types=1);

namespace DouglasGreen\PHPProjectChecker;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class PackageJsonChecker
{
    // RFC 2119 levels
    public const MUST = 'MUST';

    public const SHOULD = 'SHOULD';

    public const MAY = 'MAY';

    private readonly string $rootDir;

    /** @var array<string, mixed> */
    private array $config;

    /** @var array<string, mixed> */
    private array $package;

    /** @var array<string, mixed>|null */
    private ?array $composer = null;

    /** @var array<int, array<string, string>> */
    private array $issues = [];

    /** @var array<int, string> */
    private array $fileInventory = [];

    /** @var array<int, string> */
    private array $allowedTypes = [
        'module', 'commonjs', 'module-commonjs', 'esm', 'cjs',
    ];

    /** @var array<string, string> */
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
        'tslint.json' => 'TSLint is deprecated; migrate to ESLint with @typescript-eslint',
    ];

    /** @var array<string, array<int, string>> */
    private array $fileTypeLocations = [
        'bin/' => ['*.sh', '*.bash', '*.zsh'],
        'assets/styles/' => ['*.css', '*.scss', '*.sass', '*.less', '*.styl'],
        'assets/data/' => ['*.json', '*.yaml', '*.yml', '*.csv', '*.tsv'],
        'assets/images/' => ['*.png', '*.jpg', '*.jpeg', '*.gif', '*.svg', '*.webp', '*.ico'],
        'assets/scripts/' => ['*.js', '*.ts', '*.mjs', '*.cjs', '*.jsx', '*.tsx'],
        'config/' => ['*.json', '*.yaml', '*.yml', '*.xml', '*.ini', '*.conf', '*.dist'],
        'assets/sql/' => ['*.sql', '*.ddl', '*.dml'],
        'assets/xml/' => ['*.xml', '*.xsd', '*.xsl', '*.xslt', '*.wsdl'],
    ];

    public function __construct(string $directory, string $configFile = '')
    {
        $realPath = realpath($directory);
        $this->rootDir = $realPath !== false ? $realPath : (string) getcwd();
        $this->loadPackageJson();
        $this->loadComposerJson();
        $this->scanFileInventory();
        $this->loadConfig($configFile);
    }

    public function run(): void
    {
        echo str_repeat('=', 60) . "\n";
        echo "Running package.json Standards Checks\n";
        echo str_repeat('=', 60) . "\n\n";

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

    private function loadPackageJson(): void
    {
        $path = $this->rootDir . '/package.json';

        if (!file_exists($path)) {
            fwrite(STDERR, "\033[31mError: package.json not found in {$this->rootDir}\033[0m\n");
            exit(1);
        }

        $content = file_get_contents($path);
        if ($content === false) {
            fwrite(STDERR, "\033[31mError: Could not read package.json\033[0m\n");
            exit(1);
        }

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
            if ($content === false) {
                return;
            }

            $this->composer = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($this->composer)) {
                echo "Loaded composer.json for cross-validation\n";
            }
        }
    }

    private function scanFileInventory(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->rootDir, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relative = str_replace($this->rootDir . '/', '', $file->getPathname());
                $this->fileInventory[] = $relative;
            }
        }

        echo 'Scanned ' . count($this->fileInventory) . " files\n";
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
            'forbiddenKeywords' => ['tool', 'utility', 'helper'],
        ];

        if ($configFile && file_exists($configFile)) {
            $content = file_get_contents($configFile);
            $userConfig = $content !== false ? json_decode($content, true) : null;
            if (!is_array($userConfig)) {
                fwrite(STDERR, "\033[33mWarning: Invalid config JSON, using defaults\033[0m\n");
                $this->config = $defaults;
            } else {
                $this->config = array_merge($defaults, $userConfig);
                echo sprintf('Loaded configuration from %s%s', $configFile, PHP_EOL);
            }
        } else {
            $this->config = $defaults;
            if ($configFile !== '' && $configFile !== '0') {
                echo "Config file not found, using defaults\n";
            }
        }
    }

    private function validateBasicStructure(): void
    {
        $required = ['name', 'version', 'description'];
        foreach ($required as $field) {
            if (empty($this->package[$field])) {
                $this->addIssue(
                    self::MUST,
                    'Missing required field',
                    $field,
                    sprintf("Field '%s' is required in package.json", $field),
                );
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
                $this->addIssue(
                    self::MUST,
                    'Invalid scoped name',
                    'name: ' . $name,
                    'Scoped packages must match @owner/name with lowercase, hyphens, underscores',
                );
            }

            // Extract owner from scope
            $parts = explode('/', (string) $name);
            $scope = substr($parts[0], 1); // Remove @

            if (!empty($this->config['owner']) && $scope !== $this->config['owner']) {
                $this->addIssue(
                    self::MUST,
                    'Scope/owner mismatch',
                    'name: ' . $name,
                    sprintf("Scope '%s' does not match expected owner '%s'", $scope, $this->config['owner']),
                );
            }
        } else {
            // Unscoped: check if it matches owner/project pattern
            if (!preg_match('/^[a-z0-9_-]+$/', $name)) {
                $this->addIssue(
                    self::MUST,
                    'Invalid package name',
                    'name: ' . $name,
                    'Unscoped packages must be lowercase with hyphens/underscores only',
                );
            }

            // For cross-project consistency, suggest scoped names
            if (!empty($this->config['owner'])) {
                $this->addIssue(
                    self::SHOULD,
                    'Consider scoped package',
                    'name: ' . $name,
                    sprintf('For consistency with Composer, consider using @%s/%s', $this->config['owner'], $name),
                );
            }
        }
    }

    private function validateType(): void
    {
        $type = $this->package['type'] ?? 'commonjs';

        // NPM types are different from Composer, but we can suggest consistency
        if (!in_array($type, $this->allowedTypes, true)) {
            $this->addIssue(
                self::MAY,
                'Non-standard type',
                'type: ' . $type,
                sprintf("Type '%s' is not in standard list: ", $type) . implode(', ', $this->allowedTypes),
            );
        }

        // Check for module consistency
        if ($type === 'module' && !empty($this->package['main']) && (!str_ends_with((string) $this->package['main'], '.mjs') && !str_ends_with((string) $this->package['main'], '.js'))) {
            $this->addIssue(
                self::SHOULD,
                'Module extension',
                'main',
                'ES modules should use .mjs extension or specify exports field',
            );
        }
    }

    private function validateDescription(): void
    {
        $desc = $this->package['description'] ?? '';

        if (empty($desc)) {
            $this->addIssue(
                self::MUST,
                'Missing description',
                'description',
                'Description is required for NPM packages',
            );
        } elseif (strlen((string) $desc) < 20) {
            $this->addIssue(
                self::SHOULD,
                'Description too short',
                'description',
                'Description should be meaningful (currently ' . strlen((string) $desc) . ' chars)',
            );
        }
    }

    private function validateLicense(): void
    {
        $license = $this->package['license'] ?? '';

        if (empty($license)) {
            $this->addIssue(
                self::MUST,
                'Missing license',
                'license',
                'License field is required',
            );
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
            if (strtolower((string) $composerLicense) !== strtolower((string) $licenseStr)) {
                $this->addIssue(
                    self::SHOULD,
                    'License mismatch',
                    'license: ' . $licenseStr,
                    sprintf("License '%s' differs from composer.json '%s'", $licenseStr, $composerLicense),
                );
            }
        }

        // Check expected license
        if (!empty($this->config['expectedLicense'])) {
            $expected = $this->config['expectedLicense'];
            if (strtolower((string) $licenseStr) !== strtolower((string) $expected)) {
                $this->addIssue(
                    self::SHOULD,
                    'Unexpected license',
                    'license: ' . $licenseStr,
                    sprintf("Expected '%s' per configuration", $expected),
                );
            }
        }

        // SPDX license list validation (simplified check)
        $validLicenses = ['MIT', 'Apache-2.0', 'BSD-2-Clause', 'BSD-3-Clause', 'GPL-2.0', 'GPL-3.0', 'LGPL-2.1', 'LGPL-3.0', 'ISC', 'MPL-2.0', 'Unlicense', 'Proprietary'];

        $licenses = is_array($license) ? $license : [$license];
        foreach ($licenses as $lic) {
            if (!in_array($lic, $validLicenses, true) && !preg_match('/^proprietary|commercial|custom:/i', (string) $lic)) {
                $this->addIssue(
                    self::MAY,
                    'Non-standard license',
                    'license: ' . $lic,
                    'Consider using SPDX standard identifier',
                );
            }
        }
    }

    private function validateKeywords(): void
    {
        $keywords = $this->package['keywords'] ?? [];

        if ($this->config['requireKeywords'] && (empty($keywords) || !is_array($keywords))) {
            $this->addIssue(
                self::MUST,
                'Missing keywords',
                'keywords',
                'Keywords array is required',
            );
            return;
        }

        if (!is_array($keywords)) {
            $this->addIssue(
                self::MUST,
                'Invalid keywords',
                'keywords',
                'Keywords must be an array of strings',
            );
            return;
        }

        // Check for duplicates
        $unique = array_unique($keywords);
        if (count($unique) !== count($keywords)) {
            $dups = array_diff_assoc($keywords, $unique);
            $this->addIssue(
                self::SHOULD,
                'Duplicate keywords',
                'keywords',
                'Duplicate entries: ' . implode(', ', array_unique($dups)),
            );
        }

        // Check all are strings
        foreach ($keywords as $kw) {
            if (!is_string($kw) || empty(trim($kw))) {
                $this->addIssue(
                    self::MUST,
                    'Invalid keyword',
                    'keywords',
                    'Keywords must be non-empty strings',
                );
                break;
            }
        }

        // Check forbidden keywords
        $forbidden = $this->config['forbiddenKeywords'] ?? [];
        foreach ($keywords as $kw) {
            if (in_array(strtolower((string) $kw), $forbidden, true)) {
                $this->addIssue(
                    self::SHOULD,
                    'Generic keyword',
                    'keywords: ' . $kw,
                    'Avoid generic terms; use specific, descriptive keywords',
                );
            }
        }
    }

    private function validateVersion(): void
    {
        if (isset($this->package['version'])) {
            $version = $this->package['version'];

            if (!preg_match('/^\d+\.\d+\.\d+(-.+)?$/', $version)) {
                $this->addIssue(
                    self::SHOULD,
                    'Non-semver version',
                    'version: ' . $version,
                    'Version should follow semantic versioning',
                );
            }

            $this->addIssue(
                self::MAY,
                'Version field present',
                'version',
                'Version field is typically managed by npm publish/git tags; consider removing',
            );
        }
    }

    private function validateFiles(): void
    {
        $files = $this->package['files'] ?? [];

        // Check for common mistakes
        if (!empty($files) && in_array('src', $files) && !in_array('dist', $files)) {
            $this->addIssue(
                self::MAY,
                'Source in files',
                'files',
                "Consider including 'dist' instead of 'src' if distributing compiled code",
            );
        }
    }

    private function validateExports(): void
    {
        $exports = $this->package['exports'] ?? null;

        if ($exports === null) {
            // Not required, but recommended for modern packages
            if (isset($this->package['type']) && $this->package['type'] === 'module') {
                $this->addIssue(
                    self::SHOULD,
                    'Missing exports',
                    'exports',
                    'ES modules should define exports field for explicit entry points',
                );
            }

            return;
        }

        if (!is_array($exports) && !is_string($exports)) {
            $this->addIssue(
                self::MUST,
                'Invalid exports format',
                'exports',
                'Exports must be a string or object',
            );
            return;
        }

        // Check for "." export (main entry)
        if (is_array($exports) && !isset($exports['.']) && !isset($exports['./'])) {
            $this->addIssue(
                self::SHOULD,
                'Missing main export',
                'exports',
                "Consider adding '.' entry for main module export",
            );
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

    private function validateFileLocations(): void
    {
        foreach ($this->fileInventory as $file) {
            // Skip node_modules and hidden files
            if (str_contains((string) $file, 'node_modules')) {
                continue;
            }

            if (str_contains((string) $file, '/.')) {
                continue;
            }

            // Check .dist files
            if (str_ends_with((string) $file, '.dist')) {
                $base = substr((string) $file, 0, -5);
                $allowedDist = [
                    '.env', '.env.local', '.env.production', 'phpunit.xml',
                    'phpcs.xml', 'ecs.php', 'phpstan.neon', 'psalm.xml',
                ];

                $isAllowed = false;
                foreach ($allowedDist as $allowed) {
                    if (str_ends_with($base, $allowed)) {
                        $isAllowed = true;
                        break;
                    }
                }

                if (!$isAllowed) {
                    $this->addIssue(
                        self::SHOULD,
                        'Suspicious .dist file',
                        $file,
                        '.dist files should only be used for configuration templates',
                    );
                }
            }

            // Check each file type against expected locations
            foreach ($this->fileTypeLocations as $expectedDir => $patterns) {
                foreach ($patterns as $pattern) {
                    if (fnmatch($pattern, basename((string) $file))) {
                        if (
                            !str_starts_with((string) $file, (string) $expectedDir) &&
                            !str_starts_with((string) $file, 'src/' . $expectedDir) &&
                            !str_starts_with((string) $file, 'tests/') &&
                            !str_starts_with((string) $file, 'vendor/')
                        ) {
                            $this->addIssue(
                                self::SHOULD,
                                'File location',
                                $file,
                                sprintf("File should be in '%s' directory", $expectedDir),
                            );
                        }

                        break 2;
                    }
                }
            }
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
            $this->addIssue(
                self::SHOULD,
                'Missing Prettier config',
                'prettier',
                "Create .prettierrc.json or add 'prettier' field to package.json",
            );
            return;
        }

        // Check for plugins in config
        $plugins = [];
        if ($prettierField !== null && isset($prettierField['plugins'])) {
            $plugins = $prettierField['plugins'];
        } elseif ($configFile !== null && str_ends_with($configFile, '.json')) {
            $jsonContent = file_get_contents($this->rootDir . '/' . $configFile);
            $content = $jsonContent !== false ? json_decode($jsonContent, true) : null;
            $plugins = is_array($content) ? ($content['plugins'] ?? []) : [];
        }

        // Check installed plugins match config
        $devDeps = $this->package['devDependencies'] ?? [];
        foreach ($devDeps as $pkg => $version) {
            if (str_starts_with((string) $pkg, 'prettier-plugin-')) {
                $pluginName = str_replace('prettier-plugin-', '', (string) $pkg);
                if (!in_array($pkg, $plugins) && !in_array($pluginName, $plugins)) {
                    $this->addIssue(
                        self::SHOULD,
                        'Unconfigured Prettier plugin',
                        (string) $pkg,
                        sprintf("Plugin '%s' installed but not listed in Prettier config plugins array", $pkg),
                    );
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
            $this->addIssue(
                self::MUST,
                'Legacy ESLint config',
                $foundLegacyFile,
                'Migrate to flat config format (eslint.config.js) for ESLint v9+ compatibility',
            );
        }

        if (!$hasFlat && !$hasLegacy) {
            $this->addIssue(
                self::SHOULD,
                'Missing ESLint config',
                'eslint',
                'Create eslint.config.js for ESLint v9+',
            );
        }

        // Check plugins are configured
        if ($hasFlat) {
            $configFile = $this->rootDir . '/eslint.config.js';
            if (!file_exists($configFile)) {
                $configFile = $this->rootDir . '/eslint.config.mjs';
            }

            if (file_exists($configFile)) {
                $content = file_get_contents($configFile);
                if ($content !== false) {
                    $devDeps = array_keys($this->package['devDependencies'] ?? []);

                    foreach ($devDeps as $pkg) {
                        if (str_starts_with((string) $pkg, 'eslint-plugin-') || $pkg === '@eslint/js') {
                            $shortName = str_replace('eslint-plugin-', '', (string) $pkg);
                            if (!str_contains($content, $shortName) && !str_contains($content, (string) $pkg)) {
                                $this->addIssue(
                                    self::SHOULD,
                                    'Unconfigured ESLint plugin',
                                    (string) $pkg,
                                    sprintf("Plugin '%s' installed but may not be imported in eslint.config.js", $pkg),
                                );
                            }
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
            $this->addIssue(
                self::SHOULD,
                'Missing Stylelint config',
                'stylelint',
                "Create .stylelintrc.json or add 'stylelint' field to package.json",
            );
            return;
        }

        // Check plugins are configured
        $config = $pkgConfig ?? [];
        if ($hasConfig && str_ends_with($configFile, '.json')) {
            $jsonContent = file_get_contents($this->rootDir . '/' . $configFile);
            $content = $jsonContent !== false ? json_decode($jsonContent, true) : null;
            $config = is_array($content) ? $content : [];
        }

        $plugins = $config['plugins'] ?? [];
        $extends = $config['extends'] ?? [];

        $devDeps = array_keys($this->package['devDependencies'] ?? []);
        foreach ($devDeps as $pkg) {
            if (str_starts_with((string) $pkg, 'stylelint-')) {
                $shortName = str_replace('stylelint-', '', (string) $pkg);
                // Check if it's a plugin (stylelint-plugin-*)
                if (str_starts_with((string) $pkg, 'stylelint-plugin-')) {
                    if (!in_array($pkg, $plugins) && !in_array($shortName, $plugins)) {
                        $this->addIssue(
                            self::SHOULD,
                            'Unconfigured Stylelint plugin',
                            (string) $pkg,
                            sprintf("Plugin '%s' installed but not in stylelint config plugins", $pkg),
                        );
                    }
                } elseif (!in_array($pkg, $extends) && !str_contains($shortName, 'config')) {
                    // It's likely a config or other tool
                    $this->addIssue(
                        self::MAY,
                        'Stylelint tool check',
                        (string) $pkg,
                        sprintf("Verify '%s' is properly configured", $pkg),
                    );
                }
            }
        }
    }

    private function validateEngines(): void
    {
        $engines = $this->package['engines'] ?? [];

        if (empty($engines)) {
            $this->addIssue(
                self::MUST,
                'Missing engines',
                'engines',
                'Engines field is required to specify Node.js and npm versions',
            );
            return;
        }

        $node = $engines['node'] ?? '';
        $npm = $engines['npm'] ?? '';

        if (empty($node)) {
            $this->addIssue(
                self::MUST,
                'Missing Node.js version',
                'engines.node',
                'Node.js version constraint is required',
            );
        } elseif (!preg_match('/>=?\s*2\d|>=?\s*20|^\^2\d|^~2\d/', (string) $node)) {
            // Check for >= 20
            $this->addIssue(
                self::MUST,
                'Node.js version too low',
                'engines.node: ' . $node,
                'Requires Node.js >= 20 (current LTS)',
            );
        }

        if (empty($npm)) {
            $this->addIssue(
                self::MUST,
                'Missing npm version',
                'engines.npm',
                'npm version constraint is required',
            );
        } elseif (!preg_match('/>=?\s*1\d|>=?\s*10|^\^1\d/', (string) $npm)) {
            $this->addIssue(
                self::MUST,
                'npm version too low',
                'engines.npm: ' . $npm,
                'Requires npm >= 10',
            );
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
                if (str_starts_with((string) $pkg, $tool) || $pkg === $tool) {
                    $this->addIssue(
                        self::MUST,
                        'Dev tool in dependencies',
                        'dependencies: ' . $pkg,
                        sprintf("'%s' should be in devDependencies, not dependencies", $pkg),
                    );
                    break;
                }
            }
        }

        // Check for wildcards
        foreach (array_merge($deps, $devDeps) as $pkg => $version) {
            if ($version === '*' || $version === 'latest') {
                $this->addIssue(
                    self::MUST,
                    'Wildcard dependency',
                    $pkg . ': ' . $version,
                    "Avoid '*' or 'latest'; use explicit version constraints",
                );
            }
        }

        $seen = [];

        foreach ($deps as $pkg => $ver) {
            $seen[$pkg] = ['ver' => $ver, 'type' => 'dependencies'];
        }

        foreach ($devDeps as $pkg => $ver) {
            if (isset($seen[$pkg]) && $seen[$pkg]['ver'] !== $ver) {
                $this->addIssue(
                    self::SHOULD,
                    'Version inconsistency',
                    $pkg,
                    sprintf("Version '%s' in devDependencies differs from '%s' in dependencies", $ver, $seen[$pkg]['ver']),
                );
            }
        }

        foreach ($peerDeps as $pkg => $ver) {
            if (isset($seen[$pkg]) && $seen[$pkg]['ver'] !== $ver) {
                $this->addIssue(
                    self::SHOULD,
                    'Peer dependency mismatch',
                    $pkg,
                    sprintf("Peer dependency version '%s' differs from installed '%s'", $ver, $seen[$pkg]['ver']),
                );
            }
        }
    }

    private function validateScripts(): void
    {
        $scripts = $this->package['scripts'] ?? [];
        $devDeps = $this->package['devDependencies'] ?? [];

        // Check for standard scripts if tools are installed
        if (isset($devDeps['eslint']) && !isset($scripts['lint'])) {
            $this->addIssue(
                self::SHOULD,
                'Missing lint script',
                'scripts',
                "ESLint installed but 'lint' script not found",
            );
        }

        if ((isset($devDeps['jest']) || isset($devDeps['vitest'])) && !isset($scripts['test'])) {
            $this->addIssue(
                self::SHOULD,
                'Missing test script',
                'scripts',
                "Test runner installed but 'test' script not found",
            );
        }

        if (isset($devDeps['prettier']) && !isset($scripts['format'])) {
            $this->addIssue(
                self::SHOULD,
                'Missing format script',
                'scripts',
                "Prettier installed but 'format' script not found",
            );
        }

        // Security checks on scripts
        foreach ($scripts as $name => $command) {
            if (is_array($command)) {
                $command = implode(' ', $command);
            }

            // Check for rm -rf
            if (preg_match('/\brm\s+-rf\b/', (string) $command)) {
                $this->addIssue(
                    self::MUST,
                    'Dangerous script',
                    'scripts.' . $name,
                    sprintf("Script contains 'rm -rf': '%s'", $command),
                );
            }

            // Check for sudo
            if (preg_match('/\bsudo\b/', (string) $command)) {
                $this->addIssue(
                    self::MUST,
                    'Sudo in script',
                    'scripts.' . $name,
                    sprintf("Avoid sudo in npm scripts: '%s'", $command),
                );
            }

            // Check for hardcoded paths
            if (preg_match('/\.\.\/|^\.\/bin\/|^\.\/scripts\//', (string) $command)) {
                $this->addIssue(
                    self::SHOULD,
                    'Hardcoded path',
                    'scripts.' . $name,
                    sprintf("Use npx or node_modules/.bin/ instead of relative paths: '%s'", $command),
                );
            }
        }
    }

    private function validateConfig(): void
    {
        // General config validation
        $config = $this->package['config'] ?? [];

        // Check for engine-strict
        if (isset($config['engine-strict']) && $config['engine-strict'] !== true) {
            $this->addIssue(
                self::SHOULD,
                'Engine strict disabled',
                'config.engine-strict',
                'Set engine-strict: true to enforce Node version requirements',
            );
        }
    }

    private function validateBin(): void
    {
        $bin = $this->package['bin'] ?? [];

        if (empty($bin)) {
            return;
        }

        if (!is_array($bin)) {
            $this->addIssue(
                self::MUST,
                'Invalid bin format',
                'bin',
                'Bin must be an object mapping command names to paths',
            );
            return;
        }

        foreach ($bin as $command => $path) {
            $fullPath = $this->rootDir . '/' . $path;

            if (!file_exists($fullPath)) {
                $this->addIssue(
                    self::MUST,
                    'Missing binary',
                    'bin.' . $command,
                    'Binary file does not exist: ' . $fullPath,
                );
            } elseif (!is_executable($fullPath) && PHP_OS_FAMILY !== 'Windows') {
                $this->addIssue(
                    self::SHOULD,
                    'Non-executable binary',
                    'bin.' . $command,
                    'Binary should be executable (chmod +x)',
                );
            }

            // Check if binary is in bin/ directory
            if (!str_starts_with((string) $path, 'bin/')) {
                $this->addIssue(
                    self::SHOULD,
                    'Binary location',
                    'bin.' . $command,
                    "Binary should be in 'bin/' directory",
                );
            }
        }
    }

    private function validateDeprecatedConfigs(): void
    {
        foreach ($this->fileInventory as $file) {
            $basename = basename((string) $file);

            if (isset($this->deprecatedConfigs[$basename])) {
                $message = $this->deprecatedConfigs[$basename];
                $this->addIssue(self::SHOULD, 'Deprecated config', $file, $message);
            }
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
        $pkgParts = explode('/', (string) $pkgName);
        $pkgProject = end($pkgParts);

        $composerParts = explode('/', $composerName);
        $composerProject = end($composerParts);

        // Normalize to kebab-case
        $pkgKebab = strtolower((string) preg_replace('/([a-z])([A-Z])/', '$1-$2', $pkgProject));
        $composerKebab = strtolower((string) preg_replace('/([a-z])([A-Z])/', '$1-$2', $composerProject));

        if ($pkgKebab !== $composerKebab) {
            $this->addIssue(
                self::SHOULD,
                'Project name mismatch',
                'name',
                sprintf("package.json project '%s' differs from composer.json '%s'", $pkgProject, $composerProject),
            );
        }

        // Compare descriptions
        $pkgDesc = $this->package['description'] ?? '';
        $composerDesc = $this->composer['description'] ?? '';

        if (!empty($pkgDesc) && !empty($composerDesc) &&
            strtolower(trim((string) $pkgDesc)) !== strtolower(trim((string) $composerDesc))) {
            $this->addIssue(
                self::MAY,
                'Description mismatch',
                'description',
                'Descriptions differ between package.json and composer.json',
            );
        }

        // Compare licenses
        $pkgLicense = $this->package['license'] ?? '';
        $composerLicense = $this->composer['license'] ?? '';

        if (is_array($composerLicense)) {
            $composerLicense = implode(', ', $composerLicense);
        }

        if (!empty($pkgLicense) && !empty($composerLicense) &&
            strtolower((string) $pkgLicense) !== strtolower((string) $composerLicense)) {
            $this->addIssue(
                self::SHOULD,
                'License mismatch',
                'license',
                sprintf("package.json license '%s' differs from composer.json '%s'", $pkgLicense, $composerLicense),
            );
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
            $this->addIssue(
                self::MUST,
                'Missing homepage',
                'homepage',
                'Public packages require homepage URL',
            );
        } else {
            $expected = sprintf('https://github.com/%s/%s', $this->config['owner'], $project);
            if (!str_starts_with((string) $homepage, $expected)) {
                $this->addIssue(
                    self::MUST,
                    'Invalid homepage',
                    'homepage: ' . $homepage,
                    sprintf('Must match: https://github.com/%s/%s', $this->config['owner'], $project),
                );
            }
        }

        // Repository check
        $repo = $this->package['repository'] ?? [];
        if (is_string($repo)) {
            $repo = ['url' => $repo];
        }

        if (empty($repo) || empty($repo['url'])) {
            $this->addIssue(
                self::MUST,
                'Missing repository',
                'repository',
                'Public packages require repository',
            );
        } else {
            $url = $repo['url'];
            if (!str_contains((string) $url, sprintf('github.com/%s/', $this->config['owner']))) {
                $this->addIssue(
                    self::SHOULD,
                    'Repository mismatch',
                    'repository.url',
                    sprintf('Repository should be under %s organization', $this->config['owner']),
                );
            }
        }

        // Author check
        $author = $this->package['author'] ?? [];
        if (is_string($author)) {
            // Parse "Name <email> (url)" format
            if (!str_contains($author, 'Douglas Green')) {
                $this->addIssue(
                    self::MUST,
                    'Missing required author',
                    'author',
                    "Must include 'Douglas Green' as author",
                );
            }
        } elseif (is_array($author)) {
            if (($author['name'] ?? '') !== 'Douglas Green') {
                $this->addIssue(
                    self::MUST,
                    'Missing required author',
                    'author.name',
                    "Author name must be 'Douglas Green'",
                );
            }

            if (($author['email'] ?? '') !== 'douglas@nurd.site') {
                $this->addIssue(
                    self::MUST,
                    'Invalid author email',
                    'author.email',
                    "Author email must be 'douglas@nurd.site'",
                );
            }

            if (($author['url'] ?? '') !== 'https://nurd.site/') {
                $this->addIssue(
                    self::MUST,
                    'Invalid author URL',
                    'author.url',
                    "Author URL must be 'https://nurd.site/'",
                );
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
                        $this->addIssue(
                            self::MUST,
                            'Invalid contributor role',
                            'contributors',
                            "Douglas Green role must be 'Developer'",
                        );
                    }
                }
            }

            if (!$foundDouglas && $this->config['isPublic']) {
                $this->addIssue(
                    self::SHOULD,
                    'Missing contributor',
                    'contributors',
                    'Consider adding Douglas Green to contributors list',
                );
            }
        }

        // Keywords
        $keywords = $this->package['keywords'] ?? [];
        if (empty($keywords) || !is_array($keywords)) {
            $this->addIssue(
                self::MUST,
                'Missing keywords (public)',
                'keywords',
                'Public packages must include keywords array',
            );
        }
    }

    private function printReport(): void
    {
        $mustIssues = array_filter($this->issues, fn (array $i): bool => $i['level'] === self::MUST);
        $shouldIssues = array_filter($this->issues, fn (array $i): bool => $i['level'] === self::SHOULD);
        $mayIssues = array_filter($this->issues, fn (array $i): bool => $i['level'] === self::MAY);

        echo "\n" . str_repeat('=', 60) . "\n";
        echo "package.json Standards Compliance Report\n";
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
            echo "\033[32m✓ All package.json standards met\033[0m\n";
        }

        // Summary statistics
        echo "\nSummary:\n";
        echo "--------\n";
        printf("Project: %s\n", $this->package['name'] ?? 'unknown');
        printf("Type: %s\n", $this->package['type'] ?? 'commonjs');
        printf(
            "Total issues: %d (MUST: %d, SHOULD: %d, MAY: %d)\n",
            count($this->issues),
            count($mustIssues),
            count($shouldIssues),
            count($mayIssues),
        );

        // Compliance score
        $totalChecks = 30; // Approximate number of validation rules
        $deduction = (count($mustIssues) * 3) + (count($shouldIssues));
        $compliance = max(0, 100 - ($deduction * 100 / $totalChecks));
        printf("Compliance score: %d%%\n", $compliance);

        echo "\n";

        if ($mustIssues !== []) {
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
            'message' => $message,
        ];
    }
}
