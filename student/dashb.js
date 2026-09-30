/* ═══════════════════════════════════════════════════
   HEHMS — Student Dashboard JS  (dashb.js)
   ═══════════════════════════════════════════════════ */

/* ── Sidebar view switcher ── */
function showView(view) {

    document.getElementById('view-dashboard').style.display =
        (view === 'dashboard') ? 'block' : 'none';

    document.getElementById('view-request').style.display =
        (view === 'request') ? 'block' : 'none';

    document.getElementById('view-requests').style.display =
        (view === 'requests') ? 'block' : 'none';

    document.getElementById('view-account').style.display =
        (view === 'account') ? 'block' : 'none';

    document.getElementById('nav-dashboard').classList.toggle('active', view === 'dashboard');
    document.getElementById('nav-request').classList.toggle('active', view === 'request');
    document.getElementById('nav-requests').classList.toggle('active', view === 'requests');
    document.getElementById('nav-account').classList.toggle('active', view === 'account');

    const params = new URLSearchParams(window.location.search);
    const step = params.get("step");

    let url = "?view=" + view;

    if (view === "request") {
        url += "&step=" + (step ? step : "document");
    }

    history.replaceState({}, "", url);
}

function selectDocument(docName) {

    document.getElementById('selected-document-input').value = docName;
    document.getElementById('selected-doc-label').textContent = docName;

    // ── Conditional fields: Grade Level + School Year ──
    toggleReqFields(docName);

    document.getElementById('step-document').style.display = 'none';
    document.getElementById('step-form').style.display = 'block';

    sessionStorage.setItem('hehms_selected_doc', docName);

    history.replaceState({}, '', '?view=request&step=form');
}

function backToStep1() {
    document.getElementById('step-document').style.display = 'block';
    document.getElementById('step-form').style.display = 'none';

    sessionStorage.removeItem('hehms_selected_doc');

    history.replaceState({}, "", "?view=request");
}

function proceedToPayment() {
    /* Payment step removed — students settle payment in person at the
       Registrar's Office. Kept as an alias in case of cached pages. */
    submitRequest();
}

function submitRequest() {
    var purpose = document.getElementById('purpose-input');
    var idPhoto = document.getElementById('new-id-input');

    if (!purpose.value.trim()) {
        alert('Please enter the purpose / reason for your request.');
        purpose.focus();
        return;
    }
    if (!idPhoto.files || idPhoto.files.length === 0) {
        alert('Please upload your Valid ID.');
        return;
    }

    // ── Good Moral needs the School Year Last Attended ──
    var docType = document.getElementById('selected-document-input').value;
    var syInput = document.getElementById('sy-input');
    var glInput = document.getElementById('gl-input');
    if (syInput && !syInput.value.trim()) {
        alert('Please enter the School Year you last attended (e.g. 2024-2025).');
        syInput.focus();
        return;
    }
    if (docNeedsGl(docType) && glInput && !glInput.value.trim()) {
        alert('Please enter your Grade Level (e.g. Grade 12).');
        glInput.focus();
        return;
    }

    document.getElementById('request-form').requestSubmit();
}


/* ── Main/Archived sub-tab switch ── */
function switchTable(tab) {
    document.getElementById('tab-main').classList.toggle('active',     tab === 'main');
    document.getElementById('tab-archived').classList.toggle('active', tab === 'archived');
    document.getElementById('tbl-main').style.display     = tab === 'main'     ? '' : 'none';
    document.getElementById('tbl-archived').style.display = tab === 'archived' ? '' : 'none';
}

/* ── Search filter (combined with the status chips — see rowFilters) ── */
function filterRows(tbodyId, q) {
    var scope = tbodyId === 'archived-tbody' ? 'archived' : 'main';
    rowFilters[scope].query = q;
    applyRowFilter(scope);
}

/* ── Status filter state (search text + active chip, per table) ── */
var rowFilters = {
    main:     { query: '', status: '' },
    archived: { query: '', status: '' }
};

