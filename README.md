# AI Share Kit

AI Share Kit adds private report links, local QR codes, cloud saves and messaging
controls to AI Engine and Easy Forms. Teachers choose the report, check its title
and preview, then decide where to share it.

**Current build: 2.4.0-rc.11.** This release candidate adds dashboard-token styling for embedded Share Kit manager, connections and sharing surfaces while retaining the saved-report menu adapter used by Easy Forms batch result rows. Live provider consent/uploads and the installed site's final acceptance checks are still required. Nothing in this package deploys a website
or sends messages automatically. Earlier changes are recorded in [CHANGELOG.md](CHANGELOG.md).

## Install or upgrade

1. Back up the WordPress database and replace the existing AI Share Kit plugin
   with this package. Do not activate a second copy alongside it.
2. Clear page and asset caches. Sharing and account-management REST responses,
   private report pages and OAuth callbacks must bypass public/CDN caches.
3. Open **AI Share Kit** to review Sharing options, TTL, Privacy & retention and
   Cloud storage. Check **Share Health** after WordPress scheduled tasks run.
4. If upgrading from **2.4.0-rc.1**, replace the OAuth callback registered with
   each provider using the exact query-free URL displayed in **Cloud storage**.
   Reconnect existing cloud accounts. Do not append `action` or `provider` query
   parameters. Close any old authorisation popups before trying again.

Shared snapshots and existing private-link URLs remain in place during an
upgrade. A new summary index builds in small background batches; the plugin
uses source records while that migration is incomplete. Rewrite rules are
updated automatically when needed. If a private-link path does not resolve,
check the site's permalink/server routing configuration.

Requires **WordPress 6.0+** and **PHP 8.1+**. Cloud connections also require HTTPS
and OpenSSL AES-256-GCM. Source adapters target **AI Engine 3.8.3** and **Easy
Forms for AI Engine 0.6.4 / 0.6.5-rc.2**. See the validation section for what has actually
been exercised; the version requirement is not a claim that every WordPress,
PHP, theme or Easy Forms combination has been tested.

## Teacher workflow

### Saved-report list integration (rc.6)

Easy Forms **0.7.2-rubrics-alpha.42** uses the public
`window.AIESK_ReportShare` adapter (API version 1) for batch result rows.
`mount(container, {load})` returns a `destroy()` handle. The connected container
must be dedicated to this control. The asynchronous loader runs on each action
and must authenticate and verify one saved report, returning sanitized `html`,
`title`, output `language`, and optional `easyForms: {form_id, signature}` for
the existing Word converter. The API does not grant permissions or validate
the source on behalf of the integrating plugin. Existing sharing routes keep
their permissions and limits. An `aiesk:report-share-ready` event announces the
available interface; disabled sharing does not expose it.

No chat-form attributes, current conversation selection or fake chatbot are
required. The adapter renders a compact menu, loads only the chosen report,
and reuses normal single-response previews and destination confirmation.
Removing the container disposes its menu and any open dialog. Ordinary
conversation sharing retains the site's existing layout preference.

The alpha.42 integration was checked with 21 Chromium assertions and real
WordPress source/Word/mail-handoff checks. Actual delivery and cloud operations
were intercepted or left uncalled. See the matching Easy Forms
`BATCH-SHARING.md` for evidence and installation order.

Open **Share beside the reply you want**, then check its editable title and
preview. Each assistant reply has its own control. The dialog contains only
that response, without a conversation-wide selector, user messages or other
reports. Preparation is limited to the selected report. If that response changes
while preparing its snapshot, sharing stops and asks you to open it again.

| Action | Result |
| --- | --- |
| Private link / QR | A stored report snapshot with a server-validated expiry. QR is generated locally in the browser. |
| Google Drive, OneDrive, Dropbox | A new file in the teacher's connected cloud account. Connecting alone does not upload a report. |
| WhatsApp, Viber | An explicit composer action containing the private link. The teacher selects recipients and sends in that service. |
| Download Word (.docx) | First menu option for Easy Forms replies, using Easy Forms' native Word exporter and the selected response only. A site-supplied converter can also provide Word elsewhere. |
| PDF | A one-use download when the site's PDF converter is connected. |
| Email | An explicit send action when enabled, identifying the sender and their active school when available. Mail delivery depends on the site's mail configuration. Email drafts/.eml have been removed; the old creation route returns 410. |

