# Stage invoice visibility — local implementation

Workflow: owner authorized public Commit/Push/Draft PR, conditional merge and RC;
CI and release results must be verified for the final head before readiness.
Base: `6787ebee21511e4f6fc02cd55bbf1c3693866b7d`.
Branch: `codex/stage-invoice-visibility`; intended PR target: `main`.
Release number: 2.0.246. Entry and active runtime test identity pins advance
together; this document does not claim a completed CI run or official installer.

## Approved behavior and scope

- Preserve the canonical purchase invoice and original invoice code.
- Show issued payment stages as `SYN-100/1`, `SYN-100/2`, with each stage's
  amount and approval state in the seller, team and Finance lists.
- A paid stage does not imply settlement of a purchase that has a balance.
- Search an issued stage alias without returning an unauthorized invoice or
  creating an invoice/stage/payment. Preserve original list filters.
- Preserve the existing 2.0.245 verified gateway Finance approval fix. This task
  does not authorize actual approval of a customer's payment or installation.

Allowed runtime paths: `includes/class-sn-plugin.php`,
`includes/class-sn-payment-transactions.php`, `includes/class-sn-payment-tracking.php`,
`assets/js/payment-transactions.js`, `assets/js/public-seller.js`,
`assets/js/public-manager.js`. Related tests and this module contract are in scope.
Provider calls, migrations, financial writes/repairs, commission, tokens, roles,
stored invoice identities and Production deployment remain outside scope.

## Caller and storage

`ajax_seller_invoices`, `ajax_supervisor_invoices`, `ajax_financial_invoices`
retain login, nonce, capability and existing record scope/pagination. Only the
final scoped page is supplied to `SN_Payment_Transactions::stage_history`.
One query per up to 500 invoice IDs reads explicit stage columns. No token,
authority, proof, raw reference or customer detail is added. Failed reads expose
`available:false`, not a fabricated empty or paid history.

The shared internal `sn_invoice_tracking_search` appends
`SN_Payment_Tracking::stage_condition` to the existing tracking search OR group.
Bound code/number values and correlated invoice ID preserve caller scope. A stage
must exist; an unsuffixed code still follows the existing invoice search.

Seller and team list renderers use `snPaymentTransactions.renderStages`; Finance
uses the same renderer. Native details/summary controls work without a second
request. Codes/references use escaped text and LTR isolation. Stage state labels
remain distinct from invoice settlement and confirmed bank verification.

## Compatibility, tests and rollback

API: additive internal `stage_history:{available,stages}`; existing fields stay.
Each stage has number, display alias, STG reference, requested amount and state.
Database/schema/roles/public token/provider contracts: unchanged. No stored value
is updated by the projection or alias search.

`tests/payments/stage-visibility-native.php` runs only in the guarded disposable
WordPress/MySQL test environment. It exercises actual seller controller auth,
nonce, exact stage searches, absence/cross-owner rejection, amounts, one batched
query, no token exposure and no invoice mutation. It deletes only its newly
created synthetic fixtures. Never run against Production or a customer clone.

`tests/payments/stage-visibility-browser.cjs` runs actual shared rendering and
seller/team list function bodies with mocked transport, blocked external network
and synthetic data at desktop/mobile sizes. This is not full HTTP integration.
Also rerun gateway Finance native approval and gateway/catalog browser regressions.
Full CI including Finance list equivalence, reporting, upgrade and rollback is
required before publication readiness; this new diff has no GitHub CI yet.

Revert the local code to the reviewed base to remove the projections. No data
rollback is needed for this read-only change. A separately performed financial
approval is never reversed by reverting display code.
