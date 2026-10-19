# Changelog

## 1.0.1 — Security hardening

Addresses all 8 findings (2 HIGH, 3 MEDIUM, 3 LOW) from the GLPI Plugins catalog's pre-publication
security review of 1.0.0.

### HIGH

- The three Dashboard App data endpoints no longer trust a raw `identifier`/`phone` query parameter
  as identity. A new endpoint, `dashboard_identify.php`, resolves it once and mints a short-lived
  (5 minute) capability — authenticated-encrypted with `GLPIKey`, bound to a single GLPI user id —
  that the ticket list, ticket detail, and document endpoints now require instead.
- Internal/private follow-ups are only exposed through the panel if an administrator explicitly
  enables "Show internal/private follow-ups to Chatwoot agents" (off by default).
- The panel's ticket queries can optionally be restricted to a chosen set of entities (selecting an
  entity also covers its sub-entities).
- Only active, non-deleted GLPI accounts can be resolved.
- Identity resolution (`dashboard_identify.php`, the only endpoint that still accepts a login/phone)
  is rate-limited per IP address, with a lightweight audit log of lookups. The ticket/detail/document
  endpoints are not throttled: they already require a capability (or a per-document link) bound to
  one user, and a single ticket view legitimately fires several image requests.
- Ticket attachments and embedded images are served with GLPI core's own inline allow-list (images
  except SVG, and PDF — the same rules as `Toolbox::getFileAsResponse()`); everything else is forced
  to `attachment`, always with `X-Content-Type-Options: nosniff`, a CSP sandbox, RFC 6266 filename
  encoding, and a `realpath()` check that the file stays inside GLPI's document folder. Previously
  `Content-Disposition: inline` was applied unconditionally, including to an attacker-controlled
  `text/html` attachment. (Written with `header()`/`readfile()` rather than core's Symfony
  `Response`, whose `send()` ends the request early under PHP-FPM and dropped the file body.)

### MEDIUM

- The Dashboard App integration token is now encrypted at rest and registered under
  `Hooks::SECURED_FIELDS`. It — and the new capability — travel as request headers rather than URL
  query parameters, so neither lands in access logs, browser history, or the `Referer` header; the
  panel page also sends `Referrer-Policy: no-referrer`. Attachments/images use per-document links
  minted when a ticket is opened — bound to one user, one ticket and one document, valid for 10
  minutes — so a link copied out of a log exposes at most that one file, briefly.
- A "Regenerate panel token" control lets administrators revoke a potentially leaked token on demand.
- Outbound requests to the Chatwoot API never follow redirects while carrying the API Access Token
  header, and are restricted to `http`/`https` only. The Chatwoot URL is now validated before saving
  the configuration (previously only the "Test connection" button validated it).
- The widget loader on GLPI's own pages no longer executes JavaScript returned by the Chatwoot API —
  it always uses the documented, static `sdk.js` loader, so it stays subject to your
  Content-Security-Policy.

### LOW

- The panel's `postMessage` handler now checks `event.origin` against the configured Chatwoot
  instance, and the panel sends a `Content-Security-Policy: frame-ancestors` header so it can't be
  framed by an arbitrary site.
- The configuration form now builds its saved fields from an explicit allow-list instead of the whole
  `$_POST`, so a crafted request can no longer overwrite `dashboard_app_token`/`dashboard_app_id`.
- The API Access Token field on the configuration screen is always rendered blank; leaving it blank
  on save keeps the existing value, so the decrypted secret is never re-emitted into the page.

### Other

- Dropped the Dashboard App widget-script storage path entirely (superseded by the fix above) and
  the vestigial pre-1.0.0 upgrade branches in the install routine.
- Upgrading from 1.0.0 encrypts the existing Dashboard App token in place, so the panel already
  registered in Chatwoot keeps working without re-saving the configuration.
- The panel never mixes up contacts when the agent switches conversations: an identity lookup still
  in flight for the previous contact is discarded, and the previous contact's phone number is no
  longer reused as a fallback for the new one. An expired capability is renewed automatically.
