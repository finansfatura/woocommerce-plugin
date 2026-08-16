<?php
if (!defined('ABSPATH')) {
    exit;
}

use Finansfatura\Client;
use Finansfatura\OAuth;

/**
 * OAuth 2.0 (authorization code + PKCE) as an alternative to the pasted API
 * key. The merchant clicks "Finansfatura'ya bağlan", approves the consent
 * screen, and the store holds a rotating refresh token instead of a key.
 *
 * Registration is per store, not per plugin: Finansfatura's authorize endpoint
 * matches `redirect_uri` EXACTLY against the client's allowlist, and the token
 * endpoint requires a client secret — so a plugin-wide client id shipped in the
 * source could neither cover every shop's callback URL nor keep its secret.
 * The settings screen therefore prints this store's callback URL to register
 * with partner@finansfatura.com. Shops without a client registration stay on
 * the API key, which is the default.
 *
 * Tokens live in their own option (not the settings array) so they are never
 * rendered on a form and never travel through a settings export.
 */
class FF_OAuth
{
    public const CONNECT_ACTION    = 'ff_oauth_connect';
    public const CALLBACK_ACTION   = 'ff_oauth_callback';
    public const DISCONNECT_ACTION = 'ff_oauth_disconnect';

    /** refresh this many seconds before the access token actually expires */
    private const REFRESH_SKEW = 60;

    public static function boot(): void
    {
        add_action('admin_post_' . self::CONNECT_ACTION, [__CLASS__, 'start']);
        add_action('admin_post_' . self::CALLBACK_ACTION, [__CLASS__, 'callback']);
        add_action('admin_post_' . self::DISCONNECT_ACTION, [__CLASS__, 'disconnect']);
    }

    /** The address to register with Finansfatura for this store. */
    public static function callback_url(): string
    {
        return admin_url('admin-post.php?action=' . self::CALLBACK_ACTION);
    }

    public static function is_connected(): bool
    {
        $tokens = get_option(FF_TOKEN_OPTION_KEY, []);
        return !empty($tokens['refresh_token']);
    }

    /**
     * A live access token, refreshing when it is about to expire. Throws when
     * the store isn't connected or the refresh is rejected — FF_Api_Client
     * turns that into the "not configured" WP_Error.
     */
    public static function access_token(): string
    {
        $tokens = get_option(FF_TOKEN_OPTION_KEY, []);
        if (empty($tokens['refresh_token'])) {
            throw new \RuntimeException(__('Finansfatura OAuth bağlantısı yok.', 'finansfatura-woo'));
        }
        if (!empty($tokens['access_token']) && (int) ($tokens['expires_at'] ?? 0) - self::REFRESH_SKEW > time()) {
            return (string) $tokens['access_token'];
        }
        // Every refresh invalidates the previous refresh token, so the result
        // is persisted before it is used.
        $fresh = self::oauth()->refresh((string) $tokens['refresh_token']);
        self::store_tokens($fresh);
        return (string) ($fresh['access_token'] ?? '');
    }

    /** Sends the merchant to the consent screen. */
    public static function start(): void
    {
        self::guard(self::CONNECT_ACTION);

        try {
            $oauth = self::oauth();
        } catch (\Throwable $e) {
            self::back_with('error', $e->getMessage());
        }

        ['verifier' => $verifier, 'challenge' => $challenge] = OAuth::generatePkce();
        $state = wp_generate_password(24, false);
        // The verifier must not reach the browser; it waits here for the
        // callback, keyed by the state we get back.
        set_transient('ff_oauth_' . $state, $verifier, 10 * MINUTE_IN_SECONDS);

        wp_redirect($oauth->authorizeUrl(['codeChallenge' => $challenge, 'state' => $state]));
        exit;
    }

    /** Consent screen came back with ?code=... */
    public static function callback(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Yetkisiz erişim.', 'finansfatura-woo'), '', ['response' => 403]);
        }
        $code  = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';
        $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
        if ($code === '' || $state === '') {
            $err = isset($_GET['error']) ? sanitize_text_field(wp_unslash($_GET['error'])) : __('Onay alınamadı.', 'finansfatura-woo');
            self::back_with('error', $err);
        }

        $verifier = get_transient('ff_oauth_' . $state);
        delete_transient('ff_oauth_' . $state);
        if (!$verifier) {
            self::back_with('error', __('Oturum eşleşmedi, tekrar deneyin.', 'finansfatura-woo'));
        }

        try {
            $token = self::oauth()->exchangeCode($code, ['codeVerifier' => (string) $verifier]);
        } catch (\Throwable $e) {
            self::back_with('error', $e->getMessage());
        }

        self::store_tokens($token);
        self::back_with('connected', '1');
    }

    public static function disconnect(): void
    {
        self::guard(self::DISCONNECT_ACTION);

        $tokens = get_option(FF_TOKEN_OPTION_KEY, []);
        if (!empty($tokens['refresh_token'])) {
            // Best effort: the local tokens go either way, otherwise a failed
            // revoke would leave the store stuck as "connected".
            try {
                self::oauth()->revoke((string) $tokens['refresh_token']);
            } catch (\Throwable $e) {
                FF_Logger::write(['error' => 'oauth revoke: ' . $e->getMessage()]);
            }
        }
        delete_option(FF_TOKEN_OPTION_KEY);
        self::back_with('disconnected', '1');
    }

    private static function oauth(): OAuth
    {
        $client_id     = FF_Settings::get('oauth_client_id');
        $client_secret = FF_Settings::get('oauth_client_secret');
        if ($client_id === '' || $client_secret === '') {
            throw new \RuntimeException(__('OAuth client_id / client_secret girilmedi.', 'finansfatura-woo'));
        }
        return new OAuth([
            'clientId'     => $client_id,
            'clientSecret' => $client_secret,
            'redirectUri'  => self::callback_url(),
            'baseUrl'      => FF_Settings::get('base_url', Client::DEFAULT_BASE_URL),
            'transport'    => FF_Api_Client::transport(),
        ]);
    }

    /** @param array<string,mixed> $token */
    private static function store_tokens(array $token): void
    {
        update_option(FF_TOKEN_OPTION_KEY, [
            'access_token'  => (string) ($token['access_token'] ?? ''),
            'refresh_token' => (string) ($token['refresh_token'] ?? ''),
            'expires_at'    => time() + (int) ($token['expires_in'] ?? 3600),
            'scope'         => (string) ($token['scope'] ?? ''),
        ], false); // no autoload: only the API client reads it
    }

    private static function guard(string $action): void
    {
        if (!current_user_can('manage_woocommerce') || !check_admin_referer($action)) {
            wp_die(esc_html__('Yetkisiz erişim.', 'finansfatura-woo'), '', ['response' => 403]);
        }
    }

    /** @return never */
    private static function back_with(string $key, string $value): void
    {
        wp_safe_redirect(add_query_arg(
            ['page' => 'finansfatura-woo', 'ff_' . $key => rawurlencode($value)],
            admin_url('admin.php')
        ));
        exit;
    }
}