There is no background polling or automatic retry of create, send, delete or
upload actions. An expired WordPress session offers Reload / Sign in. Reports
are not persisted in browser localStorage.

### Email sender attribution

The email subject identifies the sending teacher and AI EdWiBo. An introduction
above the selected report says **Teacher name has sent you this report via AI
EdWiBo**, adding **from School name** only for an active, unambiguous school
identity. The selected report follows in a responsive, inline-styled email,
preserving its own headings without adding a duplicate report heading.

The sender comes from the logged-in WordPress account's public display name,
with a first/last-name fallback. A generic label is used if no usable name is
available; the account's email address is not used as a fallback. Optional school
attribution uses EdWiBo School Credits **0.9.4-rc3**'s current account identity
API. Personal, inactive, conflicting or unavailable identities omit the school.
No billing-company field, email domain or browser-supplied school/name is trusted.
The wording identifies the sender's current affiliation, not the historical
origin or school ownership of the report.

The site's configured sending address and mail transport are preserved; no
teacher address is added as From, Reply-To, CC or BCC. Only one valid recipient
is accepted. Input is not silently rewritten into another email address. The
existing email rate limit remains in effect. Successful submission means the
site's mail transport accepted the message, not confirmed inbox delivery.
The compose dialog explains that the sender's name and applicable school are
included. See [RC4 email validation](tests/QA-2.4.0-rc.4.md).

### Private links and short URLs

**Use short URL** is a checkbox, checked by default. It creates a 22-character,
128-bit random token at `/s/{token}/`. Unchecked creates a standard 64-character,
256-bit token at `/shared/{token}/`. Sites using plain permalinks receive a query
URL instead. Both formats have the same expiry and access rules; no external
URL shortener is involved. Tokens are case-sensitive. Existing integrations
that omit the strict boolean `short_url` keep the standard format.

Anyone holding an active URL or QR can view its snapshot. It is a bearer link,
not recipient-specific authentication. The server validates expiry, with a
maximum of 720 hours (30 days), and checks revocation on every public read.
Creating Link and QR for an identical report, title, language, lifetime and URL
format reuses a recently created active link for up to 15 minutes. Every request
rechecks the saved content, owner, token, expiry and revocation on the server.
The result says **Existing active link ready** and keeps its original expiry.
Changed reports/settings, expired or revoked links create a new link. There is
no blind browser cache. Only 20 recent pointers are retained per account, with
no report content in those pointers.

The public reader shows the report title, contains wide tables in horizontal
scroll areas, wraps long text and provides print styles. It loads no JavaScript
or remote fonts. Its content security policy allows only same-site and embedded
images, so third-party tracking images do not load. Report text uses automatic
writing direction. A language is attached only when explicit metadata belongs
to the selected response; historical reports never inherit the form's current
language dropdown. Missing, expired and revoked links show the same response
and ask the recipient to request a new link from their teacher.

### Shared links dashboard

- WooCommerce: **My Account → Shared links**.
- WordPress: **Dashboard → My shared links**.
- Custom dashboard: add `[aiesk_my_links]` to a page.
- Administrator: **AI Share Kit → All shared links**.

The manager provides search, status filters, pagination, Preview, Copy, local QR,
Send link for active shares, Revoke and Delete permanently. Teachers see only
own records; administrators can manage all records. Existing-link management
remains available when new-link creation is disabled. The `aiesk_manager_url`
filter can point sharing dialogs at a custom manager page.

List requests fetch summaries without loading report bodies. Preview retrieves
the chosen report separately. Dashboard markup embeds no report or bearer-link
data. Access checks run on the server, and responses use private/no-store headers.
Repeated response IDs and duplicate script initialization do not create duplicate
dashboard rows. Historical records with different URLs remain separate so
previously shared URLs are not silently removed; their owners can delete them.
Generic WordPress Editor permissions do not grant access to the hidden report
post type. Its sensitive metadata is unavailable to generic custom-field and
XML-RPC interfaces, and the post type is excluded from generic WordPress export.
Use the plugin's checked management and privacy tools instead.

### Connected accounts

