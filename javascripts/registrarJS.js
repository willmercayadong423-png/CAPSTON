/* ═══════════════════════════════════════════════════
   HEHMS — Registrar JS  (registrarJS.js)
   ═══════════════════════════════════════════════════ */

document.addEventListener('DOMContentLoaded', function () {

    /* ── Tab switching ── */
    document.getElementById('tab-main').addEventListener('click', function () {
        switchView('main');
    });
    document.getElementById('tab-archived').addEventListener('click', function () {
        switchView('archived');
    });

    /* ── Search filters ── */
    document.getElementById('search-main').addEventListener('input', function () {
        filterTable('main-tbody', this.value);
    });
    document.getElementById('search-archived').addEventListener('input', function () {
        filterTable('archived-tbody', this.value);
    });

    /* ── Modal: open via delegated click ── */
    document.body.addEventListener('click', function (e) {
        var btn = e.target.closest('.btn-view-files');
        if (!btn) return;

        document.getElementById('modal-purpose').textContent = btn.dataset.purpose || '—';

        renderFileSlot('content-id-photo',    'section-id-photo',    btn.dataset.idPhoto    || '');
        renderFileSlot('content-auth-letter', 'section-auth-letter', btn.dataset.authLetter || '');

        document.getElementById('file-modal').style.display = 'flex';
    });

    /* ── Modal: close button ── */
    document.getElementById('closeFileModal').addEventListener('click', closeModal);

    /* ── Modal: close on backdrop click ── */
    document.getElementById('file-modal').addEventListener('click', function (e) {
        if (e.target === this) closeModal();
    });
});

/* ── Tab switcher ── */
function switchView(view) {
    var isMain = view === 'main';
    document.getElementById('tab-main').classList.toggle('active', isMain);
    document.getElementById('tab-archived').classList.toggle('active', !isMain);
    document.getElementById('view-main').style.display     = isMain ? '' : 'none';
    document.getElementById('view-archived').style.display = isMain ? 'none' : '';
}

/* ── Table search filter ── */
function filterTable(tbodyId, q) {
    q = q.toLowerCase();
    document.querySelectorAll('#' + tbodyId + ' tr').forEach(function (row) {
        row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
}

/* ── Render a file slot inside the modal ── */
function renderFileSlot(contentId, sectionId, filePath) {
    var section = document.getElementById(sectionId);
    var content = document.getElementById(contentId);

    if (!filePath) {
        section.style.display = 'none';
        content.innerHTML = '';
        return;
    }

    section.style.display = 'block';

    /* Normalize path — strip Windows absolute prefixes, keep uploads/... onward */
    var cleanPath  = filePath.replace(/\\/g, '/');
    var uploadsIdx = cleanPath.indexOf('uploads/');
    if (uploadsIdx !== -1) cleanPath = cleanPath.substring(uploadsIdx);

    var ext = cleanPath.split('.').pop().toLowerCase();

    if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)) {
        content.innerHTML =
            '<img src="' + cleanPath + '" style="max-width:100%; max-height:60vh;'
            + ' border-radius:10px; box-shadow:0 4px 20px rgba(0,0,0,.15);"'
            + ' onerror="this.outerHTML=\'<p style=color:#b91c1c>Could not load image.</p>\'">';
    } else if (ext === 'pdf') {
        content.innerHTML =
            '<iframe src="' + cleanPath + '" style="width:70vw; height:60vh;'
            + ' border:none; border-radius:8px;"></iframe>';
    } else {
        content.innerHTML =
            '<a href="' + cleanPath + '" target="_blank" style="'
            + 'display:inline-flex; align-items:center; gap:6px; padding:10px 20px;'
            + 'background:#dcfce7; color:#155724; border:1px solid #bbf7d0;'
            + 'border-radius:8px; font-weight:600; text-decoration:none;">⬇ Download File</a>';
    }
}

/* ── Close modal ── */
function closeModal() {
    document.getElementById('file-modal').style.display      = 'none';
    document.getElementById('modal-purpose').textContent     = '—';
    document.getElementById('content-id-photo').innerHTML    = '';
    document.getElementById('content-auth-letter').innerHTML = '';
    document.getElementById('section-id-photo').style.display    = 'block';
    document.getElementById('section-auth-letter').style.display = 'block';
}