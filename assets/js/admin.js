(function () {
    'use strict';

    if (typeof tefAdmin === 'undefined') {
        return;
    }

    function post(params) {
        var body = new URLSearchParams();
        Object.keys(params).forEach(function (key) {
            body.append(key, params[key]);
        });
        body.append('nonce', tefAdmin.nonce);

        return fetch(tefAdmin.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        }).then(function (response) {
            return response.json();
        });
    }

    function setBusy(box, busy) {
        box.querySelectorAll('.tef-action').forEach(function (button) {
            button.disabled = busy;
        });
        var msg = box.querySelector('.tef-msg');
        if (busy) {
            if (!msg) {
                msg = document.createElement('span');
                msg.className = 'tef-msg';
                box.appendChild(msg);
            }
            msg.className = 'tef-msg';
            msg.textContent = tefAdmin.i18n.working;
        }
    }

    function showError(box, text) {
        var msg = box.querySelector('.tef-msg');
        if (!msg) {
            msg = document.createElement('span');
            box.appendChild(msg);
        }
        msg.className = 'tef-msg tef-msg-err';
        msg.textContent = text;
        box.querySelectorAll('.tef-action').forEach(function (button) {
            button.disabled = false;
        });
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('.tef-action');
        if (!button) {
            return;
        }
        event.preventDefault();

        var box = button.closest('.tef-actions');
        if (!box || button.disabled) {
            return;
        }

        var confirmText = button.getAttribute('data-confirm');
        if (confirmText && !window.confirm(confirmText)) {
            return;
        }

        var reload = box.getAttribute('data-reload') === '1';
        setBusy(box, true);

        post({
            action: 'tef_invoice_action',
            do: button.getAttribute('data-do'),
            order_id: box.getAttribute('data-order-id'),
            reload: reload ? '1' : '0'
        }).then(function (res) {
            var data = res && res.data ? res.data : {};
            if (!res || !res.success) {
                showError(box, data.message || tefAdmin.i18n.error);
                return;
            }
            if (reload) {
                box.querySelectorAll('.tef-action').forEach(function (b) {
                    b.disabled = true;
                });
                var msg = box.querySelector('.tef-msg');
                if (msg) {
                    msg.className = 'tef-msg ' + (data.ok ? 'tef-msg-ok' : 'tef-msg-err');
                    msg.textContent = data.message || '';
                }
                window.setTimeout(function () {
                    window.location.reload();
                }, 900);
                return;
            }
            if (data.html) {
                box.outerHTML = data.html;
            }
        }).catch(function () {
            showError(box, tefAdmin.i18n.error);
        });
    });

    document.addEventListener('submit', function (event) {
        var form = event.target;
        var text = form && form.getAttribute ? form.getAttribute('data-tef-confirm') : null;
        if (text && !window.confirm(text)) {
            event.preventDefault();
        }
    });

    /* Bekleyen Faturalar: seçili siparişleri sırayla (tek tek istekle) işler. */
    function format(text) {
        var args = Array.prototype.slice.call(arguments, 1);
        var index = 0;
        return String(text).replace(/%(\d+\$)?s/g, function (match, position) {
            var i = position ? parseInt(position, 10) - 1 : index++;
            return args[i];
        });
    }

    function pickAction(box) {
        var order = ['approve', 'resend', 'create'];
        for (var i = 0; i < order.length; i++) {
            if (box && box.querySelector('[data-do="' + order[i] + '"]')) {
                return order[i];
            }
        }
        return '';
    }

    var bulkAll = document.getElementById('tef-bulk-all');
    if (bulkAll) {
        bulkAll.addEventListener('change', function () {
            document.querySelectorAll('.tef-bulk-check').forEach(function (check) {
                check.checked = bulkAll.checked;
            });
        });
    }

    var bulkRun = document.getElementById('tef-bulk-run');
    if (bulkRun) {
        bulkRun.addEventListener('click', function () {
            var status = document.getElementById('tef-bulk-status');
            var ids = [];
            document.querySelectorAll('.tef-bulk-check:checked').forEach(function (check) {
                ids.push(check.value);
            });
            if (!ids.length) {
                status.textContent = tefAdmin.i18n.bulkNone;
                return;
            }
            if (!window.confirm(format(tefAdmin.i18n.bulkConfirm, ids.length))) {
                return;
            }

            bulkRun.disabled = true;
            var position = 0;
            var ok = 0;
            var failed = 0;

            function next() {
                if (position >= ids.length) {
                    status.textContent = format(tefAdmin.i18n.bulkDone, ok, failed);
                    bulkRun.disabled = false;
                    return;
                }
                var id = ids[position];
                status.textContent = format(tefAdmin.i18n.bulkProgress, position + 1, ids.length);
                var box = document.querySelector('.tef-actions[data-order-id="' + id + '"]');
                var doAction = pickAction(box);
                if (!doAction) {
                    position++;
                    next();
                    return;
                }
                post({ action: 'tef_invoice_action', do: doAction, order_id: id, reload: '0' }).then(function (res) {
                    var data = res && res.data ? res.data : {};
                    if (res && res.success && data.ok) {
                        ok++;
                    } else {
                        failed++;
                    }
                    if (data.html && box) {
                        box.outerHTML = data.html;
                    } else if (box) {
                        showError(box, data.message || tefAdmin.i18n.error);
                    }
                }).catch(function () {
                    failed++;
                    if (box) {
                        showError(box, tefAdmin.i18n.error);
                    }
                }).then(function () {
                    position++;
                    next();
                });
            }

            next();
        });
    }

    var testButton = document.getElementById('tef-test-connection');
    if (testButton) {
        testButton.addEventListener('click', function () {
            var result = document.getElementById('tef-test-result');
            testButton.disabled = true;
            result.className = 'tef-msg tef-block';
            result.textContent = tefAdmin.i18n.working;

            post({ action: 'tef_test_connection' }).then(function (res) {
                var ok = res && res.success;
                result.className = 'tef-msg tef-block ' + (ok ? 'tef-msg-ok' : 'tef-msg-err');
                result.textContent = res && res.data && res.data.message ? res.data.message : tefAdmin.i18n.error;
                if (ok) {
                    window.setTimeout(function () {
                        window.location.reload();
                    }, 2500);
                }
            }).catch(function () {
                result.className = 'tef-msg tef-block tef-msg-err';
                result.textContent = tefAdmin.i18n.error;
            }).then(function () {
                testButton.disabled = false;
            });
        });
    }

    var lookupButton = document.getElementById('tef-lookup');
    if (lookupButton) {
        lookupButton.addEventListener('click', function () {
            var input = document.getElementById('tef-lookup-taxid');
            var result = document.getElementById('tef-lookup-result');
            var detail = document.getElementById('tef-lookup-detail');
            lookupButton.disabled = true;
            result.className = 'tef-msg tef-block';
            result.textContent = tefAdmin.i18n.working;
            detail.hidden = true;

            post({ action: 'tef_lookup_taxpayer', tax_id: input.value }).then(function (res) {
                var ok = res && res.success;
                var data = res && res.data ? res.data : {};
                result.className = 'tef-msg tef-block ' + (ok ? 'tef-msg-ok' : 'tef-msg-err');
                result.textContent = data.message || tefAdmin.i18n.error;
                if (data.detail) {
                    detail.textContent = data.detail;
                    detail.hidden = false;
                }
            }).catch(function () {
                result.className = 'tef-msg tef-block tef-msg-err';
                result.textContent = tefAdmin.i18n.error;
            }).then(function () {
                lookupButton.disabled = false;
            });
        });
    }
})();

