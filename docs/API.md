# HTTP contract

All routes use index.php query parameters so shared hosts do not need rewrite configuration.

- GET /?page=login, register, verify, recover — phone/password and SMS forms.
- GET /?page=scan&token=OPAQUE — records anonymous Bonus opening, retains driver context through login, creates a verified passenger request.
- GET /?page=review&token=OPAQUE — records link opening and redirects to approved Google URL; no claim that a review was submitted.
- GET /?page=display&token=DISPLAY_TOKEN&orientation=portrait|landscape — public display only.
- GET /?page=qr&token=OPAQUE&kind=bonus|review — QR PNG.
- GET /?page=qr-pdf&token=DISPLAY_TOKEN&orientation=portrait|landscape — direct print-ready PDF.
- GET /?page=invitation&token=OPAQUE; POST same URL — explicit verified ownership/transfer acceptance.
- GET /?page=ride&id=ID — role-scoped preview/history/source evidence.
- GET /?role=passenger|driver|agency|admin&section=...&p=N&search=... — role workspace.
- GET /?page=api&action=participants&search=...&offset=N — scoped participant search, 50 rows.
- GET /?page=api&action=report — report preview, 50 rows.
- GET /?page=api&action=reconcile — administrator-only, explicit wallet/ledger reconciliation.
- GET /?page=download&format=csv|xlsx|pdf — direct authenticated report response.
- GET /?page=api&action=operation&kind=post&key=KEY — own recorded result after timeout.
- GET /?page=api&action=verify&entity=rides&id=ID&offset=N — allowlisted administrator source inspection, audited; no credential/session/OTP tables.
- GET /?page=health — database readiness, no data.

Mutations: POST /?page=api&action=ACTION, JSON or form encoding. Require session cookie and X-CSRF-Token (or form csrf). Verified active account required. Financial commands also require Idempotency-Key. JSON success: {"ok":true,...}. Errors: {"error":"MACHINE_CODE","message":"Romanian next action"} with HTTP 400/401/403/404/409/429/503.

| Action | Inputs | Scope |
|---|---|---|
| ready | token | Verified passenger |
| preview | code, fare | Performing active driver; idempotent |
| approve | ride_id, amount, decision(optional decline) | Ride passenger |
| post | ride_id | Performing active driver; idempotent |
| cancel | ride_id | Passenger or performing driver |
| reverse | ride_id, reason | Administrator; idempotent |
| agency-create | name, funding_terms, review_url?, rule inputs | Administrator |
| independent | name, plate, review_url? | Own pending application |
| rules | participant, earn_bps, cap_bps, contribution_bps, daily_limit, max_fare | Administrator |
| participant-update | participant, review_url?, status?, funding_terms?, reason? | Manager own review URL; admin all fields |
| invite | participant, phone, plate?, role | Own manager/admin; managers cannot invite managers |
| revoke-invitation | id | Own manager/admin |
| membership-update | id, active, reason | Own manager/admin; admin suspension protected |
| vehicle | driver, plate, eligible | Own manager/admin |
| qr-revoke | driver | Own driver/manager/admin |
| import-preview | participant, csv | Own manager/admin |
| import-confirm | id, partial | Original import actor, current participant permission |
| preset | name, report filters | Own account and permitted report |
| controls | earning_paused, redemption_paused | Administrator |
| readiness-check | check, status (pending/passed/failed), notes | Administrator; audited evidence record |
| test-sms | phone | Administrator; explicit test, requires idempotency key; may incur provider charges |
| fund | participant, kind, amount, reference | Administrator; idempotent |
| settlement-create | participant, period_end | Administrator; idempotent |
| settlement-update | id, status, amount?, reference? | Administrator; idempotent |
| support | message, ride_id? | Own accessible ride |
| support-resolve | id, resolution | Administrator |
| risk-resolve | id, reason | Administrator |
| maintenance-cleanup | none | Administrator |

Fare/redemption inputs are decimal strings (e.g. "20,00"). Money in JSON operation responses is integer bani. Never send client-calculated earnings/rates.

Rule commands also accept earn_percent, cap_percent, contribution_percent and max_fare_lei as ordinary decimal strings. These convert exactly to basis points/bani before validation; original integer fields remain available for integrations. When both are present, the human-unit fields take precedence.

Report filters: template, start/end (YYYY-MM-DD, Europe/Bucharest inclusive dates), participant ID, driver membership ID, vehicle, status, sort (date/-date/fare/-fare), group (participant/driver), columns (allowlisted comma-separated keys). Downloads add offset (zero based) and limit (1–5000, default 1000). Metadata labels gross ride totals separately from net ledger/fund adjustments.

Report previews return snapshot, snapshot_at and snapshot_expires. Pass the opaque snapshot value unchanged with every subsequent preview page/download using the same normalized filters. The signature is bound to owner, effective membership scope and filters; tokens expire after one hour. Editing filters, losing scope, changing owner, tampering or expiry requires a fresh preview. The committed financial/audit frontier excludes transactions and corrections committed after capture, including lower-ID commits that were still in flight. Direct database maintenance must run with writes frozen.

Ride reports include earned_adjustment, net_earned, redeemed_adjustment, net_redeemed, net_credit and net_contribution. These describe corrections through the captured moment for the selected ride cohort. Use the ledger report to analyse movements by their actual posting/correction dates. API command statuses remain stable machine codes; report rows and visible workflow states use Romanian labels.

Statuses include INSUFFICIENT_CREDIT, APPROVAL_EXPIRED, BALANCE_CHANGED, PARTICIPANT_SUSPENDED, ASSIGNMENT_CHANGED, DAILY_LIMIT, QR_REVOKED, IDEMPOTENCY_CONFLICT. After a network failure, query operation with the original actor/kind/key and keep that key on retry. Never treat a scan, preview, browser calculation or pending request as awarded credit.


Registration POST accepts required phone/password and the existing terms acceptance, plus optional full_name (maximum 160 characters) and date_of_birth (YYYY-MM-DD, real date, not in the future). Omitted profile fields remain absent/null in account_profiles. Login, registration and OTP flows normalise Romanian +407..., 07..., 7..., 407... and 00407... to one +407... identity; duplicate registration and rate limiting use that identity. Explicit international country codes remain intact.

When using the laptop reverse proxy, TRUSTED_PROXY_HOST=proxy enables client-IP forwarding only from that Docker service's resolved address. Other peers cannot override rate-limit identity through X-Forwarded-For. Direct/shared hosting leaves proxy trust disabled by default.
