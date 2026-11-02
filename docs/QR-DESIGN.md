# Visual QR templates and approval

Open Atelier QR in the sidebar. The selector contains the programme default, permitted agency templates and permitted driver templates. Pending proposals are labelled and linked above the editor. Each target has separate portrait and landscape A4 designs.

## Inheritance and publishing

1. Programme default: editable and immediately publishable only by an administrator. It applies wherever no more specific approved override exists.
2. Agency template: an agency manager submits a proposal. An administrator must explicitly approve its exact revision before it applies to all inheriting drivers in that agency, including future drivers.
3. Driver template: a driver submits a proposal for their active affiliation. An authorised agency manager must approve its exact revision before it overrides the agency/default. Admin privileges alone do not substitute for agency approval. Independent drivers, who have no agency manager, require administrator approval.

Administrators and authorised managers may also prepare proposals within their management scope. A person cannot approve their own driver proposal, including when they hold both driver and manager roles. Agency approval is an explicit administrative action; there is no separate-author restriction for an administrator's agency proposal.

The effective order is approved driver → approved agency → published programme default → built-in initial design. Pending or rejected revisions never replace the last approved design. Parent changes affect inheriting drivers immediately; approved driver overrides stay in place. A new affiliation uses its own membership/template scope.

Approval or rejection applies to a fixed revision. Concurrent edits and stale review attempts return a conflict and require reloading. Rejection requires a displayed reason. Resubmission requires another approval. Published-template previews are separate from the editable proposal. Public driver pages and PDFs always resolve the approved hierarchy.

Renunță la suprascriere și moștenește modelul clears both override and proposal, returning to the current approved parent. The owner/authorised manager/admin can do this without another approval. Copying the inherited model into the editor only changes the proposal and still requires submission/approval.

## Visual editing

Select a block on the page or inspector. Drag to move or use the lower-right resize handle. Arrow keys move focused blocks; Shift moves ten units. Exact X/Y, dimensions, text size, color, alignment and bold controls are available. Background and three locally hosted font families are adjustable. Text supports line breaks and plain text, not arbitrary HTML.

Undo, redo and reset affect the current session. Explicitly publish the default or submit an override. Leaving with unsaved edits prompts the browser warning. The editor uses marked demo QR content; public pages/PDFs substitute real bonus/review codes. Unavailable reviews show an unavailable message. QR quiet zones remain white, minimum sizes and no-overlap rules are enforced. Overflowing text triggers a warning. Verify a printed sample with a real phone.

Validated templates, proposals, revisions and decisions live in MySQL (qr_templates); changes are audited. No uploaded files, external fonts, cron, websocket or writable application directory is required. Browser/PDF rendering shares coordinates and bundled DejaVu fonts; public/assets/fonts/LICENSE.txt contains the license.

## Upgrade to 0.4.0

Back up the database, deploy the release and apply migrations through database/005_qr_templates.sql in order. Preserve config.local.php and app_key. Existing 0.3 driver designs are copied as pending proposals rather than bypassing approval. Legacy rows remain for migration evidence; runtime output uses only the approved hierarchy. Re-running migrations does not overwrite decisions. The snapshot command includes template states.

Verify programme publication, agency approval, driver approval, rejection, inheritance, concurrent-edit conflicts, cross-agency permissions, public display and single-page portrait/landscape PDFs. Pending proposals appear in Atelier QR; no external notification service is required.