<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Injects the Turkish tax identifier fields into WooCommerce checkout:
 *   - Bireysel / Kurumsal seçimi
 *   - TCKN (11 hane) ya da VKN (10 hane)
 *   - Vergi dairesi
 *
 * There is deliberately no "e-Fatura mükellefi" checkbox: the server looks the
 * VKN up at GİB and upgrades the document itself, which beats asking a shopper
 * a question they routinely get wrong.
 *
 * Validation is enforced server-side; the toggle on the storefront is purely
 * progressive enhancement.
 */
class FF_Checkout_Fields
{
    public static function boot(): void
    {
        add_action('woocommerce_after_checkout_billing_form', [__CLASS__, 'render']);
        add_action('woocommerce_checkout_process', [__CLASS__, 'validate']);
        add_action('woocommerce_checkout_update_order_meta', [__CLASS__, 'save'], 10, 1);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue']);
    }

    public static function enqueue(): void
    {
        if (!is_checkout()) {
            return;
        }
        wp_enqueue_script(
            'ff-checkout',
            FF_PLUGIN_URL . 'assets/checkout.js',
            ['jquery'],
            FF_PLUGIN_VERSION,
            true
        );
    }

    public static function render(WC_Checkout $checkout): void
    {
        $type = $checkout->get_value('ff_recipient_type') ?: 'bireysel';
        ?>
        <div id="ff-tax-fields" class="ff-tax-fields">
            <h3><?php esc_html_e('Fatura bilgileri', 'finansfatura-for-woocommerce'); ?></h3>
            <p class="form-row form-row-wide">
                <label>
                    <input type="radio" name="ff_recipient_type" value="bireysel" <?php checked('bireysel', $type); ?> />
                    <?php esc_html_e('Bireysel', 'finansfatura-for-woocommerce'); ?>
                </label>
                &nbsp;&nbsp;
                <label>
                    <input type="radio" name="ff_recipient_type" value="kurumsal" <?php checked('kurumsal', $type); ?> />
                    <?php esc_html_e('Kurumsal', 'finansfatura-for-woocommerce'); ?>
                </label>
            </p>
            <p class="form-row form-row-wide" data-ff-show="bireysel">
                <label for="ff_tckn"><?php esc_html_e('TC Kimlik No (11 hane)', 'finansfatura-for-woocommerce'); ?></label>
                <input type="text" id="ff_tckn" name="ff_tckn" maxlength="11" inputmode="numeric" value="<?php echo esc_attr($checkout->get_value('ff_tckn')); ?>" />
            </p>
            <p class="form-row form-row-wide" data-ff-show="kurumsal">
                <label for="ff_vkn"><?php esc_html_e('VKN (10 hane)', 'finansfatura-for-woocommerce'); ?></label>
                <input type="text" id="ff_vkn" name="ff_vkn" maxlength="10" inputmode="numeric" value="<?php echo esc_attr($checkout->get_value('ff_vkn')); ?>" />
            </p>
            <p class="form-row form-row-wide" data-ff-show="kurumsal">
                <label for="ff_tax_office"><?php esc_html_e('Vergi Dairesi', 'finansfatura-for-woocommerce'); ?></label>
                <input type="text" id="ff_tax_office" name="ff_tax_office" value="<?php echo esc_attr($checkout->get_value('ff_tax_office')); ?>" />
            </p>
        </div>
        <?php
    }

    /**
     * Reads a checkout field from the POST body. WooCommerce verifies the
     * checkout nonce (woocommerce-process-checkout-nonce) before firing the
     * checkout hooks these methods are attached to, so no second check here.
     */
    private static function post_field(string $key): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce has already verified the checkout nonce.
        return isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : '';
    }

    public static function validate(): void
    {
        $type = self::post_field('ff_recipient_type') ?: 'bireysel';
        if ($type === 'bireysel') {
            $tckn = preg_replace('/\D+/', '', self::post_field('ff_tckn'));
            if ($tckn !== '' && strlen($tckn) !== 11) {
                wc_add_notice(__('TC Kimlik No 11 hane olmalıdır.', 'finansfatura-for-woocommerce'), 'error');
            }
        } else {
            $vkn = preg_replace('/\D+/', '', self::post_field('ff_vkn'));
            $office = trim(self::post_field('ff_tax_office'));
            if (strlen($vkn) !== 10) {
                wc_add_notice(__('Kurumsal fatura için VKN 10 hane olmalıdır.', 'finansfatura-for-woocommerce'), 'error');
            }
            if ($office === '') {
                wc_add_notice(__('Kurumsal fatura için vergi dairesi zorunludur.', 'finansfatura-for-woocommerce'), 'error');
            }
        }
    }

    public static function save(int $order_id): void
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        $type = self::post_field('ff_recipient_type') ?: 'bireysel';
        FF_Order_Meta::bulk_set($order, [
            FF_Order_Meta::RECIPIENT_TYPE => $type === 'kurumsal' ? 'kurumsal' : 'bireysel',
            FF_Order_Meta::TCKN           => preg_replace('/\D+/', '', self::post_field('ff_tckn')),
            FF_Order_Meta::VKN            => preg_replace('/\D+/', '', self::post_field('ff_vkn')),
            FF_Order_Meta::TAX_OFFICE     => self::post_field('ff_tax_office'),
        ]);
        $order->save();
    }
}