/* Apply the combined query + chip filter to one table's rows */
function applyRowFilter(scope) {
    var tbody = document.getElementById(scope === 'main' ? 'main-tbody' : 'archived-tbody');
    if (!tbody) return;

    var state  = rowFilters[scope];
    var q      = state.query.toLowerCase();
    var f      = state.status;
    var active = q !== '' || f !== '';

    var visible = 0;
    var placeholders = [];

    tbody.querySelectorAll('tr').forEach(function (row) {
        // Rows without data-status are placeholders ("no requests yet" /
        // "no match") — handled separately below.
        if (!row.hasAttribute('data-status')) { placeholders.push(row); return; }

        var matchQ = !q || row.textContent.toLowerCase().indexOf(q) !== -1;
        var st     = row.getAttribute('data-status');
        // Exact status match — history rows carry the RESOLVED label
        // ("Rejected" / "Cancelled"), the same behaviour
        // as the registrar dashboard's chips.
        var matchF = !f || st === f;

        var show = matchQ && matchF;
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });

    // Server-rendered "no requests yet" placeholders only exist in empty
    // tables — show them only when no filter is active.
    placeholders.forEach(function (row) {
        if (!row.classList.contains('no-match-row')) {
            row.style.display = active ? 'none' : '';
        }
    });

    // Friendly "no match" row when the filters hide everything
    var noMatch = tbody.querySelector('.no-match-row');
    if (active && visible === 0) {
        if (!noMatch) {
            noMatch = document.createElement('tr');
            noMatch.className = 'no-match-row';
            noMatch.innerHTML =
                '<td colspan="6" style="text-align:center;padding:2rem;color:#888;">' +
                'No requests match the current search / filter.</td>';
            tbody.appendChild(noMatch);
        }
        noMatch.style.display = '';
    } else if (noMatch) {
        noMatch.style.display = 'none';
    }
}

/* ── Status filter chips (same interaction as the registrar dashboard) ── */
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
setupChips('main');
setupChips('archived');

/* ── Clickable stat cards → jump to the filtered table ─────────────
   Same behavior as the registrar dashboard: switches to the right
   tab (Main / History), activates the matching chip, and scrolls
   the table into view. */
