# SMSLink setup

SMSLink is the selected production SMS provider. Local Docker continues using the development database inbox until real credentials are explicitly configured.

1. Create your account at https://www.smslink.ro/ and activate SMS Gateway. Start with a small balance for testing.
2. Create a Gateway connection at https://www.smslink.ro/get-api-key/ . Copy its Connection ID and connection password, which are separate from your account login.
3. In config.local.php outside public_html, configure:

```php
'sms_backend'=>'smslink',
'smslink_connection_id'=>'YOUR_CONNECTION_ID',
'smslink_password'=>'YOUR_CONNECTION_PASSWORD',
'smslink_sender'=>'',
```

Equivalent environment variables: SMS_BACKEND, SMSLINK_CONNECTION_ID, SMSLINK_PASSWORD and SMSLINK_SENDER. Do not send secrets through chat or commit them. Environment variables override file values; Docker's development SMS_BACKEND must be overridden deliberately for a live test.

Keep sender blank for the provider default. Use AlbaTaxi only after SMSLink approves and activates that sender for your account. Enable allow_url_fopen and outbound HTTPS to secure.smslink.ro on Hostinger. No callback URL, cron or retained files are required; inspect delivery status in the SMSLink dashboard.

The adapter sends form-encoded HTTPS POST requests with certificate verification, a 10-second timeout and no redirects. Only a 2xx response containing MESSAGE;1 and a positive message ID counts as acceptance. Provider error responses fail safely even when HTTP is 200. Credentials, phone numbers, OTPs and provider response bodies are not logged by the adapter. No automatic retry occurs after an ambiguous timeout.

OTP text uses ASCII and stays below 160 characters to avoid Unicode/multipart costs. Keep future messages similarly short; provider billing and actual delivery must be verified separately.

After configuration, use Administrator → Pregătire de lansare to inspect configuration and explicitly send a test to a phone you control. Confirm receipt, then test registration verification, password recovery, incorrect/expired codes and resend limits. Check delivery on the mobile networks used by your drivers/passengers and record the evidence. Gateway acceptance is not proof of handset delivery.

Local protocol checks: `docker compose exec -T web php tests/smslink.php`. These use an isolated fake transport, never real credentials or paid messages.

Official protocol: https://www.smslink.ro/sms-gateway-conectarea-la-serviciul-sms-gateway-http.html and https://www.smslink.ro/sms-gateway-raspunsurile-de-confirmare-posibile-ale-sms-gateway-http.html .

## Operating before acquiring SMS

Leave sms_backend=disabled (SMS_BACKEND=disabled on Docker). Registration, login, invitations and rides then need no SMS confirmation. Accounts keep their actual verified flag; skipped confirmation never marks a phone verified. The local Compose and laptop installer default to this mode.

Only switch to smslink after acquiring the API and entering credentials. Verification then becomes mandatory for unverified accounts, including existing accounts at their next use. An enabled gateway with missing credentials, delivery errors or an outage does not silently disable verification. The database backend is an explicit development simulator and still requires test codes.

Without SMS, recovery pages direct users to the administrator. A trusted server operator can reset a password after confirming ownership using php bin/console.php reset-password PHONE with ALBA_NEW_PASSWORD supplied through the process environment. On Docker, pass it with docker compose exec -T -e ALBA_NEW_PASSWORD web php bin/console.php reset-password PHONE (include the laptop Compose/env arguments when using that deployment). Do not place passwords in command arguments or Git. The command revokes sessions and outstanding codes without marking the phone verified.