**Connected accounts** is permanently available in the teacher dashboard, even
without an assessment. Each destination offers a named Connect/Reconnect action
when ready. Administrators get **Set up [service]** or **Enable [service]** links
directly to that service's settings; teachers get clear administrator guidance
when setup is needed. Refresh status visibly reports progress and completion.
After a cloud upload rejects its credentials, the stored connection is marked
**Reconnect required** and remains available to disconnect. A delayed rejection
from an older upload cannot invalidate a newer connection or account switch.
Disabling a provider or removing its application
configuration does not remove the teacher's ability to disconnect stored access.
No report uploads from this panel.

The plugin does not request profile/email permissions to identify the cloud
account. Account selection takes place at the provider. Use Switch account if
you need to choose another account. A displayed local connection is not proof
that every later upload will succeed; account revocation, quota and provider
outages can still produce a save error.

Disconnect removes local tokens and pending authorisations. Saved cloud files
remain, and an upload already in progress or accepted by a provider cannot be
recalled. You can also remove the application's authorisation in the provider's
account settings.

### Cloud formats and destinations

A styled **Web page (.html)** is always offered. Easy Forms supplies Word for
its own replies through a native adapter. It calls the existing signed-form Word
route with only the selected HTML; it does not trigger the original toolbar's
whole-history export. This also supplies the Word format for cloud saves from
those replies. A custom `aiesk_generate_docx` converter keeps precedence. PDF
still requires `aiesk_generate_pdf`. This plugin bundles no new conversion
library. Signature checks reject obvious non-document results; they are not
complete document-format validation.

Cloud files are limited to **10 MiB**. Google Drive receives a new file in My
Drive; OneDrive and Dropbox use their application's folder under Apps. Unique
filenames avoid overwriting reports. Cloud saving does not create a public share
link or public file permissions. Check the cloud folder before retrying an
upload whose outcome is uncertain; the plugin does not retry it automatically.

Cloud files are independent copies. Private-link expiry, revocation, retention,
deletion and plugin uninstall do not delete files in the provider. Recipients'
downloads and screenshots are likewise outside the plugin's deletion controls.

### WhatsApp and Viber

Create a private link with the chosen expiry and optional short URL, then click
the composer action. WhatsApp opens its contact chooser; Viber requires its app.
Copy and local QR provide alternatives. A composer opening is not reported as
message delivery.

Viber's documented 200-character limit is handled by placing the complete URL
first and shortening only the optional title. If the URL itself exceeds that
limit, use a short URL or copy it. The plugin never truncates a private URL.

## Cloud application setup

Open **AI Share Kit → Cloud storage** and copy the exact callback shown there.
It is the site's HTTPS `wp-admin/admin-post.php` address **with no query string**.
WordPress installed in a subdirectory may have an additional path prefix; use
the displayed URL instead of reconstructing it. All three providers can use
this callback because the provider is resolved from the protected pending state.

| Destination | Application setup | Access requested |
| --- | --- | --- |
| Google Drive | Enable Drive API; create a Web application OAuth client; configure consent and exact callback | `https://www.googleapis.com/auth/drive.file` |
| OneDrive | Register an Entra Web application supporting work, school and personal accounts; add callback and delegated permissions | `offline_access` and `https://graph.microsoft.com/Files.ReadWrite.AppFolder` |
| Dropbox | Create a Scoped access application with **App folder** access; add callback | `files.content.write` with offline access |

Enter the client ID/App key and secret, then enable the destination. The secret
field is always blank when settings reopen: leave it blank to keep the stored
secret, or explicitly select **Remove the stored application secret**. Cloud
Save actions remain hidden until enabled and securely configured. WhatsApp and
Viber are enabled by default, can be disabled in Sharing options, and require
Share Link to be enabled.

Application secrets and teacher tokens are encrypted with keys derived from
WordPress salts, stored in non-autoloaded database options and excluded from
browser configuration. Changing salts requires entering secrets and connecting
accounts again. Changing a client ID or callback invalidates existing
connections. Backups need access controls: a backup containing both the database
and salts includes the decryption material.

OAuth uses PKCE, ten-minute single-use state bound to the teacher's WordPress
login session, fixed provider endpoints and no HTTP redirect following. Account
deletion and the WordPress personal-data eraser remove local authorisations;
they do not delete cloud files. The popup accepts completion only from the
expected callback origin and popup window.

Complete each provider's applicable production-access/consent requirements
before offering the integration publicly. Microsoft school/work tenants may
require administrator consent. Popup blockers, privacy settings and the site's
login/cache setup can affect authorisation.

## Storage, retention and limits

