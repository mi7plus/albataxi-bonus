# Shared-hosting deployment and migration

## Hosting contract

Choose a plan with PHP 8.2 or later, MySQL 8.0+ or MariaDB 10.6+ (InnoDB), HTTPS, and outbound HTTPS. Enable PDO MySQL, mbstring, GD, DOM, XML, XMLReader/Writer, SimpleXML, ZIP, Fileinfo and iconv in the hosting panel. Use 128MB PHP memory minimum; 256MB is recommended for PDF/XLSX. If the host has a small execution limit, reduce report file batches below the default 1000 rows.

No cron, worker, Docker, SSH, Composer installation, URL rewriting or writable application data directory is required at the destination. PHP and document libraries may use the host's ordinary system temporary area while generating a response; the programme does not retain generated documents.

Verified locally: PHP 8.2.35, MariaDB 11.4.13, Apache, Linux amd64 containers on Docker Desktop. PHP 8.2+ and the database versions above are the intended compatibility floor; additional provider/version combinations need a deployment check.

## Prepare a release locally

1. Install dependencies using the committed composer.lock (composer install --no-dev --prefer-dist). Docker builds install them automatically.
2. Run the automated checks and Composer audit.
3. Run php bin/package.php. It assembles dist/albataxi-shared-hosting.zip with application, vendor dependencies, public assets, schema and deployment documentation. Real configuration, tests, Git and development data are excluded.
4. Unzip on the destination. Store the application folder outside public_html. Deploy only the contents of public/ into the web root. Alternatively set the domain's document root to the release's public/ directory.

Typical layout:

~~~
/home/account/albataxi/app/
/home/account/albataxi/vendor/
/home/account/albataxi/config.local.php
/home/account/albataxi/database/
/home/account/public_html/index.php
/home/account/public_html/assets/
/home/account/public_html/app-path.php
~~~

If using separate public_html, copy public/app-path.example.php to public_html/app-path.php and return the absolute application directory. This is deployment configuration. Query-based routes support a domain root or configured subdirectory. Never put the whole repository or vendor directory inside public_html.

## Configure

Copy config.example.php to config.local.php outside the document root. Fill in base_url (the permanent QR domain), DSN, database credentials, environment=production and a random app_key. Generate a key locally with php -r "echo bin2hex(random_bytes(32));". Keep file permissions restricted to the hosting account. Do not use development credentials or the development SMS inbox in production.

For the selected SMSLink provider, follow [SMSLink setup](SMSLINK.md): set sms_backend=smslink with smslink_connection_id and smslink_password. Keep smslink_sender blank until a branded sender is approved. Enable allow_url_fopen and outbound HTTPS. No intermediary gateway is needed.

Alternatively, configure sms_backend=http and the HTTPS generic gateway URL/token. That adapter sends:

~~~
POST configured HTTPS endpoint
Authorization: Bearer configured-token
Content-Type: application/json

{"to":"+40722123456","message":"AlbaTaxi Bonus: cod ..."}
~~~

Require a 2xx response. Provide a provider adapter/gateway matching this contract; no commercial SMS account is provisioned by this repository. Confirm allow_url_fopen/outbound HTTPS is enabled; a cURL-only host needs an adapter.

