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

    /* ── Search filters (combined with status chips) ── */
    document.getElementById('search-main').addEventListener('input', function () {
        setQuery('main', this.value);
    });
    document.getElementById('search-archived').addEventListener('input', function () {
        setQuery('archived', this.value);
    });

    /* ── Status filter chips ── */
    setupChips('main');
    setupChips('archived');

    /* ── Clickable stat cards → jump to the filtered table ── */
    document.querySelectorAll('.card-click').forEach(function (card) {
        card.addEventListener('click', function () {
            var view  = card.dataset.view === 'archived' ? 'archived' : 'main';
            var chips = document.getElementById('chips-' + view);

            switchView(view);

            var status = card.dataset.goto || '';
            var chip = chips.querySelector('[data-filter="' + status + '"]');
            if (chip) chip.click();
            else {
                // No matching chip (e.g. "Today") — clear the filter
                var all = chips.querySelector('[data-filter=""]');
                if (all) all.click();
            }

            document.getElementById(view === 'main' ? 'view-main' : 'view-archived')
                .scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });

    /* ── Request detail modal: open via delegated click ── */
    document.body.addEventListener('click', function (e) {
        var btn = e.target.closest('.btn-view-files');
        if (btn) openRequestModal(btn);
    });

    /* ── Document previews: enlarge image / open PDF in lightbox ── */
    document.getElementById('docs-grid').addEventListener('click', function (e) {
        var thumb = e.target.closest('.doc-thumb');
        if (!thumb) return;

        var card  = thumb.closest('.doc-card');
        var title = card ? card.querySelector('.doc-card-title').textContent : 'Document';

        if (thumb.dataset.pdf) {
            openLightbox(thumb.dataset.pdf, title + ' — PDF Document', true);
        } else {
            var img = thumb.querySelector('img');
            if (img) openLightbox(img.getAttribute('src'), title, false);
        }
    });

    /* ── Modal: close button / backdrop ── */
    document.getElementById('closeFileModal').addEventListener('click', closeModal);
    document.getElementById('file-modal').addEventListener('click', function (e) {
        if (e.target === this) closeModal();
    });

    /* ── Lightbox: close button / backdrop ── */
    document.getElementById('lightbox-close').addEventListener('click', closeLightbox);
    document.getElementById('doc-lightbox').addEventListener('click', function (e) {
        if (e.target === this) closeLightbox();
    });

    /* ── Release modal: open via delegated click ── */
    document.body.addEventListener('click', function (e) {
        var btn = e.target.closest('.btn-release');
        if (!btn) return;

        document.getElementById('rel-req-id').textContent =
            'Request #' + String(btn.dataset.reqId || '').padStart(4, '0');
        document.getElementById('rel-req-id-input').value = btn.dataset.reqId || '';
        document.getElementById('rel-student').textContent = btn.dataset.student || '—';
        document.getElementById('rel-doc').textContent     = btn.dataset.doc || '—';

        // Fetch the system-generated defaults and fill the editable fields
        fetch('../phpLogics/previewCertificate.php?req_id=' + encodeURIComponent(btn.dataset.reqId) + '&format=json')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.ok) return;
                var d = data.defaults || {};
                document.getElementById('rf-title').value        = d.title || '';
                document.getElementById('rf-date').value         = d.cert_date || '';
                document.getElementById('rf-officer').value      = d.officer_name || '';
                document.getElementById('rf-officer-title').value = d.officer_title || '';
                document.getElementById('rf-body').value         = d.body || '';
                document.getElementById('rf-remarks').value      = d.remarks || '';
                refreshCertPreview();
            })
            .catch(function () {
                document.getElementById('rel-preview').src = 'about:blank';
            });

        document.getElementById('release-modal').style.display = 'flex';
    });

    /* ── Release modal: close / backdrop / preview refresh ── */
    document.getElementById('closeReleaseModal').addEventListener('click', closeReleaseModal);
    document.getElementById('release-modal').addEventListener('click', function (e) {
        if (e.target === this) closeReleaseModal();
    });
    document.getElementById('btn-refresh-preview').addEventListener('click', function () {
        refreshCertPreview();
    });
    document.getElementById('btn-fullscreen-preview').addEventListener('click', function () {
        var reqId = document.getElementById('rel-req-id-input').value;
        if (!reqId) return;
        // Same URL as the iframe, but in a full browser tab
        window.open('../phpLogics/previewCertificate.php?' + buildPreviewParams(reqId).toString(), '_blank');
    });

    /* Auto-refresh the preview when the registrar finishes editing a field */
    ['rf-title', 'rf-date', 'rf-officer', 'rf-officer-title', 'rf-body', 'rf-remarks'].forEach(function (id) {
    document.getElementById(id).addEventListener('change', refreshCertPreview);
    });

    /* ── Keyboard: Esc closes lightbox → detail modal → release modal ── */
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        if (document.getElementById('doc-lightbox').classList.contains('open')) {
            closeLightbox();
        } else if (document.getElementById('release-modal').style.display === 'flex') {
            closeReleaseModal();
        } else if (document.getElementById('file-modal').style.display === 'flex') {
            closeModal();
        }
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

