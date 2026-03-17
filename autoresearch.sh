#!/bin/bash
set -euo pipefail

php -l bench_lexer_tokenize.php > /dev/null
php -l src/Lexer.php > /dev/null
php -d memory_limit=-1 bench_lexer_tokenize.php
