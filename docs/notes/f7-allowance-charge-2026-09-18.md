### AllowanceCharge parse subset (UBL + CII) — 18 September 2026 (F7)

Pending merge into `docs/gobd.md` after restoring that file from a truncated write on this branch.

[UblEInvoiceParser](../src/Documents/UblEInvoiceParser.php) and
[ZugferdEInvoiceAdapter](../src/Documents/ZugferdEInvoiceAdapter.php) now detect
line- and document-level `AllowanceCharge` nodes. The supported subset keeps the
line net already reflected in `LineExtensionAmount` / CII line summation, attaches
structured metadata (`charge_indicator`, `amount_minor`, reason/reasonCode, percent),
and stores reconciling document-level metadata on the parse result /
`e_invoice_meta` when the document net still equals the sum of line nets (zero net
effect). Unknown structures (for example `BaseAmount`), non-reconciling amounts, and
document-level allowances that would require separate posting lines **fail closed**
with a clear `DocumentException`. This is F7 progress, not a claim that F7 or full
EN 16931 allowance/charge conformance is closed. No FX support was added.
Intake archive-before-parse is unchanged. Unit fixtures under
[tests/Fixtures/ubl](../tests/Fixtures/ubl) and
[UblAllowanceChargeTest](../tests/Documents/UblAllowanceChargeTest.php) cover the
supported and fail-closed cases; please run the full suite locally or in CI before
merge.