/* ═══════════════════════════════════════════════════
   TABLE FILTERING (search text + status chip combined)
   ═══════════════════════════════════════════════════ */

var rowFilters = {
    main:     { query: '', status: '' },
    archived: { query: '', status: '' }
};

function setupChips(scope) {
    var chipBox = document.getElementById('chips-' + scope);
    if (!chipBox) return;

    chipBox.addEventListener('click', function (e) {
        var chip = e.target.closest('.chip');
        if (!chip) return;

        chipBox.querySelectorAll('.chip').forEach(function (c) {
            c.classList.remove('active');
        });
        chip.classList.add('active');

        rowFilters[scope].status = chip.dataset.filter || '';
        applyRowFilter(scope);
    });
}

function setQuery(scope, q) {
    rowFilters[scope].query = q;
    applyRowFilter(scope);
}

function applyRowFilter(scope) {
    var tbody = document.getElementById(scope === 'main' ? 'main-tbody' : 'archived-tbody');
    var state = rowFilters[scope];
    var q = state.query.toLowerCase();
    var f = state.status;

    var visible = 0;
    tbody.querySelectorAll('tr').forEach(function (row) {
        var matchQ = !q || row.textContent.toLowerCase().includes(q);
        var matchF = !f || row.dataset.status === f;
        var show   = matchQ && matchF;
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });

    /* Friendly empty message when filters hide everything */
    var emptyRow = tbody.querySelector('.filter-empty-row');
    if (visible === 0) {
        if (!emptyRow) {
            emptyRow = document.createElement('tr');
            emptyRow.className = 'filter-empty-row';
            emptyRow.innerHTML =
                '<td colspan="7" class="empty-row">🔍 No requests match the current search / filter.</td>';
            tbody.appendChild(emptyRow);
        }
        emptyRow.style.display = '';
    } else if (emptyRow) {
        emptyRow.style.display = 'none';
    }
}

/* Legacy helper kept for compatibility */
function filterTable(tbodyId, q) {
    setQuery(tbodyId === 'main-tbody' ? 'main' : 'archived', q);
}

/* ═══════════════════════════════════════════════════
   REQUEST DETAIL MODAL
   ═══════════════════════════════════════════════════ */

/* Metadata for each requirement slot */
var DOC_META = {
    idPhoto:    { icon: '🪪', title: 'Valid ID',             tag: 'Required', cls: 'tag-req' },
    authLetter: { icon: '✉️', title: 'Authorization Letter', tag: 'Optional', cls: 'tag-opt' },
    receipt:    { icon: '🧾', title: 'Payment Receipt',      tag: 'Payment',  cls: 'tag-opt' },
    eCert:      { icon: '🎓', title: 'E-Certificate',        tag: 'Released', cls: 'tag-cert' }
};

