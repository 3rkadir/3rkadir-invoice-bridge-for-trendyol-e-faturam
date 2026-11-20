<?php

namespace TYEfaturam;

defined('ABSPATH') || exit;

class Settings
{
    public const OPTION = 'tef_settings';
    public const GROUP = 'tef_settings_group';

    private static ?array $cache = null;

    public static function defaults(): array
    {
        return [
            'username'       => '',
            'password'       => '',
            'company_uuid'   => '',
            'company_id'     => '',
            'user_id'        => '',
            'environment'    => 'test',
            'auto_invoice'   => 'no',
            'draft_mode'     => 'yes',
            'language'       => 'auto',
            'extra_payload'  => '',
            'order_statuses' => ['completed'],
            'einvoice_prefix' => '',
            'earchive_prefix' => '',
            'tax_id_key'     => '_billing_tax_number',
            'tax_office_key' => '_billing_tax_office',
            'checkout_fields' => 'yes',
            'fallback_tckn'  => '11111111111',
            'delete_data'    => 'no',
            'account_pdf'    => 'yes',
            'customer_email' => 'off',
            'toggle_active'  => '#2271b1',
            'toggle_inactive' => '#e6e8eb',
            'company_vkn'    => '',
            'exemption_code'   => '351',
            'exemption_reason' => 'İstisna Olmayan Diğer',
            'path_sign_in'   => '',
            'path_taxpayer'        => '',
            'path_create_earchive' => '',
            'path_create_einvoice' => '',
            'path_status_earchive' => '',
            'path_status_einvoice' => '',
        ];
    }

    public static function all(): array
    {
        if (self::$cache === null) {
            $stored = get_option(self::OPTION, []);
            self::$cache = array_merge(self::defaults(), is_array($stored) ? $stored : []);
        }
        return self::$cache;
    }

    public static function get(string $key, mixed $default = ''): mixed
    {
        $all = self::all();
        return $all[$key] ?? $default;
    }

    public static function password(): string
    {
        return self::decrypt((string) self::get('password'));
    }

    public static function isLive(): bool
    {
        return self::get('environment') === 'live';
    }

    public static function isConfigured(): bool
    {
        return (string) self::get('username') !== '' && self::password() !== '';
    }

    private static function accountOption(): string
    {
        return 'tef_account_' . (self::isLive() ? 'live' : 'test');
    }

    /**
     * Girişte otomatik alınan şirket bilgileri (ortama göre ayrı saklanır).
     *
     * @return array{company_id:string,user_id:string,company_uuid:string,company_name:string}
     */
    public static function account(): array
    {
        $stored = get_option(self::accountOption(), []);
        return array_merge(
            ['company_id' => '', 'user_id' => '', 'company_uuid' => '', 'company_name' => ''],
            is_array($stored) ? $stored : []
        );
    }

    /**
     * @param array<string,string> $data Boş olmayan değerler mevcut kaydın üzerine yazılır.
     */
    public static function saveAccount(array $data): void
    {
        $current = self::account();
        foreach (array_keys($current) as $key) {
            if (isset($data[$key]) && (string) $data[$key] !== '') {
                $current[$key] = (string) $data[$key];
            }
        }
        update_option(self::accountOption(), $current, false);
    }

    public static function clearAccounts(): void
    {
        delete_option('tef_account_test');
        delete_option('tef_account_live');
    }

    public static function companyId(): int
    {
        $manual = (int) self::get('company_id');
        return $manual > 0 ? $manual : (int) self::account()['company_id'];
    }

    public static function userId(): int
    {
        $manual = (int) self::get('user_id');
        return $manual > 0 ? $manual : (int) self::account()['user_id'];
    }

    public static function companyUuid(): string
    {
        $manual = trim((string) self::get('company_uuid'));
        return $manual !== '' ? $manual : self::account()['company_uuid'];
    }

    public static function companyName(): string
    {
        return self::account()['company_name'];
    }

    public static function hasAccountIds(): bool
    {
        return self::companyId() > 0 && self::userId() > 0;
    }

    /**
     * @return string[]
     */
    public static function triggerStatuses(): array
    {
        $statuses = self::get('order_statuses', []);
        return is_array($statuses) ? array_values($statuses) : [];
    }

    public static function register(): void
    {
        register_setting(self::GROUP, self::OPTION, [
            'type'              => 'array',
            'sanitize_callback' => [self::class, 'sanitize'],
            'default'           => self::defaults(),
        ]);
        add_filter('option_page_capability_' . self::GROUP, static function (): string {
            return 'manage_woocommerce';
        });
    }

