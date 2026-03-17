#!/bin/bash
set -euo pipefail

php -l src/Environment.php > /dev/null
php -l src/Template.php > /dev/null

./vendor/bin/php-cs-fixer fix --dry-run --show-progress=none > /tmp/twig-autoresearch-cs.log 2>&1 || {
    tail -80 /tmp/twig-autoresearch-cs.log
    exit 1
}

./vendor/bin/phpstan analyze --no-progress > /tmp/twig-autoresearch-phpstan.log 2>&1 || {
    tail -80 /tmp/twig-autoresearch-phpstan.log
    exit 1
}

./vendor/bin/simple-phpunit > /tmp/twig-autoresearch-phpunit.log 2>&1 || {
    tail -80 /tmp/twig-autoresearch-phpunit.log
    exit 1
}