function openRequestModal(btn) {
    var d = btn.dataset;

    /* Header — request id */
    document.getElementById('modal-req-id').textContent =
        'Request #' + String(d.reqId || '').padStart(4, '0');

    /* Summary strip */
    document.getElementById('ms-student').textContent = d.student || '—';
    document.getElementById('ms-doc').textContent     = d.doc    || '—';
    document.getElementById('ms-date').textContent    = d.date   || '—';
    document.getElementById('ms-status-wrap').innerHTML =
        statusBadgeHtml(d.status || '', d.cancelled || '');

    /* Avatar — photo if present, initials as fallback underneath */
    var av = document.getElementById('ms-avatar');
    av.innerHTML = '';
    av.textContent = initialsOf(d.student || '');
    if (d.avatar) {
        var img = document.createElement('img');
        img.src = '../' + d.avatar;   // DB stores web-root-relative paths
        img.alt = '';
        img.onerror = function () { img.remove(); };   // reveal initials again
        av.appendChild(img);
    }

    /* Purpose */
    document.getElementById('modal-purpose').textContent = d.purpose || '—';

    /* Requirement cards */
    renderDocs(d);

    /* Student info + history (single fetch fills both) */
    document.getElementById('student-info').innerHTML =
        '<p class="history-loading">Loading…</p>';
    loadRequestHistory(d.reqId);

    document.getElementById('file-modal').style.display = 'flex';
    document.getElementById('file-modal').querySelector('.modal-scroll').scrollTop = 0;
    document.body.style.overflow = 'hidden';
}

/* Build a card for every attached file; hide the section-less empties */
function renderDocs(d) {
    var grid = document.getElementById('docs-grid');
    grid.innerHTML = '';

    var slots = [
        ['idPhoto',    'id-photo'],
        ['authLetter', 'auth-letter'],
        ['receipt',    'receipt'],
        ['eCert',      'e-cert']
    ];

    var shown = 0;
    slots.forEach(function (pair) {
        if (!d[pair[0]]) return;
        grid.appendChild(buildDocCard(DOC_META[pair[0]], d[pair[0]]));
        shown++;
    });

    if (!shown) {
        grid.innerHTML = '<p class="docs-empty">No files were attached to this request.</p>';
    }
}

/* Normalize path — strip Windows absolute prefixes, keep uploads/... onward.
   DB paths are web-root-relative; this page sits in /registrar/, so prefix ../ */
function normalizeUploadPath(filePath) {
    var clean = String(filePath).replace(/\\/g, '/');
    var idx = clean.indexOf('uploads/');
    if (idx !== -1) clean = clean.substring(idx);
    return '../' + clean;
}

function fileExt(p) {
    return p.split('.').pop().toLowerCase();
}

function buildDocCard(meta, filePath) {
    var url  = normalizeUploadPath(filePath);
    var ext  = fileExt(url);
    var name = url.substring(url.lastIndexOf('/') + 1);
    var isImg = ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext);
    var isPdf = ext === 'pdf';

    var card = document.createElement('div');
    card.className = 'doc-card';

    /* ── card head ── */
    var head =
        '<div class="doc-card-head">' +
            '<span class="doc-card-icon">' + meta.icon + '</span>' +
            '<span class="doc-card-title">' + escapeAttr(meta.title) + '</span>' +
            '<span class="doc-card-tag ' + meta.cls + '">' + meta.tag + '</span>' +
        '</div>';

    /* ── preview area ── */
    var prev;
    if (isImg) {
        prev =
            '<div class="doc-thumb" title="Click to enlarge">' +
                '<img src="' + escapeAttr(url) + '" alt="' + escapeAttr(meta.title) + '" loading="lazy">' +
                '<span class="doc-zoom">🔍 Click to enlarge</span>' +
            '</div>';
    } else if (isPdf) {
        prev =
            '<div class="doc-thumb doc-thumb-pdf" data-pdf="' + escapeAttr(url) + '" title="Open PDF viewer">' +
                '<span class="pdf-icon">📄</span>' +
                '<span class="doc-fmt">PDF Document</span>' +
                '<span class="doc-zoom">📖 Open viewer</span>' +
            '</div>';
    } else {
        prev =
            '<div class="doc-thumb doc-thumb-file">' +
                '<span class="pdf-icon">🗂️</span>' +
                '<span class="doc-fmt">' + escapeAttr(ext.toUpperCase()) + ' File</span>' +
            '</div>';
    }

    /* ── card footer ── */
    var foot =
        '<div class="doc-card-foot">' +
            '<span class="doc-filename" title="' + escapeAttr(name) + '">' + escapeAttr(name) + '</span>' +
            '<span class="doc-actions">' +
                (isPdf
                    ? '<a class="doc-act" href="' + escapeAttr(url) + '" target="_blank" rel="noopener">👁 View</a>'
                    : '') +
                '<a class="doc-act doc-act-dl" href="' + escapeAttr(url) + '" download="' + escapeAttr(name) + '">⬇ Download</a>' +
            '</span>' +
        '</div>';

    card.innerHTML = head + prev + foot;
    return card;
}

