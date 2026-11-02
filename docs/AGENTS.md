# AGENTS.md: Architectural Directives for Autonomous Coding

## 1. Core Philosophy
Your primary goal as an autonomous coding agent is to write code where **failures are visible, contained, and easily recoverable**. 

Do not engage in shallow defensive programming. Do not attempt to patch symptoms, mask unexpected states, or guess intent when data is malformed. You must establish strict boundaries, enforce invariants, and design the system so that when it breaks, it breaks clearly and cleanly.

## 2. Boundary Enforcement: Parse and Establish Invariants Once
Untrusted data (user input, external APIs, tool payloads) must be transformed into strict domain models immediately at the system perimeter.

* **Strict Perimeter:** Use validation libraries to parse data into strictly typed objects. Reject malformed payloads immediately at the entry point with clear error context. Do not repeatedly revalidate them inside the trusted core.
* **Trust the Core:** Validate uncertainty at the boundary. Inside the boundary, program against known invariants. Once data passes the perimeter and becomes a domain object, do not perform redundant null checks, type checks, or defensive assertions within internal business logic. Assume the invariant holds.
* **Boundary Revalidation:** Data crossing a trust boundary again must be parsed again. "Trust the Core" applies only while the value remains inside the domain and invariant guarantees remain intact.
* **No Loose Types in Core Logic:** Loose, unvalidated payload types (`any`, `object`, raw dicts, `Record<string, unknown>`) are permitted only at the outer boundary as raw inputs to validation. They must never penetrate into internal domain services.

## 3. State Management: Make Illegal States Unrepresentable
Leverage the type system to restrict the search space of valid programs and eliminate classes of logical hallucinations.

* **Type-Level Constraints:** Represent invalid states in the type system whenever the language permits it. Do not rely on runtime conventions or documentation when a stronger type can inherently enforce the invariant.
* **Avoid Boolean Soup:** Never use multiple independent boolean flags to represent a lifecycle or state machine (e.g., `is_active`, `is_pending`, `is_error`).
* **Use Algebraic Data Types:** Use Tagged Unions, Sum Types, or Enums to represent mutually exclusive states. Bind required data directly to the corresponding state variant (e.g., a `Failed` variant holds the error descriptor, ensuring it cannot be accessed when in a `Running` variant).

## 4. Error Handling: Distinguish, Contain, and Route
Silent state corruption is fatal in autonomous loops. You must preserve stack traces and pinpoint violated invariants without indiscriminately crashing the host process.

* **Expected Errors vs. Invariant Violations:**
  * **Expected Errors:** (e.g., network timeouts, 404s, optimistic concurrency conflicts, invalid client data) must be modeled and propagated explicitly via the type system (e.g., typed Result/Either types or specific domain exceptions).
  * **Invariant Violations:** (e.g., an impossible state reached, corrupt internal representation, missing required fields *after* boundary parsing) must fail loudly.
* **Terminate the Unit of Work:** If an internal invariant is violated, terminate the current unit of work immediately with preserved diagnostic context. Do not continue with corrupted or guessed state.
* **Recovery Ownership:** Recovery belongs at the boundary that has enough context to recover correctly. Lower layers must propagate failures rather than guessing how callers want them handled.
* **Error Provenance:** Never replace a lower-level error with a generic message that discards the original cause, stack trace, error code, or relevant operation context. When wrapping errors, explicitly chain the source cause.
* **No Speculative Recovery:** Do not invent fallback values, alternate resources, retries, or recovery strategies unless the domain contract explicitly permits them.

## 5. Blast-Radius Containment and Recoverability
Design operations so that retries and asynchronous failures do not destabilize the host system or corrupt persistent state.

* **Explicit Retry Semantics:** Every operation that may be retried must either be idempotent, protected by an idempotency mechanism, or explicitly marked non-retryable.
* **Atomic Consistency Mechanisms:** Operations updating multiple resources must use an atomic transaction or an equivalent consistency mechanism (e.g., Compare-And-Swap, transactional outbox, or compensating saga). Never leave orphan records or partially updated state.
* **Process & Task Isolation:** Failures in independently recoverable work units must remain contained to that unit. Use workers, tasks, processes, actors, or orchestrator boundaries appropriate to the system architecture.

## 6. The Conceptual Pipeline
Maintain this mental model of data flow and boundary enforcement:

```text
UNTRUSTED INPUT
      ↓
parse + establish invariants
      ↓
TRUSTED DOMAIN
      ↓
simple core logic
      ↓
explicit state transitions
      ↓
expected failure → typed propagation
      ↓
invariant failure → terminate unit
      ↓
recovery boundary
      ↓
retry / compensate / escalate
```

## 7. Pattern Enforcement Matrix

| Anti-Pattern (Reject) | Enforced Pattern (Accept) |
| :--- | :--- |
| Sprinkling defensive checks (`if (x == null)`) across internal logic | Parsing at the perimeter; trusting the domain type inside |
| Using multiple independent boolean flags to track lifecycle | Modeling status with explicit state Enums or Tagged Unions |
| Inventing empty/default values to conceal an unexpected absence | Propagating a typed expected error or failing the unit of work |
| Swallowing unknown errors in broad catch blocks to keep running | Catching at recovery boundaries to preserve cause and terminate work |
| Stripping error provenance with generic messages | Preserving error provenance (e.g., wrapping with `{ cause: err }`) |
| Performing related multi-resource updates without consistency guards | Enforcing atomic transactions, CAS, outboxes, or compensating actions |
| Speculative recovery (inventing fallbacks on the fly) | Failing explicitly unless fallback logic is codified in the contract |
