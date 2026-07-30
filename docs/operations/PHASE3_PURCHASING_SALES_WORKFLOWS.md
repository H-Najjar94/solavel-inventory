# Phase 3 purchasing and sales workflow contract

Phase 3 is deployed behind the Phase 0 safety hold. It prepares and validates
new workflow records only. It does not deliver an event, replay history, start a
worker, or repair an existing record.

## Ownership matrix

| Lifecycle document | Operational owner | Quantity/value effect | Finance effect |
|---|---|---|---|
| Purchase order | SolaStock | None | None |
| Goods receipt | SolaStock | Increase quantity and receipt valuation | Dr Inventory / Cr GRNI only |
| Supplier bill | SolaBooks | None | Dr GRNI, Dr recoverable input tax, Cr AP |
| Supplier return | SolaStock | Reverse original receipt valuation layers | Dr GRNI / Cr Inventory only |
| Supplier debit note | SolaBooks | None | Dr AP / Cr GRNI and input-tax reversal |
| Sales order | SolaStock | None | None |
| Reservation/pick/pack | SolaStock | Availability only | None |
| Shipment | SolaStock | Reduce quantity using original FIFO/average layers | Dr COGS / Cr Inventory only |
| Customer invoice | SolaBooks | None | Dr AR / Cr Revenue / Cr output tax |
| Sales return | SolaStock | Restore original shipment valuation layers | Dr Inventory / Cr COGS only |
| Customer credit note | SolaBooks | None | Dr returns/revenue and output tax / Cr AR |
| Landed-cost allocation | SolaStock | Adjust authoritative valuation | Dr Inventory / Cr landed-cost clearing |
| Landed-cost supplier document | SolaBooks | None | Dr landed-cost clearing/tax / Cr AP |

The owning document may reverse only its own row in this matrix. Voiding an
invoice never restores stock; reversing a shipment never reverses AR, revenue,
or tax.

## Immutable document identity

`integration_document_lifecycle_mappings` uses a UUID independent of numbers,
names, SKU values, and local IDs. It retains client, central organization,
tenant database, both organization IDs, source/destination application and
document identities, document version, parent/original/reversal UUIDs,
quantities, currency/rate, accounting source key, journal ID, matching state,
and safe audit metadata.

One immutable identity is allowed for each application document within an
organization mapping. `integration_document_lifecycle_links` provides
many-to-many document edges, so partial and multiple receipts, bills,
shipments, and invoices never overwrite one another. Reusing a link with a
different allocated quantity is a conflict. Identity columns cannot be
changed. Rows cannot be deleted. Every change is hash-audited in
`integration_document_lifecycle_audits`.

The mapping snapshot includes ordered/reserved/received/billed/shipped/
invoiced/returned quantities; transaction and base currency/rate; transaction
and base subtotal/tax/total; and the SolaStock valuation effect.

Supported canonical document types are:

`purchase_order`, `goods_receipt`, `supplier_bill`, `supplier_return`,
`supplier_debit_note`, `sales_order`, `reservation`, `pick_list`, `pack`,
`shipment`, `customer_invoice`, `sales_return`, `customer_credit_note`, and
`landed_cost`.

## Validation and matching

Before a connected workflow mutates operational state, the validator requires
the verified organization mapping, authoritative dated currency, and active
stable mappings for every item, unit, warehouse, customer/supplier, tax, and
account role. Missing or ambiguous mappings return
`mapping_review_required`; nothing is guessed.

Quantity comparisons use the lifecycle totals. A future execution endpoint
must reject over-receipt, over-billing, over-shipment, over-invoicing, or
over-return unless an explicit organization policy is signed into the workflow
contract. Price, quantity, tax, currency, exchange-rate, and rounding
differences remain explicit matching exceptions; they are never silently
posted.

The source key and mapping UUID are idempotent. Reusing either identity with
different financial content is a conflict. Reversals require the original
mapping and accounting source key and preserve the original currency, rate,
accounts, taxes, values, and valuation-layer identity.

## Read-only diagnostics

SolaStock:

```bash
php artisan integration:workflow-preview \
  --database=<tenant-db> \
  --organization=<solastock-org-id> \
  --document-type=<canonical-type> \
  --document-id=<id>
```

The preview reports the operational source, related Finance document, quantity
and valuation effects, journal template, transaction/base currency, tax and
subledger ownership, match state, missing mappings, errors, and reversal
behavior. Mutation counters must all remain zero.

Landed-cost allocation can be validated without creating an allocation:

```bash
php artisan integration:workflow-preview \
  --database=<tenant-db> \
  --organization=<solastock-org-id> \
  --landed-cost-total=<amount> \
  --landed-cost-allocated=<amount> \
  --allocation-method=quantity|weight|value
```

It reports the remaining allocation and ownership split (SolaStock valuation;
SolaBooks supplier/expense document and clearing) and rejects over-allocation
or unsupported methods without mutating a cost layer or legacy Finance table.

## Holds and rollback

Phase 3 does not change these flags:

- `SOLABOOKS_DELIVERY_ENABLED=false`
- Finance receiver disabled
- normal activation disabled
- historical repair and pending replay disabled
- legacy contract disabled
- connected Finance legacy inventory writes blocked
- no worker or scheduler

Rollback disables Phase 3 readers and workflow generation by deploying the
previous application release. The additive mapping/audit tables are retained;
their migrations deliberately have no destructive `down()` operation.
