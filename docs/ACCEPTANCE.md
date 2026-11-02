# Acceptance evidence and remaining launch gates

Recorded 27 October 2026. Current implementation follows PRD v2.0 and the user's shared-hosting/manual-refresh/direct-download changes.

## Verified locally

| Area | Evidence |
|---|---|
| Financial/domain integration | 87 assertions in tests/run.php against a separate InnoDB MariaDB database; includes correction-aware stable reports, QR round trips and page query boundaries |
| HTTP and role workspaces | 61 assertions in tests/http.php covering all four roles, setup, readiness, expiry, permissions, CSRF, scan/refresh/posting and direct downloads |
| Packaged deployment | The same 61 HTTP assertions passed against the extracted 0.2.0 upload ZIP, served by a separate PHP process as www-data with read-only application files |
| Money | All three PRD examples, exact integer-bani parsing, cap boundary rejection, cross-participant redemption |
| Atomicity | A forced ledger constraint failure after wallet mutation rolled back both wallet and ride |
| Concurrent spending | Two parallel PHP processes posted against the same wallet via different participants: exactly one committed; the other required fresh approval; no negative balance |
| Retry safety | Same-key posting returns the same result; changed input conflicts; duplicate ledger effects prevented |
| Identity | Verification, persisted failed OTP attempts, expiry/single use, password reset revoking old sessions |
| Membership | Non-admin rule rejection, cross-agency manager/driver denial, protected suspension, accepted transfer revoking old QR and preserving historical attribution |
| Onboarding | CSV duplicate/row errors, explicit partial confirmation, resumable/idempotent result |
| Corrections | Linked compensating reversal and spent-credit manual recovery without negative spendable balance |
| Reports/documents | CSV/XLSX/PDF response signatures, attachment headers, formula handling, scoped reports, PNG QR generation and both A4 PDF orientations |
| Administration | Source-account inspection excludes password/session credential material; driver access is denied; statement review/approval transitions checked |
| Reconciliation | No wallet-vs-ledger differences after integration/HTTP scenarios |
| Dependencies/syntax | PHP lint passed; composer audit reported no known security advisories |
| Browser check | 24 driver page/viewport combinations, no page-wide overflow or unlabelled form fields, fare rounding and refresh/rotation preservation, setup and net report previews; details in IMPROVEMENTS.md |
| Persistence | Main application and database containers recreated while keeping volumes; persisted domain snapshot counts/checksums matched |
| Restore rehearsal | Database dump restored into a second isolated MariaDB container; all domain counts/checksums and financial totals matched, including programme/risk/rule/QR/settlement state |

Runtime observed: PHP 8.2.35; MariaDB 11.4.13; Linux amd64; Apache for the development app and a separate unprivileged PHP server for the packaged-release check. Docker Desktop is only the local test harness. The ZIP contains locked vendor dependencies and no real config, database dump, demo credentials, tests or Git metadata.

The local synthetic dump/import/checksum run completed in approximately four seconds. It is not evidence for production recovery time or a provider-to-provider DNS/TLS migration. Source/destination snapshot files and build logs are retained in the ignored artifacts/ directory for this workspace.

## Requirement mapping

| Original requirement | Implementation / qualification |
|---|---|
| FR-01–09 | QR entry, phone/password, driver eligibility, confirmed rides, server calculations, idempotency, passenger approval, wallet and cross-provider credit implemented |
| FR-10–15 | Compensating correction/manual recovery, scoped reporting, separate fund cash/liability, configurable risk signals, support and pause controls implemented |
| FR-16–18 | Stable review redirect, public QR display, print CSS and two PDF orientations implemented; physical scanning/device rotation/usability remains a release gate |
| FR-19 | Superseded by the user's explicit manual-refresh decision. Durable scan history and requests update on page refresh |
| FR-20–22 | Admin-only immutable rule versions, scoped report filters/presets/direct exports, audited administrator source inspection implemented |
| FR-23–26 | No agency/driver count ceilings, paginated/searchable onboarding, CSV previews, explicit invitation acceptance and transfers/suspensions implemented. Invitation distribution is manual link sharing |
| FR-27–28 | Superseded hosting architecture: conventional PHP/MySQL upload, no mandatory container runtime. ZIP/read-only deployment and local database restoration verified; selected real provider and cutover rehearsal still needed |

Financial history is append-only through application commands. The operational database account can still alter data through external SQL tools; restrict and audit operator database access. No privileged SQL trigger installation is required on a shared host.

## Before using real passenger data or programme funds

- Name/approve the operating legal entity, actual participating agencies, passenger terms, privacy/retention/anonymisation policy and support contact. The visible terms page is a pilot placeholder, not approved launch documentation.
- Agree contribution base/timing, reserve policy, accounting documents, fees, exit handling, daily limits, fare bounds, alert thresholds and treatment of spent-credit recovery.
- Configure a real SMS gateway and verify delivery, outages, rate limits, billing and account-recovery usability. No paid external SMS service was contacted during testing.
- Test phone/tablet portrait/landscape workflows from 320px, screen keyboards, keyboard/screen-reader use, rotation preserving input, and actual printed/on-screen QR scanning with representative devices. Narrow browser inspection and generated PDFs do not substitute for those tests.
- Verify the chosen host's PHP extensions, HTTPS cookies, outbound HTTPS, database privileges, memory/time limits, document root and error logging.
- Rehearse actual domain/TLS/DNS migration with a frozen source and one writable destination. Confirm backup service/RPO/RTO; daily backups alone do not meet the original proposed 15-minute RPO.
- Agree and measure capacity on the chosen plan. The original 50,000-driver/million-ride/20-posts-per-second benchmark has not been run. Registration has no product ceiling; physical hosting capacity remains finite.
- Conduct task-based usability tests with passengers, drivers, a manager and an administrator and fix critical failures.

These external gates are not marked complete by local automated tests.