Import database/*.sql in ascending filename order with phpMyAdmin. If CLI is available, use php bin/console.php migrate instead. Use a database account scoped to this database; do not grant global administrator rights.

For the initial administrator, either:
- CLI: set ALBA_ADMIN_PASSWORD temporarily and run php bin/console.php admin +407... .
- Without server CLI: locally run bin/admin-sql.php with ALBA_ADMIN_PASSWORD set and the intended phone, then import the generated SQL with phpMyAdmin.

The SQL contains an adaptive password hash, not a plaintext password. Keep the generated SQL out of public_html; remove the local transfer copy after importing. There is no web installer, public administrator registration or default production password. Admins bootstrap verified; all later invited/registered users verify ownership through SMS.

## Verify before opening registrations

- Health: /?page=health should return ready.
- Login and verification/recovery SMS work over HTTPS; Secure cookies require HTTPS.
- Register a test passenger, invite manager and driver, accept, assign vehicle, configure rules/fund terms, activate participant and driver.
- Open Bonus QR, log in as passenger, refresh driver's queue, confirm an isolated test ride, approve a redemption, and verify both ledger and fund.
- Test both QRs and both print orientations; update a review destination without changing the Bonus/review QR.
- Run scoped preview and CSV/XLSX/PDF downloads, verify another agency cannot access the records.
- Reconcile and document/compensate all test financial entries before real use.
- Confirm error logs do not disclose passwords/OTPs. Application failures have correlation references.
- Set host-side backups/monitoring from the control panel. App health/reconciliation remain available; no scheduled task is added by this application.

## Backups and moving provider

Use the provider's database backup/download or phpMyAdmin SQL export. Backups are operator-controlled, outside application features. The database contains all accounts, sessions, rules, QR identifiers, balances, ledger, fund, presets, support and audit state. There are no generated programme files to copy.

1. Provision compatible destination PHP/database, HTTPS and the same approved application release.
2. Rehearse a database export/import into a separate isolated destination. Compare table counts and financial reconciliation. Do not upgrade the database during the move.
3. For final cutover, set maintenance=true on the source configuration. The router returns 503 before creating sessions or processing scans, registrations or account/security changes; only the health probe remains available. Ensure the source is frozen before the final export.
4. Export the final consistent database and import at the destination. Transfer config securely and update connection details. Keep the existing programme domain and public QR paths/tokens.
5. Compare ledger/wallet sums, fund totals, statement totals and table counts. With CLI, php bin/console.php reconcile and php bin/snapshot.php help. Without CLI, use administrator Audit and source-record views plus database queries below.
6. Change DNS/routing only after destination verification. Verify TLS, public QRs, login, refresh, posting and exports.
7. Keep the source frozen. Resume destination writes only after checks pass. Before new writes, rollback can return to the frozen source. After new writes, rollback requires transferring the new database state; never restore an old source and discard transactions.

Financial queries for phpMyAdmin:

~~~sql
SELECT w.passenger_id,w.balance,COALESCE(SUM(l.amount),0) ledger
FROM wallets w LEFT JOIN ledger l ON l.passenger_id=w.passenger_id
GROUP BY w.passenger_id,w.balance
HAVING w.balance<>COALESCE(SUM(l.amount),0);

SELECT kind,SUM(amount) FROM fund_entries GROUP BY kind;
SELECT SUM(balance) FROM wallets;
SELECT participant_id,status,SUM(paid) FROM settlements GROUP BY participant_id,status;
~~~

The original proposed 15-minute RPO / four-hour RTO requires a host backup/PITR service and a timed restore exercise. Ordinary daily shared-host backups do not meet it automatically. Document the chosen provider's actual recovery capability before launch.

## Updates and troubleshooting

Back up the database, freeze writes for schema/financial changes, deploy a new immutable release folder, import reviewed new migrations, switch the entry path atomically, run health/reconciliation, then resume. Do not roll back application code across incompatible schema changes without a reviewed recovery plan.

- Blank/error page: check PHP version/extensions and the host error log using the correlation ID.
- Database errors: verify DSN, credentials, database privileges and InnoDB.
- Login loops: verify base_url/HTTPS and production Secure cookie settings.
- SMS failures: check provider configuration and outbound HTTPS; existing authenticated sessions remain usable.
- PDF/XLSX timeout: narrow dates or reduce the explicit batch size.
- Expired code/approval: create a fresh request/preview. A timeout after financial posting must be recovered using the original idempotency key.
- To inspect/remove expired temporary database rows, use administrator Account/Settings → Curățare la cerere.

## Upgrade from 0.1.0 to 0.2.0

Back up the database and enable maintenance while replacing application files. Apply database/003_acceptance_checks.sql (or run the migration command), preserve config.local.php and app_key, then run health, login, report preview/download and reconciliation checks. This migration adds only the administrator's launch-evidence register; no file directory or scheduler is required.

Use Administrator → Pregătire de lansare to inspect the active PHP/database/HTTPS/SMS configuration and record manual test evidence. A test SMS is sent only after an administrator supplies a number and explicitly requests it. A successful gateway response does not certify reception.

Report previews fix an owner/scoped/filter-bound committed cutoff for one hour. Keep that cutoff across download batches; a new preview intentionally includes later transactions/corrections. Replacing app_key invalidates sessions and report cutoffs. No generated report is retained on the server.


## Upgrade to 0.3.0

Apply database/004_qr_designs.sql and deploy all assets, including the locally bundled fonts. See [QR design guide](QR-DESIGN.md) for visual editing and print verification. No new storage directory or scheduled process is required.

## Upgrade to 0.4.0

Apply database/005_qr_templates.sql. Existing individual layouts become pending proposals; only approved templates are published. The hierarchy is programme default, approved agency, approved driver. See [QR template guide](QR-DESIGN.md).

## Upgrade to 0.4.1

Apply database/006_account_profiles.sql to add the optional registration profile table. Existing accounts require no changes. Registration now accepts optional full name and birth date, and Romanian phone formats are normalised before matching. No scheduler or file storage is needed.

## Laptop hosting alternative

Version 0.5.0 adds a separate Windows/Docker Desktop production deployment. See [Laptop installation and recovery](LAPTOP.md). It uses persistent database/certificate volumes, Caddy HTTPS, Windows scheduled startup/backups, encrypted external-drive archives and a restore workflow. This does not add a scheduler or file-storage requirement to shared-hosting deployments.

## SMS optional mode (0.5.2)

Use sms_backend=disabled until the SMS API is acquired. No database migration is needed; existing verification flags remain unchanged. Once an SMS backend is enabled, unverified accounts must complete verification. Provider errors never bypass it. See SMSLINK.md for assisted recovery and rollout. Automated development OTP tests require SMS_BACKEND=database; CI sets this explicitly.