/* Ayarlar: Bireysel / Kurumsal anahtar renk seçicileri ve canlı önizleme */
(function ($) {
    'use strict';

    if (!$ || !$.fn || !$.fn.wpColorPicker) {
        return;
    }

    function luminance(hex) {
        hex = String(hex || '').replace('#', '');
        if (hex.length === 3) {
            hex = hex.replace(/(.)/g, '$1$1');
        }
        var c = [0, 2, 4].map(function (i) {
            var v = parseInt(hex.substr(i, 2), 16) / 255;
            return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
        });
        return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
    }

    function updatePreview() {
        var preview = document.querySelector('.tef-toggle-preview');
        var active = $('#tef_toggle_active').val();
        var inactive = $('#tef_toggle_inactive').val();
        if (!preview || !active || !inactive) {
            return;
        }
        preview.style.setProperty('--tef-accent', active);
        preview.style.setProperty('--tef-track', inactive);
        preview.style.setProperty('--tef-on-accent', luminance(active) > 0.179 ? '#111827' : '#ffffff');
        preview.style.setProperty('--tef-text', luminance(inactive) > 0.179 ? '#374151' : '#e5e7eb');
    }

    $(function () {
        $('.tef-color-field').wpColorPicker({
            change: function (event, ui) {
                $(event.target).val(ui.color.toString());
                updatePreview();
            },
            clear: function () {
                setTimeout(updatePreview, 0);
            }
        });
        updatePreview();
    });
})(window.jQuery);
