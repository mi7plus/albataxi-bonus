# AlbaTaxi Bonus

Romanian mobile web loyalty programme for Alba Iulia. PHP 8.2+, MySQL 8.0+ / MariaDB 10.6+, ordinary request/response pages. Driver scan queues update only when the driver refreshes.

**The current specification is [docs/PRD.md](docs/PRD.md).** It incorporates the user's later decisions: shared hosting, manual refresh, direct downloads, no cron requirement, and no application-managed persistent files. The supplied document is retained as historical input in [docs/SOURCE-PRD.md](docs/SOURCE-PRD.md).

## Run locally with Docker Desktop

Docker is a local convenience, not a hosting requirement.

~~~sh
docker compose up -d --build
docker compose exec web php bin/console.php migrate
docker compose exec -e ALBA_DEMO_PASSWORD=CHOOSE_A_LOCAL_DEMO_PASSWORD web php bin/seed-demo.php
~~~

Open **http://localhost:8097**. Demo accounts:

| Workspace | Phone |
|---|---|
| Administrator | +40700000001 |
| Agency manager | +40700000002 |
| Driver | +40700000003 |
| Passenger | +40700000004 |

The password is the value you supply to the seed command. Seeding only runs with APP_ENV=development. No production administrator or production password is created by migration.

SMS confirmation is disabled by default: registration and login work with phone/password alone. To simulate verification in development/tests, set SMS_BACKEND=database before docker compose up; the simulator stores synthetic messages in the database. Read them using:

~~~sh
docker compose exec web php bin/console.php dev-sms
~~~

SMS is optional in production. Keep SMS_BACKEND=disabled until an API is acquired. When ready, enable the SMSLink adapter; follow [SMSLink setup](docs/SMSLINK.md) to configure the Gateway Connection ID and password. The generic HTTPS gateway remains available. The development inbox is never served over HTTP.

## Included

- Phone/password login, verification and password recovery, database sessions, CSRF and account/IP throttling.
- Passenger balances and history, driver-specific scan requests and manual matching code.
- Agency/independent onboarding, invitations, CSV preview/partial confirmation, suspension, explicit accepted transfers, vehicles.
- Administrator-only, versioned earn/cap/contribution rules; controls for pausing earnings/redemptions.
- Transactional integer-bani ledger, programme-wide wallets, passenger-approved redemption, idempotency, wallet locks, compensating reversals and manual recovery flags.
- Durable scan/review/ready activity shown on refresh; scans never post rides.
- Public display-only dual-QR pages, A4 portrait/landscape printing and PDF downloads.
- Scoped report filters, columns, grouping, presets, preview and direct CSV/XLSX/PDF downloads.
- Fund receipts/payments, statements with review/approval/payment lifecycle, reconciliation, support, audit and administrator source-record inspection.
- Shared-hosting deployment/runbook, locked dependencies, ZIP packaging, local Docker setup and integration checks.

## Tests

Use a separate test database; tests refuse to run unless its name ends in _test.

~~~sh
docker compose exec db mariadb -uroot -plocal-root-development-only -e "CREATE DATABASE albataxi_test; GRANT ALL ON albataxi_test.* TO 'alba'@'%';"
docker compose exec -e SMS_BACKEND=database -e DB_DSN="mysql:host=db;dbname=albataxi_test;charset=utf8mb4" web php tests/run.php
docker compose exec -e ALBA_DEMO_PASSWORD=YOUR_LOCAL_DEMO_PASSWORD web php tests/http.php http://web/
docker compose exec web composer audit
docker compose exec web php bin/console.php reconcile
~~~

Database credentials in Compose are exclusively local development credentials. Never use Compose defaults in production.

## Deploy / move hosting

See [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md). Upload an assembled release containing vendor/; the target host does not need Composer, SSH, Node.js, Python, Docker, or URL rewriting. Only public/ belongs in the web document root. Configuration and source live outside it. There is no writable application data directory.

Generate an upload package after installing locked dependencies:

~~~sh
php bin/package.php
~~~

## Before a real pilot

[docs/ACCEPTANCE.md](docs/ACCEPTANCE.md) separates tested behaviour from outstanding release gates. Real SMS-provider delivery, physical-device/printed-QR testing, stakeholder-approved terms/funding, production host verification, and capacity/recovery targets require validation. Shared hosting does not imply the original large-scale benchmark will fit on a low-cost account.


## Improvements delivered in 0.2.0

- Agency/independent setup checklist with required/optional steps, progress, blocked-step explanations and direct links to the relevant participant, staff and vehicle controls.
- Ordinary percentage and lei inputs; conversion to integer basis points/bani remains authoritative on the server.
- Driver matching emphasis, exact bonus estimate while entering a fare, an exact server calculation on review, explicit expiry states and passenger re-identification.
- Fare drafts keyed to the passenger request and driver account, retained for 15 minutes in the browser tab and restored after refresh; phone/tablet rotation does not reset input.
- Romanian workflow states and report column checkboxes.
- Gross, correction and net amounts shown together. Ride reports describe the selected ride cohort and corrections through the fixed report moment; the ledger report describes dated financial movements.
- Signed, owner/scope/filter-bound report cutoffs, expiring after one hour. A short database coordination lock waits for in-flight financial/audit commits before capturing monotonic entry cutoffs. Subsequent files use the same committed set, without retaining a generated file or starting a job.
- Workspace queries are conditional on the current role/screen. Full reconciliation runs only when an administrator requests it.
- Administrator launch-readiness screen with runtime diagnostics, an explicit SMS test action, and reasoned, audited records for manual hosting/device/QR/restore evidence. Diagnostics do not certify a real SMS delivery or another provider.
- View rendering is buffered so a template failure returns a proper error page/status instead of a partial successful response.

Upgrade existing installations by applying database/003_acceptance_checks.sql, then replacing application files. Preserve config.local.php and app_key. Changing app_key expires both sessions and signed report cutoffs.



Visual QR layouts: open Atelier QR to move/resize demo QR blocks, edit text, typography and colors, and save independent portrait/landscape designs. See [QR design guide](docs/QR-DESIGN.md). Upgrade requires database/004_qr_designs.sql.

QR templates now inherit programme → agency → driver. Agency proposals need admin approval; driver proposals need agency approval. Pending/rejected edits keep the approved layout live. Apply migration 005 when upgrading.

Registration keeps phone/password required and adds optional full name/date of birth. Romanian mobile numbers work with or without +4/+40, with one canonical account identity. Upgrade with migration 006.

## Windows laptop hosting

For a personal Windows laptop with a public domain, use Laptop-Install.cmd from the laptop deployment package. Start/Stop/Status/Backup/Restore buttons, persistent data, automatic recovery after Windows sign-in and encrypted daily external-drive backups are included. See [Laptop installation and recovery](docs/LAPTOP.md). Build the separate package with pwsh -File bin/package-laptop.ps1. The original shared-hosting deployment remains available.