**Privacy & retention** controls shared-snapshot storage and temporary-download
budgets. They are separate: a private link stores a report until deletion,
whereas a download stores temporary file bytes until redemption or cleanup.

| Limit | Default |
| --- | --- |
| Raw report HTML | 1 MiB, checked before sanitisation |
| New private links | 20 per account per five minutes |
| Downloads | 20 per account per five minutes |
| Cloud saves | 10 per account per five minutes across providers |
| Emails | 10 per account per ten minutes |
| Stored shared snapshots | 500 per account, with 50 MiB report-content budget |
| Temporary download storage | 25 MiB per account and 250 MiB per site, including encoded data and 1 KiB allowance per file |
| Individual download or cloud file | 10 MiB raw bytes |

Burst limits can be customised through `aiesk_rate_limit`. Rate limits do not
block viewing, revocation or deletion. Concurrent creation/storage checks use
database locks and guarded writes. Database-check failures refuse creation
instead of allowing an unchecked save.

PDF/Word download requests check their rate and reserve capacity for a maximum
10 MiB file **before** calling a converter. A reservation charges encoded size
plus overhead; success shrinks it to actual size, failures release it, and
abandoned reservations expire after ten minutes. This means a full reservation
needs about 13.34 MiB of budget even when the final file will be much smaller.

New temporary downloads use a dedicated, owner-indexed database table. No
payload is written into public uploads, WordPress transients or object-cache
records. Lookup tokens are hashed. Redemption atomically removes the row before
serving bytes, so the URL works once. Files expire within one hour; expired rows
still consume budget until physically removed. Cleanup uses bounded batches
and schedules continuation when backlogged. Account erasure/deletion removes
its owned ready files and reservations.

Earlier database-backed one-use downloads remain valid until their TTL and
count toward the site budget during cleanup. Old filesystem exports are retired
only when their filename/path is recognised. Unrelated files and symlinks are
not removed; permissions may require an administrator to finish cleanup.

### Stored snapshot retention

- New shares default to deletion **30 days after expiry or revocation, whichever
  is earlier**. Set 1–365 days, or 0 for manual deletion only.
- Each share records its policy at creation. Changing the setting does not
  rewrite dates on existing shares; older shares with no policy remain manual.
- Revoke stops public access. Owners/administrators may still Preview until the
  snapshot is deleted. Delete permanently revokes access first and removes the
  saved copy; it does not delete the original assessment.
- Hourly cleanup starts bounded batches of up to 50 snapshots, with a three-second
  work budget and short scheduled continuations while records remain. A worker
  lease prevents overlapping cleanup. Failed deletions are retried later.
- Index migration likewise uses bounded batches and scheduled continuations.
  Both depend on WordPress cron actually running. On low-traffic installations,
  arrange a real scheduler for WordPress cron. Due dates are not guarantees of
  an exact deletion time.

WordPress **Tools → Export/Erase Personal Data** includes reports belonging to
the account's current email address. It does not search report text for student
names or email addresses. Review/erase snapshots before deleting or reassigning
a teacher account. Site backups remain outside these controls.

The old server `/qr` route returns **410 Gone** and cannot contact QuickChart.
Current QR generation stays in the browser. **Privacy & retention** lists saved
legacy QR images and offers a confirmed maintenance action: revoke associated
links first, then remove verified local images, up to 50 records per click.
Upgrading does not silently revoke existing links.

### Share Health and uninstall

**AI Share Kit → Share Health** provides administrator-only, read-only storage,
index and cleanup diagnostics without displaying report bodies, private URLs
or credentials. It shows index progress, report/storage totals, overdue records,
temporary budget/reservation/expiry counts, retention batches and continuation
times, failures and provider-configuration flags. It flags disabled or overdue
WordPress scheduling. Refresh only reloads these checks; it does not purge
records, perform uploads or test live provider tokens.

Uninstall always removes the plugin's local cloud credentials, temporary files,
settings, scheduled jobs and derived tables. Shared report snapshots are
**retained by default**. An explicit **Privacy & retention** setting opts into
deleting those snapshots during uninstall. Each site has its own setting on
multisite. Original AI Engine conversations and external cloud/recipient copies
are unaffected. Removing retained plugin storage later requires reinstalling
the plugin or a deliberate administrator cleanup.

## Languages and accessibility

