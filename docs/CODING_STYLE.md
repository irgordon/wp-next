# Coding Style & Pattern Guardrails

## 1. General Style Conventions
<!-- [PLACEHOLDER: Add language-specific formatting guidelines, naming conventions, and linting tools (e.g., Ruff, Prettier, Clippy, ESLint) below.] -->

* **Formatting:** Maintain standard automated formatting. Run project formatters prior to final delivery.
* **Naming:** Use clear, intention-revealing names. State variants must be nouns or adjectives representing the specific condition, not generic flags.
* **Boundary Validation:** Prefer schema validation packages (e.g., Zod, Pydantic, Serde) at system edges over ad-hoc regex checks.

---

## 2. Autonomous Task Instruction & Behavioral Guardrails

Inject the prompt below into autonomous coding tasks, system instructions, or pull-request review bots to enforce the architectural standard defined in `/docs/AGENTS.md`.

```text
================================================================================
AUTONOMOUS TASK INSTRUCTION: ARCHITECTURAL COMPLIANCE
================================================================================
You are operating under the rules defined in /docs/AGENTS.md and /docs/OPERATING_DOCTRINE.md.
Your output must strictly comply with the architectural boundaries and error 
semantics established in those documents.

RULE OF EVALUATION: Reject the behavior, not merely the syntax. Do not treat 
banned patterns as mechanical token matches; evaluate whether the underlying 
architectural invariant is respected or violated.

CRITICAL INVARIANTS:
1. Parse uncertainty once at the perimeter. Never re-validate or sprinkle 
   defensive type/null checks across trusted internal domain logic.
2. Model states explicitly via Enums or Tagged Unions. Never use multiple 
   boolean flags for lifecycle tracking.
3. Preserve full error provenance. Lower layers propagate; recovery boundaries 
   handle. Never discard the underlying cause, code, or stack trace.
4. Distinguish expected failures (propagate as typed errors) from invariant 
   violations (terminate the unit of work immediately).
5. Never invent speculative fallbacks (default empty objects, dummy strings, or 
   silent recovery) unless explicitly codified in the contract.

================================================================================
BEHAVIORAL GUARDRAILS (PROHIBITED VS. PERMITTED)
================================================================================

[BANNED]  Inventing empty/default values to conceal an unexpected absence.
[ALLOWED] Returning an empty collection when the domain contract explicitly 
          specifies that "missing input means empty collection."

[BANNED]  Loose/unvalidated payload types inside trusted domain logic.
[ALLOWED] Loose boundary representations (e.g., Record<string, unknown>, any, 
          raw dicts) used only as input to immediate parsing.

[BANNED]  Broad catch blocks that convert unknown failures into success, 
          fallback data, or continued execution.
[ALLOWED] Boundary-level catch blocks that preserve the cause and terminate, 
          translate, or escalate the failed unit of work.

[BANNED]  Stripping error provenance (e.g., throwing a generic string or new 
          error that discards the original exception, code, or trace).
[ALLOWED] Wrapping errors into domain-specific types while preserving the causal 
          chain (e.g., throw new OperationError("Operation failed", { cause: err })).

[BANNED]  Performing related multi-resource updates without an atomic transaction 
          or equivalent consistency mechanism.
[ALLOWED] Enforcing consistency via database transactions, Compare-And-Swap (CAS), 
          transactional outboxes, or compensating sagas.

[BANNED]  Speculative recovery (inventing fallbacks, alternative resources, or 
          retries on the fly without contract authorization).
[ALLOWED] Explicit, contracted recovery policies implemented at the designated 
          recovery boundary.
================================================================================
```
