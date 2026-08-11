<?php

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Twig\Extra\ContextualEscaping\Audit;

/**
 * @internal
 *
 * @experimental
 */
final class FindingAssessor
{
    /**
     * @param array{operations: list<string>, current: string, correct: bool, expression?: string|null, plain_variable?: bool, current_safe?: list<string>, provenance?: list<string>} $plan
     *
     * @return array{status: 'proven'|'correct'|'partial'|'review'|'unsafe', label: string, assessments: list<string>, views: list<'action'|'review'|'future'|'no-urgent'>, unavailable: bool, title: string, guidance: string, covered_operations: list<string>, missing_operations: list<string>}
     */
    public function assessPlan(array $plan): array
    {
        $operations = $plan['operations'];
        if (!$operations && ($plan['provenance'] ?? [])) {
            return [
                'status' => 'proven',
                'label' => 'Statically proven safe',
                'assessments' => ['proven'],
                'views' => ['no-urgent'],
                'unavailable' => false,
                'title' => 'No change is needed',
                'guidance' => 'Every possible output comes from template-defined constants and was analyzed directly in its output context.',
                'covered_operations' => [],
                'missing_operations' => [],
            ];
        }

        $outerOperation = $operations[array_key_last($operations)];
        $outerProtected = $this->hasOuterProtection($outerOperation, $plan['current']);
        $coveredOperations = $plan['correct'] ? $operations : ($outerProtected ? [$outerOperation] : []);
        $missingOperations = array_values(array_diff($operations, $coveredOperations));
        $unavailable = [] !== array_intersect($missingOperations, [
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
        $needsUrlTrust = \in_array('UrlSchemeFilter', $missingOperations, true);
        $generatedOutput = 'none' === $plan['current'] && isset($plan['expression']) && str_starts_with(ltrim($plan['expression']), '<');

        if ($plan['correct']) {
            $status = 'correct';
            $label = 'Correct contextual protection';
            $title = 'No change is needed';
            $guidance = 'The current escaping strategy matches the inferred contextual operation.';
        } elseif ($outerProtected && !$missingOperations) {
            $status = 'partial';
            $label = 'Protected today';
            $title = 'Keep the current protection in place';
            $guidance = 'The current strategy protects the output boundary. The inferred contextual operation is more precise, but no urgent change is needed.';
        } elseif ($generatedOutput) {
            $status = 'review';
            $label = 'Generated output needs review';
            $title = 'Check the extension runtime safety contract';
            $guidance = 'A custom lexer or component transformed this source. Confirm that its runtime escapes untrusted values and declares accurate safe-content metadata; do not add a template filter blindly.';
        } elseif ('none' === $plan['current'] && ($plan['current_safe'] ?? [])) {
            $status = 'review';
            $label = 'Declared safe to Twig';
            $title = 'Verify the legacy safe-content contract';
            $guidance = \sprintf('Current Twig considers this expression safe for %s and intentionally applies no escaping. Confirm that this legacy safety declaration is valid in the inferred context.', implode(', ', $plan['current_safe']));
        } elseif ('none' === $plan['current'] && !($plan['plain_variable'] ?? false)) {
            $status = 'review';
            $label = 'Trust declaration needs review';
            $title = 'Review the expression value contract';
            $guidance = 'No escaping strategy or current safe-content declaration was found. Confirm that the expression can only produce context-safe values before changing the template.';
        } else {
            $status = 'unsafe';
            $label = 'Unsafe today';
            $title = 'Add context-appropriate protection';
            $guidance = $outerProtected
                ? 'The current strategy protects the output boundary, but one or more inner contextual protections are still missing.'
                : 'No recognized current strategy protects the enclosing output context. Avoid raw output and add the escaping or validation required by this context.';
        }

        if ($needsUrlTrust) {
            $title = 'Validate the complete URL or declare trusted metadata';
            $guidance = 'For untrusted values, validate and normalize the URL in PHP with a strict scheme allow-list. For a trusted URL-producing callable, declare is_safe: [\'url\']. Do not apply e(\'url\') to a complete URL.';
        } elseif (\in_array('UrlPath', $missingOperations, true) || \in_array('UrlQuery', $missingOperations, true)) {
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
        $views = match ($status) {
            'unsafe' => ['action'],
            'review' => ['review'],
            'correct', 'partial' => ['no-urgent'],
        };
        if ($unavailable) {
            $views[] = 'future';
        }

        return [
            'status' => $status,
            'label' => $label,
            'assessments' => $assessments,
            'views' => $views,
            'unavailable' => $unavailable,
            'title' => $title,
            'guidance' => $guidance,
            'covered_operations' => $coveredOperations,
            'missing_operations' => $missingOperations,
        ];
    }

    public function describeContextReason(string $context): string
    {
        return match ($context) {
            'HTML text' => 'The expression is rendered as HTML text.',
            'JavaScript Code' => 'The expression is rendered as executable JavaScript code.',
            'JavaScript DoubleQuotedString' => 'The expression is inside a double-quoted JavaScript string.',
            'JavaScript SingleQuotedString' => 'The expression is inside a single-quoted JavaScript string.',
            'JavaScript TemplateString' => 'The expression is inside a JavaScript template string.',
            'JavaScript RegExp' => 'The expression is inside a JavaScript regular expression.',
            'CSS Value' => 'The expression is in a CSS declaration value.',
            'CSS DoubleQuotedString' => 'The expression is inside a double-quoted CSS string.',
            'CSS SingleQuotedString' => 'The expression is inside a single-quoted CSS string.',
            'CSS UrlStart' => 'The expression starts a CSS url() value.',
            'CSS UrlUnquoted' => 'The expression is inside an unquoted CSS url() value.',
            'CSS UrlDoubleQuoted' => 'The expression is inside a double-quoted CSS url() value.',
            'CSS UrlSingleQuoted' => 'The expression is inside a single-quoted CSS url() value.',
            'srcset candidate start' => 'The expression starts a new srcset candidate.',
            default => $this->describeAttributeContextReason($context),
        };
    }

    /**
     * @return array{assessment: 'diagnostic-ambiguity'|'diagnostic-error'|'diagnostic-limitation', label: string, title: string, guidance: string}
     */
    public function assessDiagnostic(string $code): array
    {
        if (str_starts_with($code, 'Ambiguous') || str_starts_with($code, 'Incomplete') || 'UnstableLoop' === $code) {
            return [
                'assessment' => 'diagnostic-ambiguity',
                'label' => 'Ambiguous template context',
                'title' => 'Make every possible path end in the same context',
                'guidance' => 'Add static delimiters or restructure the branches so the HTML, CSS, JavaScript, or URL parser state is identical before later output.',
            ];
        }

        if (\in_array($code, ['SyntaxError', 'MismatchedExplicitEscaping'], true)) {
            return [
                'assessment' => 'diagnostic-error',
                'label' => 'Template error',
                'title' => 'Fix the template before contextual analysis',
                'guidance' => 'Correct the syntax, remove deprecated template constructs, or use a supported explicit escaping strategy, then run the audit again.',
            ];
        }

        return [
            'assessment' => 'diagnostic-limitation',
            'label' => 'Analyzer limitation',
            'title' => 'Keep this structure static or provide a supported semantic contract',
            'guidance' => 'The analyzer cannot follow this composition or structural output yet. Prefer static template references and complete static HTML structure; do not add an escaping filter blindly.',
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

    private function describeAttributeContextReason(string $context): string
    {
        if (!preg_match('/^(?:a|an) (double-quoted|single-quoted|unquoted) (.+) attribute$/', $context, $matches)) {
            return 'The expression is rendered in '.$context.'.';
        }

        $attribute = 'unquoted' === $matches[1] ? 'an unquoted HTML attribute' : 'a '.$matches[1].' HTML attribute';

        return match ($matches[2]) {
            'plain HTML' => 'The expression is inside '.$attribute.'.',
            'URL start' => 'The expression starts a URL in '.$attribute.'.',
            'URL path' => 'The expression is in the path of a URL in '.$attribute.'.',
            'URL query or fragment' => 'The expression is in the query or fragment of a URL in '.$attribute.'.',
            default => 'The expression is rendered in '.$context.'.',
        };
    }
}