document.querySelectorAll('#view-dashboard .card-click').forEach(function (card) {
    card.addEventListener('click', function () {
        var tab = card.dataset.view === 'archived' ? 'archived' : 'main';

        showView('requests');
        switchTable(tab);

        // Activate the matching chip ("All" when the card has no goto)
        var chips = document.getElementById('chips-' + tab);
        var want  = card.dataset.goto || '';
        var chip  = chips && chips.querySelector('[data-filter="' + want + '"]');
        if (chip) chip.click();

        var target = document.getElementById(tab === 'main' ? 'tbl-main' : 'tbl-archived');
        if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
});

/* ── Conditional request fields (request form + edit modal) ──
   Every document needs the School Year Last Attended. Which documents
   ALSO need the Grade Level now comes from the database (admin-managed
   per document) — dashboard.php injects window.DOC_GL_MAP before this
   file loads: { "Certificate of Enrollment": 1, ... }. */
var DOC_GL_MAP = window.DOC_GL_MAP || {};

function docNeedsGl(docName) {
    return Number(DOC_GL_MAP[docName]) === 1;
}

function toggleReqFields(docName) {
    var glWrap = document.getElementById('gl-field-wrap');
    var glInp  = document.getElementById('gl-input');
    var syWrap = document.getElementById('sy-field-wrap');
    var syInp  = document.getElementById('sy-input');

    var needsGl = docNeedsGl(docName);
    if (glWrap && glInp) {
        glWrap.style.display = needsGl ? '' : 'none';
        if (!needsGl) glInp.value = '';
    }
    if (syWrap && syInp) {
        syWrap.style.display = '';   // SY is required for every document
    }
}

function toggleSyForDoc(docName) { toggleReqFields(docName); }   // legacy alias

function updateSyField(selectEl, inputEl, wrapEl, value) {
    var needsSy = (selectEl.value === 'Certificate of Good Moral');
    wrapEl.style.display = needsSy ? '' : 'none';
    inputEl.value = needsSy ? (value || '') : '';
}

/* ── Edit modal: toggle Grade Level + School Year together ── */
function updateEditReqFields(docType, glValue, syValue) {
    var needsGl = docNeedsGl(docType);

    var glWrap = document.getElementById('edit-gl-wrap');
    var glInp  = document.getElementById('edit-gl-input');
    if (glWrap && glInp) {
        glWrap.style.display = needsGl ? '' : 'none';
        glInp.value = needsGl ? (glValue || '') : '';
    }

    var syWrap = document.getElementById('edit-sy-wrap');
    var syInp  = document.getElementById('edit-sy-input');
    if (syWrap && syInp) {
        syWrap.style.display = '';          // SY is required for every document
        syInp.value = syValue || '';
    }
}

/* ── Edit modal ── */
function openEditModal(id, docType, purpose, existId, existSy, existGl) {
    document.getElementById('edit-req-id').value  = id;
    document.getElementById('edit-purpose').value = purpose;

    var sel = document.getElementById('edit-doc-type');

    // Drop any leftover "(current)" option from a previous open
    Array.prototype.slice.call(sel.options).forEach(function (o) {
        if (o.textContent.indexOf('(current)') !== -1) sel.remove(o.index);
    });

    var found = false;
    for (var i = 0; i < sel.options.length; i++) {
        if (sel.options[i].value === docType) { sel.selectedIndex = i; found = true; break; }
    }

    // The request's document type may have been removed from the requestable
    // list since it was filed — keep it selectable (marked "current") so the
    // student can still edit the other fields. editReq.php allows exactly
    // this case server-side.
    if (!found && docType) {
        sel.add(new Option(docType + ' (current)', docType, true, true));
    }

    updateEditReqFields(docType, existGl, existSy);

    resetCard('edit-card-id', 'edit-id-input', 'edit-id-name', 'edit-id-preview');

    applyExisting(existId, 'edit-card-id', 'edit-id-existing', 'edit-id-existing-link', id, 'id_photo');

    document.getElementById('editModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeEditModal(e) {
    var modal = document.getElementById('editModal');
    if (!e || e.target === modal) {
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }
}

/* ── Reset upload card to empty state ── */
function resetCard(cardId, inputId, nameId, previewId) {
    var inp  = document.getElementById(inputId);
    var card = document.getElementById(cardId);
    var nm   = document.getElementById(nameId);
    var pv   = document.getElementById(previewId);

    if (inp)  inp.value = '';
    if (card) card.classList.remove('has-file');
    if (nm)   { nm.textContent = ''; nm.classList.remove('visible'); }
    if (pv)   { pv.innerHTML = ''; pv.classList.remove('visible'); }

    if (card) {
        var badge = card.querySelector('.ruc-existing-badge');
        if (badge) {
            badge.classList.remove('visible');
            var a = badge.querySelector('a');
            if (a) { a.href = '#'; a.innerHTML = ''; }
        }
    }
}

/* ── Show existing server-side file inside edit card ── */
function applyExisting(existingPath, cardId, badgeId, linkId, reqId, field) {
    var card  = document.getElementById(cardId);
    var badge = document.getElementById(badgeId);
    var link  = document.getElementById(linkId);
    var stem  = cardId.replace('edit-card-', 'edit-');
    var prevEl = document.getElementById(stem + '-preview');
    var nameEl = document.getElementById(stem + '-name');

    if (existingPath) {
        var fname = existingPath.split('/').pop();
        var ext   = fname.split('.').pop().toLowerCase();
        // download.php lives in phpLogics/ (page-relative from /student/)
        var downloadUrl = '../phpLogics/download.php?field=' + field + '&req_id=' + reqId;

        badge.classList.add('visible');
        link.href      = downloadUrl;
        link.innerHTML = '&#128206; ' + fname;

        if (prevEl) {
            prevEl.innerHTML = '';
            if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)) {
                prevEl.innerHTML =
                    '<img src="' + downloadUrl + '" alt="Current file" ' +
                    'style="width:100%;max-height:110px;object-fit:cover;border-radius:8px;display:block;" ' +
                    'onerror="this.style.display=\'none\'">';
            } else if (ext === 'pdf') {
                prevEl.innerHTML =
                    '<div class="pdf-badge"><span>📄</span>PDF on file &mdash; ' +
                    '<a href="' + downloadUrl + '" target="_blank" ' +
                    'style="color:#155724;font-weight:700;text-decoration:underline;" ' +
                    'onclick="event.stopPropagation()">Open &#8599;</a></div>';
            } else {
                prevEl.innerHTML = '<div class="pdf-badge"><span>&#128206;</span>' + fname + '</div>';
            }
            prevEl.classList.add('visible');
        }

        if (nameEl) { nameEl.textContent = fname; nameEl.classList.add('visible'); }
        card.classList.add('has-file');
    } else {
        badge.classList.remove('visible');
        card.classList.remove('has-file');
        if (prevEl) { prevEl.innerHTML = ''; prevEl.classList.remove('visible'); }
        if (nameEl) { nameEl.textContent = ''; nameEl.classList.remove('visible'); }
    }
}

/* ── Upload card: file selected ── */
function cardFileSelected(input, cardId, nameId, previewId) {
    var card   = document.getElementById(cardId);
    var nameEl = document.getElementById(nameId);
    var prevEl = document.getElementById(previewId);

    if (input.files && input.files[0]) {
        var file = input.files[0];
        var ext  = file.name.split('.').pop().toLowerCase();

        card.classList.add('has-file');
        nameEl.textContent = file.name;
        nameEl.classList.add('visible');
        prevEl.innerHTML = '';

        if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)) {
            var reader = new FileReader();
            reader.onload = function (ev) {
                prevEl.innerHTML = '<img src="' + ev.target.result + '" alt="preview">';
                prevEl.classList.add('visible');
            };
            reader.readAsDataURL(file);
        } else if (ext === 'pdf') {
            prevEl.innerHTML = '<div class="pdf-badge"><span>📄</span>PDF Ready</div>';
            prevEl.classList.add('visible');
        } else {
            prevEl.classList.remove('visible');
        }
    } else {
        card.classList.remove('has-file');
        nameEl.classList.remove('visible');
        prevEl.classList.remove('visible');
        prevEl.innerHTML = '';
    }
}

