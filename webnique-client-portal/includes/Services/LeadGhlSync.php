<?php
namespace WNQ\Services;

if (!defined('ABSPATH')) { exit; }

/** Direct, staff-controlled contact handoff. Never sends email itself. */
final class LeadGhlSync
{
    public const LOCATION = 'NHlmSHw4intOI2FPRcnO';
    public const TAG = 'Land Clearing Cold Email';
    private const HOOK = 'wnq_lead_ghl_worker';
    private const SETTINGS = 'wnq_lead_ghl_settings';
    private const TOKEN = 'wnq_lead_ghl_token';

    public static function register(): void
    {
        global $wpdb;
        if (get_option('wnq_lead_ghl_schema') !== '1') {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $table = self::table();
            dbDelta("CREATE TABLE {$table} (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                email_key varchar(64) NOT NULL,
                lead_id bigint unsigned NOT NULL,
                status varchar(24) NOT NULL DEFAULT 'queued',
                mode varchar(12) NOT NULL DEFAULT 'manual',
                contact_id varchar(100) NOT NULL DEFAULT '',
                stage varchar(24) NOT NULL DEFAULT '',
                approved_by bigint unsigned NOT NULL DEFAULT 0,
                attempts int unsigned NOT NULL DEFAULT 0,
                message varchar(500) NOT NULL DEFAULT '',
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                next_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY email_key (email_key),
                KEY pending (status,next_at)
            ) {$wpdb->get_charset_collate()};");
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table) {
                update_option('wnq_lead_ghl_schema', '1', false);
            }
        }
        add_filter('cron_schedules', static function ($s) {
            $s['wnq_ghl_minute'] = ['interval' => 60, 'display' => 'Lead handoff every minute'];
            return $s;
        });
        add_action(self::HOOK, [self::class, 'batch']);
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 60, 'wnq_ghl_minute', self::HOOK);
        }
        add_action('wnq_lead_created', [self::class, 'onCreated']);
        self::enableHandsFreeOnce();
    }

    public static function enableHandsFreeOnce(): void
    {
        if (!get_option('wnq_ghl_hands_free_v1', false) && self::configured()) {
            update_option(self::SETTINGS, ['automatic'=>true], false);
            update_option('wnq_ghl_hands_free_v1', true, false);
        }
    }

    public static function table(): string { global $wpdb; return $wpdb->prefix . 'wnq_lead_ghl_queue'; }
    public static function settings(): array { return (array)get_option(self::SETTINGS, []); }
    public static function configured(): bool { return self::token() !== ''; }
    public static function emailKey(string $email): string { return hash('sha256', self::LOCATION . '|' . strtolower(trim($email))); }

    /** Authenticated encryption, separate from every other provider's credentials. */
    public static function saveSettings(string $token, bool $automatic, bool $clear = false): void
    {
        if ($clear) {
            delete_option(self::TOKEN);
            $automatic = false;
        } elseif ($token !== '') {
            if (!preg_match('/^[\x21-\x7E]{10,4096}$/D', $token) || !function_exists('openssl_encrypt')) {
                throw new \RuntimeException('Enter a valid private integration token. OpenSSL is required.');
            }
            $iv = random_bytes(12);
            $cipher = openssl_encrypt($token, 'aes-256-gcm', hash('sha256', wp_salt('auth'), true), OPENSSL_RAW_DATA, $iv, $tag);
            if ($cipher === false) { throw new \RuntimeException('Token encryption failed.'); }
            $sealed = base64_encode($iv . $tag . $cipher);
            update_option(self::TOKEN, $sealed, false);
            if (get_option(self::TOKEN) !== $sealed) { throw new \RuntimeException('Token could not be saved.'); }
        }
        if ($automatic && !self::configured()) { throw new \RuntimeException('Save a token before enabling automatic sync.'); }
        update_option(self::SETTINGS, ['automatic' => $automatic], false);
        if (!$automatic) {
            global $wpdb;
            $wpdb->query('UPDATE ' . self::table() . " SET status='held', message='Automatic sync disabled; manual approval required.' WHERE status='queued' AND mode IN ('auto','auto_fast')");
        }
    }

    private static function token(): string
    {
        $raw = base64_decode((string)get_option(self::TOKEN, ''), true);
        if (!$raw || strlen($raw) < 29 || !function_exists('openssl_decrypt')) { return ''; }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', hash('sha256', wp_salt('auth'), true), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $plain === false ? '' : $plain;
    }

    public static function eligible(array $lead): bool
    {
        return self::qualification($lead) === ''
            && self::manualEligible($lead);
    }

    public static function manualEligible(array $lead): bool
    {
        return (bool)filter_var(trim($lead['email'] ?? ''), FILTER_VALIDATE_EMAIL)
            && in_array($lead['status'] ?? '', ['new', 'qualified'], true)
            && stripos($lead['notes'] ?? '', 'temporarily closed') === false;
    }

    public static function manualBlockReason(array $lead): string
    {
        if (!$lead) { return 'Lead no longer exists'; }
        if (!filter_var(trim($lead['email'] ?? ''), FILTER_VALIDATE_EMAIL)) { return 'Missing or invalid email'; }
        if (!in_array($lead['status'] ?? '', ['new', 'qualified'], true)) { return 'Status is not New/Qualified'; }
        if (stripos($lead['notes'] ?? '', 'temporarily closed') !== false) { return 'Temporarily closed'; }
        $state = self::state($lead);
        if ($state && !in_array($state['status'], ['failed', 'held', 'review'], true)) {
            return 'Existing handoff: ' . ($state['status'] ?? 'unknown');
        }
        return '';
    }

    public static function qualification(array $lead): string
    {
        $reviews = (int)($lead['review_count'] ?? 0);
        if ($reviews <= 0) { return 'Review count unconfirmed; review required.'; }
        if ($reviews >= 50) { return 'Excluded: 50 or more Google reviews.'; }
        if (($lead['company_fit'] ?? 'unknown') !== 'independent') {
            return in_array($lead['company_fit'] ?? '', ['chain','large'], true)
                ? 'Excluded: franchise/chain or large company.' : 'Review required: confirm small independent business.';
        }
        $minimum = (int)get_option('wnq_lead_seo_min', 0);
        if ($minimum > 0 && (empty($lead['seo_checked']) || (int)($lead['seo_score'] ?? 0) < $minimum)) {
            return 'SEO issue threshold not met or website not assessed.';
        }
        return '';
    }

    public static function lead(int $id): array
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}wnq_leads WHERE id = %d", $id), ARRAY_A) ?: [];
    }

    public static function state(array $lead): array
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE email_key = %s', self::emailKey($lead['email'] ?? '')), ARRAY_A) ?: [];
    }

    public static function onCreated(int $id): void
    {
        if (!empty(self::settings()['automatic']) && self::configured()) { self::enqueue($id, false, false, true); }
    }

    public static function queueBacklog(int $after, int $upper): array
    {
        global $wpdb;
        if (empty(self::settings()['automatic']) || !self::configured()) { throw new \RuntimeException('Enable automatic GHL sync and save its token first.'); }
        if (!$upper) { $upper = (int)$wpdb->get_var("SELECT MAX(id) FROM {$wpdb->prefix}wnq_leads"); }
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id FROM {$wpdb->prefix}wnq_leads WHERE id > %d AND id <= %d ORDER BY id LIMIT 100", $after, $upper), ARRAY_A);
        if (!is_array($rows)) { throw new \RuntimeException('Could not scan saved leads. Retry safely.'); }
        $queued = 0;
        foreach ($rows as $row) { $after = (int)$row['id']; if (self::enqueue($after, false, false, true)) $queued++; }
        return ['after'=>$after,'upper'=>$upper,'done'=>!$rows || $after >= $upper,'queued'=>$queued];
    }

    public static function enqueue(int $id, bool $manual = true, bool $override = false, bool $handsFree = false): bool
    {
        global $wpdb;
        $lead = self::lead($id);
        $override = $manual && $override;
        $handsFree = !$manual && $handsFree && !empty(self::settings()['automatic']);
        if (!self::configured() || !(($override || $handsFree) ? self::manualEligible($lead) : self::eligible($lead))) { return false; }
        $state = self::state($lead);
        if ($state) {
            // A suppressed or sent email stays blocked even after deleting/reimporting a lead.
            if (!$manual || !in_array($state['status'], ['failed', 'held', 'review'], true)) { return false; }
            return $wpdb->update(self::table(), [
                'status' => 'queued', 'lead_id' => $id, 'mode' => $override ? 'override' : 'manual', 'approved_by' => get_current_user_id(),
                'attempts' => 0, 'next_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
                'message' => 'Approved for retry; uncertain writes will only be reconciled.',
            ], ['id' => $state['id'], 'status' => $state['status']]) === 1;
        }
        $now = gmdate('Y-m-d H:i:s');
        return $wpdb->insert(self::table(), [
            'email_key' => self::emailKey($lead['email']), 'lead_id' => $id,
            'mode' => $handsFree ? 'auto_fast' : ($override ? 'override' : ($manual ? 'manual' : 'auto')), 'approved_by' => $manual ? get_current_user_id() : 0,
            'created_at' => $now, 'updated_at' => $now, 'next_at' => $now,
        ]) === 1;
    }

    public static function suppress(int $id): void
    {
        global $wpdb;
        $lead = self::lead($id);
        if (empty($lead['email'])) { return; }
        $lock = 'wnq_ghl_' . md5(self::table());
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)', $lock)) !== 1) {
            throw new \RuntimeException('A handoff is in progress. Retry suppression in a moment; check GHL for any workflow already started.');
        }
        $now = gmdate('Y-m-d H:i:s');
        try {
            if ($wpdb->query($wpdb->prepare('INSERT INTO ' . self::table() . " (email_key,lead_id,status,message,created_at,updated_at,next_at) VALUES (%s,%d,'suppressed','Suppressed by staff',%s,%s,%s) ON DUPLICATE KEY UPDATE status='suppressed',message='Suppressed by staff',updated_at=VALUES(updated_at)", self::emailKey($lead['email']), $id, $now, $now, $now)) === false) {
                throw new \RuntimeException('Suppression could not be saved. Try again.');
            }
        } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
    }

    private static function request(string $method, string $path, array $body = []): array
    {
        $token = self::token();
        if ($token === '') { throw new \RuntimeException('Save a private integration token first.'); }
        $args = ['method' => $method, 'timeout' => 12, 'redirection' => 0, 'sslverify' => true,
            'limit_response_size' => 1048576, 'headers' => ['Authorization' => 'Bearer ' . $token,
            'Version' => '2021-07-28', 'Accept' => 'application/json', 'Content-Type' => 'application/json']];
        if ($body) { $args['body'] = wp_json_encode($body); }
        $response = wp_remote_request('https://services.leadconnectorhq.com' . $path, $args);
        if (is_wp_error($response)) { throw new \RuntimeException('GHL network request failed. Retry after checking connectivity.'); }
        $code = (int)wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) { throw new \RuntimeException('GHL HTTP ' . $code . '. Check token scopes, location access, or rate limits.'); }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data)) { throw new \RuntimeException('GHL returned an invalid response.'); }
        return $data;
    }

    /** Read-only connection check; requires the existing trigger tag, never creates one. */
    public static function test(): void
    {
        $data = self::request('GET', '/locations/' . self::LOCATION . '/tags');
        if (!isset($data['tags']) || !is_array($data['tags'])) {
            throw new \RuntimeException('GHL did not return a valid tag list. Check locations/tags.readonly permission and location access. No contacts changed.');
        }
        foreach (($data['tags'] ?? []) as $tag) {
            if (strcasecmp((string)($tag['name'] ?? ''), self::TAG) === 0
                && ($tag['locationId'] ?? self::LOCATION) === self::LOCATION) { return; }
        }
        throw new \RuntimeException('Connection reached location ' . self::LOCATION . ', but the exact tag "' . self::TAG . '" was not found among ' . count($data['tags']) . ' returned tags. Check the tag in this sub-account, not the agency account. No contacts changed.');
    }

    private static function lookup(string $field, string $value): array
    {
        $data = self::request('GET', '/contacts/search/duplicate?' . http_build_query(['locationId' => self::LOCATION, $field => $value], '', '&', PHP_QUERY_RFC3986));
        // An undocumented/partial response must not be interpreted as permission to create.
        if (!array_key_exists('contact', $data)) { throw new \RuntimeException('Contact lookup could not be confirmed. No contact created.'); }
        if ($data['contact'] === null) { return []; }
        if (!is_array($data['contact']) || empty($data['contact']['id'])) { throw new \RuntimeException('Contact lookup returned incomplete data. No contact created.'); }
        return $data['contact'];
    }

    public static function contactSafe(array $contact, string $email): bool
    {
        return self::contactBlockReason($contact, $email) === '';
    }

    /** Read-only inspection of stored contact IDs; never creates, tags or requeues. */
    public static function diagnose(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT * FROM ' . self::table() . " WHERE status IN ('review','failed','queued') AND contact_id <> '' ORDER BY updated_at DESC LIMIT 3", ARRAY_A) ?: [];
        $reports = [];
        foreach ($rows as $job) {
            $lead = self::lead((int)$job['lead_id']);
            $data = self::request('GET', '/contacts/' . rawurlencode($job['contact_id']));
            $contact = $data['contact'] ?? [];
            if (!is_array($contact)) { $reports[] = 'Lead #' . (int)$job['lead_id'] . ': invalid contact response'; continue; }
            $value = $contact['dnd'] ?? null;
            $dnd = !array_key_exists('dnd', $contact) ? 'missing' : gettype($value);
            if (is_bool($value) || $value === 0 || $value === 1 || in_array($value, ['true','false','0','1'], true)) { $dnd .= '=' . json_encode($value); }
            $channels = [];
            foreach (is_array($contact['dndSettings'] ?? null) ? $contact['dndSettings'] : [] as $key => $settings) {
                if (!in_array(strtolower((string)$key), ['email','all'], true)) { continue; }
                $status = is_array($settings) ? ($settings['status'] ?? null) : null;
                $channels[] = strtolower((string)$key) . '=' . (in_array($status, ['active','inactive','permanent'], true) ? $status : 'missing/unrecognized');
            }
            $reports[] = 'Lead #' . (int)$job['lead_id'] . ': DND ' . $dnd
                . '; channel DND ' . ($channels ? implode(', ', $channels) : 'not supplied')
                . '; tags ' . (is_array($contact['tags'] ?? null) ? 'array' : 'missing/invalid')
                . '; check: ' . (self::contactBlockReason($contact, $lead['email'] ?? '') ?: 'passed');
        }
        return $reports ?: ['No stored GHL contact IDs available to inspect.'];
    }

    public static function contactBlockReason(array $contact, string $email): string
    {
        if (empty($contact['id'])) { return 'GHL returned no contact ID'; }
        if (($contact['locationId'] ?? '') !== self::LOCATION) { return 'GHL contact location is missing or does not match'; }
        if (strtolower(trim($contact['email'] ?? '')) !== strtolower(trim($email))) { return 'GHL contact email does not match the approved lead'; }
        // Missing is unknown, not false: delivery suppression remains with GHL.
        // Never set DND or coerce a supplied malformed value.
        if (array_key_exists('dnd', $contact) && !is_bool($contact['dnd'])) { return 'GHL returned a malformed DND status'; }
        if (($contact['dnd'] ?? false) === true) { return 'GHL contact has Do Not Disturb enabled'; }
        foreach (['deleted', 'unsubscribeEmail', 'bounceEmail'] as $flag) {
            if (!empty($contact[$flag])) { return 'GHL contact is blocked: ' . $flag; }
        }
        if (array_key_exists('dndSettings', $contact) && !is_array($contact['dndSettings'])) { return 'GHL returned invalid channel DND settings'; }
        foreach (($contact['dndSettings'] ?? []) as $channel => $settings) {
            if (!is_array($settings)) { return 'GHL returned invalid channel DND settings'; }
            if (in_array(strtolower((string)$channel), ['email', 'all'], true)
                && (!is_string($settings['status'] ?? null) || strtolower($settings['status']) !== 'inactive')) {
                return 'GHL email DND is active or unrecognized';
            }
        }
        if (!isset($contact['tags']) || !is_array($contact['tags'])) { return 'GHL did not return the contact tag list'; }
        return '';
    }

    public static function progress(): array
    {
        global $wpdb;
        $counts = ['queued'=>0, 'processing'=>0, 'sent'=>0, 'review'=>0, 'failed'=>0, 'held'=>0, 'suppressed'=>0];
        foreach ($wpdb->get_results('SELECT status, COUNT(*) AS total FROM ' . self::table() . ' GROUP BY status', ARRAY_A) ?: [] as $row) {
            if (isset($counts[$row['status']])) { $counts[$row['status']] = (int)$row['total']; }
        }
        return $counts;
    }

    /** Retry only the old DND-specific hold; worker re-fetches all safety fields. */
    public static function retryMissingDnd(): int
    {
        global $wpdb;
        self::test();
        $count = $wpdb->query($wpdb->prepare('UPDATE ' . self::table() . " SET status='queued', attempts=0, next_at=UTC_TIMESTAMP(), updated_at=UTC_TIMESTAMP() WHERE status IN ('review','failed') AND contact_id <> '' AND stage <> 'tag_started' AND message=%s",
            'GHL did not return a confirmed boolean DND status. No campaign tag applied.'));
        if ($count === false) { throw new \RuntimeException('Could not queue DND retries. Try again.'); }
        return (int)$count;
    }

    /** Small bounded cron batch. Browser processing has no one-minute delay. */
    public static function batch(): void
    {
        $start = microtime(true);
        for ($i = 0; $i < 10 && microtime(true) - $start < 15; $i++) {
            self::work();
        }
    }

    public static function hasTag(array $tags): bool
    {
        foreach ($tags as $tag) { if (is_string($tag) && strcasecmp($tag, self::TAG) === 0) { return true; } }
        return false;
    }

    private static function save(int $id, array $data): void
    {
        global $wpdb;
        $data['updated_at'] = gmdate('Y-m-d H:i:s');
        if ($wpdb->update(self::table(), $data, ['id' => $id]) === false) {
            throw new \RuntimeException('Queue state could not be saved. No further GHL writes attempted.');
        }
    }

    /** One contact per tick, serialized with a connection-scoped DB lock. */
    public static function work(): void
    {
        global $wpdb;
        if (!self::configured()) { return; }
        $lock = 'wnq_ghl_' . md5(self::table());
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)', $lock)) !== 1) { return; }
        $job = null;
        try {
            // A crashed worker must be explicitly reviewed; never blindly repeat a write.
            $wpdb->query('UPDATE ' . self::table() . " SET status='review', message='Interrupted handoff: retry reconciles before any further action.' WHERE status='processing'");
            $job = $wpdb->get_row('SELECT * FROM ' . self::table() . " WHERE status='queued' AND next_at <= UTC_TIMESTAMP() ORDER BY id LIMIT 1", ARRAY_A);
            if (!$job) { return; }
            $id = (int)$job['id'];
            if (in_array($job['mode'], ['auto','auto_fast'], true) && empty(self::settings()['automatic'])) {
                self::save($id, ['status' => 'held', 'message' => 'Automatic sync is off. Manual approval required.']); return;
            }
            $lead = self::lead((int)$job['lead_id']);
            if (!(in_array($job['mode'], ['override','auto_fast'], true) ? self::manualEligible($lead) : self::eligible($lead)) || self::emailKey($lead['email'] ?? '') !== $job['email_key']) {
                self::save($id, ['status' => 'held', 'message' => 'Lead deleted, email changed, or no longer eligible.']); return;
            }
            self::save($id, ['status' => 'processing', 'attempts' => (int)$job['attempts'] + 1]);
            self::test();
            $email = strtolower(trim($lead['email']));
            $contact = $job['contact_id'] ? ['id' => $job['contact_id']] : self::lookup('email', $email);
            $phone = preg_replace('/\D/', '', $lead['phone'] ?? '');
            if (strlen($phone) === 10) { $phone = '1' . $phone; }
            $phone = strlen($phone) === 11 && $phone[0] === '1' ? '+' . $phone : '';
            if (!$job['contact_id'] && $phone !== '') {
                $phoneMatch = self::lookup('number', $phone);
                if ($phoneMatch && (($phoneMatch['id'] ?? '') !== ($contact['id'] ?? ''))) {
                    throw new \RuntimeException('Phone/email contact conflict. Review matching in GHL; no contact changed.');
                }
            }
            if (empty($contact['id'])) {
                if ($job['stage'] !== '') { throw new \RuntimeException('Previous create outcome is uncertain. Review GHL manually; creation will not be repeated.'); }
                $body = ['locationId' => self::LOCATION, 'email' => $email, 'source' => 'Golden Web Marketing Lead Finder'];
                foreach (['business_name' => 'companyName', 'owner_first' => 'firstName', 'owner_last' => 'lastName', 'website' => 'website', 'address' => 'address1', 'city' => 'city', 'state' => 'state', 'zip' => 'postalCode'] as $from => $to) {
                    if (!empty($lead[$from])) { $body[$to] = $lead[$from]; }
                }
                if ($phone) { $body['phone'] = $phone; }
                $job['stage'] = 'create_started';
                self::save($id, ['stage' => $job['stage']]);
                $data = self::request('POST', '/contacts/', $body);
                $contact = $data['contact'] ?? [];
                if (empty($contact['id'])) { throw new \RuntimeException('Contact creation could not be confirmed. Review GHL.'); }
            }
            $contactId = (string)$contact['id'];
            self::save($id, ['contact_id' => $contactId]);
            $data = self::request('GET', '/contacts/' . rawurlencode($contactId));
            $contact = $data['contact'] ?? [];
            $reason = self::contactBlockReason($contact, $email);
            if ($reason !== '') { throw new \RuntimeException($reason . '. No campaign tag applied.'); }
            if (self::hasTag($contact['tags'])) {
                self::save($id, ['status' => 'sent', 'message' => 'Campaign tag already present; not applied again.']); return;
            }
            if ($job['stage'] === 'tag_started') { throw new \RuntimeException('Previous tag outcome is uncertain. Review workflow history manually; tag will not be repeated.'); }
            // Recheck local suppression and the automatic switch just before triggering outreach.
            $latest = self::state($lead);
            if (($latest['status'] ?? '') === 'suppressed') { return; }
            $freshLead = self::lead((int)$job['lead_id']);
            if (!(in_array($job['mode'], ['override','auto_fast'], true) ? self::manualEligible($freshLead) : self::eligible($freshLead)) || self::emailKey($freshLead['email'] ?? '') !== $job['email_key'] || (in_array($job['mode'], ['auto','auto_fast'], true) && empty(self::settings()['automatic']))) {
                self::save($id, ['status' => 'held', 'message' => 'Handoff paused before tagging.']); return;
            }
            $job['stage'] = 'tag_started';
            self::save($id, ['stage' => $job['stage']]);
            $result = self::request('POST', '/contacts/' . rawurlencode($contactId) . '/tags', ['tags' => [self::TAG]]);
            if (!self::hasTag($result['tags'] ?? [])) { throw new \RuntimeException('Tag result could not be confirmed. Retry will check without repeating an uncertain tag.'); }
            self::save($id, ['status' => 'sent', 'message' => 'Campaign tag confirmed. Email delivery is managed by GHL.']);
        } catch (\Throwable $e) {
            if ($job) {
                $attempts = (int)$job['attempts'] + 1;
                $uncertain = !empty($job['stage']);
                try {
                    self::save((int)$job['id'], ['status' => $uncertain ? 'review' : ($attempts < 3 ? 'queued' : 'failed'),
                        'message' => $e instanceof \RuntimeException ? $e->getMessage() : 'Handoff failed safely. Review configuration.',
                        'next_at' => gmdate('Y-m-d H:i:s', time() + 300 * $attempts)]);
                } catch (\Throwable $ignored) { /* Leave processing row for recovery; no raw errors logged. */ }
            }
        } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
    }
}
