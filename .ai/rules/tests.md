---
paths:
  - 'tests/**'
---

# Tests

## Use ./pest wrapper for TIA
Run tests via the repo `./pest` wrapper, not bare `vendor/bin/pest`. The wrapper sets XDEBUG_MODE=coverage (TIA needs Xdebug in coverage mode; Herd's php.ini loads xdebug with mode=off to keep the site fast). Bare `vendor/bin/pest` prints "TIA skipped — needs ext-pcov or Xdebug" and runs without replay. TIA is on by default via pest()->tia()->locally() in tests/Pest.php. All tests must be Pest-style: TIA aborts on PHPUnit-class tests (convert, don't delete).