/* ── Upload card: remove file ── */
function cardRemoveFile(event, cardId, inputId, nameId, previewId) {
    event.stopPropagation();
    resetCard(cardId, inputId, nameId, previewId);
}

/* ── Upload card: drag & drop ── */
function cardDragOver(e, cardId) {
    e.preventDefault();
    document.getElementById(cardId).classList.add('dragover');
}

function cardDragLeave(cardId) {
    document.getElementById(cardId).classList.remove('dragover');
}

function cardDrop(e, cardId, inputId, nameId, previewId) {
    e.preventDefault();
    document.getElementById(cardId).classList.remove('dragover');
    var input  = document.getElementById(inputId);
    input.files = e.dataTransfer.files;
    cardFileSelected(input, cardId, nameId, previewId);
}

/* ── Profile photo preview ── */
function previewAvatar(input) {
    var preview  = document.getElementById('avatar-new-preview');
    var nameEl   = document.getElementById('avatar-new-name');
    var avatarEl = document.getElementById('avatar-display');

    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function (ev) {
            avatarEl.innerHTML =
                '<img src="' + ev.target.result + '" alt="Preview" style="width:100%;height:100%;object-fit:cover;">';
        };
        reader.readAsDataURL(input.files[0]);
        nameEl.textContent = input.files[0].name;
        preview.classList.add('visible');
    }
}

