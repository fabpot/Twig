Contextual Escaping Extension
=============================

This package analyzes the lexical context of Twig output expressions and
reports the escaping operations required for HTML, JavaScript, CSS, URLs,
`srcset` and meta refresh content. It does not change template compilation or
rendering.

> [!WARNING]
> This package is experimental and is not covered by Twig's backward
> compatibility promise.

Installation
------------

```console
composer require --dev twig/contextual-escaping-extra
```

Usage
-----

Create the linter from the Twig environment configured by the application:

```php
use Twig\Extra\ContextualEscaping\Linter;

$linter = Linter::create($twig);
$results = $linter->lintDirectory(__DIR__.'/templates');
```

For a Symfony full-stack application, run:

```console
php vendor/bin/lint-contextual-escaping .
```

The command writes terminal output and self-contained HTML and JSON reports to
`var/contextual-escaping.html` and `var/contextual-escaping.json`. A report can
be used as a CI baseline:

```console
php vendor/bin/lint-contextual-escaping . \
    --baseline=config/contextual-escaping-baseline.json
```

The analyzer and its result types are experimental. Diagnostics can identify
unsupported constructs or analysis limitations; they do not necessarily
identify exploitable vulnerabilities.
