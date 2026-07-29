# SolaBooks journal contract v2

SolaStock builds `solastock-journal.v2`; SolaBooks is the authority that accepts
or rejects it. The payload contains immutable client, organization, tenant-local
Finance organization, mapping, signing-key, source, reversal, currency, rate,
transaction/base amount, precision, account, and tax identities.

The event must contain an explicit transaction currency. Missing currency never
defaults to USD or base currency. The cached `finance_currency_contract` on the
immutable integration setting supplies the last Finance-authoritative base
currency, enabled code set, precision, and rounding metadata for construction;
Finance re-resolves every value from its live tenant settings at acceptance.

Amounts are decimal strings and use BCMath. Base equals transaction divided by
the dated rate (`1 base = rate transaction`). Rounding is HALF_UP at Finance
boundaries and a one-minor-unit residue follows Finance’s rounding-account then
largest-line policy.

The raw canonical JSON body is SHA-256 hashed and covered by HMAC. Canonical
headers additionally bind contract version, central client, central organization,
Stock organization, Finance organization, immutable mapping, signing key, source
key, and event type.

The immutable source key is also the idempotency key. A reversal keeps the
original currency, rate, rate date/source, transaction/base amounts, account
set, and tax contract; only its posting date may move to a later Finance-open
date.

`integration:contract-preview --database=tenant_XXXXXX --organization=<id>
--event=<id>` builds a proposal only. It never delivers, changes the event, mints
a nonce, or consumes API usage. Phase 0 delivery and activation holds remain the
first guards.
