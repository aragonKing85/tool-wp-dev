/**
 * OG IMG Helper — Media uploader + preview en tiempo real.
 *
 * Depende de: wp.media (cargado por wp_enqueue_media),
 *             twdOG (localizado desde PHP).
 *
 * @package Tool_WP_Dev
 * @module  og-img-helper
 */
(function () {
    'use strict';

    /* ── Referencias DOM ─────────────────────────────────────────────────── */
    var btnSelect     = document.getElementById('twd-og-select-img');
    var btnRemove     = document.getElementById('twd-og-remove-img');
    var inputId       = document.getElementById('twd_og_image_id');
    var previewWrap   = document.getElementById('twd-og-img-preview');
    var switchInput   = document.getElementById('twd_og_active');
    var statusLabel   = document.getElementById('twd-og-status-label');
    var tabs          = document.querySelectorAll('.twd-og-tab');
    var panelSocial   = document.getElementById('twd-preview-social');
    var panelWhatsapp = document.getElementById('twd-preview-whatsapp');

    /* ── Instancia del Media Frame ───────────────────────────────────────── */
    var mediaFrame = null;

    function getMediaFrame() {
        if ( mediaFrame ) return mediaFrame;

        mediaFrame = wp.media({
            title:    twdOG.mediaTitle,
            button:   { text: twdOG.mediaButton },
            multiple: false,
            library:  { type: 'image' },
        });

        mediaFrame.on('select', onMediaSelect);
        return mediaFrame;
    }

    /* ── Seleccionar imagen ──────────────────────────────────────────────── */
    function onMediaSelect() {
        var attachment = mediaFrame.state().get('selection').first().toJSON();
        var id         = attachment.id;
        var url        = attachment.url;
        var sizes      = attachment.sizes || {};

        // Preferir tamaño medium para el thumb del formulario
        var thumbUrl = (sizes.medium && sizes.medium.url) ? sizes.medium.url : url;

        inputId.value = id;
        renderThumb(thumbUrl);
        updatePreviews(url);
        showRemoveBtn();
        updateSelectBtnLabel(true);
    }

    function renderThumb(url) {
        previewWrap.innerHTML = '<img src="' + escHtml(url) + '" alt="Imagen OG seleccionada" id="twd-og-thumb">';
    }

    function showRemoveBtn() {
        if ( !btnRemove ) {
            var actions = document.querySelector('.twd-og-img-actions');
            var btn = document.createElement('button');
            btn.type      = 'button';
            btn.id        = 'twd-og-remove-img';
            btn.className = 'twd-og-btn twd-og-btn--ghost';
            btn.innerHTML = '&#128465; Eliminar imagen';
            actions.appendChild(btn);
            btn.addEventListener('click', onRemoveImage);
            btnRemove = btn;
        }
        btnRemove.style.display = '';
    }

    function updateSelectBtnLabel(hasImage) {
        if (!btnSelect) return;
        btnSelect.innerHTML = hasImage
            ? '&#9998; Cambiar imagen'
            : '&#128247; Seleccionar imagen';
    }

    /* ── Eliminar imagen ─────────────────────────────────────────────────── */
    function onRemoveImage() {
        inputId.value = '';
        previewWrap.innerHTML =
            '<div class="twd-og-img-preview__placeholder" id="twd-og-placeholder">' +
            '<span>&#128247;</span><p>Sin imagen seleccionada</p></div>';
        updatePreviews('');
        if (btnRemove) btnRemove.style.display = 'none';
        updateSelectBtnLabel(false);
    }

    /* ── Actualizar previsualizadores ───────────────────────────────────── */
    function updatePreviews(imgUrl) {
        updateSocialPreview(imgUrl);
        updateWhatsappPreview(imgUrl);
    }

    function updateSocialPreview(imgUrl) {
        var wrap = panelSocial.querySelector('.twd-og-preview__img-wrap');
        if (!wrap) return;

        if (imgUrl) {
            wrap.innerHTML = '<img src="' + escHtml(imgUrl) + '" alt="Preview OG" id="twd-social-img">';
        } else {
            wrap.innerHTML =
                '<div class="twd-og-preview__no-img" id="twd-social-img">' +
                '<span>&#128247;</span><p>Sin imagen seleccionada</p></div>';
        }
    }

    function updateWhatsappPreview(imgUrl) {
        var thumb = panelWhatsapp.querySelector('.twd-og-wa-thumb');
        if (!thumb) return;

        if (imgUrl) {
            thumb.innerHTML = '<img src="' + escHtml(imgUrl) + '" alt="Preview WhatsApp" id="twd-wa-img">';
        } else {
            thumb.innerHTML =
                '<div class="twd-og-wa-thumb__placeholder" id="twd-wa-img">' +
                '<span>&#128247;</span></div>';
        }
    }

    /* ── Switch de activación ────────────────────────────────────────────── */
    function onSwitchChange() {
        var active = switchInput.checked;
        if (!statusLabel) return;

        statusLabel.textContent = active ? 'Activo' : 'Inactivo';

        var descEl = statusLabel.nextElementSibling;
        if (descEl) {
            descEl.innerHTML = active
                ? 'Las meta tags OG se inyectan en el <code>&lt;head&gt;</code>.'
                : 'No se inyecta ninguna meta tag OG.';
        }
    }

    /* ── Tabs de previsualizador ─────────────────────────────────────────── */
    function onTabClick(e) {
        var tab    = e.currentTarget;
        var target = tab.dataset.tab;

        tabs.forEach(function (t) {
            t.classList.toggle('twd-og-tab--active', t === tab);
        });

        if (target === 'social') {
            panelSocial.classList.remove('twd-og-preview--hidden');
            panelWhatsapp.classList.add('twd-og-preview--hidden');
        } else {
            panelWhatsapp.classList.remove('twd-og-preview--hidden');
            panelSocial.classList.add('twd-og-preview--hidden');
        }
    }

    /* ── Utilidades ──────────────────────────────────────────────────────── */
    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    /* ── Init ────────────────────────────────────────────────────────────── */
    function init() {
        if (btnSelect) {
            btnSelect.addEventListener('click', function () {
                getMediaFrame().open();
            });
        }

        if (btnRemove) {
            btnRemove.addEventListener('click', onRemoveImage);
        }

        if (switchInput) {
            switchInput.addEventListener('change', onSwitchChange);
        }

        tabs.forEach(function (tab) {
            tab.addEventListener('click', onTabClick);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
