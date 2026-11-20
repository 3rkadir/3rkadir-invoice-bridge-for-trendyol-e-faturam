<?php

namespace TYEfaturam;

defined('ABSPATH') || exit;

class ApiClient
{
    public const TOKEN_PREFIX = 'tef_token_';
    public const TAXPAYER_PREFIX = 'tef_tp_';

    private const BASE_URLS = [
        'test' => 'https://stage-apigateway.trendyolefaturam.com',
        'live' => 'https://apigateway.trendyolecozum.com',
    ];

    private string $lastError = '';

    /** @var string[] */
    private array $lastClaimKeys = [];

    private string $lastClaimsText = '';

    public function __construct(private Logger $logger)
    {
    }

    public static function baseUrl(): string
    {
        return self::BASE_URLS[Settings::isLive() ? 'live' : 'test'];
    }

    /**
     * Uç nokta yolları. Ayarlar > Gelişmiş bölümünden veya `ty_e_faturam_endpoint_paths` filtresiyle değiştirilebilir.
     * Yollarda {taxId} / :taxId ve {uuid} / :invoiceUuid yer tutucuları kullanılabilir.
     */
    public static function path(string $key, array $replace = []): string
    {
        $paths = [
            'sign_in'            => '/api/auth/signin',
            'taxpayer'           => '/api/invoice/taxpayers/{taxId}',
            'create_earchive'    => '/api/invoice/documents/earchive',
            'create_einvoice'    => '/api/invoice/documents/outgoing-einvoice',
            'status_earchive'    => '/api/invoice/documents/earchive/status/{uuid}',
            'status_einvoice'    => '/api/invoice/documents/outgoing-einvoice/status/{uuid}',
            'download_permanent' => '/api/invoice/documents/download/permanent-url',
            'download_temporary' => '/api/invoice/documents/download',
        ];
        $overrides = [
            'sign_in'         => 'path_sign_in',
            'taxpayer'        => 'path_taxpayer',
            'create_earchive' => 'path_create_earchive',
            'create_einvoice' => 'path_create_einvoice',
            'status_earchive' => 'path_status_earchive',
            'status_einvoice' => 'path_status_einvoice',
        ];
        foreach ($overrides as $name => $option) {
            $custom = trim((string) Settings::get($option));
            if ($custom !== '') {
                $paths[$name] = $custom;
            }
        }
        $paths = apply_filters('ty_e_faturam_endpoint_paths', $paths);

        $path = (string) ($paths[$key] ?? '');
        foreach ($replace as $name => $value) {
            $path = str_replace(['{' . $name . '}', ':' . $name], rawurlencode((string) $value), $path);
        }
        return $path;
    }

    private static function tokenKey(): string
    {
        return self::TOKEN_PREFIX . md5(self::baseUrl() . '|' . (string) Settings::get('username'));
    }

