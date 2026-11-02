# AlbaTaxi Bonus — current implementation specification

Version 2.1 · 27 October 2026

This document replaces conflicting architecture and delivery requirements in SOURCE-PRD.md, following the user's subsequent instructions in this chat. The original is historical input, not the active deployment specification.

## Product

Shared promotional credit in RON for verified passengers on eligible taxi rides in Alba Iulia. A passenger scans a driver's Bonus code, signs in, and appears in that driver's queue. The driver refreshes the page, selects the matching passenger, enters the fare, reviews the calculation and deliberately confirms the completed ride. Scans never award credit.

Credit earned with one active participant can be used with another. No cash deposits, withdrawals, passenger transfers, booking, dispatch, tracking or meter replacement.

## Hosting and runtime

- PHP 8.2+; PDO MySQL; MySQL 8.0+ or MariaDB 10.6+ with InnoDB and foreign keys.
- Common PHP extensions for QR/PDF/XLSX: mbstring, GD, DOM, XML, XMLReader, XMLWriter, SimpleXML, ZIP, Fileinfo, iconv.
- HTTPS and outbound HTTPS access to the selected SMS gateway.
- PHP source/configuration outside the document root; only the public entry point and assets are public.
- Database-backed sessions, account/security state, requests, scan history, presets, audit and finance.
- Driver updates use explicit refresh. No live connection, websocket, SSE, browser polling, webhook service, daemon, queue worker or cron job.
- Reports and QR documents download directly in the requesting authenticated response; generated documents are not retained by the application.
- Report downloads contain explicit batch boundaries and totals for the full filtered period. Default 1000 rows, up to 5000 per file. These are per-request resource bounds, not registration ceilings.
- Preview fixes a signed report moment for one hour, bound to owner, scope and filters. Subsequent batches use the same committed transaction set. Gross amounts, corrections and net results are displayed together; changing filters requires a new preview.
- Expiry is checked on access. Administrators can explicitly remove expired sessions and OTP rows from Account/Settings.
- Docker Desktop packages a local PHP/Apache and MariaDB test environment. Production is a conventional shared-hosting upload.
- Portability is conditional on the published runtime contract. No application can guarantee support on plans that do not expose its required runtime and database capabilities.

## Roles and permissions

One phone/account can carry more than one role. Passenger access is always own account. Driver access is own operating membership, requests and rides, with minimum matching information. Managers administer only their active agency: own staff, invitations, vehicles, reports, review destination and statement review. Administrators configure programme participants, rules, controls, audit, corrections, financial movements and statements.

Only administrators may publish reward/cap/contribution rule versions or global controls. Managers cannot create administrators or undo an administrator-imposed suspension. Initial administrator creation is an offline/CLI administrative step. Public registration creates passenger accounts only.

Administrators configure pairing, repeated-fare, rapid-confirmation and daily-award alert thresholds. Alerts open review records without claiming proof of fraud. Fare bounds, daily ride limits and suspensions enforce eligibility server-side.

Independent driver applications remain pending until the administrator configures rules, records funding terms, approves the vehicle and activates participation/membership.

No product-imposed limit on agencies, drivers or accepted memberships; lists/search and exports paginate. No deployment or schema change is needed to add participants.

## Authentication

All roles use canonical international phone plus password. SMS verifies phone ownership and supports password reset; it is not routine passwordless login. OTPs expire in five minutes, have at most five attempts, and are single-use. Requests and login attempts are throttled by phone and source IP.

Use PHP's adaptive password hashing and random server-side session tokens. Only token digests are stored; sessions have expiry and revocation versions. Password recovery invalidates prior sessions. Cookies are HttpOnly, SameSite=Lax and Secure in production. Mutations require CSRF. Terms acceptance is recorded; marketing is optional.

## Membership and QR lifecycle

Administrators create agencies and invite their managers. Managers accept with a verified account. The administrator records funding terms and rules and activates the agency. Accepted driver memberships require a verified phone and eligible assigned vehicle before activation.

Single-add and CSV import create invitations, never shared passwords. CSV uses preview, validation, explicit all-valid or partial confirmation and stored row outcomes. Conflicting existing memberships require explicit individual transfer invitations. Invitations expire or can be revoked. Inviting does not automatically send SMS.

Participant cards show a guided checklist of manager, rules, funding, participation, accepted drivers, vehicles and active driver membership. Missing requirements link to the matching controls. Review setup is optional and does not block readiness. Percentage and fare inputs use ordinary percent and lei units.

Accepting a transfer closes the previous operating membership, revokes its public/display QR identifiers and cancels uncommitted requests/approvals. Old rides retain original participant, driver, plate and rule attribution.

Bonus and Google Reviews identifiers resolve through the stable programme domain. Review destinations are validated Google HTTPS URLs and can change without reprinting. Reviews are optional and unrelated to rewards. Missing review configuration produces a setup status instead of a misleading QR.

Public display pages contain programme/participant/driver public identity and QRs, no account navigation or passenger information. Display links are revocable. Codes use quiet zones and PDF images exceed the proposed 35mm print minimum.

## Requests and financial state

Ready requests: ready → claimed → completed, cancelled or time-expired. Matching codes are short-lived and single-use for a confirmation. Same passenger/driver can reuse a still-current request; different passengers remain separate. Expired approvals never permit posting.

