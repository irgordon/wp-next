# Repository Operating Doctrine

## 1. Authority Hierarchy
All engineering workflows, whether executed by human contributors or autonomous agents, are governed by a strict hierarchy of authority:

1. **Platform, Security, and Operator Directives**
   Runtime safety constraints, active security policies, and explicit human operator instructions always take absolute precedence.
2. **`/docs/AGENTS.md` (Canonical Architectural Standard)**
   Within the repository, `/docs/AGENTS.md` is the canonical architectural standard and supersedes lower-level repository guidance such as inline comments, local conventions, and third-party examples. It does not override higher-priority platform, security, or operator instructions.
3. **`/docs/CODING_STYLE.md`**
   Governs concrete syntax, formatting conventions, language idioms, and prompt-level guardrails that implement `AGENTS.md`.
4. **Repository Architecture Decision Records (ADRs)**
   Context-specific choices for frameworks, database engines, and protocols.

## 2. Agent Operational Rules
When generating, modifying, or reviewing code in this repository, autonomous agents must adhere to the following doctrine:

* **Strict Non-Compliance Rejection:** Do not accept or merge changes that violate the Pattern Enforcement Matrix in `AGENTS.md`. Reject shallow defensive programming, unvalidated domain data, and silent error masking.
* **Scope Discipline & Defensive Slop:** Agents must identify defensive slop encountered in the directly modified path. Remove it only when necessary for the requested change or when doing so is contained and behavior-preserving. Otherwise report it without expanding scope.
* **Verification Mandate:** Code is not complete when it merely passes happy-path tests. The agent must verify that boundaries reject malformed input cleanly, that expected errors propagate with typed clarity, and that invariant violations terminate the unit of work with full error provenance intact.
