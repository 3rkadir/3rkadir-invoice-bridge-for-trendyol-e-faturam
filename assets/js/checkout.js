/* TY E-Faturam: ödeme sayfasında Bireysel / Kurumsal seçimine göre alanları açar. */
(function ($) {
    'use strict';

    var cfg = window.tefCheckout || {};
    var TYPE = 'input[name="billing_tef_customer_type"]';

    function setLabel($row, text) {
        var $label = $row.find('label').first();
        var $children = $label.children().detach();
        $label.text(text).append($children);
    }

    /**
     * @param {boolean} changeValue Değer alanına dokunulsun mu (yalnızca ilk yükleme ve seçim değişiminde).
     */
    function apply(changeValue) {
        var type = $(TYPE + ':checked').val() || '';
        var $number = $('#billing_tax_number');
        var $numberRow = $('#billing_tax_number_field');
        var $officeRow = $('#billing_tax_office_field');
        var current = $.trim($number.val());

        if (type === 'individual') {
            setLabel($numberRow, cfg.individualLabel);
            $number.attr('placeholder', cfg.individualPlaceholder);
            $numberRow.show();
            $officeRow.hide();
            if (changeValue && current === '') {
                $number.val(cfg.defaultTckn);
            }
        } else if (type === 'corporate') {
            setLabel($numberRow, cfg.corporateLabel);
            $number.attr('placeholder', cfg.corporatePlaceholder);
            $numberRow.show();
            $officeRow.show();
            if (changeValue && current === cfg.defaultTckn) {
                $number.val('');
            }
        } else {
            $numberRow.hide();
            $officeRow.hide();
        }
    }

    $(function () {
        apply(true);
        $(document.body).on('change', TYPE, function () {
            apply(true);
        });
        $(document.body).on('updated_checkout', function () {
            apply(false);
        });
    });
})(jQuery);