    public static function flushTokens(): void
    {
        global $wpdb;
        delete_transient(self::tokenKey());
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Süresi dolan/önbelleğe alınmış erişim anahtarı transient'lerini toplu siler.
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like('_transient_' . self::TOKEN_PREFIX) . '%',
                $wpdb->esc_like('_transient_timeout_' . self::TOKEN_PREFIX) . '%'
            )
        );
    }

    /**
     * @return array{ok:bool,code:int,data:array,raw:string,error:string,headers:array<string,string>}
     */
    public function request(
        string $method,
        string $path,
        ?array $body = null,
        int $orderId = 0,
        bool $auth = true,
        bool $retry = true,
        array $extraHeaders = []
    ): array {
        $result = ['ok' => false, 'code' => 0, 'data' => [], 'raw' => '', 'error' => '', 'headers' => []];
        $headers = array_merge(['Accept' => 'application/json', 'Content-Type' => 'application/json'], $extraHeaders);

        if ($auth) {
            $token = $this->getToken($orderId);
            if ($token === null) {
                $result['error'] = $this->lastError !== '' ? $this->lastError : __('Erişim anahtarı alınamadı.', 'ty-e-faturam');
                return $result;
            }
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $args = ['method' => $method, 'timeout' => 30, 'redirection' => 2, 'headers' => $headers];
        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request(self::baseUrl() . $path, $args);
        if (is_wp_error($response)) {
            $message = $response->get_error_message();
            $this->logger->log($orderId, $method, $path, 0, $message);
            $result['error'] = $message;
            return $result;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $this->logger->log($orderId, $method, $path, $code, $raw);

        if ($auth && $code === 401 && $retry) {
            delete_transient(self::tokenKey());
            return $this->request($method, $path, $body, $orderId, true, false, $extraHeaders);
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $data = $decoded;
        } elseif ($decoded !== null) {
            $data = ['value' => $decoded];
        } else {
            $data = [];
        }

        $rawHeaders = wp_remote_retrieve_headers($response);
        $flat = [];
        $source = (is_object($rawHeaders) && method_exists($rawHeaders, 'getAll')) ? $rawHeaders->getAll() : (array) $rawHeaders;
        foreach ($source as $name => $value) {
            $flat[strtolower((string) $name)] = is_array($value) ? (string) reset($value) : (string) $value;
        }

        $ok = $code >= 200 && $code < 300;
        return [
            'ok'      => $ok,
            'code'    => $code,
            'data'    => $data,
            'raw'     => $raw,
            'error'   => $ok ? '' : self::extractError($data, $raw, $code),
            'headers' => $flat,
        ];
    }

    public static function extractError(array $data, string $raw, int $code): string
    {
        foreach (['detail', 'message', 'title', 'error'] as $key) {
            if (isset($data[$key]) && is_string($data[$key]) && $data[$key] !== '') {
                return 'HTTP ' . $code . ': ' . $data[$key];
            }
        }
        $snippet = trim(wp_strip_all_tags(substr($raw, 0, 300)));
        return 'HTTP ' . $code . ($snippet !== '' ? ': ' . $snippet : '');
    }

    /**
     * POST /api/auth/signin (email + password). Erişim anahtarı yanıt başlığındadır (X-Access-Token),
     * gövde ise saniye cinsinden geçerlilik süresidir.
     */
    private function getToken(int $orderId): ?string
    {
        $cached = get_transient(self::tokenKey());
        if (is_string($cached) && $cached !== '') {
            if (!Settings::hasAccountIds()) {
                $this->captureAccount([], $cached);
            }
            return $cached;
        }

        $email = (string) Settings::get('username');
        $password = Settings::password();
        if ($email === '' || $password === '') {
            $this->lastError = __('API e-posta veya şifre tanımlı değil.', 'ty-e-faturam');
            return null;
        }

        $res = $this->request('POST', self::path('sign_in'), ['email' => $email, 'password' => $password], $orderId, false);
        if (!$res['ok']) {
            $this->lastError = sprintf(
                // translators: 1: HTTP status code, 2: error message.
                __('Giriş başarısız (%1$s): %2$s — E-posta/şifreyi ve Test/Canlı ortam seçimini kontrol edin; test ve canlı ortamın kullanıcıları farklı olabilir.', 'ty-e-faturam'),
                self::baseUrl() . self::path('sign_in'),
                $res['error']
            );
            return null;
        }

        $token = $this->pickToken($res['headers']);
        if ($token === '') {
            $this->lastError = sprintf(
                // translators: %s: list of response header names.
                __('Giriş başarılı ancak yanıt başlıklarında erişim anahtarı bulunamadı. Başlıklar: %s', 'ty-e-faturam'),
                implode(', ', array_keys($res['headers']))
            );
            return null;
        }

        $expires = isset($res['data']['value']) && is_numeric($res['data']['value']) ? (int) $res['data']['value'] : 0;
        $this->captureAccount($res['data'], $token);
        set_transient(self::tokenKey(), $token, $this->tokenTtl($token, $expires));
        return $token;
    }

    /**
     * @param array<string,string> $headers
     */
    private function pickToken(array $headers): string
    {
        foreach (['x-access-token', 'authorization', 'access-token', 'accesstoken', 'token'] as $name) {
            if (!empty($headers[$name])) {
                $value = trim($headers[$name]);
                return stripos($value, 'Bearer ') === 0 ? trim(substr($value, 7)) : $value;
            }
        }
        return '';
    }

    /**
     * @return array<string,mixed>
     */
    private function jwtClaims(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return [];
        }
        $b64 = strtr($parts[1], '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad > 0) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        $payload = json_decode((string) base64_decode($b64), true);
        return is_array($payload) ? $payload : [];
    }

    private function tokenTtl(string $token, int $expiresIn): int
    {
        if ($expiresIn > 0) {
            return max(60, min(DAY_IN_SECONDS, $expiresIn - 120));
        }
        $claims = $this->jwtClaims($token);
        if (isset($claims['exp']) && is_numeric($claims['exp'])) {
            return max(60, min(DAY_IN_SECONDS, (int) $claims['exp'] - time() - 120));
        }
        return 50 * MINUTE_IN_SECONDS;
    }

    /**
     * Token içeriğinden (JWT) ve giriş yanıtından companyId / userId bilgilerini çıkarıp saklar.
     */
    private function captureAccount(array $data, string $token): void
    {
        $found = ['company_id' => '', 'user_id' => '', 'company_uuid' => '', 'company_name' => ''];
        $claims = $this->jwtClaims($token);
        $this->lastClaimKeys = array_map('strval', array_keys($claims));
        $this->scanAccount($claims, $found);
        $this->scanAccount($data, $found);
        $this->scanKnownClaims($claims, $found);
        $this->scanClaimText($claims, $found);
        $this->lastClaimsText = $this->describeClaims($claims);
        Settings::saveAccount($found);
    }

    /**
     * TY E-Faturam token biçimi: `sub` kullanıcı ID'sidir; `privs` ise
     * {"<companyId>": ["EARCHIVE_WRITE", ...]} biçiminde şirket ID'si → yetkiler eşlemesidir.
     *
     * @param array<string,mixed>  $claims
     * @param array<string,string> $found
     */
    private function scanKnownClaims(array $claims, array &$found): void
    {
        if ($found['user_id'] === '' && isset($claims['sub']) && is_scalar($claims['sub']) && ctype_digit((string) $claims['sub'])) {
            $found['user_id'] = (string) $claims['sub'];
        }

        if ($found['company_id'] === '' && isset($claims['privs']) && is_array($claims['privs'])) {
            $first = '';
            foreach ($claims['privs'] as $companyId => $privileges) {
                $id = (string) $companyId;
                if (!ctype_digit($id)) {
                    continue;
                }
                if ($first === '') {
                    $first = $id;
                }
                if (is_array($privileges) && in_array('EARCHIVE_WRITE', $privileges, true)) {
                    $found['company_id'] = $id;
                    return;
                }
            }
            $found['company_id'] = $first;
        }
    }

    /**
     * Ad eşleşmesi bulunamazsa, yetki/rol (privs, rls) gibi alanlardaki metinlerde
     * "company…123456" / "user…123456" kalıplarını arar.
     *
     * @param array<string,mixed>  $claims
     * @param array<string,string> $found
     */
    private function scanClaimText(array $claims, array &$found): void
    {
        $text = (string) wp_json_encode($claims);
        if ($found['company_id'] === '' && preg_match('/company[^0-9a-z]{0,3}(\d{4,})/i', $text, $m)) {
            $found['company_id'] = $m[1];
        }
        if ($found['user_id'] === '' && preg_match('/user[^0-9a-z]{0,3}(\d{3,})/i', $text, $m)) {
            $found['user_id'] = $m[1];
        }
    }

    /**
     * Tanılama için token alanlarını özetler (e-posta maskelenir, uzun değerler kısaltılır).
     *
     * @param array<string,mixed> $claims
     */
    private function describeClaims(array $claims): string
    {
        $parts = [];
        foreach ($claims as $key => $value) {
            $text = is_scalar($value) ? (string) $value : (string) wp_json_encode($value);
            if (strtolower((string) $key) === 'eml' && strpos($text, '@') !== false) {
                [$local, $domain] = explode('@', $text, 2);
                $text = substr($local, 0, 2) . '***@' . $domain;
            }
            if (mb_strlen($text) > 160) {
                $text = mb_substr($text, 0, 160) . '…';
            }
            $parts[] = $key . '=' . $text;
        }
        return implode(' | ', $parts);
    }

    /**
     * Anahtar adlarını büyük/küçük harf, alt çizgi ve tire farkı gözetmeden eşleştirir
     * (companyId, company_id, CompanyID ...). İç içe nesnelerde company.id / user.id da desteklenir.
     *
     * @param array<string,mixed>  $node
     * @param array<string,string> $found
     */
    private function scanAccount(array $node, array &$found, string $parent = ''): void
    {
        foreach ($node as $key => $value) {
            $k = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $key));

            if (is_array($value)) {
                $this->scanAccount($value, $found, $k);
                continue;
            }
            if (!is_scalar($value) || $value === '') {
                continue;
            }
            $v = trim((string) $value);

            $isCompanyId = in_array($k, ['companyid', 'firmid', 'companyidentifier'], true) || ($k === 'id' && $parent === 'company');
            $isUserId = in_array($k, ['userid'], true) || ($k === 'id' && $parent === 'user');

            if ($found['company_id'] === '' && $isCompanyId && ctype_digit($v)) {
                $found['company_id'] = $v;
            }
            if ($found['user_id'] === '' && $isUserId && ctype_digit($v)) {
                $found['user_id'] = $v;
            }
            if ($found['company_uuid'] === '' && in_array($k, ['companyuuid'], true)) {
                $found['company_uuid'] = $v;
            }
            if ($found['company_name'] === '' && in_array($k, ['companyname', 'companytitle'], true)) {
                $found['company_name'] = $v;
            }
        }
    }

    /**
     * Giriş yapar, mümkün olan şirket bilgilerini çeker ve sonucu özetler.
     *
     * @return array{ok:bool,message:string}
     */
    public function resolveAccount(): array
    {
        self::flushTokens();
        $token = $this->getToken(0);
        if ($token === null) {
            return ['ok' => false, 'message' => $this->lastError];
        }

        $notes = [];
        $vkn = (string) Settings::get('company_vkn');
        if ($vkn !== '') {
            $query = $this->queryTaxpayer($vkn, 0, true);
            if ($query['ok']) {
                Settings::saveAccount(['company_name' => $query['info']['name']]);
            } else {
                // translators: %s: error message.
                $notes[] = sprintf(__('Şirket VKN sorgusu başarısız: %s', 'ty-e-faturam'), $query['error']);
            }
        }

        $labelCompany = __('Company ID', 'ty-e-faturam');
        $labelUser = __('User ID', 'ty-e-faturam');
        $labelName = __('Şirket Adı', 'ty-e-faturam');

        $found = [];
        $missing = [];
        $rows = [
            $labelCompany => Settings::companyId() > 0 ? (string) Settings::companyId() : '',
            $labelUser    => Settings::userId() > 0 ? (string) Settings::userId() : '',
            $labelName    => Settings::companyName(),
        ];
        foreach ($rows as $label => $value) {
            if ($value !== '') {
                $found[] = $label . ': ' . $value;
            } else {
                $missing[] = $label;
            }
        }

        $ok = Settings::hasAccountIds();
        $message = sprintf(
            // translators: %s: environment name (live or test).
            __('Giriş başarılı (%s ortam). ', 'ty-e-faturam'),
            Settings::isLive() ? __('canlı', 'ty-e-faturam') : __('test', 'ty-e-faturam')
        );
        if ($found) {
            // translators: %s: list of values found in the access token.
            $message .= sprintf(__('Bulunan: %s. ', 'ty-e-faturam'), implode(', ', $found));
        }
        if (!$ok) {
            $message .= __('Company ID ve User ID token içinde bulunamadı. ', 'ty-e-faturam');
            $message .= $this->lastClaimsText !== ''
                // translators: %s: contents of the access token.
                ? sprintf(__('Token içeriği: %s. ', 'ty-e-faturam'), $this->lastClaimsText)
                : __('Token JWT biçiminde değil. ', 'ty-e-faturam');
            $message .= __('Değerleri Elle Giriş bölümüne yazın (fatura için zorunludur). ', 'ty-e-faturam');
        }
        $auto = Settings::account();
        foreach (['company_id' => $labelCompany, 'user_id' => $labelUser] as $key => $label) {
            $manual = trim((string) Settings::get($key));
            if ($manual !== '' && $auto[$key] !== '' && $manual !== $auto[$key]) {
                $message .= sprintf(
                    // translators: 1: field label, 2: manually entered value, 3: value found in the token.
                    __('UYARI: Elle girilen %1$s (%2$s) tokendaki değerden (%3$s) farklı; elle girilen değer önceliklidir. Tokendaki değeri kullanmak için elle alanı boşaltın. ', 'ty-e-faturam'),
                    $label,
                    $manual,
                    $auto[$key]
                );
            }
        }
        if ($rows[$labelName] === '' && $vkn === '') {
            $message .= __('Şirket adı için Şirket VKN alanını doldurun. ', 'ty-e-faturam');
        }
        if ($notes) {
            $message .= implode(' ', $notes);
        }

        return ['ok' => $ok, 'message' => trim($message)];
    }

    /**
     * Mükellef sorgusu: GET /api/invoice/taxpayers/{taxId} → Taxpayer dizisi.
     *
     * @return array{ok:bool,taxpayer:bool,info:array{name:string,uuid:string,alias:string},error:string}
     */
    public function queryTaxpayer(string $taxId, int $orderId, bool $fresh = false): array
    {
        $empty = ['name' => '', 'uuid' => '', 'alias' => ''];
        $cacheKey = self::TAXPAYER_PREFIX . md5(self::baseUrl() . '|' . $taxId);

        if (!$fresh) {
            $cached = get_transient($cacheKey);
            if (is_string($cached)) {
                $decoded = json_decode($cached, true);
                if (is_array($decoded) && isset($decoded['taxpayer'], $decoded['info'])) {
                    return ['ok' => true, 'taxpayer' => (bool) $decoded['taxpayer'], 'info' => $decoded['info'], 'error' => ''];
                }
            }
        }

        $res = $this->request('GET', self::path('taxpayer', ['taxId' => $taxId]), null, $orderId);

        if ($res['code'] === 404) {
            set_transient($cacheKey, wp_json_encode(['taxpayer' => false, 'info' => $empty]), 12 * HOUR_IN_SECONDS);
            return ['ok' => true, 'taxpayer' => false, 'info' => $empty, 'error' => ''];
        }
        if (!$res['ok']) {
            return ['ok' => false, 'taxpayer' => false, 'info' => $empty, 'error' => $res['error']];
        }

        $parsed = $this->parseTaxpayers($res['data']);
        set_transient($cacheKey, wp_json_encode($parsed), 12 * HOUR_IN_SECONDS);
        return ['ok' => true, 'taxpayer' => $parsed['taxpayer'], 'info' => $parsed['info'], 'error' => ''];
    }

    /**
     * @return array{taxpayer:bool,info:array{name:string,uuid:string,alias:string}}
     */
    private function parseTaxpayers(array $data): array
    {
        $entries = [];
        if (isset($data[0]) && is_array($data[0])) {
            $entries = $data;
        } elseif ($data !== [] && isset($data['taxId'])) {
            $entries = [$data];
        }

        $invoiceEntries = array_values(array_filter(
            $entries,
            static fn($e): bool => is_array($e) && (!isset($e['aliasType']) || $e['aliasType'] === 'INVOICE') && empty($e['deletedAt'])
        ));

        if ($invoiceEntries === []) {
            return ['taxpayer' => false, 'info' => ['name' => '', 'uuid' => '', 'alias' => '']];
        }

        $chosen = $invoiceEntries[0];
        foreach ($invoiceEntries as $entry) {
            if (strtoupper((string) ($entry['postBoxType'] ?? '')) === 'PK') {
                $chosen = $entry;
                break;
            }
        }

        return [
            'taxpayer' => true,
            'info'     => [
                'name'  => trim((string) ($chosen['title'] ?? '')),
                'uuid'  => '',
                'alias' => trim((string) ($chosen['alias'] ?? '')),
            ],
        ];
    }

    /**
     * Ayar sayfasındaki test aracı: ham yanıtı ve yorumlanan sonucu döndürür.
     *
     * @return array{summary:string,detail:string}
     */
    public function inspectTaxpayer(string $taxId): array
    {
        $path = self::path('taxpayer', ['taxId' => $taxId]);
        $res = $this->request('GET', $path, null, 0);

        $raw = $res['raw'];
        if (mb_strlen($raw) > 3000) {
            $raw = mb_substr($raw, 0, 3000) . '…';
        }
        $detail = 'GET ' . self::baseUrl() . $path . ' → HTTP ' . $res['code'] . "\n" . $raw;

        if ($res['code'] === 0) {
            // translators: %s: error message.
            return ['summary' => sprintf(__('İstek başarısız: %s', 'ty-e-faturam'), $res['error']), 'detail' => $detail];
        }
        if ($res['ok']) {
            $parsed = $this->parseTaxpayers($res['data']);
            if (!$parsed['taxpayer']) {
                return ['summary' => __('e-Fatura mükellefi değil (e-Arşiv kesilir).', 'ty-e-faturam'), 'detail' => $detail];
            }
            $summary = __('e-Fatura mükellefi (e-Fatura kesilir).', 'ty-e-faturam');
            foreach (['name' => __('Unvan', 'ty-e-faturam'), 'alias' => __('Alias', 'ty-e-faturam')] as $key => $label) {
                if ($parsed['info'][$key] !== '') {
                    $summary .= ' ' . $label . ': ' . $parsed['info'][$key] . '.';
                }
            }
            return ['summary' => $summary, 'detail' => $detail];
        }
        if ($res['code'] === 404) {
            return ['summary' => __('Kayıt bulunamadı: e-Fatura mükellefi değil (e-Arşiv kesilir).', 'ty-e-faturam'), 'detail' => $detail];
        }
        return ['summary' => $res['error'], 'detail' => $detail];
    }

    public function createEArchive(array $payload, int $orderId): array
    {
        return $this->request('POST', self::path('create_earchive'), $payload, $orderId);
    }

    public function createOutgoingEInvoice(array $payload, int $orderId): array
    {
        return $this->request('POST', self::path('create_einvoice'), $payload, $orderId);
    }

    public function invoiceStatus(string $type, string $uuid, int $orderId): array
    {
        $key = $type === 'EFATURA' ? 'status_einvoice' : 'status_earchive';
        return $this->request('GET', self::path($key, ['uuid' => $uuid, 'invoiceUuid' => $uuid]), null, $orderId);
    }

    /**
     * PDF indirme bağlantısı (kalıcı veya geçici). Yanıt düz metin bir URL'dir.
     *
     * @param string $docType EARCHIVE | EINVOICE
     * @param string $kind    permanent | temporary
     */
    public function documentUrl(string $docType, string $uuid, string $kind, int $orderId): string
    {
        $body = [
            'documentType'  => $docType,
            'fileExtension' => 'pdf',
            'documentUuid'  => $uuid,
            'companyId'     => Settings::companyId(),
        ];
        $res = $this->request(
            'POST',
            self::path($kind === 'permanent' ? 'download_permanent' : 'download_temporary'),
            $body,
            $orderId,
            true,
            true,
            ['Accept' => 'text/plain']
        );
        if (!$res['ok']) {
            return '';
        }
        $url = isset($res['data']['value']) && is_string($res['data']['value']) ? $res['data']['value'] : $res['raw'];
        $url = trim($url, " \t\n\r\0\x0B\"");
        return preg_match('#^https?://#i', $url) ? esc_url_raw($url) : '';
    }
}
