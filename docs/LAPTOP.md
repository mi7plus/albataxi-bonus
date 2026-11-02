# Run AlbaTaxi on a Windows laptop

This is a separate production deployment. The original localhost development setup and shared-hosting package remain available. Laptop data uses its own persistent Docker volume; development/demo data is not automatically copied into production.

## First installation

1. Install [Docker Desktop for Windows](https://docs.docker.com/desktop/setup/install/windows-install/) with the WSL 2/Linux containers backend. Complete any WSL/restart prompts. Open Docker Desktop once and enable **Start Docker Desktop when you sign in**.
2. Extract `albataxi-laptop.zip` into a permanent folder such as `C:\AlbaTaxi`. Keep the folder there: scheduled tasks refer to its path. Connect the external hard drive and assign it a stable drive letter in Windows Disk Management.
3. Double-click **Laptop-Install.cmd**. Accept bonus.albataxi.net or enter another public domain, enter the certificate contact email, select the external drive in the picker, then enter the administrator phone/password and a separate backup recovery password. Keep that recovery password somewhere safe outside this laptop; it is required on a replacement laptop or Windows account.
4. The installer builds the app, creates the database and administrator, registers startup/daily-backup tasks and starts the site. If Windows denies task registration, run Install.cmd as administrator using the same Windows account. Rerunning an incomplete install preserves the generated configuration and resumes setup.
5. SMS is optional: phone/password registration works immediately with the default SMS_BACKEND=disabled. Once the API is acquired, configure SMSLink in `.laptop\sms.env` with SMS_BACKEND=smslink, SMSLINK_CONNECTION_ID and SMSLINK_PASSWORD. Keep the sender blank until approved. Run Start.cmd to apply the changed environment. The public app uses production mode; it has no demo passwords or development SMS inbox.
6. Use Status.cmd and the administrator launch-readiness page to verify the host, HTTPS and real SMS delivery before giving the domain to passengers.

Docker, PHP dependencies and the images need internet access for the initial build or a rebuild on a new laptop. No PHP/MySQL installation is needed on Windows itself. Installation asks for secrets interactively; generated state lives in the ignored `.laptop` folder. Do not upload it or include it in Git.

## Domain and network

Point the domain's A record to your router's **public** IPv4 address, not the laptop's private 192.168/10.x address. Reserve the laptop's LAN address in the router, forward inbound TCP **80 and 443** to it, and allow those ports through the Windows firewall for Docker. Do not forward the database port or Docker API. Remove an unused AAAA record unless IPv6 is configured too. If the ISP uses CGNAT or blocks incoming ports, direct domain-to-IP hosting will not work until you arrange an inbound-reachable connection. A changing public address needs updated DNS; a static public IP is simpler.

Caddy automatically obtains and renews HTTPS certificates. Public DNS and reachable ports 80/443 are required for its normal certificate setup. Certificates use persistent Docker volumes. The database is not published on a host port. A loopback-only health endpoint is available on http://localhost:8098/?page=health; the actual app must be used through its HTTPS domain because production cookies are Secure.

## Daily use

Open the `laptop` folder for these buttons:

| Button | What it does |
|---|---|
| Start.cmd | Starts Docker Desktop if needed, waits for the database/app, starts the site, and catches up a backup if overdue |
| Stop.cmd | Stops the public site deliberately; leaves the database and daily backups running |
| Status.cmd | Shows containers, paused state, drive connection, last successful backup and scheduled-task state |
| Backup.cmd | Makes an immediate encrypted recovery archive on the external drive |
| Choose-Backup-Drive.cmd | Selects a backup disk and creates AlbaTaxi-Backups automatically |
| Restore.cmd | Verifies/decrypts an archive and restores matching source, configuration and database to a fresh volume |

The Windows tasks are **AlbaTaxi-Start** (at sign-in) and **AlbaTaxi-Backup** (daily at 03:00 Windows local time). Missed runs catch up when Windows can execute them. Failed daily backups retry hourly, up to 24 attempts. They run as the Windows user that owns Docker Desktop; the user must be signed in. Logon startup performs an overdue-backup check as well. No application cron is introduced.

Backups verify a marker in the configured external-drive folder. A missing/wrong drive makes the job fail visibly instead of writing elsewhere. Check Status.cmd regularly; if a drive is unplugged or full, an at-least-daily backup cannot be guaranteed until it is available. The most recent failure is recorded in `.laptop\backup-error.txt`. Completed backups are timestamped `.albabak` files; `.partial` files are not completed backups. Existing successful archives are not automatically deleted. Monitor free space and keep selected older backups elsewhere before manually removing old archives.

## What happens after a power outage

Ordinary restart does **not** restore a daily backup. Docker keeps the database in its persistent volume, and MariaDB performs crash recovery of committed transactions. The deployment uses InnoDB flush-at-commit and graceful stop settings; containers have restart policies. Normal restart uses the existing app key, accounts, balances, history and QR templates.

1. Restore power and turn the laptop on if necessary.
2. Sign in to the installation's Windows account.
3. The startup task launches/waits for Docker Desktop and resumes the existing website. Start.cmd is the manual fallback.
4. Run Status.cmd and check the public site. A deliberately stopped site stays stopped until Start.cmd is used.

Docker Desktop on Windows is a **sign-in-based** setup, not a guarantee of boot-time hosting before login. This installer does not enable Windows automatic login or bypass a disk-encryption PIN. For unattended recovery, the machine must be able to boot and sign in by an arrangement you control; alternatively use a Linux server service deployment. BIOS options such as restoring power automatically are hardware-specific. The app cannot power a switched-off laptop on. Keep AC sleep/hibernation and lid-close sleep disabled while hosting, and account for Windows Update restarts. Laptop battery and suitable power protection reduce interruption risk; storage/hardware failure can still require backup recovery.

## Restore after disk failure or accidental data loss

On the same laptop or a replacement Windows laptop:

1. Install/open Docker Desktop; extract the laptop package to its permanent folder.
2. Connect the external drive and run laptop/Restore.cmd. Choose a completed `.albabak` and enter its recovery password. Type RESTORE when asked.
3. The archive's authentication tag and every manifest hash are verified before changing the deployment. A wrong password or modified archive is rejected. Source files and the original app key/configuration are restored with the database. The database is imported into a **new volume**, leaving the old volume untouched. Existing environment settings are retained in `.laptop\before-restore-*.env` for recovery diagnostics.
4. Reconciliation and runtime checks must pass. Restore leaves publication paused; run Start.cmd when ready, then check login, balances, reports, QR pages and HTTPS. The latest version of the restore scripts remains included in each backup's source folder.
5. Run Backup.cmd to create a fresh recovery point. If the laptop/public IP changed, update DNS/router forwarding. Certificates are preserved during ordinary restarts; on a fresh laptop they are reissued by Caddy, so DNS/connectivity must work.

The recovery archive contains a transaction-consistent compressed database dump, app key/database/SMS configuration, backup settings, source code and checksums. It is encrypted with AES-256-CBC and authenticated with HMAC-SHA256 using separate keys derived via PBKDF2-HMAC-SHA256. The automatic job's recovery password is stored locally using Windows CurrentUser DPAPI; the portable archive itself can be decrypted with the recovery password on another Windows account. Protect that password and keep it separately from the backup drive.

Restoring a daily backup necessarily loses changes made after its recorded time. Restoring is distinct from routine outage recovery, which uses the current persistent database. Never use Docker Desktop Reset/Delete data or `docker compose down -v` on the live deployment. Previous restore volumes are retained until you deliberately remove them after confirming recovery.

## Local verification and packaging

`pwsh -File tests/laptop.ps1` creates an isolated Docker project with loopback-only test ports and a synthetic database. It tests encryption/tamper rejection, backup drive identity, backup/restore and abrupt container loss. It refuses to overwrite an existing `.laptop` configuration and does not register tasks. This is not a physical power-cut, external-device or public-domain test.

`pwsh -File bin/package-laptop.ps1` creates dist/albataxi-laptop.zip without local secrets, database volumes or backup data. Application upgrades should start with a fresh external backup; stop publication, deploy reviewed files/build, apply migrations, then start and verify. Rerunning Install is a setup repair/start operation, not an automatic database migration upgrade.

References: [Docker Desktop startup settings](https://docs.docker.com/desktop/settings-and-maintenance/settings/), [Caddy automatic HTTPS](https://caddyserver.com/docs/automatic-https), [MariaDB dump consistency](https://mariadb.com/docs/server/clients-and-utilities/backup-restore-and-import-clients/mariadb-dump).

## AlbaTaxi domain and selectable backup drive

The installer suggests bonus.albataxi.net; press Enter to accept it or type another subdomain. Create an A record named bonus in the albataxi.net DNS zone pointing to the router's public IPv4 address, then configure the router/firewall as described above. The main albataxi.net website is unaffected by this subdomain configuration.

The installer opens a Windows drive picker showing drive letters, volume names and free space. Connect the external hard drive, click Refresh if needed, select it, and the AlbaTaxi-Backups folder will be created automatically. Windows/app drives are excluded. Windows may classify an external USB hard drive as Fixed; it is still selectable. Choose the actual external disk, not another internal partition.

To replace the backup disk or change its drive letter later, run laptop/Choose-Backup-Drive.cmd. It opens the picker again, creates the folder if missing, preserves existing archives, updates the destination and immediately makes a backup. Normal scheduled backups still fail if the configured disk disappears; they never silently switch destinations.