    public static function sanitize(mixed $input): array
    {
        $input = is_array($input) ? $input : [];
        $old = self::all();
        $defaults = self::defaults();
        $out = $defaults;

        $out['username'] = sanitize_text_field((string) ($input['username'] ?? ''));
        $out['company_uuid'] = sanitize_text_field((string) ($input['company_uuid'] ?? ''));
        $out['company_id'] = preg_replace('/\D/', '', (string) ($input['company_id'] ?? ''));
        $out['user_id'] = preg_replace('/\D/', '', (string) ($input['user_id'] ?? ''));

        $lang = (string) ($input['language'] ?? 'auto');
        $out['language'] = in_array($lang, ['auto', 'tr', 'en'], true) ? $lang : 'auto';

        foreach (['toggle_active', 'toggle_inactive'] as $colorKey) {
            $color = sanitize_hex_color((string) ($input[$colorKey] ?? ''));
            $out[$colorKey] = $color ? $color : $defaults[$colorKey];
        }

        $mailMode = (string) ($input['customer_email'] ?? 'off');
        $out['customer_email'] = in_array($mailMode, ['off', 'link', 'attach'], true) ? $mailMode : 'off';

        $env = (string) ($input['environment'] ?? 'test');
        $out['environment'] = in_array($env, ['test', 'live'], true) ? $env : 'test';

        foreach (['auto_invoice', 'draft_mode', 'checkout_fields', 'delete_data', 'account_pdf'] as $flag) {
            $out[$flag] = !empty($input[$flag]) && $input[$flag] !== 'no' ? 'yes' : 'no';
        }

        $valid = array_map(
            static fn(string $k): string => preg_replace('/^wc-/', '', $k),
            array_keys(wc_get_order_statuses())
        );
        $selected = isset($input['order_statuses']) && is_array($input['order_statuses'])
            ? array_map('sanitize_key', $input['order_statuses'])
            : [];
        $out['order_statuses'] = array_values(array_intersect($selected, $valid));

        foreach (['einvoice_prefix', 'earchive_prefix'] as $key) {
            $value = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($input[$key] ?? '')));
            $value = substr($value, 0, 3);
            $out[$key] = strlen($value) === 3 ? $value : '';
        }

        foreach (['tax_id_key', 'tax_office_key'] as $key) {
            $value = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) ($input[$key] ?? ''));
            $out[$key] = $value !== '' ? $value : $defaults[$key];
        }

        $tckn = preg_replace('/\D/', '', (string) ($input['fallback_tckn'] ?? ''));
        $out['fallback_tckn'] = strlen($tckn) === 11 ? $tckn : $defaults['fallback_tckn'];

        $code = preg_replace('/\D/', '', (string) ($input['exemption_code'] ?? ''));
        $out['exemption_code'] = $code !== '' ? substr((string) $code, 0, 4) : $defaults['exemption_code'];
        $reason = sanitize_text_field((string) ($input['exemption_reason'] ?? ''));
        $out['exemption_reason'] = $reason !== '' ? mb_substr($reason, 0, 200) : $defaults['exemption_reason'];

        $extra = trim((string) ($input['extra_payload'] ?? ''));
        $decodedExtra = $extra !== '' ? json_decode($extra, true) : null;
        $out['extra_payload'] = (is_array($decodedExtra) && $decodedExtra !== [] && !array_is_list($decodedExtra))
            ? (string) wp_json_encode($decodedExtra)
            : '';

        $vkn = preg_replace('/\D/', '', (string) ($input['company_vkn'] ?? ''));
        $out['company_vkn'] = preg_match('/^\d{10,11}$/', (string) $vkn) ? (string) $vkn : '';

        foreach (['path_sign_in', 'path_taxpayer', 'path_create_earchive', 'path_create_einvoice', 'path_status_earchive', 'path_status_einvoice'] as $key) {
            $path = preg_replace('/[^A-Za-z0-9_\-\/\{\}\.:]/', '', (string) ($input[$key] ?? ''));
            $path = (string) $path;
            $out[$key] = $path !== '' ? '/' . ltrim($path, '/') : '';
        }

        if (
            (string) ($old['username'] ?? '') !== $out['username']
            || (string) ($old['environment'] ?? 'test') !== $out['environment']
            || (string) ($old['company_vkn'] ?? '') !== $out['company_vkn']
        ) {
            self::clearAccounts();
        }

        $password = trim((string) ($input['password'] ?? ''));
        if ($password === '') {
            $out['password'] = (string) ($old['password'] ?? '');
        } elseif (str_starts_with($password, 'enc:') || str_starts_with($password, 'plain:')) {
            $out['password'] = $password;
        } else {
            $out['password'] = self::encrypt($password);
        }

        self::$cache = null;
        ApiClient::flushTokens();
        return $out;
    }

    private static function key(): string
    {
        return hash('sha256', wp_salt('auth'), true);
    }

    public static function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        if (!function_exists('openssl_encrypt')) {
            return 'plain:' . base64_encode($plain);
        }
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plain, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            return 'plain:' . base64_encode($plain);
        }
        return 'enc:' . base64_encode($iv . $cipher);
    }

    public static function decrypt(string $stored): string
    {
        if ($stored === '') {
            return '';
        }
        if (str_starts_with($stored, 'plain:')) {
            return (string) base64_decode(substr($stored, 6), true);
        }
        if (str_starts_with($stored, 'enc:')) {
            $raw = base64_decode(substr($stored, 4), true);
            if ($raw === false || strlen($raw) < 17 || !function_exists('openssl_decrypt')) {
                return '';
            }
            $plain = openssl_decrypt(substr($raw, 16), 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 16));
            return $plain === false ? '' : $plain;
        }
        return $stored;
    }
}