Controls and server messages use WordPress gettext. English and Greek
translations and a translation template are included. Interface language follows
WordPress; report language is separate. Easy Forms 0.6.4 does not expose reliable
per-response type/language metadata, so headings/excerpts supply editable titles
and unknown report language uses automatic text direction.

Share dialogs support keyboard operation, visible focus, reduced motion,
mobile layouts and inline progress/errors. Opening the Share menu with Enter or
Space moves focus into its options. An upload dialog may be closed while the
request completes; closing it does not undo a provider upload or prove failure.
Check the cloud destination before attempting the same save again.
Scoped styles protect button states, close controls and typography from common
Elementor/WooCommerce theme overrides. Muted text contrast is increased, close
controls are 44px, and each operation uses one progress indicator. Custom button
text colours fall back to a readable light/dark colour when their contrast with
the selected primary background is insufficient.

## Validation and final acceptance

The package contains PHP service/guard tests, Chromium interaction tests using
the production assets and QA results. Separate real-WordPress integration
fixtures were also used during validation. Actual
WordPress checks have used 6.8.3 with PHP 8.1/SQLite and PHP 8.3/MariaDB; AI Engine
3.8.3 Free and Easy Forms **0.6.4 and 0.6.5-rc.2** have passed bootstrap/render/share tests.
Cloud HTTP fixtures exercise provider request formats and failures without
sending teacher content to real accounts. The test harness does not make AI
requests or establish live email delivery.

[RC5 regression results](tests/QA-2.4.0-rc.5.md) cover the three defects identified
in the [RC4 validation report](tests/VALIDATION-2.4.0-rc.4-2026-10-07.md).

Before public rollout on the installed HTTPS stack:

1. Check a fresh report, a follow-up report and a history selection. Confirm the
   title, selected content and language/direction before sharing.
2. Open both private-link formats signed out, scan a QR, then revoke/delete and
   confirm another signed-out request is refused with site/CDN caching enabled.
3. Connect a real teacher account to each configured provider; save and open the
   selected report, reconnect/switch and disconnect. Confirm another WordPress
   user cannot use that connection. Check disabled-provider disconnection too.
4. Check actual WhatsApp/Viber handoff on target phones, keyboard navigation,
   mobile report tables, native Easy Forms Word export and email delivery if used.
5. Check cron/index/retention health and temporary-storage recovery. Run a small
   teacher pilot before wider rollout.

These checks remain distinct from fixture results. The production theme,
installed WooCommerce/AI Engine Pro versions, live consent approval, provider
quotas, CDN/login settings and physical iPhone Safari need site-specific
validation. The plugin does not change assessment generation, billing or Easy
Forms' own history/storage.

Useful test entry points (PHP/Playwright and appropriate fixture dependencies required):

```sh
php tests/bootstrap-smoke.php
php tests/bootstrap-smoke.php . activate
php tests/cloud-auth-smoke.php
php tests/cloud-files-smoke.php
php tests/access-reader-smoke.php /path/to/isolated-wordpress
node tests/frontend-smoke.cjs
node tests/frontend-lifecycle.cjs
node tests/frontend-manager.cjs
node tests/frontend-destinations.cjs
node tests/frontend-connections.cjs
```

The access/reader test creates and deletes temporary fixture users/posts and
must run only against an isolated test database. See the individual tests and
packaged QA notes for prerequisites and the limits of each test type.

## Distribution licensing

The project does not yet have an owner-chosen `LICENSE` declaration. Confirm
the intended licence and directory requirements before public directory
submission. The bundled QR generator's third-party MIT notice is retained;
that notice does not choose a licence for the rest of this plugin.

## Integration references

- [Google web-server OAuth](https://developers.google.com/identity/protocols/oauth2/web-server)
  and [Drive uploads](https://developers.google.com/workspace/drive/api/guides/manage-uploads).
- [Microsoft OAuth with PKCE](https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-auth-code-flow)
  and [OneDrive app folders](https://learn.microsoft.com/en-us/graph/onedrive-sharepoint-appfolder).
- [Dropbox OAuth](https://docs.dropboxapi.com/dropbox-api/docs/oauth)
  and [file API specification](https://github.com/dropbox/dropbox-api-spec/blob/main/files.stone).
- [WhatsApp click to chat](https://faq.whatsapp.com/5913398998672934)
  and [Viber share button](https://developers.viber.com/docs/tools/share-button/).
