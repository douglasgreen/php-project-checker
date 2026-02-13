# PHP Project Checker

Add the `bin/` directory to your path.

## Doc checker

**Usage:**

```bash
# Check current directory
doc-checker.php

# Check specific project
doc-checker.php /path/to/project
```

**Features:**

1. **Required Files Check**: Validates `README.md`, `CHANGELOG.md`, `LICENSE`, `docs/index.md`, `docs/development/setup.md`, `docs/development/testing.md`, `docs/architecture.md`, and ADR structure per sections 2.3.1–2.3.3

2. **Naming & Encoding**: Enforces kebab-case filenames (2.2.2), UTF-8 encoding, and Unix line endings (LF) (2.2.3)

3. **Markdown Syntax**: Validates ATX headings (6.1.1), single H1 per document, no skipped heading levels (6.2.2), fenced code blocks with language tags (4.2.2, 6.1.2), hyphen lists (6.1.3), and no trailing whitespace (6.1.5)

4. **Content Quality**: Detects fluff words like "simply/just/obviously" (3.2.2), passive voice (3.1.1), future tense (3.1.2), sentence case violations in headings (3.1.5), and inconsistent list punctuation (3.1.7)

5. **Security Scanning**: Detects exposed API keys (OpenAI, GitHub, AWS patterns), hardcoded passwords, and IP addresses per sections 4.2.4 and 8.1.2

6. **Link Validation**: Checks for broken internal links, bare URLs (6.1.4), "click here" text (7.1.3), and orphaned files (not linked from other docs)

7. **Frontmatter**: Validates YAML frontmatter presence for metadata (1.3.3)

The script uses ANSI color codes for terminal output, returns exit code 1 if **MUST** violations exist (suitable for CI/CD pipelines), and provides a compliance score based on issue severity.

## Composer checker

**Usage:**

```bash
# Check current directory
composer-checker.php

# Check specific directory
composer-checker.php -d /path/to/project

# With configuration file
composer-checker.php -d /path/to/project -c /path/to/composer-checker-config.json
```

**Example configuration file** (`composer-checker-config.json`):

```json
{
    "owner": "douglasgreen",
    "isPublic": true,
    "expectedLicense": "MIT",
    "phpMinimumVersion": ">=8.3",
    "minimumPackageVersions": {
        "phpstan/phpstan": "1.10.0",
        "phpunit/phpunit": "10.0.0",
        "rector/rector": "0.18.0",
        "symplify/easy-coding-standard": "12.0.0"
    },
    "checkSchema": false
}
```

**Features validated:**

- **Package Name Format**: Validates `vendor/package` with lowercase, numbers, hyphens, underscores only
- **Owner Validation**: Checks against configured owner parameter
- **License**: Ensures presence and matches expected value
- **Project Type**: Validates against Composer's allowed type list plus WordPress/Symfony extensions
- **Public Project Fields** (when `isPublic: true`):
  - Homepage must match `https://github.com/OWNER/PROJECT` pattern
  - Authors must include Douglas Green with specific email, homepage, and role
  - Keywords array required
- **Autoload Configuration**: PSR-4 required, namespace must match owner/project StudlyCase, paths must exist
- **Config**: `sort-packages` must be `true`
- **PHP Version**: Requires PHP 8.3+ constraint
- **Package Versions**: Validates against configured minimum versions using composer.lock if available
- **Post-Install/Update Scripts**: Detects hardcoded paths and dangerous commands (rm -rf, system calls)
- **Repository Registry**: Flags deprecated `type: gitlab`
- **Security**: Scans scripts for `rm -rf`, `sudo`, piped shells, and path traversal
- **Semantic Versioning**: Checks for wildcards (`*`) and dev dependencies in require
- **Stability**: Validates `minimum-stability` and `prefer-stable`
- **Bin**: Checks binary files exist and are executable
- **Extra**: Validates Symfony bundle classes and Laravel auto-discovery
- **Support/Funding**: Validates URL formats and GitHub patterns
- **Composer Validate**: Runs `composer validate --strict` internally
- **JSON Schema**: Optional validation against official Composer schema (requires `justinrainbow/json-schema`)

Exit codes: `0` for success, `1` if MUST violations exist.

## Composer sorter

**Usage:**

```bash
sort-composer-json.php
```

## Package checker

**Usage:**

```bash
# Check current directory
./package-checker.php

# Check specific directory
./package-checker.php -d /path/to/project

# With configuration file
./package-checker.php -d /path/to/project -c /path/to/package-checker-config.json
```

**Example configuration file** (`package-checker-config.json`):

```json
{
    "owner": "douglasgreen",
    "isPublic": true,
    "expectedLicense": "MIT",
    "minimumPackageVersions": {
        "husky": ">=9.0.0",
        "prettier": ">=3.0.0",
        "eslint": ">=9.0.0",
        "stylelint": ">=16.0.0",
        "jest": ">=29.0.0",
        "typescript": ">=5.0.0"
    },
    "requireKeywords": true,
    "forbiddenKeywords": ["tool", "utility", "helper", "misc"]
}
```

**Features validated:**

- **Package Name & Owner**: Validates scoped (`@owner/package`) or unscoped names, checks against configured owner, suggests scoped format for Composer consistency
- **License**: Ensures presence, matches expected value, cross-validates with composer.json, SPDX validation
- **Engines**: Requires Node.js >= 20 and npm >= 10 with regex validation of version constraints
- **Package Versions**: Enforces minimum versions for Husky >= 9, Prettier >= 3, ESLint >= 9, Stylelint >= 16, Jest >= 29, TypeScript >= 5 via configuration
- **Dependency Consistency**: Checks for dev tools in production dependencies, wildcard versions, version mismatches across dependencies/devDependencies/peerDependencies
- **Prettier Configuration**: Validates `.prettierrc.json` exists (preferred over `.prettierrc`), checks installed plugins are listed in config
- **Stylelint Configuration**: Validates config file exists and plugins are properly listed
- **ESLint Configuration**: Enforces flat config format (`eslint.config.js`), flags all legacy `.eslintrc.*` formats, validates plugins are imported in config
- **File Location Standards**: Enforces directory structure:
  - `bin/` for shell scripts
  - `assets/styles/` for CSS/SCSS
  - `assets/data/` for JSON/YAML data
  - `assets/images/` for images
  - `assets/scripts/` for JS/TS
  - `config/` for configuration
  - `assets/sql/` for SQL
  - `assets/xml/` for XML
- **Deprecated Config Files**: Flags `.eslintrc.js`, `.prettierrc.yaml`, `.mocharc.cjs`, `phpcs.xml`, `tslint.json` with migration instructions
- **Project File Analysis**: Detects JS/TS files and flags if ESLint missing, CSS files and flags if Stylelint missing, flags non-config `.dist` files
- **Security/Best Practices**: Checks for lockfile (package-lock.json/yarn.lock/pnpm-lock.yaml), validates no dev tools in production dependencies, ensures standard scripts (lint, test, format) present when tools installed
- **Cross-file Validation**: Compares name (project part only), description, and license with composer.json for consistency

Exit codes: `0` for success, `1` if MUST violations exist.

## Manual checking

After all automated checks are complete, manually run:
- `composer-unused.phar`
- `composer bump`
- `composer update`
- `composer outdated`
- `npm update`
- `npm outdated`
