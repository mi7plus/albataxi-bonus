
# Improvement verification — 0.2.0

27 October 2026. These checks supplement ACCEPTANCE.md; external launch gates remain explicit.

## Automated evidence

- 87 domain/database assertions: original financial, identity, tenancy and recovery scenarios, plus human percentage conversions, setup blockers, Romanian labels, correction-aware reports, fixed batch membership, token tampering/owner/filter isolation, in-flight transaction coordination, page query boundaries, all report templates and grouping, launch-evidence permissions, and QR decoding to both exact destinations.
- 61 HTTP assertions: all workspaces, new checklist/column controls, readiness/reconciliation permissions, direct CSV/XLSX/PDF and QR generation, server-calculated bonus amounts, expired confirmation UI and server rejection.
- The same 61 HTTP assertions also passed against the extracted upload ZIP with read-only application files served as www-data. The restore comparison included the new launch-evidence table and matched all domain counts/checksums.
- The report configuration workspace used no more than five database queries in the test; it did not query ride history, wallets/ledger, fleet or support. Agency navigation did not execute reconciliation.
- A concurrent PHP process inserted a ride and ledger entries but held its transaction open for 450ms. Report snapshot creation waited for the commit and then included that ride, proving that a later commit cannot appear below an already captured cutoff.
- A report retained identical rows/totals after a new ride and a reversal were posted. A fresh report included the new ride and showed the original earned credit, a negative correction and zero net credit for the reversed ride.
- PHP lint, JavaScript syntax and dependency audit remain required alongside the suites.

## Browser evidence

Checked six driver workspaces (queue, QR, history, reports, help, account) at:
- 320 × 780 phone portrait
- 780 × 320 phone landscape
- 768 × 1024 tablet portrait
- 1024 × 768 tablet landscape

All 24 rendered cases had document scroll width equal to document client width, with contained report-table scrolling and no unlabelled form fields found by the DOM check. Evidence is saved in artifacts/layout-checks.json for this workspace.

Entered 31,99 lei for a synthetic passenger: the preview displayed 1,59 lei. The same fare/bonus remained after switching from phone portrait to tablet landscape, pressing the explicit refresh button, and switching to phone landscape. The passenger code remained attached to the same request.

The 320px agency checklist and the report's original/correction/net preview were also inspected in the browser. Browser console inspection reported no warnings/errors in the checked session.

These are simulated browser viewport checks. They do not prove physical-device keyboard behaviour, screen-reader usability, SMS reception, camera scanning or printed QR readability.

## Real-service evidence

Use Administrator → Pregătire de lansare to:
1. Inspect the current runtime, database, HTTPS configuration, production mode and SMS adapter status.
2. Explicitly request a test SMS to a chosen number when a real gateway is configured. No page visit or CSV upload sends messages automatically.
3. Record the actual hosting plan, test device, operator, result and evidence for each manual gate.

No hosting provider or real SMS credentials were supplied during this change. No paid SMS was sent. Physical-device, printed-QR and real-provider verification must be completed before launch. No cron, worker or retained generated-file directory was added.