- Translations: every new configuration string is translated (pt_BR, en_GB, en_US), and the panel's
  "invalid token" message now comes from its translation dictionary.
- `sanity.php` checked paths one directory above the plugin root and always failed; fixed.

## 1.0.0 — Initial release

First public version. Summary of everything the plugin does:

### 💬 Widget

- Official Chatwoot widget injected into every authenticated GLPI page.
- Website Token and HMAC Token fetched automatically from the Chatwoot API (from Account ID + Inbox
  ID), no need to copy/paste anything.
- The embed script used is literally the one returned by the Chatwoot API for the Inbox — changes to
  the embed format on Chatwoot's side arrive automatically, no plugin update needed.
- Automatic hourly sync (GLPI Automatic action) keeping Website Token, HMAC Token, and the embed
  script up to date; "Test connection" button syncs on the spot.
- Customization: position, bubble type, launcher title, dark mode.

### 🪪 User identification

- `setUser()` with name, email, normalized phone number, and profile photo (via a signed-token proxy).
- `identifier_hash` (Identity Validation via HMAC).
- Identifier = GLPI username.
- City/country fetched from Location → Entity, with REST API sync (works around a known Chatwoot SDK
  bug that doesn't reliably persist these fields via `setUser()`).

### 🔒 Permissions

- Restrict who sees the widget by Profile, Group, and/or User, with self-contained search pickers.
- Empty in all three = visible to everyone authenticated (default).

### 🎫 "GLPI" panel in Chatwoot (Dashboard App)

- Automatically registered under Integrations > Dashboard Apps when the configuration is saved.
- Lists the tickets of the person identified in the conversation (as requester): search by
  title/number directly in GLPI, status filter, technician, location, and an SLA progress bar based
  on GLPI's real deadline.
- Ticket detail opens inside the panel itself (without leaving Chatwoot): status, priority,
  technician, location, description with embedded images, full timeline (follow-ups from both sides
  + solution, with channel/source and internal notes flagged), and attachments with download.
- Phone-based fallback search (Phone, Mobile phone, Phone 2, normalizing both sides to compare only
  the digits) when the conversation's identifier doesn't match any GLPI user.
- Protected by a unique integration token — doesn't rely on a GLPI session, since the panel runs in
  an iframe on Chatwoot's domain.

### 🌐 Languages

- Source code (comments and strings) in English.
- Configuration screen: GLPI's standard gettext (`locales/*.po`/`*.mo`), with **en_GB**, **en_US**,
  and **pt_BR** included — automatically follows each GLPI account's configured language.
- "GLPI" panel inside Chatwoot: its own plain JSON translations, **100% automatic**, driven by the
  Chatwoot account's own configured language (`Config::getAccountLocale()`, via
  `GET /api/v1/accounts/{account_id}`) — never by GLPI's own language setting, and with no manual
  switcher. Rendered server-side, so the page loads already in the right language.
- This also covers values that come from GLPI itself (ticket status, priority, solution status): the
  panel sends the resolved language along with each request, and `Config::applyRequestLanguage()`
  switches GLPI's gettext for that single request only — no real user session exists in this context
  to begin with, so there's nothing to affect outside that one response.

### 🔐 Security

- API Access Token and HMAC Token encrypted with `GLPIKey`, automatically re-encrypted on GLPI's
  security key rotation (`Hooks::SECURED_FIELDS`).
- Ticket content (description, follow-ups) converted from HTML to plain text on the server and
  inserted via `textContent`, never `innerHTML`.
- Attachment/embedded image downloads validated against the real link to the ticket before serving
  any file.

### ✅ Compatibility

- **GLPI 11.x only**. GLPI 10.x uses a fundamentally different bootstrap/routing model (requires
  `inc/includes.php` in every script, no `public/` asset convention, no equivalent of the
  `Firewall`/`SessionManager` APIs the Dashboard App panel depends on), so
  `plugin_chatwoot_check_prerequisites()` blocks installation on anything older than 11.0.0 instead
  of failing partway through.
- PHP 8.1+.
