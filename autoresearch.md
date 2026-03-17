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
- Replacing the full token-start regex pre-scan with repeated `strpos()` scans in `lexData()` regressed on the representative mixed-template benchmark; the current one-shot regex scan is faster here.
- Naively fast-pathing every punctuation character before the operator regex was faster on the benchmark, but it broke overlapping operators like `?.`, `??`, `?:`, `? :`, `..`, and `...`.
- **Kept:** branching on the current expression character to fast-path inline comments, quoted strings, digit-starting numbers, and only unambiguous punctuation before attempting the expensive operator regex improved `lex_ms` from 363.906 to 353.493 while keeping the suite green.
- **Kept:** switching known single-line lexer expression advances (names, numbers, inline comments, and quote delimiters) to cheaper no-newline cursor movement improved `lex_ms` further to 345.982.
- **Kept:** skipping operator whitespace normalization for already single-token operators, and removing the now-redundant operator bracket check after the punctuation fast path, improved `lex_ms` to 342.425.
- **Kept:** precomputing the set of first bytes that can start an operator and skipping the operator regex entirely for other expression tokens improved `lex_ms` to 339.501.
- Current hypotheses worth testing next:
  - reducing repeated small string allocations in cursor advancement and token emission
  - finding safe fast paths in `lexData()` that do not regress the mixed-template benchmark
