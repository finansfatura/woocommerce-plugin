(function ($) {
    'use strict';

    $(function () {
        var $box = $('.ff-meta-box');
        if (!$box.length) return;

        $box.on('click', '[data-ff-action]', function () {
            var $btn = $(this);
            var $out = $box.find('.ff-result');
            $btn.prop('disabled', true);
            $out.text(FFAdmin.i18n.working);

            $.post(FFAdmin.ajaxUrl, {
                action: $btn.data('ff-action'),
                nonce: FFAdmin.nonce,
                order_id: $box.data('order-id')
            }).done(function (resp) {
                if (resp && resp.success) {
                    $out.text(FFAdmin.i18n.success);
                    window.setTimeout(function () { window.location.reload(); }, 1200);
                } else {
                    $out.text((resp && resp.data && resp.data.message) || FFAdmin.i18n.failed);
                    $btn.prop('disabled', false);
                }
            }).fail(function () {
                $out.text(FFAdmin.i18n.failed);
                $btn.prop('disabled', false);
            });
        });
    });
})(jQuery);
