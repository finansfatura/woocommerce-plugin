(function ($) {
    'use strict';

    function ffSync() {
        var type = $('input[name="ff_recipient_type"]:checked').val() || 'bireysel';
        $('[data-ff-show]').each(function () {
            var show = $(this).data('ff-show');
            $(this).toggle(show === type);
        });
    }

    $(function () {
        ffSync();
        $(document.body).on('change', 'input[name="ff_recipient_type"]', ffSync);
        $(document.body).on('updated_checkout', ffSync);
    });
})(jQuery);
