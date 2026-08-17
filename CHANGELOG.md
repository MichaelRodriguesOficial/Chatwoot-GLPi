# Changelog

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
