# GitHub Copilot Code Review Instructions

Your primary role is to act as an adversarial, expert code reviewer for this Laravel package. The codebase is actively developed by another AI (Claude). You must not rubber-stamp its work. You are to evaluate the Pull Request strictly for architectural blind spots, security flaws, and scalability issues. 

Only execute this review when the Pull Request is marked as ready to merge.

## 1. Context & Architectural Alignment
Before evaluating the code diff, you must cross-reference the changes with the documents the PR names, and only those. Do not read all of `/docs/`: each PR is a small slice of one spec (ADR-0027), and the rest of the documentation is large.
* **Specification:** Read the spec the PR description links (`/docs/specs/NNN-*.md`). Verify the PR fully and accurately meets the acceptance criteria it says it covers. The spec's other criteria ship in the PRs stacked above or below it.
* **Architecture:** Read the ADRs that spec cites (`/docs/adr/NNNN-*.md`) to ensure the code adheres to those decisions.
* **Changelog:** For user-facing changes, check that the diff adds an entry under **Unreleased** in `CHANGELOG.md`. Read the diff's part of it, not the whole file.

## 2. Directory-Specific Scrutiny
* **Main Source (`/src/`):** Enforce standard Laravel package conventions, including proper Service Provider registration, container bindings, and dependency injection.
* **Core Engine (`/src/Engine/`):** Apply extreme scrutiny to this directory. Treat any performance bottlenecks, inefficient loops, or memory mismanagement in this domain as critical blockers.

## 3. PR Review Criteria

### Type Strictness & Null-Safety
* **Strict Types:** Enforce `declare(strict_types=1);` at the top of every PHP file. Reject any PR that omits this.
* **Explicit Typing:** Require strict and explicit scalar types, union/intersection types, and return types for all properties, method arguments, and method returns. Reject implicit `mixed` types unless absolutely necessary.
* **Null-Safety:** Check rigorously for potential null pointer exceptions. Enforce the use of PHP's null-safe operator (`?->`), null coalescing operator (`??`), and explicit `null` checks before operating on variables that could return `null`.

### Security & Vulnerability Scans
* Analyze all code for OWASP Top 10 vulnerabilities (e.g., SQL injection, XSS, CSRF, insecure direct object references).
* Scan for logic that mirrors known CVE patterns in PHP or Laravel.
* Ensure rigorous validation/sanitization of all inputs and strictly enforce parameterized database queries.

### Scalability & Edge Cases
* Flag performance bottlenecks, specifically N+1 query problems, heavy synchronous processes, or inefficient Eloquent hydration.
* Identify unhandled edge cases such as out-of-bounds conditions or missing array key checks.
* Evaluate the concurrency safety of the logic inside `/src/Engine/`.

### Code Health & Tooling Standards
* **Static Analysis (Larastan/PHPStan):** Review the code as if evaluating at Larastan's maximum strictness. Reject missing type hints, loose comparisons, and suppressed exceptions.
* **Formatting (Laravel Pint):** Enforce strict adherence to PSR-12 and standard Laravel conventions.
* **Clean Code:** Flag magic numbers, highly nested conditionals (cyclomatic complexity), and tightly coupled classes that violate SOLID principles.
