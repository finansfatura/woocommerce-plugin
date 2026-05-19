(function ($) {
    'use strict';

    $(function () {
        var $box = $('.ff-meta-box');
        if (!$box.length) return;

        $('#ff-manual-issue').on('click', function () {
            var $btn = $(this);
            var $out = $('#ff-manual-issue-result');
            $btn.prop('disabled', true);
            $out.text(FFAdmin.i18n.issuing);

            $.post(FFAdmin.ajaxUrl, {
                action: 'ff_manual_issue',
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
