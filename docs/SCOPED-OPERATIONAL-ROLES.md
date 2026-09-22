# Scoped operational roles (2026-09-22)

Organization membership, application entry, native actions, and resource assignments remain separate. New presets use new identifiers; legacy assignments and explicit denials are retained. Empty scopes grant no employee/warehouse access.

| Preset | Actions | Scope / exclusions |
|---|---|---|
| HR Manager (`hr_administrator`) | Work-profile updates, attendance recording, leave review, scoped member permission assignment | Explicit units/employees; payroll requires an owner-issued separate grant. The scoped screen edits name, job title and work email; full onboarding and sensitive record workflows remain with existing authorized administration. |
| Unit Manager (`hr_unit_manager`) | Work-profile updates, attendance recording, leave review | Assigned units/employees only; no unit reassignment, payroll, documents or member administration. |
| Attendance Recorder (`hr_attendance_recorder`) | Read attendance and record today's attendance through native rules/audit | Assigned employees/units; names/numbers and attendance fields only. No general employee API, profiles, leave, payroll, reports, exports or downloads. Existing records/history require the existing correction workflow. |
| HR Viewer (`hr_operational_viewer`) | Read work profiles, attendance and leave status | Assigned units/employees; no sensitive fields or mutations. |
| Inventory Manager (`scoped_inventory_manager`) | Receiving, transfers, picking, packing, shipments, reservations, zones/bins, reports/exports | Assigned warehouses; no global catalog edits, adjustments, settings, delegation, integration or quarantine overrides. |
| Warehouse Manager (`warehouse_manager`) | Receiving, transfers, picking, packing, shipments, reservations, zones/bins | Assigned warehouses; same administrative exclusions. |
| Warehouse Operator (`warehouse_operator`) | Receiving, transfers, picking, packing, shipments, reservations | Assigned warehouses; both transfer endpoints required. Receiving requires an approved purchase order and immutable source valuation. |
| Inventory Viewer (`scoped_inventory_viewer`) | Read inventory/catalog and fulfillment | Assigned warehouse quantities; cannot write. Existing `warehouse_user` stays read-only. |

Owners configure HR scope/actions at `/member-management/{centralOrg}/{centralMember}` via Central's authenticated SSO link. Delegated HR administrators can only manage actions and scopes within their own grants and cannot grant payroll. Department employment membership never grants authorization. Payroll grants explicitly disclose organization-wide salary/payslip access. Employee self-service remains separate.

Stock reuses Settings custom roles and warehouse assignments. Presets can be copied into narrower custom roles. A custom key matching a preset never substitutes that preset's broader definition. Inactive custom assignments fail closed.

Finance's standard invitation choices are Manager, Accountant (Member), and Viewer. Fixed Asset Approver remains a native role; existing assignments and pending valid invitations remain valid.

## Rollout

1. Build immutable Stock and HR candidates with independent dependencies.
2. Review per-client dry runs, then apply only the new migration: Stock `inventory:provision-operational-roles CLIENT [--dry-run]`; HR `hr:tenant-migrate-all --tenant=CLIENT --operational-only [--dry-run]`.
3. Activate Stock, then HR, verify native readiness, then deploy Central. Central's canonical manifests reference current deployed Stock/HR releases so fresh provisioning includes the new definitions.
4. Do not rerun the completed Finance tenant repairs.

Preserve every current and rollback release and the 8 GiB capacity gate. Roll back Central before destination apps; retain additive tables/audit records (no down migration or defaults reset). Newly assigned scoped users fail closed under older destination code until the capabilities are restored.

## Verification

Isolated SQLite synthetic users cover two organizations, HR units, warehouse boundaries, fresh denials/revocation, minimal HR serialization, native attendance recording, delegation, receiving valuation, custom role collisions and viewer writes. Central tests exercise all new role keys through invitation acceptance and the established HR SSO / Stock handoff; management links identify the actor separately from the target. Existing Finance application code is unchanged. Authenticated production browser checks require authorized account access and must be reported separately from synthetic HTTP tests.
