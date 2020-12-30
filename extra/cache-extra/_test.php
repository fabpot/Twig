<?php

require_once __DIR__.'/vendor/autoload.php';

use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Twig\Extension\DebugExtension;
use Twig\Extension\StringLoaderExtension;
use Twig\Extra\Cache\CacheExtension;
use Twig\Extra\Cache\CacheRuntime;
use Twig\Extra\Intl\IntlExtension;
use Twig\Markup;
use Twig\RuntimeLoader\RuntimeLoaderInterface;
use Twig\TwigFilter;
use Twig\TwigFunction;

error_reporting(-1);

$loader = new Twig\Loader\ArrayLoader([
    'index' => <<<EOF

{##}

BEFORE

{% set foo = "bar" %}

{% cache "key;v1;item_key" ttl(200) tags(['tag_1', 'tag_2']) %}
    {% set foo = "bar1" %}
    CACHED!
{% endcache %}

{% cache "key1;v1;item_key" %}
{% set foo = "bar2" %}
CACHED!
{% endcache %}

{{ foo }}

AFTER

EOF
,

    'name' => '{{ name|upper }}',
]);

$twig = new Twig\Environment($loader, [
    'strict_variables' => true,
    'debug' => false,
    'cache' => false, //__DIR__.'/cache_bug',
    'autoescape' => 'html',
]);

$twig->addExtension(new StringLoaderExtension());
$twig->addExtension(new DebugExtension());

$cache = new FilesystemAdapter();
$twig->addExtension(new CacheExtension());
$twig->addRuntimeLoader(new class implements RuntimeLoaderInterface {
    public function load($class) {
        if (CacheRuntime::class === $class) {
            return new CacheRuntime(new FilesystemAdapter());
        }
    }
});

$twig->registerUndefinedTokenParserCallback(function ($name) {
    print "MISSING $name\n\n";

    return false;
});

$template = 'index';
echo $twig->tokenize(new Twig\Source($twig->getLoader()->getSourceContext($template)->getCode(), $template))."\n\n";
//echo $twig->parse($twig->tokenize(new Twig\Source($twig->getLoader()->getSourceContext($template)->getCode(), $template)))."\n\n";
echo $twig->compile($twig->parse($twig->tokenize(new Twig\Source($twig->getLoader()->getSourceContext($template)->getCode(), $template))))."\n\n";

$template = $twig->load('index');

echo $template->render($data = [
]);
echo "\n\n---\n\n";
