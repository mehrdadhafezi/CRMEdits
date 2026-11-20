# 2.0.240 — unpaid gateway request / manual receipt

Fixes the dead end where an unpaid Zibal request indefinitely prevented receipt
capture. Internal payment management offers **انتخاب واریز دستی**, followed by an
explicit confirmation and fresh inquiry. Successful inquiry alone does not choose
a method. After selection, use **ادامه و ثبت فیش**; Finance still reviews the receipt.

The customer must stop using the previous bank request. This local choice is not
bank cancellation, refund or payment approval. If payment nevertheless arrives
late, its bank reference is retained for financial reconciliation, without another
invoice settlement or wallet credit. Unknown, paid or failed inquiry results do
not grant a manual switch. Terminal cancellation/refund inquiry behavior remains.

Gateway intents are labelled separately from manual transfers. They no longer
appear as a pending receipt with a missing attachment. A local request is never
expired solely because it is older than the reuse window.

No schema migration or role/capability change. Existing HR invoice reissue and
installed reporting features remain. Existing invoice/payment InnoDB tables are
required for atomic method selection; the UI reports a safe failure otherwise.

Do not blindly downgrade while `manual_hold` / `reconcile_error` requests exist.
Keep their history and bank references; coordinate a forward fix/reconciliation.
No Production installation, gateway call or data repair is performed by this task.
Release readiness requires current exact-revision CI and a verified original RC
artifact, plus target compatibility and a valid backup. Private test archives are
not official release artifacts. See [task](tasks/GATEWAY_MANUAL_PAYMENT_240.md).
