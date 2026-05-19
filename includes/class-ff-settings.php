<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin settings page under WooCommerce → Finansfatura. Stores everything
 * under a single option key (FF_OPTION_KEY) so backups + migrations stay simple.
 * The api_key field is write-only — the rendered form shows only the prefix
 * (last 4 chars of the stored hash window) so screenshots don't leak secrets.
 */
class FF_Settings
{
    public static function boot(): void
    {
        add_action('admin_menu', [__CLASS__, 'register_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('wp_ajax_ff_test_connection', [__CLASS__, 'ajax_test_connection']);
    }

    public static function get(string $key, $default = ''): string
    {
        $opts = get_option(FF_OPTION_KEY, []);
        return isset($opts[$key]) ? (string) $opts[$key] : (string) $default;
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

        // api_key: only overwrite when a non-empty new value comes in. This
        // lets the admin re-save other fields without re-entering the key.
        $clean['api_key'] = isset($input['api_key']) && trim((string) $input['api_key']) !== ''
            ? trim((string) $input['api_key'])
            : (isset($existing['api_key']) ? $existing['api_key'] : '');

        $clean['base_url']                 = esc_url_raw($input['base_url'] ?? 'https://api.finansfatura.com');
        $clean['default_doc_type']         = in_array($input['default_doc_type'] ?? '', ['EARSIV', 'EFATURA'], true)
            ? $input['default_doc_type']
            : 'EARSIV';
        $clean['auto_issue_on_complete']   = !empty($input['auto_issue_on_complete']) ? 'yes' : 'no';
        $clean['auto_issue_iade_on_refund']= !empty($input['auto_issue_iade_on_refund']) ? 'yes' : 'no';
        $clean['issuer_vkn']               = preg_replace('/\D+/', '', (string)($input['issuer_vkn'] ?? ''));
        $clean['issuer_title']             = sanitize_text_field($input['issuer_title'] ?? '');
        $clean['issuer_address']           = sanitize_textarea_field($input['issuer_address'] ?? '');
        $clean['issuer_tax_office']        = sanitize_text_field($input['issuer_tax_office'] ?? '');
        $clean['issuer_email']             = sanitize_email($input['issuer_email'] ?? '');
        $clean['issuer_phone']             = preg_replace('/[^\d+]/', '', (string)($input['issuer_phone'] ?? ''));

        return $clean;
    }

    public static function render_page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        $opts = get_option(FF_OPTION_KEY, []);
        $api_key_present = !empty($opts['api_key']);
        $masked_prefix = $api_key_present ? substr((string) $opts['api_key'], 0, 16) : '';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Finansfatura — WooCommerce ayarları', 'finansfatura-woo'); ?></h1>
            <form action="options.php" method="post">
                <?php settings_fields('ff_settings_group'); ?>

                <h2><?php esc_html_e('API bağlantısı', 'finansfatura-woo'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="ff_api_key"><?php esc_html_e('API anahtarı', 'finansfatura-woo'); ?></label></th>
                        <td>
                            <input name="<?php echo esc_attr(FF_OPTION_KEY); ?>[api_key]" id="ff_api_key" type="password" class="regular-text" autocomplete="off" />
                            <?php if ($api_key_present): ?>
                                <p class="description">
                                    <?php
                                    printf(
                                        /* translators: %s = visible key prefix */
                                        esc_html__('Mevcut anahtar: %s… (yeni anahtar yazmadan kaydederseniz değişmez)', 'finansfatura-woo'),
                                        '<code>' . esc_html($masked_prefix) . '</code>'
                                    );
                                    ?>
                                </p>
                            <?php else: ?>
                                <p class="description"><?php esc_html_e('Finansfatura panelinden Ayarlar → API Anahtarları üzerinden bir anahtar üretin.', 'finansfatura-woo'); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ff_base_url"><?php esc_html_e('API adresi', 'finansfatura-woo'); ?></label></th>
                        <td>
                            <input name="<?php echo esc_attr(FF_OPTION_KEY); ?>[base_url]" id="ff_base_url" type="url" class="regular-text" value="<?php echo esc_attr($opts['base_url'] ?? 'https://api.finansfatura.com'); ?>" />
                            <button type="button" class="button" id="ff-test-connection"><?php esc_html_e('Bağlantıyı test et', 'finansfatura-woo'); ?></button>
                            <span id="ff-test-result" style="margin-left:8px;"></span>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Fatura davranışı', 'finansfatura-woo'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Otomatik fatura', 'finansfatura-woo'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(FF_OPTION_KEY); ?>[auto_issue_on_complete]" value="yes" <?php checked('yes', $opts['auto_issue_on_complete'] ?? 'yes'); ?> />
                                <?php esc_html_e('Sipariş "tamamlandı" olunca otomatik kes', 'finansfatura-woo'); ?>
                            </label><br/>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(FF_OPTION_KEY); ?>[auto_issue_iade_on_refund]" value="yes" <?php checked('yes', $opts['auto_issue_iade_on_refund'] ?? 'yes'); ?> />
                                <?php esc_html_e('İade oluşturulunca IADE faturası kes', 'finansfatura-woo'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ff_default_doc_type"><?php esc_html_e('Varsayılan belge tipi', 'finansfatura-woo'); ?></label></th>
                        <td>
                            <select name="<?php echo esc_attr(FF_OPTION_KEY); ?>[default_doc_type]" id="ff_default_doc_type">
                                <option value="EARSIV" <?php selected('EARSIV', $opts['default_doc_type'] ?? 'EARSIV'); ?>>e-Arşiv</option>
                                <option value="EFATURA" <?php selected('EFATURA', $opts['default_doc_type'] ?? 'EARSIV'); ?>>e-Fatura</option>
                            </select>
                            <p class="description"><?php esc_html_e('Kurumsal alıcı + e-Fatura mükellefi seçildiğinde belge tipi otomatik EFATURA olur. Bireysel alıcılarda her zaman EARSIV.', 'finansfatura-woo'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Satıcı bilgileri (Issuer)', 'finansfatura-woo'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label><?php esc_html_e('VKN', 'finansfatura-woo'); ?></label></th>
                        <td><input name="<?php echo esc_attr(FF_OPTION_KEY); ?>[issuer_vkn]" type="text" class="regular-text" value="<?php echo esc_attr($opts['issuer_vkn'] ?? ''); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label><?php esc_html_e('Ünvan', 'finansfatura-woo'); ?></label></th>
                        <td><input name="<?php echo esc_attr(FF_OPTION_KEY); ?>[issuer_title]" type="text" class="regular-text" value="<?php echo esc_attr($opts['issuer_title'] ?? ''); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label><?php esc_html_e('Adres', 'finansfatura-woo'); ?></label></th>
                        <td><textarea name="<?php echo esc_attr(FF_OPTION_KEY); ?>[issuer_address]" class="large-text" rows="2"><?php echo esc_textarea($opts['issuer_address'] ?? ''); ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row"><label><?php esc_html_e('Vergi dairesi', 'finansfatura-woo'); ?></label></th>
                        <td><input name="<?php echo esc_attr(FF_OPTION_KEY); ?>[issuer_tax_office]" type="text" class="regular-text" value="<?php echo esc_attr($opts['issuer_tax_office'] ?? ''); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label><?php esc_html_e('E-posta', 'finansfatura-woo'); ?></label></th>
                        <td><input name="<?php echo esc_attr(FF_OPTION_KEY); ?>[issuer_email]" type="email" class="regular-text" value="<?php echo esc_attr($opts['issuer_email'] ?? ''); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label><?php esc_html_e('Telefon', 'finansfatura-woo'); ?></label></th>
                        <td><input name="<?php echo esc_attr(FF_OPTION_KEY); ?>[issuer_phone]" type="text" class="regular-text" value="<?php echo esc_attr($opts['issuer_phone'] ?? ''); ?>" /></td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
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

    public static function ajax_test_connection(): void
    {
        if (!current_user_can('manage_woocommerce') || !check_ajax_referer('ff_test_connection', '_wpnonce', false)) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }
        $client = new FF_Api_Client();
        $resp = $client->health();
        if (is_wp_error($resp)) {
            wp_send_json_error(['message' => $resp->get_error_message()], 500);
        }
        wp_send_json_success($resp);
    }
}
