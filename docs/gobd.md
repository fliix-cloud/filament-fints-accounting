# GoBD readiness

Reviewed: 5 September 2026, [commit 99b2218](https://github.com/fliix-cloud/filament-fints-accounting/tree/99b221895511d8c42c1228b36bd3e9f75c0d3e39).

## Verdict

The current package is **not ready for an unqualified “GoBD-konform” claim**.
Useful controls exist, but evidence preservation, accounting correctness,
authorization, and audit access still have release-blocking gaps.

A defensible, scoped claim is achievable. It requires a tested release **and**
an evidenced operating procedure. Installing an open-source package cannot
guarantee the compliance of every host application or deployment.

This is a repository-wide, risk-based technical review of the ledger, documents,
tax, banking, reconciliation, authorization, storage, audit, exports, migrations,
UI integration, tests, and CI. It is not an exhaustive security audit, legal
opinion, or certification. Production infrastructure and business procedures
were not inspected. The baseline findings describe source-level observations
from 5 September: PHP and Composer were unavailable in that review environment.
Subsequent implementation checks were executed locally; their results and the
latest project-state check are recorded below.

## Legal basis and claim boundary

The reviewed basis is [AO § 146][ao146], [AO § 147][ao147], the
[GoBD text through March 2024][gobd], and the [July 2025 amendment][amendment].
The linked AO handbook does not incorporate that amendment; read them together.

- GoBD Rz. 21 and 23: responsibility and assessment include the taxpayer's
  actual system and procedures, not software alone.
- Rz. 100–111: controls must address access, loss, changes, and historical meaning.
- Rz. 150–154: procedures and their changes need understandable documentation.
- Rz. 179–181: the tax authority does not issue a generally binding software
  approval; third-party test reports do not bind it either.

**Current public wording:** “Provides double-entry bookkeeping, audit-chain
verification, and document-integrity controls. GoBD readiness is under review.”

**Target wording, only after the release gates below pass:**
“Version X provides the technical requirements for GoBD-compliant processing
within the documented scope when deployed and operated according to the
specified requirements.” Publish the scope and evidence alongside the claim;
obtain accounting and legal review of any stronger German marketing wording.

Do not equate GoBD readiness with complete VAT correctness, XRechnung
conformance, DATEV compatibility, or statutory financial statements.

## Implementation progress

Updated: 11 September 2026, implementation at `6287428`. The table below tracks changes after the reviewed
baseline; the detailed findings retain that baseline as their reference.
**No finding is fully closed and the compliance verdict is unchanged.**

### AllowanceCharge parse subset (UBL + CII) — 18 September 2026 (F7)

TEMPORARY SHORT MARKER — full restore pending via file-backed MCP push.
Source of truth: /workspace/f7-allowance-charge/out/gobd-RESTORE.md (81236 chars).
Local unpushed commit: 65cef15d246f2cea88746f16a17c42a60bfffd8b
