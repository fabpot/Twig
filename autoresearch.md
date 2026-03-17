# Autoresearch: Twig lexer tokenization

## Objective
Optimize the time it takes for `Twig\Lexer` to tokenize representative Twig templates.
The workload is steady-state lexing only: repeatedly calling `Lexer::tokenize()` on a mix
of HTML-heavy, expression-heavy, and string/comment/verbatim-heavy templates.

## Metrics
- **Primary**: `lex_ms` (ms, lower is better)
- **Secondary**: correctness via `autoresearch.checks.sh` (`php-cs-fixer`, `phpstan`, `simple-phpunit`)

## How to Run
`./autoresearch.sh` — lints the benchmark and `src/Lexer.php`, then runs `bench_lexer_tokenize.php`, which prints `METRIC lex_ms=...`.

## Files in Scope
- `src/Lexer.php` — lexer hot path under optimization
- `bench_lexer_tokenize.php` — representative lexer benchmark workload
- `autoresearch.sh` — benchmark entrypoint
- `autoresearch.md` — session memory and experiment record
- `autoresearch.ideas.md` — deferred optimization ideas
- `.claude/napkin.md` — persistent repo notes

## Off Limits
- Adding caches of any kind for lexer speed
- Changing parser, compiler, or rendering behavior unless strictly required to preserve lexer semantics
- New dependencies

## Constraints
- Favor algorithmic improvements, fast paths, and bypassing unnecessary work
- Preserve existing lexer behavior and public semantics
- Keep the benchmark honest; do not optimize only for a contrived micro-case
- `autoresearch.checks.sh` must pass before a result can be kept
- If checks fail after a benchmark improvement, fix the breakage instead of abandoning the optimization

## What's Been Tried
- New target as of 2026-03-17: lexer tokenization. Previous render-path experiments are intentionally ignored for this session.
- Initial source review suggests two likely hot spots to validate through experiments:
  - full-template token-start pre-scanning in `tokenize()` / `lexData()`
  - repeated regex and string-allocation work while advancing the cursor inside expressions and text segments
