<?php

require __DIR__.'/vendor/autoload.php';

use Twig\Environment;
use Twig\Lexer;
use Twig\Loader\ArrayLoader;
use Twig\Source;

$templates = [
    <<<'TWIG'
{% extends "base.html.twig" %}
{% set classes = ['page', user.active ? 'is-active' : 'is-idle', user.role ?? 'guest'] %}
{% block content %}
    <section class="{{ classes|join(' ') }}">
        {% for article in articles if article.enabled %}
            <article data-id="{{ article.id }}" data-slug="{{ article.slug|default('n-a') }}">
                <h2>{{ loop.index }}. {{ article.title|upper }}</h2>
                {% if article.tags is not empty %}
                    <ul>
                        {% for tag in article.tags %}
                            <li class="tag-{{ loop.index0 }}">{{ tag|trim }}</li>
                        {% endfor %}
                    </ul>
                {% elseif article.summary is defined and article.summary is not empty %}
                    <p>{{ article.summary|striptags|slice(0, 160) }}</p>
                {% else %}
                    <p>{{ "No summary for #{ article.author.name ?? 'unknown' }" }}</p>
                {% endif %}
            </article>
        {% else %}
            <p>{{ "No results for #{ query }"|trim }}</p>
        {% endfor %}
    </section>
{% endblock %}
TWIG,
    <<<'TWIG'
{% set activeUsers = users
    |filter(user => user.enabled and (user.score >= threshold or user.role in ['admin', 'editor']))
    |map(user => {
        id: user.id,
        label: "#{ user.firstName|title } #{ user.lastName|upper }",
        meta: {
            age: user.age ?? 0,
            tags: user.tags ?? [],
            flags: [user.verified ? 'verified' : 'pending', user.archived ? 'archived' : 'live'],
        },
    })
%}
{{ {
    total: activeUsers|length,
    first: activeUsers|first,
    names: activeUsers|map(user => user.label)|join(', '),
    hasInactive: users|filter(user => not user.enabled)|length > 0,
    window: range(0, 5),
}|json_encode }}
TWIG,
    <<<'TWIG'
{# lexer benchmark exercises comments, strings, interpolation, and verbatim blocks #}
{% set message = "Escaped quote: \" and interpolation #{ user.name ?? 'guest' }" %}
{% set mapping = {
    alpha: "a\\nb",
    beta: 'c\\td',
    gamma: value matches '/^[a-z0-9_\\-]+$/i' ? 'ok' : 'bad',
} %}
<div class="wrapper {{ extraClass|default('') }}">
    {{ message }}
    {% verbatim %}
        <script type="text/x-template">{{ this_should_not_be_tokenized }}</script>
    {% endverbatim %}
    {% for row in rows %}
        {{- row.title -}}
        {{ row.description ?? "missing #{ row.id }" }}
    {% endfor %}
</div>
TWIG,
];

$sources = [];
foreach ($templates as $index => $template) {
    $sources[] = new Source(str_repeat($template."\n", 10), 'benchmark_'.$index);
}

$env = new Environment(new ArrayLoader());
$lexer = new Lexer($env);

for ($i = 0; $i < 25; ++$i) {
    foreach ($sources as $source) {
        $lexer->tokenize($source);
    }
}

$start = hrtime(true);
for ($i = 0; $i < 150; ++$i) {
    foreach ($sources as $source) {
        $lexer->tokenize($source);
    }
}
$elapsedMs = (hrtime(true) - $start) / 1_000_000;

printf("METRIC lex_ms=%.3f\n", $elapsedMs);
