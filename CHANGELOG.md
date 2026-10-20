# AI Share Kit changelog

This file records historical changes. [README.md](README.md) describes the
current build; earlier storage mechanisms and limits below are not current
installation instructions.

## 2.4.0-rc.6

- Add a public saved-report menu adapter for authenticated report lists.
- Reload the selected report before every sharing action and reuse existing
  previews, dialogs, Word export, link expiry, email and cloud controls.
- Keep the source separate from chat forms, require explicit teacher action,
  and clean up menus/dialogs if their source row is removed.
- Batch rows use a compact Share menu even when ordinary chat toolbars are inline.

## 2.4.0-rc.5

- Prevent HEAD metadata requests from consuming one-use private downloads;
  the following GET can still redeem the download exactly once.
- Mark rejected cloud credentials as requiring reconnection after an upload
  authentication failure. Preserve Disconnect and protect newer connections
  from delayed failures belonging to an older credential.
- Preserve literal backslashes in shared-report titles at the WordPress write
  boundary so identical Link/QR requests can reuse the existing active link.

## 2.4.0-rc.4

- Identify the sending teacher in the email subject and in an AI EdWiBo
  introduction above the selected report. Include the school only when the
  School Credits account identity API confirms an active school association.
- Resolve names server-side, ignore submitted identity fields, escape labels,
  and preserve the site's configured sending address and transport.
- Add a compose notice explaining sender attribution, with Greek translations.
- Reject malformed recipients without rewriting addresses; handle transport
  exceptions safely while preserving existing limits and releasing send locks.

## 2.4.0-rc.3

- Bind Share to each individual assistant reply, including historical replies,
  and remove the conversation-wide response selector. Exclude user messages and
  nested transcript content. Use bounded content probes during streaming.
- Put Download Word (.docx) first for Easy Forms, reusing its native signed-form
  exporter with only the selected response. Preserve custom converter priority.
- Remove email drafts and retire creation through the old EML route.
- Reuse exact recent active links for Link/QR after server validation on every
  request, preserving expiry and respecting revocation. Prevent duplicate
  dashboard rows and duplicate initialization without deleting historical links.
- Protect contrast, typography, selected states and 44px close buttons from
  page-builder styles. Show one loading indicator for link and QR creation.
- Add clear per-service Connect, Reconnect, Set up and Enable actions with
  visible refresh feedback in Connected accounts.
- Update Greek translations and regression checks using actual Easy Forms
  history rendering, native Word output and aggressive theme fixtures.

## 2.4.0-rc.2

Patches from the 2.4.0-rc.1 security, performance and usability audit:

- Isolate hidden report records from ordinary WordPress Editor capabilities.
  Deny generic access to sensitive report metadata, including XML-RPC custom
  fields, and exclude report storage from generic WordPress export. Existing
  records are protected without a role or data migration.
- Replace provider callbacks containing query parameters with the site's
  query-free `wp-admin/admin-post.php` address. Resolve the provider from bound
  pending state, preserving PKCE, one-use state and login-session checks.
  RC1 installations must update registered callbacks and reconnect accounts.
- Add permanently accessible Connected accounts to teacher dashboards, with
  Connect, Reconnect, Switch account, Check connection and Disconnect. Stored
  connections can be disconnected even when a provider is disabled/unconfigured.
- Check download rate and reserve per-account/site temporary storage before
  PDF/Word conversion. Store owned download payloads in a dedicated table,
  outside WordPress transients and object caches, with atomic one-use redemption.
  Add bounded continuation cleanup and account erasure support.
- Add a rebuildable background summary index for ownership, token and retention
  lookups. Fetch management lists without report bodies; load content only when
  previewing a selected report. Retention cleanup continues through bounded
  batches instead of waiting an hour between batches.
- Remove five-minute reuse of generated links, preventing revoked links from
  reappearing as newly created results. Move keyboard focus into the Share menu
  on keyboard activation. Permit dismissing an upload dialog while preserving
  truthful feedback about its potentially continuing request.
- Add a responsive, script-free public report reader: visible escaped title,
  contained and keyboard-focusable wide tables, long-text wrapping, print styles
  and automatic direction. Use explicit per-response language only; block
  third-party images/fonts by default. Unavailable-link responses give a next
  action without disclosing whether a record existed.
