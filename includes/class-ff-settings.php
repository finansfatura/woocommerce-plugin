<?php
if (!defined('ABSPATH')) {
    exit;
}

use Finansfatura\Client;

/**
 * Admin settings page under WooCommerce → Finansfatura. Everything lives under
 * one option key (FF_OPTION_KEY) so backups + migrations stay simple; OAuth
 * tokens are deliberately NOT here (see FF_OAuth).
 *
 * Secrets (api_key, oauth_client_secret) are write-only: the form renders a
 * prefix, and saving an empty field keeps the stored value, so a merchant can
 * edit anything else without re-pasting the credential.
 */
class FF_Settings
{
    /** field => whether an empty submission keeps the stored value */
    private const SECRET_FIELDS = ['api_key', 'oauth_client_secret'];

    public static function boot(): void
    {
        add_action('admin_menu', [__CLASS__, 'register_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('wp_ajax_ff_test_connection', [__CLASS__, 'ajax_test_connection']);
    }

    public static function get(string $key, $default = ''): string
    {
        $opts = get_option(FF_OPTION_KEY, []);
        return isset($opts[$key]) && $opts[$key] !== '' ? (string) $opts[$key] : (string) $default;
    }

    public static function is_enabled(string $key): bool
    {
        return self::get($key) === 'yes';
    }

    public static function register_menu(): void
    {
        add_submenu_page(
            'woocommerce',
            __('Finansfatura', 'finansfatura-woo'),
            __('Finansfatura', 'finansfatura-woo'),
            'manage_woocommerce',
            'finansfatura-woo',
            [__CLASS__, 'render_page']
        );
    }

    public static function register_settings(): void
    {
        register_setting('ff_settings_group', FF_OPTION_KEY, [
            'type'              => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitize'],
            'default'           => [],
        ]);
    }

    public static function sanitize($input): array
    {
        $input = is_array($input) ? $input : [];
        $existing = get_option(FF_OPTION_KEY, []);
        $clean = [];

        foreach (self::SECRET_FIELDS as $field) {
            $new = trim((string) ($input[$field] ?? ''));
            $clean[$field] = $new !== '' ? $new : (string) ($existing[$field] ?? '');
        }

        $clean['auth_mode'] = ($input['auth_mode'] ?? '') === 'oauth' ? 'oauth' : 'api_key';
        $clean['base_url']  = esc_url_raw($input['base_url'] ?? Client::DEFAULT_BASE_URL);
        $clean['oauth_client_id'] = sanitize_text_field($input['oauth_client_id'] ?? '');

        $clean['auto_sale']                 = !empty($input['auto_sale']) ? 'yes' : 'no';
        $clean['auto_invoice']              = !empty($input['auto_invoice']) ? 'yes' : 'no';
        $clean['auto_issue_iade_on_refund'] = !empty($input['auto_issue_iade_on_refund']) ? 'yes' : 'no';

        return $clean;
    }

    public static function render_page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        $opts      = get_option(FF_OPTION_KEY, []);
        $mode      = $opts['auth_mode'] ?? 'api_key';
        $key       = (string) ($opts['api_key'] ?? '');
        $connected = FF_OAuth::is_connected();
        $name      = esc_attr(FF_OPTION_KEY);

        if (isset($_GET['ff_error'])) {
            echo '<div class="notice notice-error"><p>' .
                esc_html(sanitize_text_field(wp_unslash($_GET['ff_error']))) . '</p></div>';
        } elseif (isset($_GET['ff_connected'])) {
            echo '<div class="notice notice-success"><p>' .
                esc_html__('Finansfatura bağlantısı kuruldu.', 'finansfatura-woo') . '</p></div>';
        } elseif (isset($_GET['ff_disconnected'])) {
            echo '<div class="notice notice-success"><p>' .
                esc_html__('Bağlantı kesildi.', 'finansfatura-woo') . '</p></div>';
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Finansfatura — WooCommerce ayarları', 'finansfatura-woo'); ?></h1>
            <p class="description">
                <?php esc_html_e('Siparişler önce satış olarak aktarılır, fatura o satışın üzerine kesilir. Böylece fatura kesilemese bile satış ciroda ve stokta yerini alır.', 'finansfatura-woo'); ?>
            </p>

            <form action="options.php" method="post">
                <?php settings_fields('ff_settings_group'); ?>

                <h2><?php esc_html_e('Bağlantı', 'finansfatura-woo'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Kimlik doğrulama', 'finansfatura-woo'); ?></th>
                        <td>
                            <label>
                                <input type="radio" name="<?php echo $name; ?>[auth_mode]" value="api_key" <?php checked('api_key', $mode); ?> />
                                <?php esc_html_e('API anahtarı', 'finansfatura-woo'); ?>
                            </label>
                            &nbsp;&nbsp;
                            <label>
                                <input type="radio" name="<?php echo $name; ?>[auth_mode]" value="oauth" <?php checked('oauth', $mode); ?> />
                                <?php esc_html_e('OAuth 2.0', 'finansfatura-woo'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('API anahtarı: panelden üretip yapıştırırsınız, tek mağaza için en hızlısı. OAuth: mağazanızın callback adresi Finansfatura tarafında kayıtlıysa kullanılabilir.', 'finansfatura-woo'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ff_api_key"><?php esc_html_e('API anahtarı', 'finansfatura-woo'); ?></label></th>
                        <td>
                            <input name="<?php echo $name; ?>[api_key]" id="ff_api_key" type="password" class="regular-text" autocomplete="off" />
                            <?php if ($key !== ''): ?>
                                <p class="description">
                                    <?php
                                    printf(
                                        /* translators: %s = visible key prefix */
                                        esc_html__('Mevcut anahtar: %s… (boş bırakırsanız değişmez)', 'finansfatura-woo'),
                                        '<code>' . esc_html(substr($key, 0, 16)) . '</code>'
                                    );
                                    ?>
                                </p>
                            <?php else: ?>
                                <p class="description"><?php esc_html_e('Finansfatura panelinde Ayarlar → API Anahtarları üzerinden üretin.', 'finansfatura-woo'); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ff_base_url"><?php esc_html_e('API adresi', 'finansfatura-woo'); ?></label></th>
                        <td>
                            <input name="<?php echo $name; ?>[base_url]" id="ff_base_url" type="url" class="regular-text" value="<?php echo esc_attr($opts['base_url'] ?? Client::DEFAULT_BASE_URL); ?>" />
                            <button type="button" class="button" id="ff-test-connection"><?php esc_html_e('Bağlantıyı test et', 'finansfatura-woo'); ?></button>
                            <span id="ff-test-result" style="margin-left:8px;"></span>
                            <p class="description">
                                <?php
                                printf(
                                    /* translators: %s = sandbox url */
                                    esc_html__('Test ortamı: %s', 'finansfatura-woo'),
                                    '<code>' . esc_html(Client::SANDBOX_BASE_URL) . '</code>'
                                );
                                ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('OAuth 2.0', 'finansfatura-woo'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Callback adresi', 'finansfatura-woo'); ?></th>
                        <td>
                            <code><?php echo esc_html(FF_OAuth::callback_url()); ?></code>
                            <p class="description">
                                <?php esc_html_e('Bu adresi partner@finansfatura.com adresine bildirin; birebir kayıtlı olmayan callback reddedilir. Kayıt sonrası size verilen client_id / client_secret değerlerini aşağıya girin.', 'finansfatura-woo'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ff_oauth_client_id">client_id</label></th>
                        <td><input name="<?php echo $name; ?>[oauth_client_id]" id="ff_oauth_client_id" type="text" class="regular-text" value="<?php echo esc_attr($opts['oauth_client_id'] ?? ''); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ff_oauth_client_secret">client_secret</label></th>
                        <td>
                            <input name="<?php echo $name; ?>[oauth_client_secret]" id="ff_oauth_client_secret" type="password" class="regular-text" autocomplete="off" />
                            <?php if (!empty($opts['oauth_client_secret'])): ?>
                                <p class="description"><?php esc_html_e('Kayıtlı (boş bırakırsanız değişmez).', 'finansfatura-woo'); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Durum', 'finansfatura-woo'); ?></th>
                        <td>
                            <?php if ($connected): ?>
                                <strong><?php esc_html_e('Bağlı', 'finansfatura-woo'); ?></strong>
                            <?php else: ?>
                                <?php esc_html_e('Bağlı değil', 'finansfatura-woo'); ?>
                            <?php endif; ?>
                            <p class="description"><?php esc_html_e('Ayarları kaydettikten sonra bağlanın.', 'finansfatura-woo'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Otomasyon', 'finansfatura-woo'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Satış', 'finansfatura-woo'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo $name; ?>[auto_sale]" value="yes" <?php checked('yes', $opts['auto_sale'] ?? 'yes'); ?> />
                                <?php esc_html_e('Ödeme alınınca siparişi satış olarak aktar', 'finansfatura-woo'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Fatura', 'finansfatura-woo'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo $name; ?>[auto_invoice]" value="yes" <?php checked('yes', $opts['auto_invoice'] ?? 'yes'); ?> />
                                <?php esc_html_e('Sipariş "tamamlandı" olunca faturayı da kes', 'finansfatura-woo'); ?>
                            </label><br/>
                            <label>
                                <input type="checkbox" name="<?php echo $name; ?>[auto_issue_iade_on_refund]" value="yes" <?php checked('yes', $opts['auto_issue_iade_on_refund'] ?? 'yes'); ?> />
                                <?php esc_html_e('İade oluşturulunca IADE faturası kes', 'finansfatura-woo'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Kapatırsanız satış yine aktarılır; faturayı sipariş ekranındaki "Fatura Kes" düğmesinden veya Finansfatura panelinden kesersiniz.', 'finansfatura-woo'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Belge tipi', 'finansfatura-woo'); ?></th>
                        <td>
                            <p class="description"><?php esc_html_e('Belge e-Arşiv olarak gönderilir; alıcının VKN/TCKN\'si GİB\'de e-Fatura mükellefi çıkarsa sunucu belgeyi otomatik e-Faturaya yükseltir. Ayar gerekmez.', 'finansfatura-woo'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <?php if ($mode === 'oauth'): ?>
                <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                    <?php if ($connected): ?>
                        <input type="hidden" name="action" value="<?php echo esc_attr(FF_OAuth::DISCONNECT_ACTION); ?>" />
                        <?php wp_nonce_field(FF_OAuth::DISCONNECT_ACTION); ?>
                        <?php submit_button(__('Bağlantıyı kes', 'finansfatura-woo'), 'delete', 'submit', false); ?>
                    <?php else: ?>
                        <input type="hidden" name="action" value="<?php echo esc_attr(FF_OAuth::CONNECT_ACTION); ?>" />
                        <?php wp_nonce_field(FF_OAuth::CONNECT_ACTION); ?>
                        <?php submit_button(__('Finansfatura\'ya bağlan', 'finansfatura-woo'), 'primary', 'submit', false); ?>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
        </div>
        <script>
        (function(){
            var btn = document.getElementById('ff-test-connection');
            if (!btn) return;
            btn.addEventListener('click', function(){
                var out = document.getElementById('ff-test-result');
                out.textContent = '<?php echo esc_js(__('Test ediliyor…', 'finansfatura-woo')); ?>';
                var form = new FormData();
                form.append('action', 'ff_test_connection');
                form.append('_wpnonce', '<?php echo esc_js(wp_create_nonce('ff_test_connection')); ?>');
                fetch(ajaxurl, { method: 'POST', body: form, credentials: 'same-origin' })
                    .then(function(r){ return r.json(); })
                    .then(function(j){
                        out.textContent = j.success ? '<?php echo esc_js(__('Bağlantı başarılı.', 'finansfatura-woo')); ?>' : (j.data && j.data.message) || '<?php echo esc_js(__('Bağlantı başarısız.', 'finansfatura-woo')); ?>';
                    })
                    .catch(function(){ out.textContent = '<?php echo esc_js(__('Bağlantı başarısız.', 'finansfatura-woo')); ?>'; });
            });
        })();
        </script>
        <?php
    }

    /**
     * Cheapest call that proves the credential, the scope and the host all
     * work: one page of invoices.
     */
    public static function ajax_test_connection(): void
    {
        if (!current_user_can('manage_woocommerce') || !check_ajax_referer('ff_test_connection', '_wpnonce', false)) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }
        $resp = (new FF_Api_Client())->ping();
        if (is_wp_error($resp)) {
            wp_send_json_error(['message' => $resp->get_error_message()], 200);
        }
        wp_send_json_success(['ok' => true]);
    }
}
