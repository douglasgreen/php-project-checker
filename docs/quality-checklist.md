# Quality Checklist

This is the checklist for code cleanup.

1. Confirm that all relevant config files are configured, up-to-date, and in use.

- check-config-dates.php

2. Confirm that all scripts in php-project-checker pass their checks.

- class-checker.php
- composer-checker.php
- doc-checker.php
- file-checker.php
- function-checker.php
- gitlab-ci-checker.php
- package-checker.php
- sort-composer-json.php
- sort-package-json.php

3. Run all fixing and formatting scripts in composer.json and package.json.

4. Confirm that all lint scripts pass.

5. Confirm that all functions are in classes and all classes autoload.

6. Add unit tests.

7. Resolve all todo statements.

8. Extract significant HTML code to templates.

9. Apply all the latest relevant standards files.
