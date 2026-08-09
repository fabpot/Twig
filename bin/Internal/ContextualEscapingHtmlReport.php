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
     * @param list<array{template: string, path: string|null, line: int, operations: list<string>, current: string, correct: bool, expression: string|null, plain_variable: bool, current_safe: list<string>}> $plans
     * @param list<array{template: string, path: string|null, line: int, code: string, message: string}>                                                                                                       $diagnostics
     * @param array<string, int>                                                                                                                                                                               $unsupportedNodes
     * @param array{templates: int, output_sites: int, correct_plans: int, incorrect_plans: int, diagnostics: int}                                                                                             $summary
     */
    public function write(array $plans, array $diagnostics, array $unsupportedNodes, array $summary): void
    {
        $entries = [];
        $operations = [];
        $assessmentCounts = ['correct' => 0, 'partial' => 0, 'review' => 0, 'unsafe' => 0, 'unavailable' => 0];
        foreach ($plans as $plan) {
            $assessment = $this->assessPlan($plan);
            $entries[$plan['template']][] = ['type' => 'plan', 'assessment' => $assessment, ...$plan];
            ++$assessmentCounts[$assessment['status']];
            if ($assessment['unavailable']) {
                ++$assessmentCounts['unavailable'];
            }
            foreach ($plan['operations'] as $operation) {
                $operations[$operation] = true;
            }
        }
        foreach ($diagnostics as $diagnostic) {
            $entries[$diagnostic['template']][] = ['type' => 'diagnostic', ...$diagnostic];
        }
        ksort($entries);
        ksort($operations);

        $navigationEntries = [];
        $sections = '';
        foreach ($entries as $template => $templateEntries) {
            $id = 'template-'.substr(hash('sha256', $template), 0, 12);
            $navigationEntries[$template] = [
                'id' => $id,
                'count' => \count($templateEntries),
            ];
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
        $navigation = $this->renderNavigation($navigationEntries);

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
:root { color-scheme: light dark; --bg: #f5f7fb; --panel: #fff; --text: #172033; --muted: #667085; --border: #d9dfeb; --accent: #3769d4; --accent-bg: #eef4ff; --folder: #b7791f; --good: #087443; --good-bg: #e8f8ef; --bad: #b42318; --bad-bg: #fff0ee; --warn: #9a6700; --warn-bg: #fff8db; }
@media (prefers-color-scheme: dark) { :root { --bg: #11151d; --panel: #1a202c; --text: #edf1f7; --muted: #a6b0c3; --border: #374151; --accent: #8bb4ff; --accent-bg: #1b2d4d; --folder: #f0bd66; --good: #6ce9a6; --good-bg: #123a2b; --bad: #ff9b94; --bad-bg: #421d1b; --warn: #ffd56a; --warn-bg: #3b3217; } }
* { box-sizing: border-box; }
body { margin: 0; background: var(--bg); color: var(--text); font: 14px/1.5 system-ui, sans-serif; }
a { color: var(--accent); }
.top { padding: 28px 32px 20px; background: var(--panel); border-bottom: 1px solid var(--border); }
h1 { margin: 0 0 6px; font-size: 28px; }
.meta { color: var(--muted); }
.summary { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 18px; }
.metric { min-width: 130px; padding: 10px 14px; border: 1px solid var(--border); border-radius: 9px; background: var(--bg); }
.metric strong { display: block; font-size: 20px; }
.guide { max-width: 1500px; margin: 20px auto 0; padding: 0 32px; }
.guide > div { padding: 14px 16px; border: 1px solid var(--border); border-radius: 9px; background: var(--panel); }
.guide p { margin: 5px 0 0; color: var(--muted); }
.guide strong { color: var(--text); }
.controls { display: grid; grid-template-columns: minmax(240px, 1fr) 240px 210px; gap: 12px; padding: 16px 32px; position: sticky; top: 0; z-index: 3; background: color-mix(in srgb, var(--panel) 94%, transparent); border-bottom: 1px solid var(--border); backdrop-filter: blur(10px); }
input, select { width: 100%; padding: 9px 11px; border: 1px solid var(--border); border-radius: 7px; background: var(--panel); color: var(--text); }
.layout { display: grid; grid-template-columns: 280px minmax(0, 1fr); gap: 24px; max-width: 1500px; margin: 0 auto; padding: 24px 32px 60px; }
.navigation { position: sticky; top: 86px; max-height: calc(100vh - 110px); overflow: auto; align-self: start; }
.navigation-tools { display: flex; gap: 6px; margin-bottom: 8px; }
.navigation-tools button { flex: 1; padding: 5px 7px; border: 1px solid var(--border); border-radius: 5px; background: var(--panel); color: var(--text); cursor: pointer; }
.navigation-tree a { display: flex; justify-content: space-between; gap: 10px; padding: 5px 7px; color: var(--text); text-decoration: none; border-radius: 5px; }
.navigation-tree a:hover { background: var(--panel); color: var(--accent); }
.navigation-tree details > div { margin-left: 17px; padding-left: 7px; border-left: 1px solid var(--border); }
.navigation-tree summary { display: flex; justify-content: space-between; gap: 8px; padding: 5px 7px; cursor: pointer; font-weight: 650; list-style: none; }
.navigation-tree summary::-webkit-details-marker { display: none; }
.navigation-tree summary:hover { color: var(--accent); }
.navigation-tree .nav-name { display: flex; align-items: center; min-width: 0; gap: 5px; }
.navigation-tree .nav-name span { overflow-wrap: anywhere; }
.navigation-tree .nav-count { color: var(--muted); font-variant-numeric: tabular-nums; }
.tree-icon-sprite { position: absolute; width: 0; height: 0; overflow: hidden; }
.tree-icon { width: 16px; height: 16px; flex: 0 0 16px; }
.tree-chevron { width: 12px; height: 12px; flex-basis: 12px; color: var(--muted); transition: transform .12s ease; }
.tree-folder-closed, .tree-folder-open { color: var(--folder); }
.tree-folder-open { display: none; }
.tree-file { margin-left: 17px; color: var(--muted); }
.nav-directory[open] > summary .tree-chevron { transform: rotate(90deg); }
.nav-directory[open] > summary .tree-folder-closed { display: none; }
.nav-directory[open] > summary .tree-folder-open { display: block; }
.template { margin-bottom: 22px; scroll-margin-top: 90px; }
.template > header { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 9px; }
h2 { margin: 0; font-size: 18px; word-break: break-all; }
.source-link { white-space: nowrap; }
.finding { margin: 8px 0; padding: 13px 15px; border: 1px solid var(--border); border-left-width: 4px; border-radius: 8px; background: var(--panel); }
.finding.correct { border-left-color: var(--good); }
.finding.partial { border-left-color: var(--warn); }
.finding.review { border-left-color: var(--accent); }
.finding.unsafe { border-left-color: var(--bad); }
.finding.diagnostic { border-left-color: var(--warn); }
.finding-head { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 8px; }
.badge { display: inline-block; padding: 2px 7px; border-radius: 999px; font-size: 12px; font-weight: 650; }
.correct .status { color: var(--good); background: var(--good-bg); }
.partial .status { color: var(--warn); background: var(--warn-bg); }
.review .status { color: var(--accent); background: var(--accent-bg); }
.unsafe .status { color: var(--bad); background: var(--bad-bg); }
.diagnostic .status { color: var(--warn); background: var(--warn-bg); }
.operation { background: var(--bg); border: 1px solid var(--border); }
.capability { color: var(--accent); background: var(--accent-bg); }
.plan-label, .current { color: var(--muted); }
.guidance { margin: 0 0 10px; padding: 9px 11px; border-radius: 6px; background: var(--bg); }
.guidance strong { display: block; margin-bottom: 2px; }
.guidance span { color: var(--muted); }
.line { margin-left: auto; color: var(--muted); }
code { font-family: ui-monospace, SFMono-Regular, Consolas, monospace; }
.expression { display: block; padding: 9px 11px; overflow-x: auto; white-space: pre-wrap; overflow-wrap: anywhere; border-radius: 6px; background: var(--bg); }
.source { margin: 0; padding: 10px 0; overflow-x: auto; border-radius: 6px; background: var(--bg); font: 13px/1.55 ui-monospace, SFMono-Regular, Consolas, monospace; }
.source-line { display: grid; grid-template-columns: 52px minmax(max-content, 1fr); min-height: 20px; }
.source-number { padding: 0 10px; color: var(--muted); text-align: right; text-decoration: none; user-select: none; border-right: 1px solid var(--border); }
.source-code { padding: 0 12px; white-space: pre; }
.expression-highlight { padding: 2px 0; color: var(--text); background: #ffe078; border-radius: 3px; }
@media (prefers-color-scheme: dark) { .expression-highlight { background: #745d00; } }
.message { margin: 0 0 9px; }
.limitations { max-width: 1500px; margin: 0 auto 32px; padding: 0 32px; }
.limitations summary { cursor: pointer; font-weight: 700; }
.limitations li { margin: 8px 0; }
.limitations code { display: block; margin-top: 3px; overflow-wrap: anywhere; }
.empty { padding: 40px; text-align: center; color: var(--muted); }
[hidden] { display: none !important; }
@media (max-width: 850px) { .controls { grid-template-columns: 1fr; position: static; } .layout { grid-template-columns: 1fr; padding: 18px; } nav { position: static; max-height: 260px; } .top { padding: 24px 18px; } .guide { padding: 0 18px; } }
</style>
</head>
<body>
<svg class="tree-icon-sprite" aria-hidden="true">
<symbol id="tree-icon-chevron" viewBox="0 0 24 24"><path d="m9 18 6-6-6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
<symbol id="tree-icon-folder" viewBox="0 0 24 24"><path d="M3 6a2 2 0 0 1 2-2h4l2 3h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></symbol>
<symbol id="tree-icon-folder-open" viewBox="0 0 24 24"><path d="M3 7v10a2 2 0 0 0 2 2h14a2 2 0 0 0 1.9-1.4l2-6A2 2 0 0 0 21 9H8a2 2 0 0 0-1.8 1.1L3 17M3 7V6a2 2 0 0 1 2-2h4l2 3h5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
<symbol id="tree-icon-file" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Zm0 0v6h6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
</svg>
<header class="top">
<h1>Twig contextual escaping report</h1>
<div class="meta">Generated {$this->escape($generatedAt)}</div>
<div class="summary">
<div class="metric"><strong>{$summary['templates']}</strong>templates</div>
<div class="metric"><strong>{$summary['output_sites']}</strong>output sites</div>
<div class="metric"><strong>{$assessmentCounts['correct']}</strong>current plan matches</div>
<div class="metric"><strong>{$assessmentCounts['partial']}</strong>outer protection present</div>
<div class="metric"><strong>{$assessmentCounts['review']}</strong>findings to review</div>
<div class="metric"><strong>{$assessmentCounts['unsafe']}</strong>unsafe today</div>
<div class="metric"><strong>{$assessmentCounts['unavailable']}</strong>pipelines unavailable</div>
<div class="metric"><strong>{$summary['diagnostics']}</strong>diagnostics</div>
</div>
</header>
<section class="guide"><div><strong>How to use this report</strong><p>This is a contextual migration assessment, not a list of confirmed vulnerabilities. “Outer HTML protection present” means quoted-attribute breakout is prevented, but inner URL, JavaScript, or CSS handling may still be missing. Findings marked for review may be intrinsically safe expressions or output transformed by a custom lexer or component; check their runtime safety contract separately. “Pipeline unavailable” means current Twig has no built-in filter for every required operation. For a complete URL, validate and normalize untrusted values in PHP, or declare genuinely trusted URL-producing callables as <code>is_safe: ['url']</code>. Never apply <code>e('url')</code> to a complete URL.</p></div></section>
<div class="controls">
<label><span hidden>Search</span><input id="search" type="search" placeholder="Filter templates, expressions, operations..."></label>
<label><span hidden>Assessment</span><select id="status"><option value="">All assessments</option><option value="unsafe">Unsafe today</option><option value="partial">Outer protection present</option><option value="review">Needs review</option><option value="correct">Current plan matches</option><option value="url-trust">URL validation or trusted metadata needed</option><option value="unavailable">Pipeline unavailable</option><option value="diagnostic">Diagnostics</option></select></label>
<label><span hidden>Operation</span><select id="operation"><option value="">All operations</option>{$operationOptions}</select></label>
</div>
<div class="layout">
<aside class="navigation">
<div class="navigation-tools"><button type="button" id="expand-all">Expand all</button><button type="button" id="collapse-all">Collapse all</button></div>
<nav class="navigation-tree" id="navigation">{$navigation}</nav>
</aside>
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
const directories = [...document.querySelectorAll('.nav-directory')];
const empty = document.querySelector('#empty');
function filterReport() {
    const query = search.value.trim().toLowerCase();
    let visible = 0;
    for (const finding of findings) {
        const matches = (!query || finding.dataset.search.includes(query))
            && (!status.value || finding.dataset.assessments.split(' ').includes(status.value))
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
    for (const directory of [...directories].reverse()) directory.hidden = !directory.querySelector('a:not([hidden])');
    if (query || status.value || operation.value) for (const directory of directories) directory.open = !directory.hidden;
    empty.hidden = 0 !== visible;
}
for (const control of [search, status, operation]) control.addEventListener('input', filterReport);
document.querySelector('#expand-all').addEventListener('click', () => directories.forEach((directory) => directory.open = true));
document.querySelector('#collapse-all').addEventListener('click', () => directories.forEach((directory) => directory.open = false));
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
     * @param array<string, array{id: string, count: int}> $entries
     */
    private function renderNavigation(array $entries): string
    {
        $tree = ['count' => 0, 'directories' => [], 'files' => []];
        foreach ($entries as $template => $entry) {
            $parts = explode('/', $template);
            $file = array_pop($parts);
            $tree['count'] += $entry['count'];
            $node = &$tree;
            foreach ($parts as $part) {
                $node['directories'][$part] ??= ['count' => 0, 'directories' => [], 'files' => []];
                $node['directories'][$part]['count'] += $entry['count'];
                $node = &$node['directories'][$part];
            }
            $node['files'][$file] = ['template' => $template, ...$entry];
            unset($node);
        }

        return $this->renderNavigationNode($tree);
    }

    private function renderNavigationNode(array $node): string
    {
        ksort($node['directories']);
        ksort($node['files']);
        $html = '';
        foreach ($node['directories'] as $name => $directory) {
            $html .= \sprintf(
                '<details class="nav-directory"><summary><span class="nav-name">%s%s%s<span>%s</span></span><span class="nav-count">%d</span></summary><div>%s</div></details>',
                $this->renderTreeIcon('chevron', 'tree-chevron'),
                $this->renderTreeIcon('folder', 'tree-folder-closed'),
                $this->renderTreeIcon('folder-open', 'tree-folder-open'),
                $this->escape($name),
                $directory['count'],
                $this->renderNavigationNode($directory),
            );
        }
        foreach ($node['files'] as $name => $file) {
            $html .= \sprintf(
                '<a href="#%s" data-template-link="%s"><span class="nav-name">%s<span>%s</span></span><span class="nav-count">%d</span></a>',
                $file['id'],
                $this->escape($file['template']),
                $this->renderTreeIcon('file', 'tree-file'),
                $this->escape($name),
                $file['count'],
            );
        }

        return $html;
    }

    private function renderTreeIcon(string $name, string $class): string
    {
        return \sprintf('<svg class="tree-icon %s" aria-hidden="true"><use href="#tree-icon-%s"></use></svg>', $class, $name);
    }

    /**
     * @param array{operations: list<string>, current: string, correct: bool, expression?: string|null, plain_variable?: bool, current_safe?: list<string>} $plan
     *
     * @return array{status: 'correct'|'partial'|'review'|'unsafe', label: string, assessments: list<string>, unavailable: bool, title: string, guidance: string}
     */
    private function assessPlan(array $plan): array
    {
        $operations = $plan['operations'];
        $outerOperation = $operations[array_key_last($operations)];
        $outerProtected = $this->hasOuterProtection($outerOperation, $plan['current']);
        $unavailable = !$plan['correct'] && [] !== array_intersect($operations, [
            'HtmlRcdata',
            'JavaScriptValue',
            'JavaScriptTemplateString',
            'JavaScriptRegExp',
            'CssValue',
            'MetaRefreshDelay',
            'SrcsetFilter',
            'UrlSchemeFilter',
            'UrlNormalize',
        ]);
        $needsUrlTrust = \in_array('UrlSchemeFilter', $operations, true);
        $generatedOutput = 'none' === $plan['current'] && isset($plan['expression']) && str_starts_with(ltrim($plan['expression']), '<');

        if ($plan['correct']) {
            $status = 'correct';
            $label = 'Current plan matches';
            $title = 'No change is needed';
            $guidance = 'The current escaping strategy matches the inferred contextual operation.';
        } elseif ($outerProtected) {
            $status = 'partial';
            $label = \in_array($outerOperation, ['HtmlAttribute', 'HtmlAttributeUnquoted'], true) ? 'Outer HTML protection present' : 'Outer protection present';
            $title = 'Review the missing inner operations';
            $guidance = 'The current strategy protects the enclosing output context, but it does not provide every operation in the inferred pipeline.';
        } elseif ($generatedOutput) {
            $status = 'review';
            $label = 'Generated output to review';
            $title = 'Check the extension runtime safety contract';
            $guidance = 'A custom lexer or component transformed this source. Confirm that its runtime escapes untrusted values and declares accurate safe-content metadata; do not add a template filter blindly.';
        } elseif ('none' === $plan['current'] && ($plan['current_safe'] ?? [])) {
            $status = 'review';
            $label = 'Trusted by current Twig';
            $title = 'Verify the legacy safe-content contract';
            $guidance = \sprintf('Current Twig considers this expression safe for %s and intentionally applies no escaping. Confirm that this legacy safety declaration is valid in the inferred context.', implode(', ', $plan['current_safe']));
        } elseif ('none' === $plan['current'] && !($plan['plain_variable'] ?? false)) {
            $status = 'review';
            $label = 'Expression safety to review';
            $title = 'Review the expression value contract';
            $guidance = 'No escaping strategy or current safe-content declaration was found. Confirm that the expression can only produce context-safe values before changing the template.';
        } else {
            $status = 'unsafe';
            $label = 'Unsafe today';
            $title = 'Add context-appropriate protection';
            $guidance = 'No recognized current strategy protects the enclosing output context. Avoid raw output and add the escaping or validation required by this context.';
        }

        if ($needsUrlTrust) {
            $title = 'Validate the complete URL or declare trusted metadata';
            $guidance = 'For untrusted values, validate and normalize the URL in PHP with a strict scheme allow-list. For a trusted URL-producing callable, declare is_safe: [\'url\']. Do not apply e(\'url\') to a complete URL.';
        } elseif (\in_array('UrlPath', $operations, true) || \in_array('UrlQuery', $operations, true)) {
            $title = 'Encode the dynamic URL component';
            $guidance = 'Keep the URL structure static and apply e(\'url\') only to the dynamic path, query, or fragment component. Keep the HTML attribute quoted.';
        } elseif ('partial' === $status && ['HtmlAttribute'] === $operations) {
            $title = 'Keep this HTML attribute quoted';
            $guidance = 'Current html escaping already prevents quoted-attribute breakout. No urgent template change is needed; html_attr is the exact contextual operation.';
        } elseif ($unavailable) {
            $title = 'No direct Twig filter implements this pipeline';
            $guidance = 'Do not mechanically combine existing filters. Validate or serialize the value in trusted application code until Twig provides the contextual operation.';
        }

        $assessments = [$status];
        if ($needsUrlTrust) {
            $assessments[] = 'url-trust';
        }
        if ($unavailable) {
            $assessments[] = 'unavailable';
        }

        return [
            'status' => $status,
            'label' => $label,
            'assessments' => $assessments,
            'unavailable' => $unavailable,
            'title' => $title,
            'guidance' => $guidance,
        ];
    }

    private function hasOuterProtection(string $operation, string $current): bool
    {
        $strategies = explode(' | ', $current);
        $expected = match ($operation) {
            'HtmlText', 'HtmlRcdata' => ['html'],
            'HtmlAttribute' => ['html', 'html_attr', 'html_attr_relaxed'],
            'HtmlAttributeUnquoted' => ['html_attr', 'html_attr_relaxed'],
            'JavaScriptString' => ['js', 'js_string'],
            'JavaScriptTemplateString' => ['js_template'],
            'JavaScriptRegExp' => ['js_regexp'],
            'CssString' => ['css', 'css_string'],
            default => [],
        };

        return [] !== array_intersect($strategies, $expected);
    }

    /**
     * @param array{template: string, path: string|null, line: int, operations: list<string>, current: string, correct: bool, expression: string|null, plain_variable: bool, current_safe: list<string>, type: string, assessment: array{status: 'correct'|'partial'|'review'|'unsafe', label: string, assessments: list<string>, unavailable: bool, title: string, guidance: string}} $plan
     */
    private function renderPlan(array $plan): string
    {
        $operations = '';
        foreach ($plan['operations'] as $operation) {
            $operations .= \sprintf('<span class="badge operation">%s</span>', $this->escape($operation));
        }
        $assessment = $plan['assessment'];
        $capabilities = '';
        if (\in_array('url-trust', $assessment['assessments'], true)) {
            $capabilities .= '<span class="badge capability">URL validation or trusted metadata needed</span>';
        }
        if ($assessment['unavailable']) {
            $capabilities .= '<span class="badge capability">Pipeline unavailable in current Twig</span>';
        }
        $source = $this->renderSourceExcerpt($plan['path'], $plan['line'], $plan['expression']);
        $current = 'none' === $plan['current'] && $plan['current_safe'] ? 'safe for '.implode(', ', $plan['current_safe']) : $plan['current'];
        $search = strtolower(implode(' ', [
            $plan['template'],
            $plan['line'],
            implode(' ', $plan['operations']),
            $current,
            $assessment['label'],
            $assessment['title'],
            $assessment['guidance'],
            $plan['expression'],
        ]));

        return \sprintf(
            '<article class="finding %s" data-assessments="%s" data-operations="%s" data-search="%s"><div class="finding-head"><span class="badge status">%s</span>%s<span class="plan-label">Required future pipeline:</span>%s<span class="current">Current Twig: %s</span><span class="line">Line %d</span></div><p class="guidance"><strong>%s</strong><span>%s</span></p>%s</article>',
            $assessment['status'],
            $this->escape(implode(' ', $assessment['assessments'])),
            $this->escape(implode(' ', $plan['operations'])),
            $this->escape($search),
            $this->escape($assessment['label']),
            $capabilities,
            $operations,
            $this->escape($current),
            $plan['line'],
            $this->escape($assessment['title']),
            $this->escape($assessment['guidance']),
            $source,
        );
    }

    /**
     * @param array{template: string, path: string|null, line: int, code: string, message: string, type: string} $diagnostic
     */
    private function renderDiagnostic(array $diagnostic): string
    {
        $search = strtolower(implode(' ', [$diagnostic['template'], $diagnostic['line'], $diagnostic['code'], $diagnostic['message']]));

        return \sprintf(
            '<article class="finding diagnostic" data-assessments="diagnostic" data-operations="" data-search="%s"><div class="finding-head"><span class="badge status">%s</span><span class="line">Line %d</span></div><p class="message">%s</p>%s</article>',
            $this->escape($search),
            $this->escape($diagnostic['code']),
            $diagnostic['line'],
            $this->escape($diagnostic['message']),
            $this->renderSourceExcerpt($diagnostic['path'], $diagnostic['line'], null),
        );
    }

    private function renderSourceExcerpt(?string $path, int $line, ?string $expression): string
    {
        if (null === $path || !is_file($path) || false === $code = file_get_contents($path)) {
            return null === $expression ? '' : \sprintf('<code class="expression">%s</code>', $this->escape($expression));
        }

        $code = str_replace(["\r\n", "\r"], "\n", $code);
        $lines = explode("\n", $code);
        $target = max(0, min(\count($lines) - 1, $line - 1));
        $starts = [];
        $offset = 0;
        foreach ($lines as $sourceLine) {
            $starts[] = $offset;
            $offset += \strlen($sourceLine) + 1;
        }

        $range = $this->findHighlightRange($code, $lines, $starts, $target, $expression);
        $rangeEndLine = $target;
        foreach ($starts as $index => $start) {
            if ($start >= $range[1]) {
                break;
            }
            $rangeEndLine = $index;
        }
        $first = max(0, $target - 2);
        $last = min(\count($lines) - 1, max($target + 2, $rangeEndLine + 2));
        $uri = $this->fileUri($path);
        $html = '<pre class="source">';
        for ($index = $first; $index <= $last; ++$index) {
            $sourceLine = $lines[$index];
            $start = $starts[$index];
            $end = $start + \strlen($sourceLine);
            $highlightStart = max($start, $range[0]);
            $highlightEnd = min($end, $range[1]);
            if ($highlightStart < $highlightEnd) {
                $before = $this->escape(substr($sourceLine, 0, $highlightStart - $start));
                $highlight = $this->escape(substr($sourceLine, $highlightStart - $start, $highlightEnd - $highlightStart));
                $after = $this->escape(substr($sourceLine, $highlightEnd - $start));
                $sourceLine = $before.'<mark class="expression-highlight">'.$highlight.'</mark>'.$after;
            } else {
                $sourceLine = $this->escape($sourceLine);
            }
            $lineNumber = 1 + $index;
            $html .= \sprintf(
                '<span class="source-line"><a class="source-number" href="%s#L%d">%d</a><span class="source-code">%s</span></span>',
                $this->escape($uri),
                $lineNumber,
                $lineNumber,
                $sourceLine,
            );
        }

        return $html.'</pre>';
    }

    /**
     * @param list<string> $lines
     * @param list<int>    $starts
     *
     * @return array{int, int}
     */
    private function findHighlightRange(string $code, array $lines, array $starts, int $target, ?string $expression): array
    {
        $lineStart = $starts[$target];
        $lineEnd = $lineStart + \strlen($lines[$target]);
        if (null !== $expression && str_starts_with(ltrim($expression), '<twig:')) {
            if (false !== $opening = stripos($code, '<twig:', $lineStart)) {
                if ($opening <= $lineEnd && null !== $closing = $this->findTagClosing($code, $opening + 6)) {
                    return [$opening, $closing + 1];
                }
            }
        }
        if (null !== $expression && str_starts_with($expression, '{{')) {
            if (false !== $exact = strpos($code, $expression, $lineStart)) {
                if ($exact <= $lineEnd) {
                    return [$exact, $exact + \strlen($expression)];
                }
            }
            if (false !== $opening = strpos($code, '{{', $lineStart)) {
                if ($opening <= $lineEnd && null !== $closing = $this->findExpressionClosing($code, $opening + 2)) {
                    return [$opening, $closing + 2];
                }
            }
        }
        if (null !== $expression && false !== $exact = strpos($lines[$target], $expression)) {
            return [$lineStart + $exact, $lineStart + $exact + \strlen($expression)];
        }

        return [$lineStart, $lineEnd];
    }

    private function findTagClosing(string $code, int $offset): ?int
    {
        $quote = null;
        $length = \strlen($code);
        for ($i = $offset; $i < $length; ++$i) {
            $character = $code[$i];
            if (null !== $quote) {
                if ($quote === $character && '\\' !== ($code[$i - 1] ?? null)) {
                    $quote = null;
                }

                continue;
            }
            if ('"' === $character || "'" === $character) {
                $quote = $character;

                continue;
            }
            if ('>' === $character) {
                return $i;
            }
        }

        return null;
    }

    private function findExpressionClosing(string $code, int $offset): ?int
    {
        $delimiters = [];
        $quote = null;
        $escaped = false;
        $length = \strlen($code);
        for ($i = $offset; $i < $length; ++$i) {
            $character = $code[$i];
            if (null !== $quote) {
                if ($escaped) {
                    $escaped = false;
                } elseif ('\\' === $character) {
                    $escaped = true;
                } elseif ($quote === $character) {
                    $quote = null;
                }

                continue;
            }
            if ('"' === $character || "'" === $character) {
                $quote = $character;

                continue;
            }
            if ('}' === $character && '}' === ($code[$i + 1] ?? null) && [] === $delimiters) {
                return $i;
            }
            if (isset(['(' => true, '[' => true, '{' => true][$character])) {
                $delimiters[] = $character;

                continue;
            }
            if (isset([')' => '(', ']' => '[', '}' => '{'][$character]) && end($delimiters) === [')' => '(', ']' => '[', '}' => '{'][$character]) {
                array_pop($delimiters);
            }
        }

        return null;
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
