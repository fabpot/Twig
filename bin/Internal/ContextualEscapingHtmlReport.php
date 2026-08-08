<?php

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Twig\Experimental\ContextualEscaping;

final class ContextualEscapingHtmlReport
{
    public function __construct(
        private string $path,
    ) {
    }

    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * @param list<array{template: string, path: string|null, line: int, operations: list<string>, current: string, correct: bool, expression: string|null}> $plans
     * @param list<array{template: string, path: string|null, line: int, code: string, message: string}>                                                     $diagnostics
     * @param array<string, int>                                                                                                                             $unsupportedNodes
     * @param array{templates: int, output_sites: int, correct_plans: int, incorrect_plans: int, diagnostics: int}                                           $summary
     */
    public function write(array $plans, array $diagnostics, array $unsupportedNodes, array $summary): void
    {
        $entries = [];
        $operations = [];
        foreach ($plans as $plan) {
            $entries[$plan['template']][] = ['type' => 'plan', ...$plan];
            foreach ($plan['operations'] as $operation) {
                $operations[$operation] = true;
            }
        }
        foreach ($diagnostics as $diagnostic) {
            $entries[$diagnostic['template']][] = ['type' => 'diagnostic', ...$diagnostic];
        }
        ksort($entries);
        ksort($operations);

        $navigation = '';
        $sections = '';
        foreach ($entries as $template => $templateEntries) {
            $id = 'template-'.substr(hash('sha256', $template), 0, 12);
            $navigation .= \sprintf('<a href="#%s" data-template-link="%s">%s <span>%d</span></a>', $id, $this->escape($template), $this->escape($template), \count($templateEntries));
            $findings = '';
            $path = null;
            foreach ($templateEntries as $entry) {
                $path ??= $entry['path'];
                $findings .= 'plan' === $entry['type'] ? $this->renderPlan($entry) : $this->renderDiagnostic($entry);
            }
            $sourceLink = null === $path ? '' : \sprintf('<a class="source-link" href="%s">Open source</a>', $this->escape($this->fileUri($path)));
            $sections .= \sprintf(
                '<section class="template" id="%s" data-template="%s"><header><h2>%s</h2>%s</header>%s</section>',
                $id,
                $this->escape($template),
                $this->escape($template),
                $sourceLink,
                $findings,
            );
        }

        $operationOptions = '';
        foreach (array_keys($operations) as $operation) {
            $operationOptions .= \sprintf('<option value="%s">%s</option>', $this->escape($operation), $this->escape($operation));
        }

        $unsupported = '';
        foreach ($unsupportedNodes as $message => $count) {
            $unsupported .= \sprintf(
                '<li><strong>%d occurrence%s</strong><code>%s</code></li>',
                $count,
                1 === $count ? '' : 's',
                $this->escape($message),
            );
        }
        if ('' !== $unsupported) {
            $unsupported = '<details class="limitations"><summary>Unsupported nodes</summary><ul>'.$unsupported.'</ul></details>';
        }

        $generatedAt = gmdate('Y-m-d H:i:s').' UTC';
        $html = <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Twig contextual escaping report</title>
<style>
:root { color-scheme: light dark; --bg: #f5f7fb; --panel: #fff; --text: #172033; --muted: #667085; --border: #d9dfeb; --accent: #3769d4; --good: #087443; --good-bg: #e8f8ef; --bad: #b42318; --bad-bg: #fff0ee; --warn: #9a6700; --warn-bg: #fff8db; }
@media (prefers-color-scheme: dark) { :root { --bg: #11151d; --panel: #1a202c; --text: #edf1f7; --muted: #a6b0c3; --border: #374151; --accent: #8bb4ff; --good: #6ce9a6; --good-bg: #123a2b; --bad: #ff9b94; --bad-bg: #421d1b; --warn: #ffd56a; --warn-bg: #3b3217; } }
* { box-sizing: border-box; }
body { margin: 0; background: var(--bg); color: var(--text); font: 14px/1.5 system-ui, sans-serif; }
a { color: var(--accent); }
.top { padding: 28px 32px 20px; background: var(--panel); border-bottom: 1px solid var(--border); }
h1 { margin: 0 0 6px; font-size: 28px; }
.meta { color: var(--muted); }
.summary { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 18px; }
.metric { min-width: 130px; padding: 10px 14px; border: 1px solid var(--border); border-radius: 9px; background: var(--bg); }
.metric strong { display: block; font-size: 20px; }
.controls { display: grid; grid-template-columns: minmax(240px, 1fr) 180px 210px; gap: 12px; padding: 16px 32px; position: sticky; top: 0; z-index: 3; background: color-mix(in srgb, var(--panel) 94%, transparent); border-bottom: 1px solid var(--border); backdrop-filter: blur(10px); }
input, select { width: 100%; padding: 9px 11px; border: 1px solid var(--border); border-radius: 7px; background: var(--panel); color: var(--text); }
.layout { display: grid; grid-template-columns: 280px minmax(0, 1fr); gap: 24px; max-width: 1500px; margin: 0 auto; padding: 24px 32px 60px; }
nav { position: sticky; top: 86px; max-height: calc(100vh - 110px); overflow: auto; align-self: start; }
nav a { display: flex; justify-content: space-between; gap: 10px; padding: 6px 9px; color: var(--text); text-decoration: none; border-radius: 5px; word-break: break-word; }
nav a:hover { background: var(--panel); color: var(--accent); }
nav span { color: var(--muted); }
.template { margin-bottom: 22px; scroll-margin-top: 90px; }
.template > header { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 9px; }
h2 { margin: 0; font-size: 18px; word-break: break-all; }
.source-link { white-space: nowrap; }
.finding { margin: 8px 0; padding: 13px 15px; border: 1px solid var(--border); border-left-width: 4px; border-radius: 8px; background: var(--panel); }
.finding.correct { border-left-color: var(--good); }
.finding.incorrect { border-left-color: var(--bad); }
.finding.diagnostic { border-left-color: var(--warn); }
.finding-head { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 8px; }
.badge { display: inline-block; padding: 2px 7px; border-radius: 999px; font-size: 12px; font-weight: 650; }
.correct .status { color: var(--good); background: var(--good-bg); }
.incorrect .status { color: var(--bad); background: var(--bad-bg); }
.diagnostic .status { color: var(--warn); background: var(--warn-bg); }
.operation { background: var(--bg); border: 1px solid var(--border); }
.current { color: var(--muted); }
.line { margin-left: auto; color: var(--muted); }
code { font-family: ui-monospace, SFMono-Regular, Consolas, monospace; }
.expression { display: block; padding: 9px 11px; overflow-x: auto; white-space: pre-wrap; overflow-wrap: anywhere; border-radius: 6px; background: var(--bg); }
.message { margin: 0; }
.limitations { max-width: 1500px; margin: 0 auto 32px; padding: 0 32px; }
.limitations summary { cursor: pointer; font-weight: 700; }
.limitations li { margin: 8px 0; }
.limitations code { display: block; margin-top: 3px; overflow-wrap: anywhere; }
.empty { padding: 40px; text-align: center; color: var(--muted); }
[hidden] { display: none !important; }
@media (max-width: 850px) { .controls { grid-template-columns: 1fr; position: static; } .layout { grid-template-columns: 1fr; padding: 18px; } nav { position: static; max-height: 260px; } .top { padding: 24px 18px; } }
</style>
</head>
<body>
<header class="top">
<h1>Twig contextual escaping report</h1>
<div class="meta">Generated {$this->escape($generatedAt)}</div>
<div class="summary">
<div class="metric"><strong>{$summary['templates']}</strong>templates</div>
<div class="metric"><strong>{$summary['output_sites']}</strong>output sites</div>
<div class="metric"><strong>{$summary['correct_plans']}</strong>correct plans</div>
<div class="metric"><strong>{$summary['incorrect_plans']}</strong>incorrect plans</div>
<div class="metric"><strong>{$summary['diagnostics']}</strong>diagnostics</div>
</div>
</header>
<div class="controls">
<label><span hidden>Search</span><input id="search" type="search" placeholder="Filter templates, expressions, operations..."></label>
<label><span hidden>Status</span><select id="status"><option value="">All statuses</option><option value="incorrect">Incorrect</option><option value="correct">Correct</option><option value="diagnostic">Diagnostics</option></select></label>
<label><span hidden>Operation</span><select id="operation"><option value="">All operations</option>{$operationOptions}</select></label>
</div>
<div class="layout">
<nav id="navigation">{$navigation}</nav>
<main id="report">{$sections}<p class="empty" id="empty" hidden>No matching findings.</p></main>
</div>
{$unsupported}
<script>
const search = document.querySelector('#search');
const status = document.querySelector('#status');
const operation = document.querySelector('#operation');
const findings = [...document.querySelectorAll('.finding')];
const templates = [...document.querySelectorAll('.template')];
const links = [...document.querySelectorAll('[data-template-link]')];
const empty = document.querySelector('#empty');
function filterReport() {
    const query = search.value.trim().toLowerCase();
    let visible = 0;
    for (const finding of findings) {
        const matches = (!query || finding.dataset.search.includes(query))
            && (!status.value || finding.dataset.status === status.value)
            && (!operation.value || finding.dataset.operations.split(' ').includes(operation.value));
        finding.hidden = !matches;
        visible += Number(matches);
    }
    for (const template of templates) {
        const hidden = !template.querySelector('.finding:not([hidden])');
        template.hidden = hidden;
        const link = links.find((item) => item.dataset.templateLink === template.dataset.template);
        if (link) link.hidden = hidden;
    }
    empty.hidden = 0 !== visible;
}
for (const control of [search, status, operation]) control.addEventListener('input', filterReport);
</script>
</body>
</html>
HTML;

        $directory = \dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException(\sprintf('Unable to create the HTML report directory "%s".', $directory));
        }
        if (false === file_put_contents($this->path, $html)) {
            throw new \RuntimeException(\sprintf('Unable to write the HTML report to "%s".', $this->path));
        }
    }

    /**
     * @param array{template: string, path: string|null, line: int, operations: list<string>, current: string, correct: bool, expression: string|null, type: string} $plan
     */
    private function renderPlan(array $plan): string
    {
        $operations = '';
        foreach ($plan['operations'] as $operation) {
            $operations .= \sprintf('<span class="badge operation">%s</span>', $this->escape($operation));
        }
        $expression = null === $plan['expression'] ? '' : \sprintf('<code class="expression">%s</code>', $this->escape($plan['expression']));
        $status = $plan['correct'] ? 'correct' : 'incorrect';
        $search = strtolower(implode(' ', [$plan['template'], $plan['line'], implode(' ', $plan['operations']), $plan['current'], $status, $plan['expression']]));

        return \sprintf(
            '<article class="finding %s" data-status="%s" data-operations="%s" data-search="%s"><div class="finding-head"><span class="badge status">%s</span>%s<span class="current">Current: %s</span><span class="line">Line %d</span></div>%s</article>',
            $status,
            $status,
            $this->escape(implode(' ', $plan['operations'])),
            $this->escape($search),
            ucfirst($status),
            $operations,
            $this->escape($plan['current']),
            $plan['line'],
            $expression,
        );
    }

    /**
     * @param array{template: string, path: string|null, line: int, code: string, message: string, type: string} $diagnostic
     */
    private function renderDiagnostic(array $diagnostic): string
    {
        $search = strtolower(implode(' ', [$diagnostic['template'], $diagnostic['line'], $diagnostic['code'], $diagnostic['message']]));

        return \sprintf(
            '<article class="finding diagnostic" data-status="diagnostic" data-operations="" data-search="%s"><div class="finding-head"><span class="badge status">%s</span><span class="line">Line %d</span></div><p class="message">%s</p></article>',
            $this->escape($search),
            $this->escape($diagnostic['code']),
            $diagnostic['line'],
            $this->escape($diagnostic['message']),
        );
    }

    private function fileUri(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return 'file://'.implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
