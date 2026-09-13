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

    document.getElementById('step-document').style.display = 'none';
    document.getElementById('step-form').style.display = 'block';
    document.getElementById('step-payment').style.display = 'none';

    sessionStorage.setItem('hehms_selected_doc', docName);

    history.replaceState({}, '', '?view=request&step=form');
}

function backToStep1() {
    document.getElementById('step-document').style.display = 'block';
    document.getElementById('step-form').style.display = 'none';
    document.getElementById('step-payment').style.display = 'none';

    sessionStorage.removeItem('hehms_selected_doc');

    history.replaceState({}, "", "?view=request");
}

function proceedToPayment() {
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

    document.getElementById('step-form').style.display = 'none';
    document.getElementById('step-payment').style.display = 'block';
    history.replaceState({}, '', '?view=request&step=payment');
}

function backToStep2() {
    document.getElementById('step-document').style.display = 'none';
    document.getElementById('step-form').style.display = 'block';
    document.getElementById('step-payment').style.display = 'none';

    history.replaceState({}, "", "?view=request&step=form");
}

function proceedPayment() {
    let payment = document.querySelector('input[name="payment"]:checked');

    if (!payment) {
        alert("Please select a payment method.");
        return;
    }

    if (payment.value === "Cash") {
        document.getElementById('request-form').requestSubmit();

    } else if (payment.value === "Cashless") {
        document.getElementById("step-payment").style.display = "none";
        document.getElementById("step-cashless").style.display = "block";
        history.replaceState({}, '', '?view=request&step=cashless');
    }
}

function backToPayment() {
    document.getElementById("step-cashless").style.display = "none";
    document.getElementById("step-payment").style.display = "block";
    history.replaceState({}, '', '?view=request&step=payment');
}

/* ── Cashless payment submit (receipt optional) ── */
function submitCashlessPayment() {
    document.getElementById('request-form').requestSubmit();
}


/* ── Main/Archived sub-tab switch ── */
function switchTable(tab) {
    document.getElementById('tab-main').classList.toggle('active',     tab === 'main');
    document.getElementById('tab-archived').classList.toggle('active', tab === 'archived');
    document.getElementById('tbl-main').style.display     = tab === 'main'     ? '' : 'none';
    document.getElementById('tbl-archived').style.display = tab === 'archived' ? '' : 'none';
}

/* ── Search filter ── */
function filterRows(tbodyId, q) {
    q = q.toLowerCase();
    document.querySelectorAll('#' + tbodyId + ' tr').forEach(function (row) {
        row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
}

/* ── Edit modal ── */
function openEditModal(id, docType, purpose, existId, existAl, existReceipt) {
    document.getElementById('edit-req-id').value  = id;
    document.getElementById('edit-purpose').value = purpose;

    var sel = document.getElementById('edit-doc-type');
    for (var i = 0; i < sel.options.length; i++) {
        if (sel.options[i].value === docType) { sel.selectedIndex = i; break; }
    }

    resetCard('edit-card-id', 'edit-id-input', 'edit-id-name', 'edit-id-preview');
    resetCard('edit-card-al', 'edit-al-input', 'edit-al-name', 'edit-al-preview');

    applyExisting(existId, 'edit-card-id', 'edit-id-existing', 'edit-id-existing-link', id, 'id_photo');
    applyExisting(existAl, 'edit-card-al', 'edit-al-existing', 'edit-al-existing-link', id, 'auth_letter');

    // Receipt card is optional in the edit modal — only wire it up if present
    if (document.getElementById('edit-card-receipt')) {
        resetCard('edit-card-receipt', 'edit-receipt-input', 'edit-receipt-name', 'edit-receipt-preview');
        applyExisting(existReceipt, 'edit-card-receipt', 'edit-receipt-existing', 'edit-receipt-existing-link', id, 'receipt');
    }

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
        var downloadUrl = 'download.php?field=' + field + '&req_id=' + reqId;

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
            }
        }

        if (step === 'payment') {
            document.getElementById('step-document').style.display = 'none';
            document.getElementById('step-form').style.display = 'none';
            document.getElementById('step-payment').style.display = 'block';

            if (savedDoc) {
                document.getElementById('selected-document-input').value = savedDoc;
                document.getElementById('selected-doc-label').textContent = savedDoc;
            }
        }

        if (step === 'cashless') {
            document.getElementById('step-document').style.display = 'none';
            document.getElementById('step-form').style.display = 'none';
            document.getElementById('step-payment').style.display = 'none';
            document.getElementById('step-cashless').style.display = 'block';

            if (savedDoc) {
                document.getElementById('selected-document-input').value = savedDoc;
                document.getElementById('selected-doc-label').textContent = savedDoc;
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
        btn.dataset.authLetter,
        btn.dataset.receipt
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