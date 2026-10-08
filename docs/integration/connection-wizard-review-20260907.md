# Connection wizard review contract

The connection assistant uses existing records, not account age or subscription name, to select the fresh-workspace or existing-business path. It checks Finance catalog/documents/journals and Stock catalog, balances, ledger, operational documents and outbox records. Zero quantities alone do not establish a fresh workspace.

Finance account candidates are organization-owned, active, postable and compatible with the role's canonical account types. Existing valid custom mappings take precedence. Finance's configured defaults are proposals until explicitly saved in the draft. Other recommendations require the canonical role key or account code; a name containing “clearing” does not establish transfer-clearing purpose. Legacy candidate identities remain stable so existing draft selections can be revalidated without rewriting them.

A proposal, a saved valid selection and accounting approval are separate states. Incomplete/unresolved decisions do not count as complete. Status and preview use the same validity checks. Approval binds the selected account identity and decision revision, with separate explicit owner and accounting review. A missing Finance base currency prevents approval. No wizard UI action releases historical outbox events.

Unused configured currencies are explicitly excluded from initial connection scope. Existing authoritative Stock warehouses need no redundant Finance recreation. Existing documents and historical exclusions remain reviewable. Finance unit references resolve through Finance's inventory_units catalog, independently of Stock unit IDs. Existing catalog records remain on the existing-business path even at zero balance.

The account table uses a searchable portaled selector with real account codes, names and types. English/Arabic labels distinguish missing accounts, ambiguity, invalid mappings, proposals and saved choices. Queries and local wizard state are scoped to the current organization. Background failures retain the last rendered state and offer retry; failed saves remain visibly unconfirmed.

## Release scope

No Production migration, chart change, subscription change, operational mapping, activation or historical replay is part of this repair. Existing immutable SPSB b0010 remains unchanged: schema, provisioning artifacts, dependency locks and approved bundle contents are unchanged. The only SQL-schema additions are minimal disposable test-fixture definitions in scripts/rebuild-test-db.sh, which retains its isolated-Staging guards.

Organization 139 was inspected read-only: the checked Finance inventory/invoice/bill/journal tables and Stock inventory/operational/outbox tables contained no organization records. Its 118 active postable Finance accounts include 11 verified configured defaults. GRNI, transfer clearing and rounding have no canonical verified default proposal and require an explicit accounting choice. Standard Finance chart initialization does not automatically create these integration-specific defaults; no failed new-tenant initialization was demonstrated. Any proposed new account requires a separate reviewed, duplicate-safe chart change, not a silent wizard write.

Production contained two unfrozen drafts at inspection. Organization 3's 24 existing selections remain valid under candidate evaluation; organization 139 had no saved selections. No draft, mapping or chart was modified during these checks.
