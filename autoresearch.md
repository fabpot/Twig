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
- **Kept:** replacing bracket tracking `in_array()` / `str_replace()` logic with direct opening/closing bracket dispatch in `checkBrackets()` improved `lex_ms` to 328.872.
- **Kept:** only calling `checkBrackets()` for punctuation tokens that are actual brackets shaved a little more off the hot path, bringing `lex_ms` to 328.418.
- **Kept:** inlining bracket stack handling directly into the punctuation fast path removed another method call and improved `lex_ms` to 319.630.
- Additional dead ends after resuming:
  - deleting the now-redundant fallback number/string/comment branches after the current-character fast paths regressed
  - a `lexData()` empty-text fast path regressed
  - replacing the `?` lookahead `strspn()` with a manual whitespace scan regressed
  - skipping CR normalization when the source contains no `\r` regressed on this workload
  - replacing the punctuation gate with a direct switch-based dispatcher regressed
  - precomputing the `lexData()` token-start count regressed
  - hoisting `lexData()` position arrays or trim markers into locals regressed
  - splitting the operator branch to reuse the no-whitespace fast path for cursor movement regressed
  - skipping line counting in `pushStringToken()` for single-line literals regressed
  - replacing inline-comment regex matching with `strpos("\n")` scanning regressed
  - replacing `lexComment()` / `lexRawData()` cursor concatenation with end-offset arithmetic regressed
  - reusing `REGEX_STRING` capture groups to avoid `substr()` in quoted-literal decoding regressed
  - replacing the punctuation `str_contains()` check with a precomputed lookup table regressed
  - inlining hot `pushState()` / `popState()` call sites regressed
  - replacing `ctype_digit()` with direct ASCII range checks regressed badly
  - replacing `lexData()` `count()` end checks with `isset()` probes regressed
  - calling `strspn()` for `?` lookahead only when the next byte is whitespace still regressed
  - storing operator-start bytes as a string and probing them with `strpos()` regressed badly versus the associative-array lookup
- Current hypotheses worth testing next:
  - reducing repeated small string allocations in cursor advancement and token emission without adding extra branchy guards
  - revisiting `lexData()` with a simpler fast path than the rejected empty-text branch, but avoiding new local aliases and guards
