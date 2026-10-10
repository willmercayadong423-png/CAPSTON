/* ═══════════════════════════════════════════════════
   HEHMHS — Student Dashboard JS  (dashb.js)
   ═══════════════════════════════════════════════════ */

/* ── Sidebar view switcher ── */
function showView(view) {

    // Whitelist — an unknown view (bad ?view= in the URL) would hide
    // every section and leave a blank page.
    const views = ['dashboard', 'request', 'requests', 'account', 'notifications'];
    if (views.indexOf(view) === -1) view = 'dashboard';

    views.forEach(function (v) {
        const el = document.getElementById('view-' + v);
        if (el) {
            el.style.display = (view === v) ? 'block' : 'none';
        }
        const nav = document.getElementById('nav-' + v);
        if (nav) {
            nav.classList.toggle('active', view === v);
        }
    });

    const params = new URLSearchParams(window.location.search);
    const step = params.get("step");

    let url = "?view=" + view;

    if (view === "request") {
        url += "&step=" + (step ? step : "document");
    }

    history.replaceState({}, "", url);

    if (view === 'notifications') {
        if (typeof initNotificationsReadState === 'function') {
            initNotificationsReadState();
        }
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
}

function selectDocument(docName) {

    // ── Read-only guard: documents with an existing active request ──
    // (Pending / Processing / Ready for Pickup / Released). The button is
    // rendered disabled, but a double guard here protects the sessionStorage
    // restore path and any stale tab.
    if (window.ACTIVE_DOC_TYPES
        && Object.prototype.hasOwnProperty.call(window.ACTIVE_DOC_TYPES, docName)) {
        backToStep1();
        alert('You already have a ' + window.ACTIVE_DOC_TYPES[docName]
            + ' request for "' + docName + '". You can request it again only after it is cancelled or rejected.');
        return;
    }

    document.getElementById('selected-document-input').value = docName;
    document.getElementById('selected-doc-label').textContent = docName;

    // ── Conditional fields: Grade Level + School Year ──
    toggleReqFields(docName);

    document.getElementById('step-document').style.display = 'none';
    document.getElementById('step-form').style.display = 'block';

    // Step indicator (portal design) — best effort, optional elements
    var d1 = document.getElementById('stepdot-1'), d2 = document.getElementById('stepdot-2');
    if (d1) d1.classList.remove('active');
    if (d2) d2.classList.add('active');

    sessionStorage.setItem('hehms_selected_doc', docName);

    history.replaceState({}, '', '?view=request&step=form');
}

function backToStep1() {
    document.getElementById('step-document').style.display = 'block';
    document.getElementById('step-form').style.display = 'none';

    // Step indicator (portal design) — best effort, optional elements
    var d1 = document.getElementById('stepdot-1'), d2 = document.getElementById('stepdot-2');
    if (d1) d1.classList.add('active');
    if (d2) d2.classList.remove('active');

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

    // Document type first — the card picker writes a hidden input, so an
    // empty value otherwise only fails server-side AFTER the upload.
    var docInput = document.getElementById('selected-document-input');
    if (!docInput || !docInput.value) {
        alert('Please choose the document you want to request.');
        var firstCard = document.querySelector('.doc-pick-card, .rf-doc-card, #doc-cards a');
        if (firstCard) firstCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
    }

    if (!purpose.value) {
        alert('Please select the purpose of your request.');
        purpose.focus();
        return;
    }
    var purposeOther = document.getElementById('purpose-other-input');
    if (purpose.value === 'Other' && purposeOther && !purposeOther.value.trim()) {
        alert('Please specify your purpose (under Other).');
        purposeOther.focus();
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
    // (colspan matches this table's real column count)
    var noMatch = tbody.querySelector('.no-match-row');
    if (active && visible === 0) {
        if (!noMatch) {
            var refRow = tbody.querySelector('tr[data-status]') || tbody.querySelector('tr');
            var cols   = refRow ? refRow.children.length : 6;
            noMatch = document.createElement('tr');
            noMatch.className = 'no-match-row';
            noMatch.innerHTML =
                '<td colspan="' + cols + '" style="text-align:center;padding:2rem;color:#888;">' +
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

/* ── Dropdown helpers (request form + edit modal) ───────────── */
function toggleOtherPurpose(otherInputId) {
    otherInputId = otherInputId || 'purpose-other-input';
    var other  = document.getElementById(otherInputId);
    var select = document.getElementById(otherInputId === 'edit-purpose-other-input'
        ? 'edit-purpose' : 'purpose-input');
    if (!other || !select) return;
    var isOther = select.value === 'Other';
    other.style.display = isOther ? '' : 'none';
    if (!isOther) other.value = '';
}

/* Set a <select>'s value, first adding it as an option when the saved
   value is legacy free-text that isn't in the dropdown list. */
function setSelectValue(selectEl, value) {
    if (!selectEl) return;
    if (value) {
        var exists = Array.prototype.slice.call(selectEl.options).some(function (o) { return o.value === value; });
        if (!exists) selectEl.add(new Option(value, value));
    }
    selectEl.value = value || '';
}

function toggleSyForDoc(docName) { toggleReqFields(docName); }   // legacy alias

/* ── Edit modal: toggle Grade Level + School Year together ── */
function updateEditReqFields(docType, glValue, syValue) {
    var needsGl = docNeedsGl(docType);

    var glWrap = document.getElementById('edit-gl-wrap');
    var glInp  = document.getElementById('edit-gl-input');
    if (glWrap && glInp) {
        glWrap.style.display = needsGl ? '' : 'none';
        setSelectValue(glInp, needsGl ? glValue : '');
    }

    var syWrap = document.getElementById('edit-sy-wrap');
    var syInp  = document.getElementById('edit-sy-input');
    if (syWrap && syInp) {
        syWrap.style.display = '';          // SY is required for every document
        setSelectValue(syInp, syValue);
    }
}

/* ── Edit modal ── */
function openEditModal(id, docType, purpose, existId, existSy, existGl) {
    document.getElementById('edit-req-id').value = id;

    // Purpose dropdown: pick the saved list option, or "Other" + free text
    var purposeSel   = document.getElementById('edit-purpose');
    var purposeOther = document.getElementById('edit-purpose-other-input');
    var purposeKnown = Array.prototype.slice.call(purposeSel.options).some(function (o) {
        return o.value === purpose && purpose !== '';
    });
    if (purpose && !purposeKnown) {
        purposeSel.value   = 'Other';
        purposeOther.value = purpose;
    } else {
        purposeSel.value   = purpose || '';
        purposeOther.value = '';
    }
    toggleOtherPurpose('edit-purpose-other-input');

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

    // One active request per document type: lock every option the student
    // already has an active request for, EXCEPT this request's own type.
    Array.prototype.slice.call(sel.options).forEach(function (o) {
        o.disabled = !!docType && o.value !== docType
            && !!window.ACTIVE_DOC_TYPES
            && Object.prototype.hasOwnProperty.call(window.ACTIVE_DOC_TYPES, o.value);
    });

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
                    '<div class="pdf-badge"><span>' + svgIcon('file-text', 13) + '</span>PDF on file &mdash; ' +
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
            prevEl.innerHTML = '<div class="pdf-badge"><span>' + svgIcon('file-text', 13) + '</span>PDF Ready</div>';
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

/* ── Profile photo preview ──
   NOTE: previewAvatar is defined in dashboard.php's inline script (it also
   fills the small "new photo" thumbnail) — intentionally not duplicated here. */

/* ── Auto-switch to archived tab from URL param ── */
(function () {
    var t = new URLSearchParams(window.location.search).get('tab');
    if (t === 'archived') switchTable('archived');
    if (t === 'main')     switchTable('main');
})();


function toggleProfileMenu(event) {
    event.stopPropagation();
    document.getElementById("profileDropdown").classList.toggle("show");
    // Only one header dropdown open at a time
    closeNotifPanel();
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


/* ── Notification bell panel ── */
function toggleNotifPanel(event) {
    event.stopPropagation();
    document.getElementById("notifPanel").classList.toggle("open");
    // Only one header dropdown open at a time
    closeProfileMenu();
    // Refresh unread highlights + badge count when the panel opens
    initNotificationsReadState();
}

function closeNotifPanel() {
    document.getElementById("notifPanel").classList.remove("open");
}

document.addEventListener("click", function(e) {
    const panel = document.getElementById("notifPanel");
    const wrapper = document.querySelector(".notif-panel-wrapper");

    if (!panel || !wrapper) return;

    if (!wrapper.contains(e.target)) {
        panel.classList.remove("open");
    }
});


/* ══════════ NOTIFICATIONS LOGIC ══════════ */
function filterNotifs(type, btn) {
    const pills = document.querySelectorAll('.notif-pill');
    pills.forEach(function (p) { p.classList.remove('active'); });
    if (btn) btn.classList.add('active');

    const cards = document.querySelectorAll('.notif-card');
    let visibleCount = 0;

    cards.forEach(function (card) {
        const cType = card.dataset.type;
        if (type === 'all' || cType === type) {
            card.style.display = 'flex';
            visibleCount++;
        } else {
            card.style.display = 'none';
        }
    });

    let emptyCategory = document.getElementById('notif-category-empty');
    const container = document.getElementById('notifCardsGrid');
    if (!emptyCategory && container) {
        emptyCategory = document.createElement('div');
        emptyCategory.id = 'notif-category-empty';
        emptyCategory.className = 'notif-empty-state';
        emptyCategory.style.padding = '40px 20px';
        emptyCategory.innerHTML = '<div class="notif-empty-icon">' + svgIcon('inbox', 30) + '</div><h3>No notifications found</h3><p>There are no notifications matching this filter.</p>';
        container.appendChild(emptyCategory);
    }
    if (emptyCategory) {
        emptyCategory.style.display = (visibleCount === 0) ? 'block' : 'none';
    }
}

/* Storage access can throw (blocked cookies / private mode) — never let
   it kill the read-state logic. */
function notifStorageSet(key, val) { try { localStorage.setItem(key, val); } catch (e) {} }
function notifStorageGet(key)     { try { return localStorage.getItem(key); } catch (e) { return null; } }

function markAllNotifsAsRead() {
    notifStorageSet('hehms_student_notif_last_seen', Date.now().toString());

    // Visually unmark unread state (full view cards + bell panel items)
    document.querySelectorAll('.notif-card.is-unread').forEach(function (card) {
        card.classList.remove('is-unread');
    });
    document.querySelectorAll('.np-item.np-unread').forEach(function (item) {
        item.classList.remove('np-unread');
    });

    // Clear / hide badges
    const avatarBadge = document.getElementById('avatarNotifBadge');
    if (avatarBadge) avatarBadge.style.display = 'none';

    const dropdownBadge = document.getElementById('dropdownNotifBadge');
    if (dropdownBadge) dropdownBadge.style.display = 'none';

    // Feedback on whichever "mark all read" button(s) exist
    ['.btn-mark-all-read', '.np-mark-read'].forEach(function (sel) {
        const btn = document.querySelector(sel);
        if (!btn) return;
        const origText = btn.innerHTML;
        btn.textContent = 'All marked as read';
        btn.disabled = true;
        setTimeout(function () {
            btn.innerHTML = origText;
            btn.disabled = false;
        }, 2000);
    });
}

function initNotificationsReadState() {
    const lastSeenStr = notifStorageGet('hehms_student_notif_last_seen');
    const lastSeen = lastSeenStr ? parseInt(lastSeenStr, 10) : 0;

    let unreadCount = 0;
    const cards = document.querySelectorAll('.notif-card');

    cards.forEach(function (card) {
        const timeSec = parseInt(card.dataset.time || '0', 10);
        const timeMs = timeSec * 1000;
        if (timeMs > lastSeen) {
            card.classList.add('is-unread');
            unreadCount++;
        } else {
            card.classList.remove('is-unread');
        }
    });

    // Bell dropdown panel items share the same unread logic
    document.querySelectorAll('.np-item').forEach(function (item) {
        const timeSec = parseInt(item.dataset.time || '0', 10);
        item.classList.toggle('np-unread', (timeSec * 1000) > lastSeen);
    });

    const avatarBadge = document.getElementById('avatarNotifBadge');
    const dropdownBadge = document.getElementById('dropdownNotifBadge');

    if (unreadCount === 0) {
        if (avatarBadge) avatarBadge.style.display = 'none';
        if (dropdownBadge) dropdownBadge.style.display = 'none';
    } else {
        const displayVal = unreadCount > 99 ? '99+' : unreadCount;
        if (avatarBadge) {
            avatarBadge.textContent = displayVal;
            avatarBadge.style.display = 'block';
        }
        if (dropdownBadge) {
            dropdownBadge.textContent = displayVal;
            dropdownBadge.style.display = 'inline-block';
        }
    }
}

window.addEventListener('DOMContentLoaded', function () {

    const params = new URLSearchParams(window.location.search);
    const view = params.get('view') || 'dashboard';
    const step = params.get('step');

    showView(view);
    initNotificationsReadState();

    if (view === 'request') {

        const savedDoc = sessionStorage.getItem('hehms_selected_doc');

        if (step === 'form') {
            // A doc saved in sessionStorage may have become locked (request
            // submitted in another tab / stale bookmark) — send the student
            // back to the selection step instead of restoring a taken doc.
            if (savedDoc && window.ACTIVE_DOC_TYPES
                && Object.prototype.hasOwnProperty.call(window.ACTIVE_DOC_TYPES, savedDoc)) {
                sessionStorage.removeItem('hehms_selected_doc');
                history.replaceState({}, '', '?view=request');
                return;
            }

            document.getElementById('step-document').style.display = 'none';
            document.getElementById('step-form').style.display = 'block';

            var _d1 = document.getElementById('stepdot-1'), _d2 = document.getElementById('stepdot-2');
            if (_d1) _d1.classList.remove('active');
            if (_d2) _d2.classList.add('active');

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

/* ── Upload cards: click the card → open the file picker ──────────
   The file input is visually hidden (CSS); the card itself acts as
   the dropzone/trigger. Applies to the request-form card and the
   edit-modal card. */
document.querySelectorAll('.req-upload-card').forEach(function (card) {
    card.addEventListener('click', function (e) {
        // Don't re-open the picker on the remove button / "view file" link
        if (e.target.closest('.ruc-remove-btn') || e.target.closest('a')) return;
        var input = card.querySelector('input[type="file"]');
        if (input && !input.disabled) input.click();
    });
});

/* ── Warn before leaving if the request form has unsaved input ── */
(function () {
    function requestFormHasData() {
        var purpose = document.getElementById('purpose-input');
        var idInput = document.getElementById('new-id-input');
        var selectedDoc = document.getElementById('selected-document-input');

        var hasPurpose = purpose && purpose.value.trim() !== '';
        var hasIdFile   = idInput && idInput.files && idInput.files.length > 0;
        var hasDoc      = selectedDoc && selectedDoc.value !== '';

        return hasPurpose || hasIdFile || hasDoc;
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
