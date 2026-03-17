#!/bin/bash
set -euo pipefail

php -l src/Environment.php > /dev/null
php -l src/Template.php > /dev/null

./vendor/bin/simple-phpunit > /tmp/twig-autoresearch-phpunit.log 2>&1 || {
    tail -80 /tmp/twig-autoresearch-phpunit.log
    exit 1
}