/* Status badge (mirrors the PHP statusBadge helper) */
function statusBadgeHtml(status, cancelledBy) {
    if (status === 'Cancelled') {
        if (cancelledBy === 'registrar') {
            return '<span class="status rejected">Rejected</span>';
        }
        if (cancelledBy === 'unclaimed') {
            return '<span class="status unclaimed">Unclaimed</span>';
        }
        return '<span class="status cancelled">Cancelled</span>';   // by student
    }
    var cls = {
        'Pending':          'pending',
        'Processing':       'processing',
        'Ready for Pickup': 'ready',
        'Released':         'released'
    }[status] || 'pending';
    return '<span class="status ' + cls + '">' + escapeAttr(status) + '</span>';
}

function initialsOf(name) {
    var parts = String(name).trim().split(/\s+/);
    var first = (parts[0] || ' ')[0] || '';
    var last  = parts.length > 1 ? (parts[parts.length - 1][0] || '') : '';
    return (first + last).toUpperCase();
}

/* ═══════════════════════════════════════════════════
   FULLSCREEN LIGHTBOX (image / PDF)
   ═══════════════════════════════════════════════════ */

function openLightbox(url, caption, isPdf) {
    document.getElementById('lightbox-caption').textContent = caption;
    document.getElementById('lightbox-open').href = url;

    var img   = document.getElementById('lightbox-img');
    var frame = document.getElementById('lightbox-frame');

    if (isPdf) {
        img.style.display   = 'none';
        img.src             = '';
        frame.style.display = 'block';
        frame.src           = url;
    } else {
        frame.style.display = 'none';
        frame.src           = 'about:blank';
        img.style.display   = 'block';
        img.src             = url;
    }

    document.getElementById('doc-lightbox').classList.add('open');
}

function closeLightbox() {
    var lb = document.getElementById('doc-lightbox');
    if (!lb.classList.contains('open')) return;

    lb.classList.remove('open');
    document.getElementById('lightbox-img').src   = '';
    document.getElementById('lightbox-frame').src = 'about:blank';

    /* keep body scroll locked if the detail modal is still open behind it */
    if (document.getElementById('file-modal').style.display !== 'flex') {
        document.body.style.overflow = '';
    }
}

/* ═══════════════════════════════════════════════════
   STUDENT INFO + REQUEST HISTORY (single AJAX call)
   ═══════════════════════════════════════════════════ */

function loadRequestHistory(reqId) {
    var box = document.getElementById('content-history');
    if (!box) return;
    box.innerHTML = '<p class="history-loading">Loading…</p>';

    if (!reqId) {
        box.innerHTML = '<p class="history-loading">—</p>';
        return;
    }

    fetch('../phpLogics/getStudentHistory.php?req_id=' + encodeURIComponent(reqId))
        .then(function (r) { return r.json(); })
        .then(function (data) {
            renderHistory(box, data);
            renderStudentInfo(data && data.student);
        })
        .catch(function () {
            box.innerHTML = '<p class="history-loading">Failed to load history.</p>';
            document.getElementById('student-info').innerHTML =
                '<p class="history-loading">Failed to load student record.</p>';
        });
}