Ride previews bind passenger, driver, participant, plate, exact fare and immutable rule version for five minutes. Fare/provider/passenger changes require cancelling and creating a new preview. Rule changes affect new previews; suspension overrides existing previews immediately.

Passengers select and approve the exact redemption on their own authenticated device. Approval binds the preview and wallet balance. Drivers cannot submit a redemption amount. If the balance changes before posting, fresh passenger approval is required. Credit earned by this ride cannot finance this ride.

Financial mutations require actor/operation-scoped idempotency keys. Same key and inputs returns the original result; changed inputs conflict. Clients preserve keys after timeout and can query the recorded operation.

Posting atomically updates wallet, immutable ledger additions, reimbursement/contribution obligations, ride/request state, activity and audit. Lock the wallet row to prevent cross-participant overspending; lock participant/membership to serialize suspensions and assignment changes with posting. Retry bounded database deadlocks.

## Money and rules

All amounts are integer bani; rates are integer basis points. Never calculate financial amounts from floating point request values. Round down earnings and caps to whole bani.

- Maximum credit = min(current balance, floor(fare × cap_bps / 10000)).
- Non-credit payment = fare − approved credit.
- New credit = floor(non-credit payment × earn_bps / 10000).
- Contribution due = floor(non-credit payment × contribution_bps / 10000).

Initial configurable proposals: earn 500 bps, cap 3000 bps, contribution 500 bps, five rewarded rides/day and max fare 100000 bani. These values are not evidence of agreed commercial terms. Store the actual rule ID with each ride.

At 5% / 30%: 20.00 fare, zero starting balance → 1.00 earned. 40.00 fare, 10.00 balance/used → 30.00 payable, 1.50 earned/final balance. 20.00 fare, 10.00 balance, 6.00 used → 14.00 payable, 0.70 earned, 4.70 final balance.

## Corrections and fund

No balance-edit endpoint and no deletion of financial records. Reversal creates reasoned, actor-attributed compensating ledger/fund entries linked to the original ride. If credit was already spent and reversal would create a negative balance, create a manual-recovery case without changing the spendable balance.

Keep promotional credit liability distinct from actual fund cash. Show contributions due and received, reimbursements due and paid, and adjustments separately. Settlement preparation claims previously unallocated fund entries up to a cutoff, snapshots totals, and leaves subsequent corrections for a later statement. Statements progress draft → under review → approved → partially paid / paid. Record actual payment references manually; do not initiate bank transfers.

Reconcile every wallet projection against summed ledger entries. Fund cash equals actual receipts minus actual payments. The ride activity reports show original gross amounts; the ledger and fund reports show net adjustments. Administrators can drill to original records and source rule versions.

## Reporting and UI

Romanian interface, RON, Europe/Bucharest local presentation/report bounds; UTC database timestamps. Passenger wallet/history/help/account, driver queue/QR/history/reports/account/help, agency overview/staff/vehicles/reports/fund, administrator participants/transactions/fund/reports/audit/support.

Forms preserve input on errors; financial confirmations are explicit buttons. Driver fare input uses numeric keyboard hints. Requests show masked phone suffix, matching code and arrival timestamp. All driver features must work on phones/tablets and both orientations from 320 CSS pixels, with contained scrolling only for wide tables.

Driver fare drafts are tied to account/request and retained in the tab for 15 minutes. Fare entry shows a rounded bonus estimate; review shows the server-calculated amount. Expired confirmations have no posting control. Administrator reconciliation is an explicit action, not part of routine page loading. Workspace queries load only the records needed for the current screen.

Templates: rides/earnings (gross), redemptions (gross), ledger/corrections, driver activity, participant statement, fund reconciliation, audit. Label filters, columns, sorting, grouping and saved presets. Preview totals before export. Generate CSV/XLSX/PDF from the same scoped query and filters; include timestamp, timezone and batch boundaries. Escape formula-like CSV cells and use explicit string XLSX cells.

Exports are authenticated direct responses with no saved public or private download links. Administrators can inspect allowlisted source tables through a paginated audited endpoint. Authentication hashes, session/OTP secrets are excluded.

Administrator launch-readiness diagnostics and an audited manual evidence register help verify hosting, real SMS, devices, QR scanning, restoration and approved documents. An explicit SMS test can be requested to a chosen number; a configured adapter or successful HTTP response alone does not prove reception. Manual evidence remains unverified until actually tested.

## Release evidence

Automated checks cover calculations, atomic posting, idempotency, concurrent spending, tenant isolation, bound rules, suspension, transfers, corrections, CSV onboarding, OTP attempts, reports and document generation. Exact observed test results and outstanding human/provider checks belong in ACCEPTANCE.md.

Before real launch: approve programme/fund documents, privacy/retention, risk/recovery ownership, SMS provider contract, physical QR readability, representative usability, real hosting configuration, backup restoration and an agreed capacity target. Manual refresh intentionally replaces the original two-second notification SLA and connected-session benchmark.


## QR template approval hierarchy (0.4.0)

Administrators edit and publish the programme default. Agency templates require explicit administrator approval before applying to inheriting drivers. Driver templates require approval by their active agency manager; independent drivers use administrator approval. Pending and rejected proposals do not replace approved layouts. Portrait and landscape are independent. Reviews are tied to exact revisions, changes are audited, and authorised users can return to the approved parent. See QR-DESIGN.md for behaviour and migration.
