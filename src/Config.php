<?php

namespace GlpiPlugin\Chatwoot;

use CommonDBTM;
use CommonGLPI;
use Entity;
use GLPIKey;
use Glpi\Application\View\TemplateRenderer;
use Location;
use Session;
use User;

class Config extends CommonDBTM
{
    public static $rightname = 'config';

    /**
     * Fields stored encrypted (GLPIKey).
     * Kept in sync with Hooks::SECURED_FIELDS in setup.php.
     */
    public const ENCRYPTED_FIELDS = ['api_key', 'hmac_token'];

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_chatwoot_configs';
    }

    public static function getTypeName($nb = 0): string
    {
        return __('Chatwoot', 'chatwoot');
    }

    public static function canCreate(): bool
    {
        // Single row (id=1), created on install. Never through the generic form.
        return false;
    }

    public function canPurgeItem(): bool
    {
        return false;
    }

    /**
     * Fetches (or creates, if missing) the single configuration row.
     */
    public static function getInstance(): self
    {
        $config = new self();
        if (!$config->getFromDB(1)) {
            $config->add(['id' => 1]);
            $config->getFromDB(1);
        }
        return $config;
    }

    /**
     * Returns the configuration with sensitive fields already decrypted,
     * ready for internal use (must never be exposed directly to the browser).
     */
    public static function getConfig(): array
    {
        $fields  = self::getInstance()->fields;
        $glpikey = new GLPIKey();

        foreach (self::ENCRYPTED_FIELDS as $field) {
            if (!empty($fields[$field])) {
                $fields[$field] = $glpikey->decrypt($fields[$field]);
            }
        }

        return $fields;
    }

    /**
     * Temporarily switches GLPI's active gettext language for the rest of
     * this request only — used by the public dashboard endpoints so ticket
     * status/priority/solution labels match the language the Chatwoot panel
     * resolved (Config::getAccountLocale()), without touching any real
     * user's session. There is no session to touch in the first place in
     * this unauthenticated context ($_SESSION here is a fresh, throwaway one
     * tied only to this one request, not any logged-in GLPI user's actual
     * session), so this can't leak into or affect anyone's normal GLPI usage.
     */
    public static function applyRequestLanguage(string $lang): void
    {
        $map = [
            'en'    => 'en_GB',
            'pt_BR' => 'pt_BR',
        ];
        $glpi_lang = $map[$lang] ?? null;
        if ($glpi_lang === null) {
            return;
        }

        try {
            if (class_exists(\Session::class) && method_exists(\Session::class, 'loadLanguage')) {
                \Session::loadLanguage($glpi_lang);
            }
        } catch (\Throwable $e) {
            // Best effort — if this fails for any reason (missing intl
            // extension, unexpected environment, etc.), labels just stay in
            // GLPI's default system language, same as before this existed.
        }
    }

    /**
     * Fetches the Inbox data directly from the Chatwoot API (website_token,
     * hmac_token, and the official embed script), so the user doesn't need to
     * copy those values manually, and the widget stays aligned with whatever
     * Chatwoot itself recommends — even if the format of that script changes
     * in the future (bubble, text box, new options, etc.).
     * Uses the same endpoint documented by Chatwoot:
     * GET /api/v1/accounts/{account_id}/inboxes/{inbox_id}.
     *
     * @return array{ok: bool, website_token?: string, hmac_token?: string, widget_script?: string, name?: string, error?: string}
     */
    public static function fetchInboxDetails(string $base_url, string $api_key, string $account_id, string $inbox_id): array
    {
        $base_url = rtrim($base_url, '/');
        $url = sprintf(
            '%s/api/v1/accounts/%s/inboxes/%s',
            $base_url,
            rawurlencode($account_id),
            rawurlencode($inbox_id)
        );

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPGET        => true,
            CURLOPT_HTTPHEADER     => ['api_access_token: ' . $api_key, 'Content-Type: application/json'],
            CURLOPT_USERAGENT      => 'GLPI-Chatwoot-Plugin/' . PLUGIN_CHATWOOT_VERSION,
        ]);
        $body       = curl_exec($ch);
        $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($curl_error !== '') {
            return ['ok' => false, 'error' => sprintf(__('Could not connect: %s', 'chatwoot'), $curl_error)];
        }

        if ($http_code === 401) {
            return ['ok' => false, 'error' => __('API Access Token rejected by Chatwoot.', 'chatwoot')];
        }

        if ($http_code === 404) {
            return ['ok' => false, 'error' => __('Account ID or Inbox ID not found.', 'chatwoot')];
        }

        if ($http_code !== 200) {
            return ['ok' => false, 'error' => sprintf(__('The Chatwoot API responded with HTTP code %d.', 'chatwoot'), $http_code)];
        }

        $data = json_decode((string) $body, true);
        if (!is_array($data) || empty($data['website_token'])) {
            return ['ok' => false, 'error' => __('The API response did not include a Website Token for this Inbox.', 'chatwoot')];
        }

        return [
            'ok'            => true,
            'website_token' => (string) $data['website_token'],
            'hmac_token'    => (string) ($data['hmac_token'] ?? ''),
            'widget_script' => self::extractScriptContent((string) ($data['web_widget_script'] ?? '')),
            'name'          => (string) ($data['name'] ?? ''),
        ];
    }

    /**
     * Syncs additional_attributes (city/country/country_code/company_name)
     * of a contact via the REST API — works around a known widget SDK bug,
     * where these fields sent via setUser() sometimes don't persist on the
     * contact record (chatwoot/chatwoot#7822). Looks up the contact by the
     * exact identifier and PATCHes the additional attributes.
     *
     * Doesn't fail the widget loading on error: it's just a "best effort"
     * extra, since setUser() alone already normally covers name/email/phone
     * /identity.
     */
    public static function syncContactAttributes(array $config, string $identifier, array $additional_attributes): bool
    {
        $base_url   = rtrim((string) ($config['base_url'] ?? ''), '/');
        $api_key    = (string) ($config['api_key'] ?? '');
        $account_id = (string) ($config['account_id'] ?? '');

        if ($base_url === '' || $api_key === '' || $account_id === '' || empty($additional_attributes)) {
            return false;
        }

        $contact_id = self::findContactIdByIdentifier($base_url, $api_key, $account_id, $identifier);
        if ($contact_id === null) {
            // The contact doesn't exist in Chatwoot yet at this point (the SDK
            // creates it in the identify call itself) — not an error, just
            // nothing to update yet.
            return false;
        }

        $ch = curl_init($base_url . '/api/v1/accounts/' . rawurlencode($account_id) . '/contacts/' . $contact_id);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'PATCH',
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_HTTPHEADER     => ['api_access_token: ' . $api_key, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode(['additional_attributes' => $additional_attributes], JSON_UNESCAPED_UNICODE),
        ]);
        curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $http_code >= 200 && $http_code < 300;
    }

    private static function findContactIdByIdentifier(string $base_url, string $api_key, string $account_id, string $identifier): ?int
    {
        $ch = curl_init($base_url . '/api/v1/accounts/' . rawurlencode($account_id) . '/contacts/filter');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_HTTPHEADER     => ['api_access_token: ' . $api_key, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode([
                'payload' => [[
                    'attribute_key'   => 'identifier',
                    'filter_operator' => 'equal_to',
                    'values'          => [$identifier],
                    'query_operator'  => null,
                ]],
            ], JSON_UNESCAPED_UNICODE),
        ]);
        $body      = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code !== 200) {
            return null;
        }

        $data = json_decode((string) $body, true);
        $id   = $data['payload'][0]['id'] ?? null;

        return $id !== null ? (int) $id : null;
    }

    /**
     * Fetches the Chatwoot account's own configured language via
     * GET /api/v1/accounts/{account_id}, which returns a "locale" field
     * directly on the account object — confirmed against a real response
     * (e.g. {"locale": "en", "id": 2, "name": "...", ...}). This is
     * account-wide (shared by everyone in the account), not a specific
     * agent's own preference — there's no reliable, documented way to read a
     * single agent's individual language from inside the Dashboard App panel,
     * so the account default is the best available proxy for "the language
     * configured in Chatwoot".
     *
     * @return string|null 'en' or 'pt_BR', or null if not determinable
     */
    public static function getAccountLocale(array $config): ?string
    {
        $base_url   = rtrim((string) ($config['base_url'] ?? ''), '/');
        $api_key    = (string) ($config['api_key'] ?? '');
        $account_id = (string) ($config['account_id'] ?? '');

        if ($base_url === '' || $api_key === '' || $account_id === '') {
            return null;
        }

        $ch = curl_init($base_url . '/api/v1/accounts/' . rawurlencode($account_id));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_HTTPHEADER     => ['api_access_token: ' . $api_key],
        ]);
        $body      = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code !== 200) {
            return null;
        }

        $data = json_decode((string) $body, true);
        // Some responses come back as a bare object, others (confirmed by a
        // real test) as an array with a single item — handles both shapes.
        if (is_array($data) && isset($data[0]) && is_array($data[0])) {
            $data = $data[0];
        }

        $raw = is_array($data) ? ($data['locale'] ?? null) : null;
        if (!$raw) {
            return null;
        }

        return stripos((string) $raw, 'pt') === 0 ? 'pt_BR' : 'en';
    }

    /**
     * Generates a random integration token (used to protect the Dashboard
     * App's public panel — see public/dashboard_app.php). Only generated
     * once; subsequent calls reuse the token already saved.
     */
    public static function ensureDashboardAppToken(array $config): string
    {
        $token = (string) ($config['dashboard_app_token'] ?? '');
        if ($token !== '') {
            return $token;
        }

        $token = bin2hex(random_bytes(32));
        $item  = self::getInstance();
        $item->update(['id' => 1, 'dashboard_app_token' => $token]);

        return $token;
    }

    /**
     * Creates (or updates, if it already exists) the "GLPI" Dashboard App in
     * Chatwoot, pointing to this plugin's public panel. Called automatically
     * when the configuration is saved, so it doesn't need to be set up
     * manually under Integrations > Dashboard Apps in Chatwoot.
     */
    public static function registerDashboardApp(array $config, string $panel_url): array
    {
        $base_url   = rtrim((string) ($config['base_url'] ?? ''), '/');
        $api_key    = (string) ($config['api_key'] ?? '');
        $account_id = (string) ($config['account_id'] ?? '');

        if ($base_url === '' || $api_key === '' || $account_id === '') {
            return ['ok' => false, 'error' => __('Incomplete configuration.', 'chatwoot')];
        }

        $payload = [
            'dashboard_app' => [
                'title'   => 'GLPI',
                'content' => [
                    ['type' => 'frame', 'url' => $panel_url],
                ],
            ],
        ];

        $existing_id = !empty($config['dashboard_app_id']) ? (int) $config['dashboard_app_id'] : null;

        // If there's already a saved ID, try to update first (PATCH). If it
        // no longer exists on the Chatwoot side (was manually deleted there),
        // create it again.
        if ($existing_id !== null) {
            $result = self::callDashboardAppApi('PATCH', $base_url, $api_key, $account_id, $existing_id, $payload);
            if ($result['ok']) {
                return ['ok' => true, 'id' => $existing_id];
            }
        }

        $result = self::callDashboardAppApi('POST', $base_url, $api_key, $account_id, null, $payload);
        if (!$result['ok']) {
            return $result;
        }

        return ['ok' => true, 'id' => $result['id']];
    }

    private static function callDashboardAppApi(string $method, string $base_url, string $api_key, string $account_id, ?int $id, array $payload): array
    {
        $url = $base_url . '/api/v1/accounts/' . rawurlencode($account_id) . '/dashboard_apps';
        if ($id !== null) {
            $url .= '/' . $id;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['api_access_token: ' . $api_key, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
        $body      = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error     = curl_error($ch);
        curl_close($ch);

        if ($error !== '') {
            return ['ok' => false, 'error' => $error];
        }
        if ($http_code < 200 || $http_code >= 300) {
            return ['ok' => false, 'error' => sprintf(__('HTTP %d while registering the Dashboard App.', 'chatwoot'), $http_code)];
        }

        $data = json_decode((string) $body, true);
        return ['ok' => true, 'id' => (int) ($data['id'] ?? $id)];
    }

    /**
     * Dashboard App panel: validates the integration token against the one
     * saved in the configuration.
     */
    public static function isValidDashboardAppToken(string $token): bool
    {
        if ($token === '') {
            return false;
        }
        $config = self::getConfig();
        $saved  = (string) ($config['dashboard_app_token'] ?? '');
        return $saved !== '' && hash_equals($saved, $token);
    }

    /**
     * Looks up the GLPI user by identifier (username) and returns the
     * tickets where they are the requester, most recent first. Used by the
     * Dashboard App panel — each returned ticket already comes with a direct
     * link to open it in GLPI.
     * Accepts search by title/number and a status filter — every call
     * queries the database directly, so it isn't limited to what was loaded
     * initially (the panel sends a fresh request on every search/filter).
     *
     * @return array{user_found: bool, user_name?: string, tickets: array<int, array>}
     */
    public static function getTicketsForIdentifier(string $identifier, string $search = '', int $status = 0, int $limit = 50, string $phone = ''): array
    {
        global $DB;

        $user = self::resolveUser($identifier, $phone);
        if (!$user) {
            return ['user_found' => false, 'tickets' => []];
        }

        $user_name = trim((string) $user['firstname'] . ' ' . (string) $user['realname']) ?: (string) $user['name'];

        $where = [
            'glpi_tickets_users.users_id' => (int) $user['id'],
            'glpi_tickets_users.type'     => 1, // 1 = CommonITILActor::REQUESTER
            'glpi_tickets.is_deleted'     => 0,
        ];

        if ($status > 0) {
            $where['glpi_tickets.status'] = $status;
        }

        $search = trim($search);
        if ($search !== '') {
            $or = [['glpi_tickets.name' => ['LIKE', '%' . $search . '%']]];
            if (ctype_digit($search)) {
                $or[] = ['glpi_tickets.id' => (int) $search];
            }
            $where[] = ['OR' => $or];
        }

        $tickets = [];
        if ($DB->tableExists('glpi_tickets_users') && $DB->tableExists('glpi_tickets')) {
            $criteria = [
                'SELECT' => [
                    'glpi_tickets.id',
                    'glpi_tickets.name',
                    'glpi_tickets.status',
                    'glpi_tickets.date',
                    'glpi_tickets.date_mod',
                    'glpi_tickets.time_to_resolve',
                    'glpi_tickets.solvedate',
                    'glpi_tickets.closedate',
                ],
                'FROM'      => 'glpi_tickets_users',
                'INNER JOIN' => [
                    'glpi_tickets' => [
                        'FKEY' => ['glpi_tickets_users' => 'tickets_id', 'glpi_tickets' => 'id'],
                    ],
                ],
                'WHERE'  => $where,
                'ORDER'  => 'glpi_tickets.date_mod DESC',
                'LIMIT'  => $limit,
            ];

            $has_locations = $DB->tableExists('glpi_locations') && $DB->fieldExists('glpi_tickets', 'locations_id');
            if ($has_locations) {
                $criteria['SELECT'][] = 'glpi_locations.name AS location_name';
                $criteria['LEFT JOIN'] = [
                    'glpi_locations' => ['FKEY' => ['glpi_tickets' => 'locations_id', 'glpi_locations' => 'id']],
                ];
            }

            foreach ($DB->request($criteria) as $row) {
                $sla = self::computeSlaProgress($row);
                $tickets[(int) $row['id']] = [
                    'id'          => (int) $row['id'],
                    'title'       => (string) $row['name'],
                    'status'      => self::ticketStatusLabel((int) $row['status']),
                    'status_id'   => (int) $row['status'],
                    'date'        => (string) $row['date'],
                    'date_mod'    => (string) $row['date_mod'],
                    'location'    => $has_locations ? (string) ($row['location_name'] ?? '') : '',
                    'technician'  => '',
                    'sla_percent' => $sla['percent'],
                    'sla_color'   => $sla['color'],
                    'url'         => '/front/ticket.form.php?id=' . (int) $row['id'],
                ];
            }

            self::attachTechnicians($tickets);
        }

        return ['user_found' => true, 'user_name' => $user_name, 'tickets' => array_values($tickets)];
    }

    /**
     * Looks up the GLPI user by identifier (username) and, if not found,
     * tries again by phone — useful for old conversations or contacts where
     * the identifier didn't match for some reason, but the phone number is
     * the same one registered in GLPI.
     *
     * @return array{id: int, name: string, realname: string, firstname: string}|null
     */
    private static function resolveUser(string $identifier, string $phone = ''): ?array
    {
        global $DB;

        if (!$DB->tableExists('glpi_users')) {
            return null;
        }

        if ($identifier !== '') {
            $rows = iterator_to_array($DB->request([
                'SELECT' => ['id', 'name', 'realname', 'firstname'],
                'FROM'   => 'glpi_users',
                'WHERE'  => ['name' => $identifier],
                'LIMIT'  => 1,
            ]));
            $user = reset($rows);
            if ($user) {
                return $user;
            }
        }

        if ($phone !== '') {
            $user_id = self::findUserIdByPhone($phone);
            if ($user_id !== null) {
                $rows = iterator_to_array($DB->request([
                    'SELECT' => ['id', 'name', 'realname', 'firstname'],
                    'FROM'   => 'glpi_users',
                    'WHERE'  => ['id' => $user_id],
                    'LIMIT'  => 1,
                ]));
                $user = reset($rows);
                if ($user) {
                    return $user;
                }
            }
        }

        return null;
    }

    /**
     * Looks up a user by phone, checking the three fields GLPI has (Phone,
     * Mobile phone, Phone 2), comparing only the digits on both sides — GLPI
     * usually stores it formatted ("55 11 9411-9832"), and Chatwoot sends it
     * in E.164 format ("+5511941198320"). They can't be compared directly:
     * both are normalized by removing everything that isn't a digit.
     *
     * Since formatting can break a LIKE search in the middle of the digits
     * (the dash in "9411-9832" splits the sequence), this can't be safely
     * filtered directly in SQL — we compare user by user in PHP. For
     * installations with tens of thousands of users this can get slow; in
     * that case this function should be revisited before using it in production.
     */
    private static function findUserIdByPhone(string $raw_phone): ?int
    {
        global $DB;

        $digits = preg_replace('/\D+/', '', $raw_phone);
        if ($digits === '' || !$DB->tableExists('glpi_users')) {
            return null;
        }

        $has_mobile = $DB->fieldExists('glpi_users', 'mobile');
        $has_phone  = $DB->fieldExists('glpi_users', 'phone');
        $has_phone2 = $DB->fieldExists('glpi_users', 'phone2');
        if (!$has_mobile && !$has_phone && !$has_phone2) {
            return null;
        }

        $select = ['id'];
        $or     = [];
        if ($has_mobile) {
            $select[] = 'mobile';
            $or[] = ['glpi_users.mobile' => ['<>', '']];
        }
        if ($has_phone) {
            $select[] = 'phone';
            $or[] = ['glpi_users.phone' => ['<>', '']];
        }
        if ($has_phone2) {
            $select[] = 'phone2';
            $or[] = ['glpi_users.phone2' => ['<>', '']];
        }

        foreach ($DB->request([
            'SELECT' => $select,
            'FROM'   => 'glpi_users',
            'WHERE'  => ['OR' => $or],
        ]) as $row) {
            foreach (['mobile', 'phone', 'phone2'] as $field) {
                if (empty($row[$field])) {
                    continue;
                }
                // Some records store more than one number in the same field
                // (e.g. "11 1234-5678 / 11 9876-5432") — split before
                // normalizing, otherwise the digits of both numbers end up
                // glued into a single sequence and never match by suffix.
                $parts = preg_split('/[\/;,]+|\bou\b/i', (string) $row[$field]) ?: [(string) $row[$field]];
                foreach ($parts as $part) {
                    $candidate = preg_replace('/\D+/', '', $part);
                    if ($candidate === '') {
                        continue;
                    }
                    $match = $candidate === $digits
                        || (strlen($candidate) >= 8 && str_ends_with($digits, $candidate))
                        || (strlen($digits) >= 8 && str_ends_with($candidate, $digits));
                    if ($match) {
                        return (int) $row['id'];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Approximation of the SLA progress that GLPI shows in the ticket
     * listing: uses the real deadline already computed by GLPI
     * (time_to_resolve, which already accounts for the calendar/holidays of
     * the applied SLA), so the deadline DATE is exact — only the color
     * thresholds (green/orange/red) are our own approximation, since there
     * is no public documentation confirming the exact values GLPI uses internally.
     *
     * @return array{percent: ?int, color: string} color: 'none'|'green'|'orange'|'red'
     */
    private static function computeSlaProgress(array $row): array
    {
        $status  = (int) $row['status'];
        $created = !empty($row['date']) ? strtotime((string) $row['date']) : false;
        $ttr     = !empty($row['time_to_resolve']) ? strtotime((string) $row['time_to_resolve']) : false;

        // 5 = Solved, 6 = Closed
        if (in_array($status, [5, 6], true)) {
            $resolved_at = !empty($row['solvedate'])
                ? strtotime((string) $row['solvedate'])
                : (!empty($row['closedate']) ? strtotime((string) $row['closedate']) : false);

            if ($ttr && $resolved_at) {
                return ['percent' => 100, 'color' => $resolved_at > $ttr ? 'red' : 'green'];
            }
            return ['percent' => 100, 'color' => 'green'];
        }

        // No SLA (time_to_resolve) set for this ticket.
        if (!$ttr || !$created || $ttr <= $created) {
            return ['percent' => null, 'color' => 'none'];
        }

        $now     = time();
        $elapsed = $now - $created;
        $total   = $ttr - $created;
        $percent = (int) round(max(0, min(100, $elapsed / $total * 100)));

        $color = 'green';
        if ($now > $ttr) {
            $color   = 'red';
            $percent = 100;
        } elseif ($percent >= 75) {
            $color = 'orange';
        }

        return ['percent' => $percent, 'color' => $color];
    }

    /**
     * Fetches the assigned technicians for several tickets at once (avoids
     * one query per ticket) and fills the 'technician' field of each item in
     * the array passed by reference.
     *
     * @param array<int, array> $tickets Indexed by ticket ID.
     */
    private static function attachTechnicians(array &$tickets): void
    {
        global $DB;

        $ticket_ids = array_keys($tickets);
        if (empty($ticket_ids) || !$DB->tableExists('glpi_users')) {
            return;
        }

        $names_by_ticket = [];
        foreach ($DB->request([
            'SELECT' => ['glpi_tickets_users.tickets_id', 'glpi_users.name', 'glpi_users.realname', 'glpi_users.firstname'],
            'FROM'   => 'glpi_tickets_users',
            'INNER JOIN' => [
                'glpi_users' => ['FKEY' => ['glpi_tickets_users' => 'users_id', 'glpi_users' => 'id']],
            ],
            'WHERE'  => [
                'glpi_tickets_users.tickets_id' => $ticket_ids,
                'glpi_tickets_users.type'       => 2, // 2 = CommonITILActor::ASSIGN
            ],
        ]) as $row) {
            $label = trim((string) $row['firstname'] . ' ' . (string) $row['realname']) ?: (string) $row['name'];
            $names_by_ticket[(int) $row['tickets_id']][] = $label;
        }

        foreach ($names_by_ticket as $ticket_id => $names) {
            if (isset($tickets[$ticket_id])) {
                $tickets[$ticket_id]['technician'] = implode(', ', array_unique($names));
            }
        }
    }

    /**
     * Full detail of a specific ticket — only returns data if the ticket
     * really belongs to the identified person as requester (same security
     * check as the listing, so this can't become a way to snoop on anyone's
     * tickets by guessing the number).
     */
    public static function getTicketDetail(int $ticket_id, string $identifier, string $phone = ''): ?array
    {
        global $DB;

        if ($ticket_id <= 0 || (!$DB->tableExists('glpi_users'))) {
            return null;
        }

        $user = self::resolveUser($identifier, $phone);
        if (!$user) {
            return null;
        }

        $criteria = [
            'SELECT' => [
                'glpi_tickets.id',
                'glpi_tickets.name',
                'glpi_tickets.status',
                'glpi_tickets.priority',
                'glpi_tickets.content',
                'glpi_tickets.date',
                'glpi_tickets.date_mod',
                'glpi_tickets.time_to_resolve',
                'glpi_tickets.solvedate',
                'glpi_tickets.closedate',
            ],
            'FROM'      => 'glpi_tickets_users',
            'INNER JOIN' => [
                'glpi_tickets' => ['FKEY' => ['glpi_tickets_users' => 'tickets_id', 'glpi_tickets' => 'id']],
            ],
            'WHERE'  => [
                'glpi_tickets_users.users_id' => (int) $user['id'],
                'glpi_tickets_users.type'     => 1, // requester
                'glpi_tickets.id'             => $ticket_id,
                'glpi_tickets.is_deleted'     => 0,
            ],
            'LIMIT'  => 1,
        ];

        $has_locations = $DB->tableExists('glpi_locations') && $DB->fieldExists('glpi_tickets', 'locations_id');
        if ($has_locations) {
            $criteria['SELECT'][] = 'glpi_locations.name AS location_name';
            $criteria['LEFT JOIN'] = [
                'glpi_locations' => ['FKEY' => ['glpi_tickets' => 'locations_id', 'glpi_locations' => 'id']],
            ];
        }

        $rows = iterator_to_array($DB->request($criteria));
        $row  = reset($rows);
        if (!$row) {
            return null;
        }

        $technician = '';
        foreach ($DB->request([
            'SELECT' => ['glpi_users.name', 'glpi_users.realname', 'glpi_users.firstname'],
            'FROM'   => 'glpi_tickets_users',
            'INNER JOIN' => [
                'glpi_users' => ['FKEY' => ['glpi_tickets_users' => 'users_id', 'glpi_users' => 'id']],
            ],
            'WHERE'  => ['glpi_tickets_users.tickets_id' => $ticket_id, 'glpi_tickets_users.type' => 2],
        ]) as $trow) {
            $label = trim((string) $trow['firstname'] . ' ' . (string) $trow['realname']) ?: (string) $trow['name'];
            $technician = $technician === '' ? $label : $technician . ', ' . $label;
        }

        // Ticket content in GLPI comes with HTML — before converting to
        // plain text, we keep the IDs of embedded images (captured from the
        // raw HTML), so they can be shown separately as thumbnails (served
        // by our own proxy, see streamTicketDocument()).
        $raw_content        = (string) ($row['content'] ?? '');
        $description_images = self::extractInlineImageIds($raw_content);
        $description        = trim(html_entity_decode(strip_tags($raw_content), ENT_QUOTES, 'UTF-8'));
        $sla                 = self::computeSlaProgress($row);

        return [
            'id'          => (int) $row['id'],
            'title'       => (string) $row['name'],
            'status'      => self::ticketStatusLabel((int) $row['status']),
            'status_id'   => (int) $row['status'],
            'priority'    => self::ticketPriorityLabel((int) ($row['priority'] ?? 0)),
            'description' => $description,
            'description_images' => $description_images,
            'location'    => $has_locations ? (string) ($row['location_name'] ?? '') : '',
            'technician'  => $technician,
            'sla_percent' => $sla['percent'],
            'sla_color'   => $sla['color'],
            'date'        => (string) $row['date'],
            'date_mod'    => (string) $row['date_mod'],
            'url'         => '/front/ticket.form.php?id=' . (int) $row['id'],
            'timeline'    => self::getTicketTimeline($ticket_id, (int) $user['id']),
            'attachments' => self::getTicketAttachments($ticket_id),
        ];
    }

    /**
     * Looks for <img> tags in an HTML string and extracts the referenced
     * GLPI document IDs (pattern "document.send.php?docid=123", used by
     * GLPI's rich text editor for images pasted/embedded in the content).
     *
     * @return array<int, int>
     */
    private static function extractInlineImageIds(string $html): array
    {
        if ($html === '' || stripos($html, '<img') === false) {
            return [];
        }

        preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $matches);

        $ids = [];
        foreach ($matches[1] as $src) {
            if (preg_match('/docid=(\d+)/', $src, $m)) {
                $ids[] = (int) $m[1];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Builds the ticket timeline (follow-ups from both sides + solution), in
     * chronological order. Each item is marked whether it's from the
     * requester themselves (to style it like a conversation, matching the
     * rest of the panel) and whether it's a private follow-up (an internal
     * note, not visible to the requester in the GLPI portal, but shown here
     * because whoever sees this panel is the agent, who needs the full context).
     *
     * Each query is protected by a column check + try/catch: if a field name
     * doesn't match this installation's schema, that piece is simply left
     * out, instead of breaking the whole screen with a 500.
     *
     * @return array<int, array>
     */
    private static function getTicketTimeline(int $ticket_id, int $requester_id): array
    {
        global $DB;

        $timeline = [];

        if ($DB->tableExists('glpi_itilfollowups')) {
            try {
                $select = ['glpi_itilfollowups.content', 'glpi_itilfollowups.date_creation'];
                $has_private = $DB->fieldExists('glpi_itilfollowups', 'is_private');
                $has_users   = $DB->fieldExists('glpi_itilfollowups', 'users_id');
                $has_source  = $DB->fieldExists('glpi_itilfollowups', 'requesttypes_id') && $DB->tableExists('glpi_requesttypes');
                if ($has_private) {
                    $select[] = 'glpi_itilfollowups.is_private';
                }
                if ($has_users) {
                    $select[] = 'glpi_itilfollowups.users_id';
                }
                if ($has_source) {
                    $select[] = 'glpi_itilfollowups.requesttypes_id';
                }

                $criteria = [
                    'SELECT' => $select,
                    'FROM'   => 'glpi_itilfollowups',
                    'WHERE'  => ['glpi_itilfollowups.itemtype' => 'Ticket', 'glpi_itilfollowups.items_id' => $ticket_id],
                ];

                if ($has_users && $DB->tableExists('glpi_users')) {
                    $criteria['SELECT'] = array_merge($criteria['SELECT'], ['glpi_users.name', 'glpi_users.realname', 'glpi_users.firstname']);
                    $criteria['LEFT JOIN']['glpi_users'] = ['FKEY' => ['glpi_itilfollowups' => 'users_id', 'glpi_users' => 'id']];
                }
                if ($has_source) {
                    $criteria['SELECT'][] = 'glpi_requesttypes.name AS source_name';
                    $criteria['LEFT JOIN']['glpi_requesttypes'] = ['FKEY' => ['glpi_itilfollowups' => 'requesttypes_id', 'glpi_requesttypes' => 'id']];
                }

                foreach ($DB->request($criteria) as $frow) {
                    $author = trim((string) ($frow['firstname'] ?? '') . ' ' . (string) ($frow['realname'] ?? ''))
                        ?: (string) ($frow['name'] ?? __('Unknown', 'chatwoot'));
                    $raw = (string) ($frow['content'] ?? '');
                    $timeline[] = [
                        'type'         => 'followup',
                        'author'       => $author,
                        'is_requester' => $has_users && (int) ($frow['users_id'] ?? 0) === $requester_id,
                        'is_private'   => $has_private && !empty($frow['is_private']),
                        'source'       => $has_source ? (string) ($frow['source_name'] ?? '') : '',
                        'date'         => (string) ($frow['date_creation'] ?? ''),
                        'content'      => trim(html_entity_decode(strip_tags($raw), ENT_QUOTES, 'UTF-8')),
                        'images'       => self::extractInlineImageIds($raw),
                    ];
                }
            } catch (\Throwable $e) {
                // Schema different than expected on this installation: no
                // follow-ups in the timeline, but the rest of the ticket
                // keeps working normally.
            }
        }

        if ($DB->tableExists('glpi_itilsolutions')) {
            try {
                $select = ['glpi_itilsolutions.content', 'glpi_itilsolutions.date_creation'];
                $has_status = $DB->fieldExists('glpi_itilsolutions', 'status');
                $has_users  = $DB->fieldExists('glpi_itilsolutions', 'users_id');
                if ($has_status) {
                    $select[] = 'glpi_itilsolutions.status';
                }
                if ($has_users) {
                    $select[] = 'glpi_itilsolutions.users_id';
                }

                $criteria = [
                    'SELECT' => $select,
                    'FROM'   => 'glpi_itilsolutions',
                    'WHERE'  => ['glpi_itilsolutions.itemtype' => 'Ticket', 'glpi_itilsolutions.items_id' => $ticket_id],
                ];

                if ($has_users && $DB->tableExists('glpi_users')) {
                    $criteria['SELECT'] = array_merge($criteria['SELECT'], ['glpi_users.name', 'glpi_users.realname', 'glpi_users.firstname']);
                    $criteria['LEFT JOIN'] = ['glpi_users' => ['FKEY' => ['glpi_itilsolutions' => 'users_id', 'glpi_users' => 'id']]];
                }

                foreach ($DB->request($criteria) as $srow) {
                    $author = trim((string) ($srow['firstname'] ?? '') . ' ' . (string) ($srow['realname'] ?? ''))
                        ?: (string) ($srow['name'] ?? __('Unknown', 'chatwoot'));
                    $raw = (string) ($srow['content'] ?? '');
                    $timeline[] = [
                        'type'         => 'solution',
                        'author'       => $author,
                        'is_requester' => $has_users && (int) ($srow['users_id'] ?? 0) === $requester_id,
                        'is_private'   => false,
                        'status'       => $has_status ? self::solutionStatusLabel((int) ($srow['status'] ?? 0)) : '',
                        'status_id'    => $has_status ? (int) ($srow['status'] ?? 0) : 0,
                        'date'         => (string) ($srow['date_creation'] ?? ''),
                        'content'      => trim(html_entity_decode(strip_tags($raw), ENT_QUOTES, 'UTF-8')),
                        'images'       => self::extractInlineImageIds($raw),
                    ];
                }
            } catch (\Throwable $e) {
                // Same as above: no solution in the timeline, rest of the
                // ticket keeps working fine.
            }
        }

        usort($timeline, static fn ($a, $b) => strcmp($a['date'], $b['date']));

        return $timeline;
    }

    /** English label for a ticket solution's status (CommonITILSolution). */
    private static function solutionStatusLabel(int $status): string
    {
        $labels = [
            1 => __('Proposed', 'chatwoot'),
            2 => __('Refused', 'chatwoot'),
            3 => __('Accepted', 'chatwoot'),
        ];
        return $labels[$status] ?? __('Proposed', 'chatwoot');
    }

    /**
     * Lists the ticket's attachments (id, name, size). The actual download
     * goes through streamTicketDocument(), which checks again that the
     * document belongs to this ticket before serving any byte.
     *
     * @return array<int, array{id: int, name: string, size: string}>
     */
    private static function getTicketAttachments(int $ticket_id): array
    {
        global $DB;

        $attachments = [];
        if (!$DB->tableExists('glpi_documents_items') || !$DB->tableExists('glpi_documents')) {
            return $attachments;
        }

        try {
            $has_name     = $DB->fieldExists('glpi_documents', 'name');
            $has_filename = $DB->fieldExists('glpi_documents', 'filename');
            $has_filesize = $DB->fieldExists('glpi_documents', 'filesize');
            $has_doc_deleted  = $DB->fieldExists('glpi_documents', 'is_deleted');
            $has_link_deleted = $DB->fieldExists('glpi_documents_items', 'is_deleted');

            $select = ['glpi_documents.id'];
            if ($has_name) {
                $select[] = 'glpi_documents.name';
            }
            if ($has_filename) {
                $select[] = 'glpi_documents.filename';
            }
            if ($has_filesize) {
                $select[] = 'glpi_documents.filesize';
            }

            $where = ['glpi_documents_items.itemtype' => 'Ticket', 'glpi_documents_items.items_id' => $ticket_id];
            // Deleted documents/links (trash bin) should not show up here —
            // without this check, old "ghost" entries could get mixed in
            // with the real attachments.
            if ($has_doc_deleted) {
                $where['glpi_documents.is_deleted'] = 0;
            }
            if ($has_link_deleted) {
                $where['glpi_documents_items.is_deleted'] = 0;
            }

            $seen = [];
            foreach ($DB->request([
                'SELECT'  => $select,
                'DISTINCT' => true,
                'FROM'    => 'glpi_documents_items',
                'INNER JOIN' => [
                    'glpi_documents' => ['FKEY' => ['glpi_documents_items' => 'documents_id', 'glpi_documents' => 'id']],
                ],
                'WHERE' => $where,
            ]) as $drow) {
                $doc_id = (int) ($drow['id'] ?? 0);
                if ($doc_id > 0 && isset($seen[$doc_id])) {
                    continue;
                }
                $seen[$doc_id] = true;

                $name = self::completeFileExtension(
                    trim((string) ($drow['name'] ?? '')),
                    trim((string) ($drow['filename'] ?? ''))
                );
                if ($name === '') {
                    $name = __('Unnamed file', 'chatwoot');
                }

                $attachments[] = [
                    'id'   => $doc_id,
                    'name' => $name,
                    'size' => $has_filesize ? self::formatFileSize((int) ($drow['filesize'] ?? 0)) : '',
                ];
            }
        } catch (\Throwable $e) {
            // Schema different than expected on this installation: no
            // attachments list, but the rest of the ticket keeps working normally.
        }

        return $attachments;
    }

    /**
     * Serves the content of a GLPI document (embedded image or attachment),
     * first confirming that it really belongs to a ticket where the
     * identified person is the requester — prevents the proxy from becoming
     * a way to download any document in the system just by knowing its ID.
     *
     * @return array{path: string, mime: string, name: string}|null
     */
    public static function streamTicketDocument(int $ticket_id, string $identifier, int $doc_id, string $phone = ''): ?array
    {
        global $DB;

        if ($ticket_id <= 0 || $doc_id <= 0) {
            return null;
        }
        if (!$DB->tableExists('glpi_users') || !$DB->tableExists('glpi_documents')) {
            return null;
        }

        $user = self::resolveUser($identifier, $phone);
        if (!$user) {
            return null;
        }

        // The ticket needs to belong to this user as requester...
        $owns_ticket = iterator_to_array($DB->request([
            'FROM'  => 'glpi_tickets_users',
            'WHERE' => ['users_id' => (int) $user['id'], 'type' => 1, 'tickets_id' => $ticket_id],
            'LIMIT' => 1,
        ]));
        if (empty($owns_ticket)) {
            return null;
        }

        // ...and the document needs to actually be linked to this ticket
        // (directly, or to one of its follow-ups/solution — images embedded
        // in replies get linked to the ITILFollowup/ITILSolution, not
        // directly to the Ticket).
        $linked = false;
        if ($DB->tableExists('glpi_documents_items')) {
            $direct = iterator_to_array($DB->request([
                'FROM'  => 'glpi_documents_items',
                'WHERE' => ['itemtype' => 'Ticket', 'items_id' => $ticket_id, 'documents_id' => $doc_id],
                'LIMIT' => 1,
            ]));
            if (!empty($direct)) {
                $linked = true;
            }

            if (!$linked && $DB->tableExists('glpi_itilfollowups')) {
                $followup_ids = array_column(iterator_to_array($DB->request([
                    'SELECT' => ['id'],
                    'FROM'   => 'glpi_itilfollowups',
                    'WHERE'  => ['itemtype' => 'Ticket', 'items_id' => $ticket_id],
                ])), 'id');
                if (!empty($followup_ids)) {
                    $via_followup = iterator_to_array($DB->request([
                        'FROM'  => 'glpi_documents_items',
                        'WHERE' => ['itemtype' => 'ITILFollowup', 'items_id' => $followup_ids, 'documents_id' => $doc_id],
                        'LIMIT' => 1,
                    ]));
                    $linked = !empty($via_followup);
                }
            }

            if (!$linked && $DB->tableExists('glpi_itilsolutions')) {
                $solution_ids = array_column(iterator_to_array($DB->request([
                    'SELECT' => ['id'],
                    'FROM'   => 'glpi_itilsolutions',
                    'WHERE'  => ['itemtype' => 'Ticket', 'items_id' => $ticket_id],
                ])), 'id');
                if (!empty($solution_ids)) {
                    $via_solution = iterator_to_array($DB->request([
                        'FROM'  => 'glpi_documents_items',
                        'WHERE' => ['itemtype' => 'ITILSolution', 'items_id' => $solution_ids, 'documents_id' => $doc_id],
                        'LIMIT' => 1,
                    ]));
                    $linked = !empty($via_solution);
                }
            }
        }

        if (!$linked) {
            return null;
        }

        $drows = iterator_to_array($DB->request([
            'FROM'  => 'glpi_documents',
            'WHERE' => ['id' => $doc_id],
            'LIMIT' => 1,
        ]));
        $doc = reset($drows);
        if (!$doc || empty($doc['filepath'])) {
            return null;
        }

        $path = rtrim(GLPI_DOC_DIR, '/') . '/' . ltrim((string) $doc['filepath'], '/');
        if (!is_file($path)) {
            return null;
        }

        $name = self::completeFileExtension(
            trim((string) ($doc['name'] ?? '')),
            trim((string) ($doc['filename'] ?? ''))
        );
        if ($name === '') {
            $name = basename($path);
        }

        return [
            'path' => $path,
            'mime' => (string) ($doc['mime'] ?? '') ?: 'application/octet-stream',
            'name' => $name,
        ];
    }

    /**
     * GLPI's "name" field is an editable title and doesn't always include
     * the file extension — completes it from the real filename (the stored
     * physical name), if it doesn't have one yet. Used both in the
     * attachments listing and in the actual download, so the two never diverge.
     */
    private static function completeFileExtension(string $name, string $filename): string
    {
        if ($name === '') {
            return $filename;
        }
        if ($filename !== '') {
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            if ($ext !== '' && !preg_match('/\.' . preg_quote($ext, '/') . '$/i', $name)) {
                $name .= '.' . $ext;
            }
        }
        return $name;
    }

    private static function formatFileSize(int $bytes): string
    {
        if ($bytes <= 0) {
            return '';
        }
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return round($bytes / (1024 * 1024), 1) . ' MB';
    }

    /** English label for GLPI ticket priorities (CommonITILObject). */
    private static function ticketPriorityLabel(int $priority): string
    {
        $labels = [
            1 => __('Very low', 'chatwoot'),
            2 => __('Low', 'chatwoot'),
            3 => __('Medium', 'chatwoot'),
            4 => __('High', 'chatwoot'),
            5 => __('Very high', 'chatwoot'),
            6 => __('Major', 'chatwoot'),
        ];
        return $labels[$priority] ?? __('Not set', 'chatwoot');
    }

    /** List of GLPI ticket statuses, in the order used by the filters (id => label). */
    public static function ticketStatusOptions(): array
    {
        return [
            1 => __('New', 'chatwoot'),
            2 => __('Processing (assigned)', 'chatwoot'),
            3 => __('Processing (planned)', 'chatwoot'),
            4 => __('Pending', 'chatwoot'),
            5 => __('Solved', 'chatwoot'),
            6 => __('Closed', 'chatwoot'),
        ];
    }

    /** English label for GLPI ticket statuses (CommonITILObject). */
    private static function ticketStatusLabel(int $status): string
    {
        return self::ticketStatusOptions()[$status] ?? __('Unknown', 'chatwoot');
    }

    /** Keeps only the digits and prefixes "+" (the E.164-like format Chatwoot expects). */
    private static function normalizePhone(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw);
        return $digits !== '' ? '+' . $digits : '';
    }

    /**
     * Only reformats if it's entirely uppercase (a common data-entry pattern
     * in GLPI, e.g. "PETROLINA"); names that already have proper
     * capitalization are left untouched.
     */
    private static function normalizeCase(string $value): string
    {
        $value = trim($value);
        if ($value === '' || $value !== mb_strtoupper($value, 'UTF-8')) {
            return $value;
        }
        return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
    }

    /** Most common country names in GLPI entities/locations -> English name + ISO code. */
    private static function countryInfo(string $raw): array
    {
        $map = [
            'brasil' => ['Brazil', 'BR'], 'brazil' => ['Brazil', 'BR'],
            'portugal' => ['Portugal', 'PT'],
            'estados unidos' => ['United States', 'US'], 'united states' => ['United States', 'US'], 'usa' => ['United States', 'US'],
            'argentina' => ['Argentina', 'AR'], 'chile' => ['Chile', 'CL'],
            'uruguai' => ['Uruguay', 'UY'], 'uruguay' => ['Uruguay', 'UY'],
            'paraguai' => ['Paraguay', 'PY'], 'paraguay' => ['Paraguay', 'PY'],
            'bolivia' => ['Bolivia', 'BO'], 'bolívia' => ['Bolivia', 'BO'],
            'mexico' => ['Mexico', 'MX'], 'méxico' => ['Mexico', 'MX'],
        ];
        $key = mb_strtolower(trim($raw));
        return $map[$key] ?? ['', ''];
    }

    /**
     * Builds the full identity of the logged-in user for Chatwoot:
     * identifier (username), name, email, phone, photo, and location — with
     * a User → Location → Entity fallback for phone/city/country, and
     * ALL-CAPS names normalized to proper capitalization. Used both when
     * loading the widget (setUser) and when syncing via the API
     * (syncContactAttributes), so the logic isn't duplicated.
     *
     * @return array{identifier:string, name:string, email:string, phone:string, avatar_url:string, city:string, country:string, country_code:string}
     */
    public static function getUserIdentity(int $user_id): array
    {
        $u = new User();
        $u->getFromDB($user_id);

        $identifier = (string) ($u->fields['name'] ?? '');
        if ($identifier === '') {
            $identifier = 'glpi-user-' . $user_id;
        }

        $name = trim((string) ($u->fields['firstname'] ?? '') . ' ' . (string) ($u->fields['realname'] ?? ''));
        if ($name === '') {
            $name = $u->getFriendlyName();
        }
        $name = self::normalizeCase($name);

        $email = (string) ($u->getDefaultEmail() ?: '');

        $raw_phone = (string) ($u->fields['mobile'] ?? '') ?: (string) ($u->fields['phone'] ?? '');
        $city      = (string) ($u->fields['town'] ?? '');
        $country   = (string) ($u->fields['country'] ?? '');

        if (($raw_phone === '' || $city === '' || $country === '') && !empty($u->fields['locations_id'])) {
            $location = new Location();
            if ($location->getFromDB((int) $u->fields['locations_id'])) {
                if ($raw_phone === '') {
                    $raw_phone = (string) ($location->fields['phonenumber'] ?? $location->fields['phone'] ?? '');
                }
                if ($city === '') {
                    $city = (string) ($location->fields['town'] ?? '');
                }
                if ($country === '') {
                    $country = (string) ($location->fields['country'] ?? '');
                }
            }
        }

        if (($raw_phone === '' || $city === '' || $country === '') && !empty($u->fields['entities_id'])) {
            $entity = new Entity();
            if ($entity->getFromDB((int) $u->fields['entities_id'])) {
                if ($raw_phone === '') {
                    $raw_phone = (string) ($entity->fields['phonenumber'] ?? $entity->fields['phone'] ?? '');
                }
                if ($city === '') {
                    $city = (string) ($entity->fields['town'] ?? '');
                }
                if ($country === '') {
                    $country = (string) ($entity->fields['country'] ?? '');
                }
            }
        }

        [$country_name, $country_code] = self::countryInfo($country);

        $avatar_url = '';
        if (!empty($u->fields['picture'])) {
            $glpikey = new GLPIKey();
            $token   = rawurlencode(base64_encode($glpikey->encrypt((string) $user_id)));
            $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host    = (string) ($_SERVER['HTTP_HOST'] ?? '');
            $avatar_url = $scheme . '://' . $host . '/plugins/chatwoot/avatar.php?t=' . $token;
        }

        return [
            'identifier'   => $identifier,
            'name'         => $name,
            'email'        => $email,
            'phone'        => self::normalizePhone($raw_phone),
            'avatar_url'   => $avatar_url,
            'city'         => self::normalizeCase($city),
            'country'      => $country_name,
            'country_code' => $country_code,
        ];
    }

    /**
     * Chatwoot returns the embed script already built, wrapped in
     * <script>...</script>. Since our file is loaded as plain .js (not
     * HTML), we extract only the JS content from inside the tags — the rest
     * is used exactly as Chatwoot sent it, without rewriting anything of our own.
     */
    private static function extractScriptContent(string $raw): string
    {
        if ($raw === '') {
            return '';
        }
        if (preg_match('#<script\b[^>]*>(.*)</script>#is', $raw, $matches)) {
            return trim($matches[1]);
        }
        return trim($raw);
    }

    public function prepareInputForUpdate($input)
    {
        $glpikey = new GLPIKey();

        foreach (self::ENCRYPTED_FIELDS as $field) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            if ($input[$field] === '') {
                $input[$field] = null;
                continue;
            }
            $input[$field] = $glpikey->encrypt((string) $input[$field]);
        }

        if (array_key_exists('enabled', $input)) {
            $input['enabled'] = (int) $input['enabled'];
        }

        return $input;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        return $item instanceof \Config
            ? self::createTabEntry(text: self::getTypeName(), icon: 'ti ti-message-circle')
            : '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if ($item instanceof \Config) {
            self::showForConfig();
        }
        return true;
    }

    public static function showForConfig(): void
    {
        if (!Session::haveRight(self::$rightname, READ)) {
            return;
        }

        $targets = self::getTargets();

        TemplateRenderer::getInstance()->display('@chatwoot/config.html.twig', [
            'config'         => self::getConfig(),
            'can_edit'       => Session::haveRight(self::$rightname, UPDATE),
            'targets'        => $targets,
            'profile_labels' => self::getLabelsByIds('Profile', $targets['Profile']),
            'group_labels'   => self::getLabelsByIds('Group', $targets['Group']),
            'user_labels'    => self::getLabelsByIds('User', $targets['User']),
        ]);
    }

    private static function targetsTable(): string
    {
        return 'glpi_plugin_chatwoot_targets';
    }

    /**
     * Who can see the widget, grouped by type. Empty lists in all three
     * types = no restriction (every authenticated user sees it, default behavior).
     *
     * @return array{Profile: int[], Group: int[], User: int[]}
     */
    public static function getTargets(): array
    {
        global $DB;

        $result = ['Profile' => [], 'Group' => [], 'User' => []];
        $table  = self::targetsTable();

        if (!$DB->tableExists($table)) {
            return $result;
        }

        foreach ($DB->request(['FROM' => $table]) as $row) {
            if (isset($result[$row['itemtype']])) {
                $result[$row['itemtype']][] = (int) $row['items_id'];
            }
        }

        return $result;
    }

    /**
     * Replaces the list of profiles/groups/users authorized to see the widget.
     */
    public static function saveTargets(array $profile_ids, array $group_ids, array $user_ids): void
    {
        global $DB;

        $table = self::targetsTable();
        if (!$DB->tableExists($table)) {
            return;
        }

        $DB->delete($table, ['id' => ['>', 0]]);

        $rows = [];
        foreach ($profile_ids as $id) {
            $rows[] = ['itemtype' => 'Profile', 'items_id' => (int) $id];
        }
        foreach ($group_ids as $id) {
            $rows[] = ['itemtype' => 'Group', 'items_id' => (int) $id];
        }
        foreach ($user_ids as $id) {
            $rows[] = ['itemtype' => 'User', 'items_id' => (int) $id];
        }

        foreach ($rows as $row) {
            $DB->insert($table, $row);
        }
    }

    /**
     * Checks whether the logged-in user can see the widget, considering
     * their active profile, their groups, and themselves directly. With no
     * targets configured at all, it's allowed for every authenticated user
     * (default behavior).
     */
    public static function canCurrentUserSeeWidget(): bool
    {
        global $DB;

        $targets = self::getTargets();
        if (empty($targets['Profile']) && empty($targets['Group']) && empty($targets['User'])) {
            return true;
        }

        $user_id = Session::getLoginUserID();
        if (!$user_id) {
            return false;
        }

        if (in_array((int) $user_id, $targets['User'], true)) {
            return true;
        }

        $active_profile = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
        if ($active_profile && in_array($active_profile, $targets['Profile'], true)) {
            return true;
        }

        if (!empty($targets['Group']) && $DB->tableExists('glpi_groups_users')) {
            $user_groups = [];
            foreach ($DB->request(['SELECT' => 'groups_id', 'FROM' => 'glpi_groups_users', 'WHERE' => ['users_id' => $user_id]]) as $row) {
                $user_groups[] = (int) $row['groups_id'];
            }
            if (array_intersect($user_groups, $targets['Group'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Metadata map used by the search and by label resolution, so the list
     * of tables/fields isn't repeated in every method.
     */
    private const TARGET_MAP = [
        'Profile' => ['table' => 'glpi_profiles', 'name_fields' => ['name']],
        'Group'   => ['table' => 'glpi_groups', 'name_fields' => ['name']],
        'User'    => ['table' => 'glpi_users', 'name_fields' => ['realname', 'firstname', 'name']],
    ];

    private static function formatTargetLabel(string $itemtype, array $row): string
    {
        if ($itemtype === 'User') {
            $label = trim((string) ($row['firstname'] ?? '') . ' ' . (string) ($row['realname'] ?? ''));
            return $label !== '' ? $label . ' (' . $row['name'] . ')' : (string) $row['name'];
        }
        return (string) ($row['name'] ?? '');
    }

    /**
     * Looks up profiles/groups/users by name for the permission screen's
     * pickers. With no search term, returns the first $limit (avoids loading
     * the entire user base at once).
     *
     * @return array<int, array{id:int, text:string}>
     */
    public static function searchTargets(string $itemtype, string $term, int $limit = 10): array
    {
        global $DB;

        if (!isset(self::TARGET_MAP[$itemtype])) {
            return [];
        }
        $meta  = self::TARGET_MAP[$itemtype];
        $table = $meta['table'];
        if (!$DB->tableExists($table)) {
            return [];
        }

        $criteria = [
            'SELECT' => array_merge(['id'], $meta['name_fields']),
            'FROM'   => $table,
            'ORDER'  => $meta['name_fields'][0],
            'LIMIT'  => max(1, $limit),
        ];

        $where = self::activeUserWhere($itemtype);

        $term = trim($term);
        if ($term !== '') {
            $or = [];
            foreach ($meta['name_fields'] as $field) {
                $or[] = [$field => ['LIKE', '%' . $term . '%']];
            }
            $where[] = ['OR' => $or];
        }

        if (!empty($where)) {
            $criteria['WHERE'] = count($where) > 1 ? ['AND' => $where] : reset($where);
        }

        $results = [];
        foreach ($DB->request($criteria) as $row) {
            $results[] = ['id' => (int) $row['id'], 'text' => self::formatTargetLabel($itemtype, $row)];
        }

        return $results;
    }

    /**
     * Only active (and not deleted) users can be selected in the permission
     * screen — checks whether the columns exist before filtering, so it
     * doesn't break on installations with a different schema.
     *
     * @return array<int, array>
     */
    private static function activeUserWhere(string $itemtype): array
    {
        global $DB;

        if ($itemtype !== 'User') {
            return [];
        }

        $conditions = [];
        if ($DB->fieldExists('glpi_users', 'is_active')) {
            $conditions[] = ['is_active' => 1];
        }
        if ($DB->fieldExists('glpi_users', 'is_deleted')) {
            $conditions[] = ['is_deleted' => 0];
        }

        return $conditions;
    }

    /**
     * Labels only for the already-selected IDs — used to pre-populate the
     * screen on load, without needing to download the entire list of
     * profiles/groups/users.
     *
     * @return array<int, string>
     */
    public static function getLabelsByIds(string $itemtype, array $ids): array
    {
        global $DB;

        if (empty($ids) || !isset(self::TARGET_MAP[$itemtype])) {
            return [];
        }
        $meta  = self::TARGET_MAP[$itemtype];
        $table = $meta['table'];
        if (!$DB->tableExists($table)) {
            return [];
        }

        $labels = [];
        foreach ($DB->request([
            'SELECT' => array_merge(['id'], $meta['name_fields']),
            'FROM'   => $table,
            'WHERE'  => ['id' => array_map('intval', $ids)],
        ]) as $row) {
            $labels[(int) $row['id']] = self::formatTargetLabel($itemtype, $row);
        }

        return $labels;
    }

    /**
     * Description shown under Setup > Maintenance > Automatic actions.
     */
    public static function cronInfo($name): array
    {
        switch ($name) {
            case 'refreshtoken':
                return [
                    'description' => __('Chatwoot: automatically updates the Inbox Website Token and HMAC Token', 'chatwoot'),
                ];
        }
        return [];
    }

    /**
     * Automatic action: fetches the current Website Token/HMAC Token from
     * the Chatwoot API and updates the saved configuration, without needing
     * anyone to open the screen and click Save. This way, if the token gets
     * regenerated (or the Inbox recreated) on the Chatwoot side, the GLPI
     * widget doesn't stay broken waiting for a manual update.
     */
    public static function cronRefreshtoken(\CronTask $task): int
    {
        $config = self::getConfig();

        if (
            empty($config['enabled'])
            || empty($config['base_url'])
            || empty($config['api_key'])
            || empty($config['account_id'])
            || empty($config['inbox_id'])
        ) {
            $task->addVolume(0);
            $task->log(__('Incomplete or disabled configuration: nothing to update.', 'chatwoot'));
            return 0;
        }

        $result = self::fetchInboxDetails(
            $config['base_url'],
            $config['api_key'],
            $config['account_id'],
            $config['inbox_id']
        );

        if (!$result['ok']) {
            $task->log(sprintf(__('Failed to update: %s', 'chatwoot'), $result['error']));
            return -1;
        }

        $item = self::getInstance();
        $item->update([
            'id'            => 1,
            'website_token' => $result['website_token'],
            'hmac_token'    => $result['hmac_token'],
            'widget_script' => $result['widget_script'],
        ]);

        $task->addVolume(1);
        $task->log(__('Website Token/HMAC Token/widget script successfully updated.', 'chatwoot'));

        return 1;
    }
}