/* Student verification panel (school ID, LRN, grade, contact…) */
function renderStudentInfo(s) {
    var box = document.getElementById('student-info');
    if (!s) {
        box.innerHTML = '<p class="history-loading">No student record found.</p>';
        return;
    }

    var fields = [
        ['Student ID',        s.student_id],
        ['LRN',               s.lrn],
        ['Grade Level',       s.grade_level],
        ['Strand / Track',    s.strand],
        ['Email',             s.email],
        ['Contact Number',    s.contact]
    ];

    var html = '';
    fields.forEach(function (f) {
        html += '<div class="si-cell">' +
                    '<span class="si-label">' + f[0] + '</span>' +
                    '<span class="si-value" title="' + escapeAttr(f[1] || '') + '">' +
                        (f[1] ? escapeAttr(f[1]) : '—') +
                    '</span>' +
                '</div>';
    });
    box.innerHTML = html;
}

function renderHistory(box, data) {
    if (!data || data.error || !data.items || !data.items.length) {
        box.innerHTML = '<p class="history-loading">No previous requests.</p>';
        return;
    }

    var html = '';

    // ⚠️ Highlight when this student already requested THIS document before
    // (any status, any date — even requests completed a long time ago)
    if (data.same_doc_count > 0) {
        var lastTxt = data.same_doc_last ? ' — most recent was ' + data.same_doc_last : '';
        html += '<div class="history-banner repeat">'
              + '🔁 This student already requested <strong>' + escapeAttr(data.current_doc || 'this document') + '</strong> '
              + data.same_doc_count + ' time(s) before' + lastTxt + '.'
              + '</div>';
    } else {
        html += '<div class="history-banner first">✅ First time this student requests <strong>'
              + escapeAttr(data.current_doc || 'this document') + '</strong>.</div>';
    }

    data.items.forEach(function (it) {
        var cls = it.status === 'Cancelled' ? 'cancelled' : (it.status === 'Released' ? 'released' : 'open');
        html += '<div class="history-item' + (it.is_current ? ' current' : '') + '">'
              + (it.is_current ? '<span class="history-now">THIS REQUEST</span>' : '')
              + '<span class="history-doc">' + escapeAttr(it.document_type || '')
              + (it.same_doc ? ' <span class="same-doc-tag">SAME DOC</span>' : '')
              + '</span>'
              + '<span class="history-status ' + cls + '">' + escapeAttr(it.status_label || it.status || '') + '</span>'
              + '<span class="history-date">' + escapeAttr(it.date_requested || '') + '</span>'
              + '</div>';
    });
    box.innerHTML = html;
}

/* ═══════════════════════════════════════════════════
   MODAL CLOSE + HELPERS
   ═══════════════════════════════════════════════════ */

/* Build the preview query string from the current field values */
function buildPreviewParams(reqId) {
    return new URLSearchParams({
        req_id:        reqId,
        title:         document.getElementById('rf-title').value,
        body:          document.getElementById('rf-body').value,
        officer_name:  document.getElementById('rf-officer').value,
        officer_title: document.getElementById('rf-officer-title').value,
        cert_date:     document.getElementById('rf-date').value,
        remarks:       document.getElementById('rf-remarks').value
    });
}

/* Rebuild the certificate preview iframe from the current field values */
function refreshCertPreview() {
    var reqId = document.getElementById('rel-req-id-input').value;
    if (!reqId) return;

    document.getElementById('rel-preview').src =
        '../phpLogics/previewCertificate.php?' + buildPreviewParams(reqId).toString();
}

function closeReleaseModal() {
    document.getElementById('release-modal').style.display = 'none';
    document.getElementById('rel-preview').src = 'about:blank';
}

function closeModal() {
    closeLightbox();

    document.getElementById('file-modal').style.display = 'none';
    document.getElementById('modal-req-id').textContent  = '—';
    document.getElementById('ms-student').textContent    = '—';
    document.getElementById('ms-doc').textContent        = '—';
    document.getElementById('ms-date').textContent       = '—';
    document.getElementById('ms-status-wrap').innerHTML  = '—';
    document.getElementById('ms-avatar').innerHTML       = '';
    document.getElementById('modal-purpose').textContent = '—';
    document.getElementById('docs-grid').innerHTML       = '';
    document.getElementById('student-info').innerHTML    = '';
    document.getElementById('content-history').innerHTML = '';

    document.body.style.overflow = '';
}

/* Escape a value for safe use in HTML / attributes */
function escapeAttr(s) {
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}