/* ── Auto-switch to archived tab from URL param ── */
(function () {
    var t = new URLSearchParams(window.location.search).get('tab');
    if (t === 'archived') switchTable('archived');
    if (t === 'main')     switchTable('main');
})();


function toggleProfileMenu(event) {
    event.stopPropagation();
    document.getElementById("profileDropdown").classList.toggle("show");
}

function closeProfileMenu() {
    document.getElementById("profileDropdown").classList.remove("show");
}

document.addEventListener("click", function(e) {
    const menu = document.getElementById("profileDropdown");
    const wrapper = document.querySelector(".header-avatar-wrapper");

    if (!menu || !wrapper) return;

    if (!wrapper.contains(e.target)) {
        menu.classList.remove("show");
    }
});


window.addEventListener('DOMContentLoaded', function () {

    const params = new URLSearchParams(window.location.search);
    const view = params.get('view') || 'dashboard';
    const step = params.get('step');

    showView(view);

    if (view === 'request') {

        const savedDoc = sessionStorage.getItem('hehms_selected_doc');

        if (step === 'form') {
            document.getElementById('step-document').style.display = 'none';
            document.getElementById('step-form').style.display = 'block';

            if (savedDoc) {
                document.getElementById('selected-document-input').value = savedDoc;
                document.getElementById('selected-doc-label').textContent = savedDoc;
                toggleSyForDoc(savedDoc);   // re-show the SY field if this doc needs it
            }
        }

        // Legacy payment/cashless steps removed — send old bookmarks
        // (step=payment / step=cashless) back to the form step.
        if (step === 'payment' || step === 'cashless') {
            document.getElementById('step-document').style.display = 'none';
            document.getElementById('step-form').style.display = 'block';

            if (savedDoc) {
                document.getElementById('selected-document-input').value = savedDoc;
                document.getElementById('selected-doc-label').textContent = savedDoc;
                toggleSyForDoc(savedDoc);
            }
        }
    }
});


document.getElementById('main-tbody').addEventListener('click', function (e) {
    const btn = e.target.closest('.btn-edit');
    if (!btn) return;
    openEditModal(
        btn.dataset.reqId,
        btn.dataset.docType,
        btn.dataset.purpose,
        btn.dataset.idPhoto,
        btn.dataset.sy,
        btn.dataset.gl
    );
});

/* ── Warn before leaving if the request form has unsaved input ── */
(function () {
    function requestFormHasData() {
        var purpose = document.getElementById('purpose-input');
        var idInput = document.getElementById('new-id-input');
        var alInput = document.getElementById('new-al-input');
        var selectedDoc = document.getElementById('selected-document-input');

        var hasPurpose = purpose && purpose.value.trim() !== '';
        var hasIdFile   = idInput && idInput.files && idInput.files.length > 0;
        var hasAlFile   = alInput && alInput.files && alInput.files.length > 0;
        var hasDoc      = selectedDoc && selectedDoc.value !== '';

        return hasPurpose || hasIdFile || hasAlFile || hasDoc;
    }

    window.addEventListener('beforeunload', function (e) {
        if (requestFormHasData()) {
            e.preventDefault();
            e.returnValue = '';
        }
    });
})();


document.getElementById('request-form').addEventListener('submit', function () {
    window.onbeforeunload = null;
    sessionStorage.removeItem('hehms_selected_doc');
});
/* ── Toggle fields when the doc type changes in the edit modal ── */
document.getElementById('edit-doc-type').addEventListener('change', function () {
    var glWrap = document.getElementById('edit-gl-wrap');
    var glInp  = document.getElementById('edit-gl-input');
    var needsGl = docNeedsGl(this.value);
    if (glWrap && glInp) {
        glWrap.style.display = needsGl ? '' : 'none';
        if (!needsGl) glInp.value = '';
    }
});