- Add read-only Share Health diagnostics and explicit uninstall retention choice.
  Shared snapshots are retained by default; local cloud access and temporary
  storage are removed on uninstall.
- Replace contradictory historical README instructions with current setup,
  storage, security, upgrade and acceptance guidance.

Live cloud-account consent/uploads and the installed site's final acceptance
checks remain necessary. This release does not deploy changes automatically.

## 2.4.0-rc.1

- Add authenticated Google Drive, OneDrive and Dropbox report uploads. Teachers
  connect their own accounts and explicitly save HTML, or PDF/DOCX when a site
  converter is installed. Cloud files remain independent of link retention.
- Encrypt application secrets and tokens using WordPress-derived keys; add PKCE,
  session-bound one-use state, token refresh, disconnect and privacy erasure.
- Add WhatsApp/Viber private-link composers to Share and active-link management.
  Handle Viber's 200-character limit without truncating the URL.
- Add provider setup guidance, browser feedback, English/Greek strings and
  fixture-based cloud/OAuth checks. Live cloud account use was not verified.

RC1 contained the audited access-control, callback, resource and UX defects
addressed in RC2; it should not be used for public release.

## 2.3.0-rc.2

- Replace legacy filesystem temporary downloads with one-use database records;
  retire recognised old export files in bounded cleanup. RC2 of 2.4.0 later moves
  new temporary payloads into the dedicated owned/budgeted table.
- Retire the server QR endpoint with 410 Gone. Add explicit legacy QR inventory
  and confirmed revoke-and-clean maintenance; current QR generation is local.
- Add permanent shared-snapshot deletion, recorded retention policies and
  WordPress personal-data export/erase integration. Default new-share deletion
  is 30 days after expiry/revocation, with older records remaining manual.
- Add report-count/storage quotas, rate limits, atomic creation leases, early
  input-size guards and actionable expired-session feedback.
- Expand English/Greek localisation and lazy preparation of selected reports.
- Exercise actual WordPress 6.8.3 with PHP 8.1/SQLite and PHP 8.3/MariaDB, AI Engine
  3.8.3 Free/Easy Forms 0.6.4 render/share roundtrips and browser fixtures.

## 2.3.0-rc.1

- Add teacher Shared links management through WooCommerce My Account, WordPress
  dashboard and `[aiesk_my_links]`; add administrator all-links scope.
- Add Preview, Copy, local QR, Revoke, search, status filters and pagination with
  owner-scoped REST and private response headers.
- Add the checked-by-default short-URL checkbox: 128-bit compact tokens at `/s/`
  alongside standard 256-bit tokens at `/shared/`. Preserve existing URLs and
  standard-format integration defaults.
- Publish records only after verifying their required metadata.

## 2.2.1

- Move menus outside transformed/clipped conversation/theme wrappers to the
  body-level sharing UI root. Use the Popover top layer when supported and a
  detached fixed popup otherwise.
- Fix the menu appearing open but positioned off-screen in page-builder layouts.
  Cover transformed Easy Forms wrappers with click/hit-testing browser fixtures.

## 2.2.0

- Rebuild Share with animated menus, accessible modal dialogs, mobile layouts,
  reduced-motion support, selected-response preview and editable titles.
- Add expiry presets/custom duration, inline progress/errors, copy feedback,
  local QR generation and explicit export/email dialogs.
- Deliver toolbar markup with the page and use scoped DOM observers without
  polling or full-page streamed-text scans. Preserve native Easy Forms controls.
- Hide unavailable converters and then-placeholder cloud features. Validate
  generator availability and basic PDF/DOCX signatures.
- Introduce five-minute same-snapshot link reuse; removed in 2.4.0-rc.2 following
  the cross-tab revocation audit.

## 2.1.2

- Restore the Share dropdown's click/keyboard handler, expanded state,
  outside-click/Escape dismissal and existing-sharebar visibility.
- Avoid starting conversation observers on guest pages without a share portal.

## 2.1.1

- Correct the admin-menu include path that caused 2.1.0 startup to fail.
- Add CLI bootstrap coverage for startup, activation, registration and
  deactivation using WordPress API stubs.
