/* AI News Agent — Admin JS */
(function ($) {
    'use strict';

    // ── Tabs ────────────────────────────────────────────────────────────────
    $(document).on('click', '.ana-tab', function (e) {
        e.preventDefault();
        var tab = $(this).data('tab');
        $('.ana-tab').removeClass('active');
        $(this).addClass('active');
        $('.ana-tab-content').removeClass('active');
        $('#ana-tab-' + tab).addClass('active');
        location.hash = 'ana-' + tab;
    });

    // Restore tab from hash
    var hash = location.hash.replace('#ana-', '');
    if (hash) {
        var $t = $('[data-tab="' + hash + '"]');
        if ($t.length) $t.trigger('click');
    }

    // ── Color pickers ────────────────────────────────────────────────────────
    $('.ana-color-picker').wpColorPicker();

    // ── Count +/- ────────────────────────────────────────────────────────────
    $('#ana-count-plus').on('click', function () {
        var v = parseInt($('#ana-count').val()) || 6;
        $('#ana-count').val(Math.min(50, v + 1));
    });
    $('#ana-count-minus').on('click', function () {
        var v = parseInt($('#ana-count').val()) || 6;
        $('#ana-count').val(Math.max(1, v - 1));
    });

    // ── GENERATE ─────────────────────────────────────────────────────────────
    var generating = false;

    $('#ana-btn-generate').on('click', function () {
        if (generating) return;
        generating = true;
        var count = parseInt($('#ana-count').val()) || 6;
        var $btn  = $(this);

        $btn.prop('disabled', true).text('⏳ En cours…');
        $('#ana-status-msg').text('');
        $('#ana-progress').show();
        $('#ana-phase-1').show();
        $('#ana-phase-2').hide();
        setProgress(5);

        // Phase 1: prepare queue
        $.post(ANA.ajax_url, { action: 'ana_prepare', nonce: ANA.nonce, count: count }, function (res) {
            if (!res.success) {
                showError('Erreur Phase 1 : ' + (res.data || ''));
                done($btn);
                return;
            }
            var total = res.data.count || count;
            $('#ana-phase-1').text('✅ Phase 1 : ' + total + ' articles en file.');
            $('#ana-phase-2').show();
            setProgress(15);
            processNext($btn, count, 0, count);
        }).fail(function () { showError('Erreur réseau Phase 1'); done($btn); });
    });

    function processNext($btn, total, done_count, target) {
        if (done_count >= target) {
            setProgress(100);
            $('#ana-status-msg').html('✅ ' + done_count + ' articles créés. <a href="' + ANA.site_url + '/wp-admin/edit.php?post_status=draft&post_type=post">Voir les brouillons →</a>');
            done($btn);
            location.reload();
            return;
        }
        $.post(ANA.ajax_url, { action: 'ana_process_next', nonce: ANA.nonce }, function (res) {
            if (!res.success) {
                showError('Erreur Phase 2');
                done($btn);
                return;
            }
            var d = res.data;
            done_count++;
            var pct = 15 + Math.round((done_count / target) * 85);
            setProgress(pct);
            $('#ana-phase-2').text('✍️ ' + done_count + '/' + target + ' — ' + (d.title || ''));
            if (d.status === 'done' || d.status === 'empty' || d.remaining === 0) {
                setProgress(100);
                $('#ana-status-msg').html('✅ ' + done_count + ' articles créés. <a href="' + ANA.site_url + '/wp-admin/edit.php?post_status=draft&post_type=post">Voir les brouillons →</a>');
                done($btn);
                setTimeout(function () { location.reload(); }, 1500);
            } else {
                processNext($btn, total, done_count, target);
            }
        }).fail(function () { showError('Erreur réseau Phase 2'); done($btn); });
    }

    function setProgress(pct) { $('#ana-progress-fill').css('width', pct + '%'); }
    function showError(msg) { $('#ana-status-msg').html('❌ ' + msg).css('color', '#c00'); }
    function done($btn) {
        generating = false;
        $btn.prop('disabled', false).text('▶ Générer les articles');
    }

    // ── RESET ────────────────────────────────────────────────────────────────
    $('#ana-btn-reset').on('click', function () {
        if (!confirm('Réinitialiser la file d\'attente ?')) return;
        $.post(ANA.ajax_url, { action: 'ana_reset_queue', nonce: ANA.nonce }, function (res) {
            if (res.success) {
                $('#ana-status-msg').text('File réinitialisée.');
                setProgress(0);
                $('#ana-progress').hide();
            }
        });
    });

    // ── SAVE SETTINGS (generic) ───────────────────────────────────────────────
    function saveForm(formSelector, statusSelector) {
        var data = $(formSelector).serializeArray();
        var payload = { action: 'ana_save_settings', nonce: ANA.nonce };
        $.each(data, function (i, f) { payload[f.name] = f.value; });
        // Also grab color pickers (they may not serialize correctly)
        $(formSelector + ' .ana-color-picker').each(function () {
            payload[$(this).attr('name')] = $(this).val();
        });
        $(statusSelector).text('💾 Sauvegarde…');
        $.post(ANA.ajax_url, payload, function (res) {
            $(statusSelector).text(res.success ? (res.data.msg || '✓ Sauvegardé') : '❌ Erreur');
            setTimeout(function () { $(statusSelector).text(''); }, 3000);
        });
    }

    $('#ana-save-ai').on('click', function () { saveForm('#ana-form-ai', '#ana-ai-save-status'); });
    $('#ana-save-branding').on('click', function () {
        // Save logo IDs from hidden fields
        var payload = {
            action: 'ana_save_settings',
            nonce: ANA.nonce,
            ana_logo_ai_id:     $('#ana-form-branding [name="ana_logo_ai_id"]').val(),
            ana_logo_banner_id: $('#ana-form-branding [name="ana_logo_banner_id"]').val(),
            ana_brand_color_bg:     $('#ana-form-branding [name="ana_brand_color_bg"]').val(),
            ana_brand_color_text:   $('#ana-form-branding [name="ana_brand_color_text"]').val(),
            ana_brand_color_accent: $('#ana-form-branding [name="ana_brand_color_accent"]').val(),
        };
        $('#ana-branding-save-status').text('💾 Sauvegarde…');
        $.post(ANA.ajax_url, payload, function (res) {
            $('#ana-branding-save-status').text(res.success ? '✓ Sauvegardé' : '❌ Erreur');
            setTimeout(function () { $('#ana-branding-save-status').text(''); }, 3000);
        });
    });
    $('#ana-save-automation').on('click', function () { saveForm('#ana-form-automation', '#ana-automation-save-status'); });

    // ── TEST API ─────────────────────────────────────────────────────────────
    $('#ana-test-api').on('click', function () {
        // First save the key
        var key      = $('[name="ana_api_key"]').val();
        var provider = $('[name="ana_api_provider"]').val();
        $('#ana-api-status').text('⏳ Test…');
        $.post(ANA.ajax_url, {
            action: 'ana_save_settings', nonce: ANA.nonce,
            ana_api_key: key, ana_api_provider: provider
        }, function () {
            $.post(ANA.ajax_url, { action: 'ana_test_api', nonce: ANA.nonce }, function (res) {
                if (res.success && res.data.ok) {
                    $('#ana-api-status').html('<span style="color:green">✅ Connexion OK</span>');
                } else {
                    $('#ana-api-status').html('<span style="color:red">❌ Échec</span>');
                }
            });
        });
    });

    // ── TEST SOURCES ─────────────────────────────────────────────────────────
    $('#ana-test-sources').on('click', function () {
        var $btn = $(this).prop('disabled', true).text('⏳ Test…');
        $.post(ANA.ajax_url, { action: 'ana_test_sources', nonce: ANA.nonce }, function (res) {
            if (!res.success) { alert('Erreur test sources'); $btn.prop('disabled', false).text('🔍 Tester tous les flux'); return; }
            var html = '<strong>' + res.data.count + ' articles récupérés</strong><br><table style="width:100%;border-collapse:collapse;margin-top:8px;">';
            html += '<tr><th style="text-align:left;padding:4px;">Source</th><th>Type</th><th>Statut</th><th>Articles</th></tr>';
            $.each(res.data.sources || [], function (i, s) {
                html += '<tr><td style="padding:4px;">' + s.name + '</td><td>' + s.type + '</td>';
                html += '<td>' + (s.ok ? '✅' : '❌') + '</td><td>' + s.found + '</td></tr>';
            });
            html += '</table>';
            $('#ana-sources-result').html(html).show();
            $btn.prop('disabled', false).text('🔍 Tester tous les flux');
        }).fail(function () { $btn.prop('disabled', false).text('🔍 Tester tous les flux'); });
    });

    // ── SOURCES CRUD ─────────────────────────────────────────────────────────
    var sourceTemplate = function (idx) {
        return '<div class="ana-source-row" data-index="' + idx + '">' +
            '<div class="ana-source-fields">' +
            '<input type="text" class="ana-src-name regular-text" placeholder="Nom (ex: BBC News)">' +
            '<input type="url"  class="ana-src-url  regular-text" placeholder="URL du flux RSS">' +
            '<select class="ana-src-lang">' +
            '<option value="en">English</option><option value="fr">Français</option>' +
            '<option value="hy">Հայերեն</option><option value="ru">Русский</option>' +
            '<option value="es">Español</option><option value="de">Deutsch</option>' +
            '<option value="ar">عربي</option><option value="zh">中文</option>' +
            '</select>' +
            '<select class="ana-src-type"><option value="rss">RSS</option><option value="scrape">Scrape</option></select>' +
            '<button type="button" class="button ana-remove-source">✕</button>' +
            '</div></div>';
    };

    $('#ana-add-source').on('click', function () {
        var idx = $('#ana-sources-list .ana-source-row').length;
        $('#ana-sources-list').append(sourceTemplate(idx));
    });

    $(document).on('click', '.ana-remove-source', function () {
        $(this).closest('.ana-source-row').remove();
    });

    // Show/hide scrape extra fields
    $(document).on('change', '.ana-src-type', function () {
        var $row   = $(this).closest('.ana-source-row');
        var type   = $(this).val();
        var $extra = $row.find('.ana-scrape-extra');
        if (type === 'scrape') {
            if (!$extra.length) {
                $row.append('<div class="ana-scrape-extra">' +
                    '<input type="url" class="ana-src-base regular-text" placeholder="Base URL (ex: https://exemple.com)">' +
                    '<input type="text" class="ana-src-pattern regular-text" placeholder="Pattern regex (ex: #/article/[0-9]+#)">' +
                    '</div>');
            } else { $extra.show(); }
        } else { $extra.hide(); }
    });

    $('#ana-save-sources').on('click', function () {
        var sources = [];
        $('#ana-sources-list .ana-source-row').each(function () {
            var $r    = $(this);
            var type  = $r.find('.ana-src-type').val();
            var entry = {
                name: $r.find('.ana-src-name').val().trim(),
                url:  $r.find('.ana-src-url').val().trim(),
                lang: $r.find('.ana-src-lang').val(),
                type: type,
            };
            if (type === 'scrape') {
                entry.base    = $r.find('.ana-src-base').val().trim();
                entry.pattern = $r.find('.ana-src-pattern').val().trim();
            }
            if (entry.name && entry.url) sources.push(entry);
        });
        $.post(ANA.ajax_url, {
            action: 'ana_save_sources', nonce: ANA.nonce, sources: JSON.stringify(sources)
        }, function (res) {
            if (res.success) {
                alert(res.data.msg);
            } else { alert('Erreur sauvegarde sources'); }
        });
    });

    // ── MEDIA UPLOADER ────────────────────────────────────────────────────────
    var mediaFrame;
    $(document).on('click', '.ana-media-choose', function (e) {
        e.preventDefault();
        var $field = $(this).closest('.ana-media-field');

        mediaFrame = wp.media({
            title: 'Choisir un logo',
            button: { text: 'Utiliser ce logo' },
            multiple: false,
            library: { type: 'image' },
        });

        mediaFrame.on('select', function () {
            var attachment = mediaFrame.state().get('selection').first().toJSON();
            $field.find('.ana-media-id').val(attachment.id);
            $field.find('.ana-media-preview img').attr('src', attachment.url).parent().show();
            $field.find('.ana-media-remove').show();
        });

        mediaFrame.open();
    });

    $(document).on('click', '.ana-media-remove', function () {
        var $field = $(this).closest('.ana-media-field');
        $field.find('.ana-media-id').val(0);
        $field.find('.ana-media-preview').hide();
        $(this).hide();
    });

    // ── REST KEY REGEN ────────────────────────────────────────────────────────
    $('#ana-regen-key').on('click', function () {
        var newKey = Math.random().toString(36).substr(2, 16) + Math.random().toString(36).substr(2, 16);
        $('#ana-rest-key').val(newKey);
        // Update the cron command display
        var cmd = 'wget -q -O /dev/null "' + ANA.rest_url + '?key=' + newKey + '"';
        $('#ana-cron-cmd').text(cmd);
    });

})(jQuery);
