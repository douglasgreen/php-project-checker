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

