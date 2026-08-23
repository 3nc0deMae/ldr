/* ═══════════════════════════════════════════════════════════════════════════
   Custom Announcement Templates
   AJAX-saves the "Create Custom Template" modal and inserts the new card
   into the Template Library grid without a page refresh.
   ═══════════════════════════════════════════════════════════════════════════ */
(function () {
    'use strict';

    var overlay = document.getElementById('customTemplateOverlay');
    var openBtn = document.getElementById('openCustomTemplateModal');
    if (!overlay || !openBtn) return;

    var form      = document.getElementById('customTemplateForm');
    var closeBtn  = document.getElementById('customTemplateClose');
    var cancelBtn = document.getElementById('customTemplateCancel');
    var grid      = document.getElementById('templateGrid');
    var submitBtn = form ? form.querySelector('button[type="submit"]') : null;
    var BASE_URL  = window.BASE_URL || '';

    // ─── Modal helpers (mirrors inline openModal/closeModal behaviour) ──
    function openModal(el) {
        if (!el) return;
        el.classList.add('show');
        document.body.style.overflow = 'hidden';
        var f = el.querySelector('input[type="text"], textarea, select');
        if (f) setTimeout(function () { f.focus(); }, 200);
    }
    function closeModal(el) {
        if (!el) return;
        el.classList.remove('show');
        if (!document.querySelector('.event-modal-overlay.show')) document.body.style.overflow = '';
    }

    // ─── Toast ──────────────────────────────────────────────────────────
    function showToast(type, message) {
        var c = document.getElementById('toastContainer');
        if (!c) return;
        var icons = { success: 'check-circle-fill', error: 'x-circle-fill', warning: 'exclamation-triangle-fill', info: 'info-circle-fill' };
        var t = document.createElement('div');
        t.className = 'toast-notification ' + type;
        t.innerHTML = '<i class="bi bi-' + (icons[type] || icons.info) + '"></i><span></span>';
        t.querySelector('span').textContent = message;
        c.appendChild(t);
        setTimeout(function () { if (t.parentNode) t.remove(); }, 4200);
    }

    openBtn.addEventListener('click', function () { openModal(overlay); });
    if (closeBtn)  closeBtn.addEventListener('click', function () { closeModal(overlay); });
    if (cancelBtn) cancelBtn.addEventListener('click', function () { closeModal(overlay); });

    // ─── Build + insert the new card into the Template Library grid ─────
    function insertTemplateCard(tpl) {
        if (!grid || !tpl || !tpl.id) return;

        var col = document.createElement('div');
        col.className = 'col-4 col-md-4 col-lg-2';

        var card = document.createElement('div');
        card.className = 'card h-100 text-center template-card';
        card.dataset.templateKey  = 'custom_' + tpl.id;
        card.dataset.customSubject = tpl.subject || '';
        card.dataset.customBody    = tpl.body_content || '';

        var iconClass = /^bi-[a-z0-9-]+$/.test(tpl.icon || '') ? tpl.icon : 'bi-bookmark-fill';

        card.innerHTML =
            '<button type="button" class="tpl-delete-btn" data-tpl-id="' + parseInt(tpl.id, 10) + '" title="Delete template"><i class="bi bi-trash"></i></button>' +
            '<div class="card-body">' +
                '<div style="width:44px;height:44px;border-radius:12px;background:rgba(255,255,255,0.06);display:inline-flex;align-items:center;justify-content:center;margin-bottom:10px;">' +
                    '<i class="bi ' + iconClass + '" style="font-size:20px;color:var(--ann-text-secondary);"></i>' +
                '</div>' +
                '<div class="tpl-name"></div>' +
            '</div>';
        card.querySelector('.tpl-name').textContent = tpl.title || 'Untitled';

        col.appendChild(card);
        grid.appendChild(col);

        // Brief glow so the user spots the freshly added template
        card.style.transition = 'box-shadow 0.3s ease';
        card.style.boxShadow = '0 0 0 2px #4f46e5';
        setTimeout(function () { card.style.boxShadow = ''; }, 1800);
        card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    if (!form) return;

    // ─── Delete custom templates (delegated — covers AJAX-inserted cards) ─
    function getCsrf() {
        var el = form.querySelector('input[name="csrf_token"]');
        return el ? el.value : '';
    }

    // Delete confirmation modal (matches the other modals' design)
    var tplDeleteOverlay = document.getElementById('deleteTemplateOverlay');
    var pendingDelete = null;

    function resetPendingDelete() {
        if (pendingDelete && pendingDelete.btn) pendingDelete.btn.disabled = false;
        pendingDelete = null;
    }

    if (grid) {
        grid.addEventListener('click', function (e) {
            var btn = e.target.closest('.tpl-delete-btn');
            if (!btn || btn.disabled) return;
            e.preventDefault();
            e.stopPropagation();

            var cardEl = btn.closest('.template-card');
            if (!cardEl) return;
            if (!tplDeleteOverlay) { return; }

            resetPendingDelete();
            pendingDelete = { btn: btn, card: cardEl };

            var nameEl = cardEl.querySelector('.tpl-name');
            var name = nameEl ? (nameEl.textContent || '').trim() : '';
            var subjEl = document.getElementById('deleteTplSubject');
            if (subjEl) {
                subjEl.textContent = name
                    ? '\u201C' + name + '\u201D will be permanently removed from your Template Library.'
                    : 'This template will be permanently removed from your Template Library.';
            }

            openModal(tplDeleteOverlay);
        });
    }

    function doDeleteTemplate() {
        if (!pendingDelete) { closeModal(tplDeleteOverlay); return; }
        var btn = pendingDelete.btn;
        var cardEl = pendingDelete.card;
        pendingDelete = null;
        closeModal(tplDeleteOverlay);

        var fd = new FormData();
        fd.append('action', 'delete');
        if (btn.dataset.presetKey) fd.append('preset_key', btn.dataset.presetKey);
        else fd.append('id', btn.dataset.tplId || '');
        fd.append('csrf_token', getCsrf());
        btn.disabled = true;

        fetch(BASE_URL + '/process_custom_template.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (r) {
            return r.text().then(function (txt) {
                var trimmed = (txt || '').trim();
                if (!trimmed) throw new Error('Empty server response');
                try { return JSON.parse(trimmed); }
                catch (parseErr) { throw new Error('Unexpected server response'); }
            });
        })
        .then(function (d) {
            if (!d.success) throw new Error(d.message || 'Failed to delete the template.');
            var col = cardEl.parentNode;
            if (col && col !== grid && col.parentNode === grid) {
                grid.removeChild(col);
            } else if (cardEl.parentNode === grid) {
                grid.removeChild(cardEl);
            }
            showToast('success', d.message || 'Custom template deleted.');
        })
        .catch(function (err) {
            btn.disabled = false;
            showToast('error', err.message || 'Failed to delete the template. Please try again.');
        });
    }

    if (tplDeleteOverlay) {
        document.getElementById('btnConfirmDeleteTemplate').addEventListener('click', function () {
            doDeleteTemplate();
        });
        document.getElementById('deleteTemplateClose').addEventListener('click', function () {
            resetPendingDelete();
            closeModal(tplDeleteOverlay);
        });
        document.getElementById('deleteTemplateCancel').addEventListener('click', function () {
            resetPendingDelete();
            closeModal(tplDeleteOverlay);
        });
    }

    // ─── Intercept submit → POST JSON endpoint instead of page reload ───
    form.addEventListener('submit', function (e) {
        e.preventDefault();

        var title   = ((form.elements['title'] || {}).value || '').trim();
        var subject = ((form.elements['subject'] || {}).value || '').trim();
        var body    = ((form.elements['body_content'] || {}).value || '').trim();

        if (!title || !subject || !body) {
            showToast('warning', 'Please fill in all required fields.');
            return;
        }

        var fd = new FormData(form);

        if (submitBtn) { submitBtn.disabled = true; submitBtn.classList.add('btn-loading'); }

        fetch(BASE_URL + '/process_custom_template.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (r) {
            return r.text().then(function (txt) {
                var trimmed = (txt || '').trim();
                if (!trimmed) throw new Error('Empty server response');
                try { return JSON.parse(trimmed); }
                catch (parseErr) { throw new Error('Unexpected server response'); }
            });
        })
        .then(function (d) {
            if (!d.success) throw new Error(d.message || 'Failed to save the template.');
            insertTemplateCard(d.template);
            closeModal(overlay);
            form.reset();
            showToast('success', d.message || 'Custom template saved.');
        })
        .catch(function (err) {
            showToast('error', err.message || 'Failed to save the template. Please try again.');
        })
        .finally(function () {
            if (submitBtn) { submitBtn.disabled = false; submitBtn.classList.remove('btn-loading'); }
        });
    });
})();
