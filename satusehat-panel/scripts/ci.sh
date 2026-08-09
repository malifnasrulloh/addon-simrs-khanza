#!/usr/bin/env bash
#
# CI gate for satusehat-panel: verifies the shared library is in lockstep
# with php-service (source of truth) and the PHPUnit suite stays green.
#
# Usage: bash scripts/ci.sh   (run from the satusehat-panel directory)

set -euo pipefail
cd "$(dirname "$0")/.."

echo "==> 1/3 lint all PHP sources"
find src public index.php -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
echo "    OK"

echo "==> 2/3 library sync check (php-service = source of truth)"
php scripts/sync-lib.php --verify
echo "    OK"

echo "==> 3/3 PHPUnit suite"
if [ -f vendor/autoload.php ]; then
    composer test
else
    php -r 'echo "vendor/ missing — run: composer install\n";' >&2
    exit 1
fi
echo "    OK"

echo "All CI gates passed."
