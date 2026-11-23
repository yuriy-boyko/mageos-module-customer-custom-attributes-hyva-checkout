#!/usr/bin/env bash
# Runs the checks that work without a Magento install: PHP syntax, XML well-formedness, JSON validity. Exits non-zero on the first
# failure. PHP_OPTS (optional) is passed to the PHP syntax check, e.g. PHP_OPTS=-n.
#
# Static analysis needs a store with Hyvä Checkout and the main module (Hyvä Checkout is not public, so CI cannot install it):
#   vendor/bin/phpstan analyse -c vendor/mage-os/module-customer-custom-attributes-hyva-checkout/phpstan.neon.dist
# Run it after every Hyvä Checkout update: it is what finds a changed signature of the Hyvä classes this package uses.
set -euo pipefail
cd "$(dirname "$0")/.."

echo "== PHP syntax"
find . -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php ${PHP_OPTS:-} -l >/dev/null
echo "ok"

echo "== XML well-formedness"
php -r 'foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(".", FilesystemIterator::SKIP_DOTS)) as $f) { if (substr((string) $f, -4) === ".xml" && strpos((string) $f, "/.git/") === false) { $d = new DOMDocument(); if (!@$d->load((string) $f)) { fwrite(STDERR, "bad xml: $f\n"); exit(1); } } } echo "ok\n";'

echo "== JSON validity"
php -r 'foreach (glob("composer.json") as $f) { json_decode(file_get_contents($f), true, 512, JSON_THROW_ON_ERROR); echo "ok $f\n"; }'
