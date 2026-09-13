/* ═══════════════════════════════════════════════════
   HEHMS — Student Records JS  (studRec.js)
   ═══════════════════════════════════════════════════ */

var currentView        = 'enrolled';
var editingStudentId   = null;
var originalEmail      = null;
var currentRoleFilter  = '';

document.addEventListener('DOMContentLoaded', function () {

    loadStudents();

    document.getElementById('tab-enrolled').addEventListener('click', function () { switchView('enrolled'); });
    document.getElementById('tab-alumni').addEventListener('click', function () { switchView('alumni'); });
    document.getElementById('student-search').addEventListener('input', applyFilters);

    document.getElementById('btn-open-modal').addEventListener('click', function (e) {
        e.preventDefault();
        openAddModal();
    });
    document.getElementById('btn-close-modal').addEventListener('click', closeAddModal);
    document.getElementById('btn-save-student').addEventListener('click', submitStudent);

    document.getElementById('f-photo').addEventListener('change', function () {
        previewModalAvatar(this);
    });
    document.getElementById('modal-photo-clear').addEventListener('click', clearModalAvatar);
    document.getElementById('addStudentModal').addEventListener('click', handleAddOverlayClick);

    document.querySelectorAll('.pw-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () { togglePw(btn); });
    });

    // ── CSV Import button ───────────────────────────────────────────
    var importBtn = document.getElementById('btn-import-csv');
    if (importBtn) {
        importBtn.addEventListener('click', function (e) {
            e.preventDefault();
            document.getElementById('csv-file-input').click();
        });
    }
    var csvInput = document.getElementById('csv-file-input');
    if (csvInput) {
        csvInput.addEventListener('change', function () {
            if (this.files && this.files[0]) handleCsvImport(this.files[0]);
            this.value = ''; // reset so same file can be re-selected
        });
    }
    // ────────────────────────────────────────────────────────────────

    // ── Auto-fill password from last 5 digits of LRN ───────────────
    var lrnInput = document.getElementById('f-lrn');
    if (lrnInput) {
        lrnInput.addEventListener('input', function () {
            var lrn = this.value.trim();
            var pwField = document.getElementById('f-password');
            if (!pwField) return;
            if (editingStudentId) return;
            pwField.value = lrn.length >= 5 ? new Date().getFullYear() + '-' + lrn.slice(-5) : '';
        });
    }
    // ───────────────────────────────────────────────────────────────




// ── Role filter dropdown ────────────────────────────────────────
  var roleBtn  = document.getElementById('role-filter-btn');
    var roleDrop = document.getElementById('role-filter-dropdown');
    if (roleBtn && roleDrop) {
        // Move dropdown to body so it escapes table stacking context
        document.body.appendChild(roleDrop);
        roleDrop.style.position = 'fixed';

        roleBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            var isOpen = roleDrop.classList.contains('open');
            roleDrop.classList.toggle('open', !isOpen);
            roleBtn.classList.toggle('active', !isOpen);
            if (!isOpen) {
                // Position it under the button
                var rect = roleBtn.getBoundingClientRect();
                roleDrop.style.top  = (rect.bottom + 4) + 'px';
                roleDrop.style.left = rect.left + 'px';
            }
        });
        roleDrop.querySelectorAll('a').forEach(function (a) {
            a.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                currentRoleFilter = this.dataset.role;
                roleDrop.querySelectorAll('a').forEach(function (x) { x.classList.remove('active'); });
                this.classList.add('active');
                roleDrop.classList.remove('open');
                roleBtn.classList.remove('active');
                roleBtn.textContent = currentRoleFilter ? ('▾ ' + currentRoleFilter) : '▾';
                applyFilters();
            });
        });
        document.addEventListener('click', function (e) {
            if (!roleBtn.contains(e.target) && !roleDrop.contains(e.target)) {
                roleDrop.classList.remove('open');
                roleBtn.classList.remove('active');
            }
        });
    }
    // ────────────────────────────────────────────────────────────────

    // ── View Docs modal close buttons ───────────────────────────────
    var closeDocsBtn1 = document.getElementById('btn-close-docs-modal');
    var closeDocsBtn2 = document.getElementById('btn-close-docs-footer');
    if (closeDocsBtn1) closeDocsBtn1.addEventListener('click', closeDocsModal);
    if (closeDocsBtn2) closeDocsBtn2.addEventListener('click', closeDocsModal);
    var docsOverlay = document.getElementById('viewDocsModal');
    if (docsOverlay) {
        docsOverlay.addEventListener('click', function (e) {
            if (e.target === docsOverlay) closeDocsModal();
        });
    }
    // ────────────────────────────────────────────────────────────────















    // ── Strand auto-disable when non-SHS grade is selected ─────────
    var gradeEl  = document.getElementById('f-grade');
    var strandGrp = document.getElementById('group-strand');
    if (gradeEl && strandGrp) {
        gradeEl.addEventListener('change', function () {
            var shs = ['Grade 11', 'Grade 12'];
            var strandSel = document.getElementById('f-strand');
            if (!shs.includes(this.value)) {
                if (strandSel) strandSel.value = '';
                strandGrp.style.opacity      = '0.45';
                strandGrp.style.pointerEvents = 'none';
            } else {
                strandGrp.style.opacity      = '';
                strandGrp.style.pointerEvents = '';
            }
        });
    }
});

/* ── Load & render ── */
function loadStudents() {
    fetch('phpLogics/getStud.php')
        .then(function (res) { return res.json(); })
        .then(function (data) { renderStudents(data); })
        .catch(function (err) { console.error('Error loading students:', err); });
}

function renderStudents(students) {
    var tbody     = document.getElementById('records-tbody');
    tbody.innerHTML = '';
    var total = 0, registrars = 0, archived = 0;

    students.forEach(function (s) {
        var fullName   = (s.first_name + ' ' + s.last_name).trim();
        var status     = (s.status || '').toLowerCase().trim();
        var role       = (s.role   || '').toLowerCase().trim();
        var isArchived = (status === 'archived' || status === 'archive');

        if (isArchived) {
            archived++;
        } else {
            if (role === 'student')   total++;
            if (role === 'registrar') registrars++;
        }

        var contact = s.contact_number || s.contact || '';
        var row     = document.createElement('tr');
        row.className            = isArchived ? 'view-alumni' : 'view-enrolled';
        row.dataset.profilePhoto = s.profile_photo || '';
        row.dataset.id           = s.student_id;
        row.dataset.fullname     = fullName;
        row.dataset.role         = s.role;
        row.dataset.email        = s.email;
        row.dataset.contact      = contact;
        row.dataset.lrn          = s.lrn                        || '';
        row.dataset.dob          = s.date_of_birth              || '';
        row.dataset.grade        = s.grade_level                || '';
        row.dataset.strand       = s.strand                     || '';
        row.dataset.syear        = s.school_year_last_attended  || '';

        // ── Searchable composite string stored as data attribute ────
        row.dataset.searchIndex = [
            s.student_id,
            fullName,
            s.role,
            s.email,
            contact,
            s.lrn || '',
            s.grade_level || '',
            s.strand || ''
        ].join(' ').toLowerCase();
        // ────────────────────────────────────────────────────────────

        row.innerHTML =
            '<td><strong>' + s.student_id + '</strong></td>' +
            '<td><div class="rec-name-cell">' + avatarHtml(s) + '<span>' + fullName + '</span></div></td>' +
            '<td><span class="badge-grade">' + s.role + '</span></td>' +
            '<td><small>' + s.email + '</small></td>' +
            '<td>' + contact + '</td>' +
            '<td><div class="action-group"><div class="dropdown">' +
                '<button class="dots-btn">⋮</button>' +
                '<div class="dropdown-content">' +
                    '<a href="#" class="edit">✏️ Edit Record</a>' +
                    '<a href="#" class="view-docs">📄 View Documents</a>' +
                    (isArchived
                        ? '<a href="#" class="restore">♻️ Unarchive</a>'
                        : '<a href="#" class="delete">📦 Archive</a>') +
                '</div></div></div></td>';

        tbody.appendChild(row);
    });

    document.getElementById('stat-total').textContent     = total;
    document.getElementById('stat-registrar').textContent = registrars;
    document.getElementById('stat-archived').textContent  = archived;

    document.querySelectorAll('.rec-avatar').forEach(function (img) {
        img.onerror = function () {
            var span = document.createElement('span');
            span.className   = 'rec-avatar-initials';
            span.textContent = img.dataset.initials;
            img.replaceWith(span);
        };
    });

    applyFilters();
}

function avatarHtml(s) {
    var name     = (s.first_name || '') + ' ' + (s.last_name || '');
    var initials = ((s.first_name || '').charAt(0) + (s.last_name || '').charAt(0)).toUpperCase();
    if (s.profile_photo) {
        return '<img src="' + s.profile_photo + '" alt="' + name + '" class="rec-avatar" data-initials="' + initials + '">';
    }
    return '<span class="rec-avatar-initials">' + initials + '</span>';
}

/* ── Tab & filter ── */
function switchView(view) {
    currentView = view;
    document.getElementById('tab-enrolled').classList.toggle('active', view === 'enrolled');
    document.getElementById('tab-alumni').classList.toggle('active', view === 'alumni');
    applyFilters();
}

/* ── Search now matches ID, name, role, email, contact, LRN, grade, strand ── */
function applyFilters() {
    var search = document.getElementById('student-search').value.toLowerCase().trim();
    var hasResults = false;

    document.querySelectorAll('#records-tbody tr:not(.no-results-row)').forEach(function (row) {
        if (!row.classList.contains('view-' + currentView)) {
            row.style.display = 'none';
            return;
        }
        var roleMatch = !currentRoleFilter ||
            (row.dataset.role || '').toLowerCase() === currentRoleFilter.toLowerCase();
        var match = roleMatch && (!search || (row.dataset.searchIndex || '').includes(search));
        row.style.display = match ? '' : 'none';
        if (match) hasResults = true;
    });

    // ── No-results message ──────────────────────────────────────────
    var existing = document.querySelector('#records-tbody .no-results-row');
    if (!hasResults && search) {
        if (!existing) {
            var noRow = document.createElement('tr');
            noRow.className = 'no-results-row';
            noRow.innerHTML = '<td colspan="6" style="text-align:center;padding:2rem;color:#888;">No records found for "<strong>' + escapeHtml(search) + '</strong>"</td>';
            document.getElementById('records-tbody').appendChild(noRow);
        } else {
            existing.style.display = '';
            existing.querySelector('td').innerHTML = 'No records found for "<strong>' + escapeHtml(search) + '</strong>"';
        }
    } else if (existing) {
        existing.style.display = 'none';
    }
    // ────────────────────────────────────────────────────────────────
}

function escapeHtml(str) {
    return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ── CSV Import ── */
function handleCsvImport(file) {
    var reader = new FileReader();
    reader.onload = function (e) {
        var lines = e.target.result.split(/\r?\n/).filter(function (l) { return l.trim(); });
        if (lines.length < 2) { showToast('❌ CSV is empty or has no data rows.', 'error'); return; }

        var headers = lines[0].split(',').map(function (h) { return h.trim().toLowerCase().replace(/\s+/g,'_'); });

        // Required columns
        var required = ['first_name', 'last_name', 'role', 'email'];
        var missing  = required.filter(function (r) { return headers.indexOf(r) === -1; });
        if (missing.length) {
            showToast('❌ CSV missing columns: ' + missing.join(', '), 'error');
            return;
        }

        var rows = [];
        for (var i = 1; i < lines.length; i++) {
            var cols = parseCsvLine(lines[i]);
            if (cols.length < 2) continue;
            var obj = {};
            headers.forEach(function (h, idx) { obj[h] = (cols[idx] || '').trim(); });
            rows.push(obj);
        }

        if (!rows.length) { showToast('❌ No valid rows found in CSV.', 'error'); return; }

        // Show preview modal
        openCsvPreviewModal(rows, headers);
    };
    reader.readAsText(file);
}

function parseCsvLine(line) {
    var cols = [], cur = '', inQ = false;
    for (var i = 0; i < line.length; i++) {
        var c = line[i];
        if (c === '"') { inQ = !inQ; }
        else if (c === ',' && !inQ) { cols.push(cur); cur = ''; }
        else { cur += c; }
    }
    cols.push(cur);
    return cols;
}

function openCsvPreviewModal(rows, headers) {
    // Build or reuse preview modal
    var overlay = document.getElementById('csvPreviewModal');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id        = 'csvPreviewModal';
        overlay.className = 'add-modal-overlay';
        overlay.innerHTML =
            '<div class="add-modal-box" style="max-width:780px" role="dialog">' +
                '<div class="add-modal-header">' +
                    '<h3 id="csvModalTitle">📥 Import Preview</h3>' +
                    '<button id="btn-close-csv-modal" class="modal-close" type="button">✕</button>' +
                '</div>' +
                '<div class="add-modal-body" id="csv-preview-body" style="overflow-x:auto"></div>' +
                '<div class="add-modal-footer" style="gap:0.75rem;display:flex;justify-content:flex-end">' +
                    '<button id="btn-cancel-import" class="btn-save" style="background:#6b7280" type="button">Cancel</button>' +
                    '<button id="btn-confirm-import" class="btn-save" type="button">✅ Import All</button>' +
                '</div>' +
            '</div>';
        document.body.appendChild(overlay);

        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) overlay.classList.remove('active');
        });
        document.getElementById('btn-close-csv-modal').addEventListener('click', function () {
            overlay.classList.remove('active');
        });
        document.getElementById('btn-cancel-import').addEventListener('click', function () {
            overlay.classList.remove('active');
        });
    }

    // Build preview table
    var display = ['first_name','last_name','role','email','contact','lrn','grade_level','strand','school_year_last_attended'];
    var labels  = {
        first_name:'First Name', last_name:'Last Name', role:'Role', email:'Email',
        contact:'Contact', lrn:'LRN', grade_level:'Grade', strand:'Strand',
        school_year_last_attended:'School Year'
    };
    var cols = display.filter(function (d) { return headers.indexOf(d) !== -1; });

    var html = '<p style="margin-bottom:0.75rem;color:#555;">Previewing <strong>' + rows.length + '</strong> row(s). Review before importing.</p>';
    html += '<table style="width:100%;border-collapse:collapse;font-size:0.8rem"><thead><tr>';
    cols.forEach(function (c) {
        html += '<th style="padding:6px 8px;background:#f3f4f6;border:1px solid #e5e7eb;text-align:left">' + (labels[c] || c) + '</th>';
    });
    html += '</tr></thead><tbody>';
    rows.forEach(function (r, idx) {
        var bg = idx % 2 === 0 ? '#fff' : '#f9fafb';
        html += '<tr style="background:' + bg + '">';
        cols.forEach(function (c) {
            html += '<td style="padding:5px 8px;border:1px solid #e5e7eb">' + escapeHtml(r[c] || '') + '</td>';
        });
        html += '</tr>';
    });
    html += '</tbody></table>';

    document.getElementById('csv-preview-body').innerHTML = html;
    document.getElementById('csvPreviewModal').classList.add('active');

    // Wire confirm button (replace old listener)
    var confirmBtn = document.getElementById('btn-confirm-import');
    var newBtn     = confirmBtn.cloneNode(true);
    confirmBtn.parentNode.replaceChild(newBtn, confirmBtn);
    newBtn.addEventListener('click', function () {
        executeCsvImport(rows, newBtn);
    });
}

function executeCsvImport(rows, btn) {
    btn.disabled    = true;
    btn.textContent = '⏳ Importing…';

    var success = 0, failed = 0;

    function importNext(idx) {
        if (idx >= rows.length) {
            btn.disabled    = false;
            btn.textContent = '✅ Import All';
            document.getElementById('csvPreviewModal').classList.remove('active');
            showToast('📥 Import done: ' + success + ' added, ' + failed + ' failed.', success > 0 ? 'success' : 'error');
            loadStudents();
            return;
        }

        var r  = rows[idx];
        var fd = new FormData();
        fd.append('first_name', r.first_name                       || '');
        fd.append('last_name',  r.last_name                        || '');
        fd.append('role',       r.role                             || 'Student');
        fd.append('email',      r.email                            || '');
        fd.append('contact',    r.contact                          || '');
        fd.append('lrn',        r.lrn                              || '');
        fd.append('date_of_birth', r.date_of_birth                 || '');
        fd.append('grade_level',   r.grade_level                   || '');
        fd.append('strand',        r.strand                        || '');
        fd.append('school_year_last_attended', r.school_year_last_attended || '');

        // Auto-generate password from LRN if not supplied
        var pw = r.password || '';
        if (!pw && r.lrn && r.lrn.length >= 5) {
            pw = new Date().getFullYear() + '-' + r.lrn.slice(-5);
        }
        fd.append('password', pw);
        fd.append('csrf_token', CSRF_TOKEN);

        fetch('phpLogics/addStud.php', { method: 'POST', body: fd })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.success) success++; else failed++;
                importNext(idx + 1);
            })
            .catch(function () { failed++; importNext(idx + 1); });
    }

    importNext(0);
}

/* ── Dropdown menu ── */
var MENU_WIDTH      = 165;
var _activeDropdown = null;

function openDropdown(btn) {
    closeAllDropdowns();
    var dropdown = btn.closest('.dropdown');
    var menu     = dropdown.querySelector('.dropdown-content');
    menu._origin = dropdown;
    menu._row    = btn.closest('tr');
    document.body.appendChild(menu);
    menu.style.position = 'fixed';
    menu.style.display  = 'block';
    positionMenu(btn, menu);
    dropdown.classList.add('active');
    _activeDropdown = { dropdown: dropdown, btn: btn, menu: menu };
}

function closeAllDropdowns() {
    if (_activeDropdown) {
        var d = _activeDropdown;
        d.menu.style.display = 'none';
        if (d.menu._origin) d.menu._origin.appendChild(d.menu);
        d.dropdown.classList.remove('active');
        _activeDropdown = null;
    }
}

function positionMenu(btn, menu) {
    var rect = btn.getBoundingClientRect();
    var left = rect.right - MENU_WIDTH;
    if (left < 8) left = 8;
    menu.style.top  = (rect.bottom + 4) + 'px';
    menu.style.left = left + 'px';
}

document.addEventListener('click', function (e) {
    var dotsBtn = e.target.closest('.dots-btn');
    if (dotsBtn) {
        e.stopPropagation();
        var dropdown = dotsBtn.closest('.dropdown');
        var isOpen   = dropdown.classList.contains('active');
        closeAllDropdowns();
        if (!isOpen) openDropdown(dotsBtn);
        return;
    }

    var editLink = e.target.closest('a.edit');
    if (editLink) {
        e.preventDefault();
        var row = _activeDropdown ? _activeDropdown.menu._row : editLink.closest('tr');
        closeAllDropdowns();
        if (row) openEditModal(row);
        return;
    }

    var archiveLink = e.target.closest('a.delete');
    if (archiveLink) {
        e.preventDefault();
        var row = _activeDropdown ? _activeDropdown.menu._row : archiveLink.closest('tr');
        closeAllDropdowns();
        if (!row || !confirm('Move this student to archive?')) return;
        fetch('phpLogics/archStud.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'student_id=' + encodeURIComponent(row.dataset.id) +
                  '&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
        })
            .then(function (r) { return r.json(); })
            .then(function (d) { if (d.success) { showToast('📦 Student archived', 'success'); loadStudents(); } });
        return;
    }



var viewDocsLink = e.target.closest('a.view-docs');
    if (viewDocsLink) {
        e.preventDefault();
        var row = _activeDropdown ? _activeDropdown.menu._row : viewDocsLink.closest('tr');
        closeAllDropdowns();
        if (row) openDocsModal(row);
        return;
    }





    var restoreLink = e.target.closest('a.restore');
    if (restoreLink) {
        e.preventDefault();
        var row = _activeDropdown ? _activeDropdown.menu._row : restoreLink.closest('tr');
        closeAllDropdowns();
        if (!row || !confirm('Restore this student?')) return;
        fetch('phpLogics/unArch.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'student_id=' + encodeURIComponent(row.dataset.id) +
                  '&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
        })
            .then(function (r) { return r.json(); })
            .then(function (d) { if (d.success) { showToast('♻️ Student restored', 'success'); loadStudents(); } });
        return;
    }

    closeAllDropdowns();
});

window.addEventListener('scroll', function () {
    if (_activeDropdown) positionMenu(_activeDropdown.btn, _activeDropdown.menu);
}, true);

/* ── Modal: Add ── */
function openAddModal() {
    editingStudentId = null;
    originalEmail    = null;
    resetForm();
    document.getElementById('addModalTitle').textContent          = '➕ Add New Account';
    document.getElementById('pw-required').style.display          = 'inline';
    document.getElementById('f-password').placeholder = 'Password will be auto-filled from LRN if left blank';
    document.getElementById('modal-avatar-display').textContent   = '';
    document.getElementById('modal-photo-name').textContent       = '';
    document.getElementById('modal-photo-clear').style.display    = 'none';
    document.getElementById('addStudentModal').classList.add('active');
}

/* ── Modal: Edit ── */
function openEditModal(row) {
    editingStudentId = row.dataset.id;
    originalEmail    = row.dataset.email;
    var parts        = row.dataset.fullname.split(' ');

    document.getElementById('f-first').value   = parts[0] || '';
    document.getElementById('f-last').value    = parts.slice(1).join(' ') || '';
    document.getElementById('f-role').value    = row.dataset.role;
    document.getElementById('f-email').value   = row.dataset.email;
    document.getElementById('f-contact').value = row.dataset.contact;
    document.getElementById('f-password').value = '';

    var lrnEl    = document.getElementById('f-lrn');
    var dobEl    = document.getElementById('f-dob');
    var gradeEl  = document.getElementById('f-grade');
    var strandEl = document.getElementById('f-strand');
    var syearEl  = document.getElementById('f-syear');

    if (lrnEl)    lrnEl.value    = row.dataset.lrn    || '';
    if (dobEl)    dobEl.value    = row.dataset.dob    || '';
    if (gradeEl)  gradeEl.value  = row.dataset.grade  || '';
    if (strandEl) strandEl.value = row.dataset.strand || '';
    if (syearEl)  syearEl.value  = row.dataset.syear  || '';

    var strandGrp = document.getElementById('group-strand');
    if (gradeEl && strandGrp) {
        var shs = ['Grade 11', 'Grade 12'];
        if (!shs.includes(gradeEl.value)) {
            strandGrp.style.opacity      = '0.45';
            strandGrp.style.pointerEvents = 'none';
        } else {
            strandGrp.style.opacity      = '';
            strandGrp.style.pointerEvents = '';
        }
    }

    document.getElementById('addModalTitle').textContent  = '✏️ Edit Information';
    document.getElementById('pw-required').style.display  = 'none';
    document.getElementById('f-password').placeholder     = 'Leave blank to keep current';

    document.querySelectorAll('.field-error').forEach(function (el) {
        el.textContent = ''; el.style.display = 'none';
        if (el.closest('.form-group')) el.closest('.form-group').classList.remove('has-error');
    });

    document.getElementById('btn-save-student').disabled = false;
    document.getElementById('btn-save-student').classList.remove('loading');

    var photo    = (row.dataset.profilePhoto || '').trim();
    var initials = ((parts[0] || '').charAt(0) + (parts[1] || '').charAt(0)).toUpperCase();
    var disp     = document.getElementById('modal-avatar-display');
    var nameEl   = document.getElementById('modal-photo-name');
    var clearBtn = document.getElementById('modal-photo-clear');
    var photoInp = document.getElementById('f-photo');

    if (photoInp) photoInp.value = '';
    if (nameEl)   nameEl.textContent = photo ? photo.split('/').pop() : '';
    if (clearBtn) clearBtn.style.display = 'none';
    if (disp) {
        disp.innerHTML = photo
            ? '<img src="' + photo + '" alt="avatar" style="width:100%;height:100%;object-fit:cover;" onerror="this.parentElement.textContent=\'' + initials + '\'">'
            : initials;
    }

    document.getElementById('addStudentModal').classList.add('active');
}


/* ── Modal: View Documents ── */
function openDocsModal(row) {
    var studentId   = row.dataset.id;
    var studentName = row.dataset.fullname;

    document.getElementById('viewDocsTitle').textContent = '📄 Document Requests';
    var body = document.getElementById('docs-modal-body');
    body.innerHTML = '<p style="color:#888;text-align:center;padding:2rem">Loading…</p>';
    document.getElementById('viewDocsModal').classList.add('active');

    fetch('phpLogics/getStudDocs.php?student_id=' + encodeURIComponent(studentId))
        .then(function (res) { return res.json(); })
        .then(function (data) {
            renderDocsModal(body, studentName, data);
        })
        .catch(function () {
            body.innerHTML = '<p style="color:#e55;text-align:center;padding:2rem">❌ Failed to load requests.</p>';
        });
}

function renderDocsModal(body, studentName, counts) {
    var docTypes = [
        { key: 'good_moral',         label: 'Good Moral',         icon: '🏅' },
        { key: 'diploma',            label: 'Diploma',            icon: '🎓' },
        { key: 'form_137',           label: 'Form 137',           icon: '📋' },
        { key: 'form_138',           label: 'Report Card',        icon: '📊' },
        { key: 'transcript',         label: 'Transcript',         icon: '📜' },
        { key: 'certification',      label: 'Certification',      icon: '📝' },
        { key: 'yearbook',           label: 'Yearbook',           icon: '📒' },
        { key: 'tor',                label: 'TOR',                icon: '🗒️' },
        { key: 'other',              label: 'Other',              icon: '📁' },
    ];

    var total = docTypes.reduce(function (sum, d) { return sum + (parseInt(counts[d.key]) || 0); }, 0);

    var html = '<div class="docs-student-name">Requests for <strong>' + escapeHtml(studentName) + '</strong>'
        + ' &mdash; <span style="color:#5b21b6;font-weight:600">' + total + ' total</span></div>';

    html += '<div class="docs-grid">';
    docTypes.forEach(function (d) {
        var n = parseInt(counts[d.key]) || 0;
        html += '<div class="doc-card">'
            + '<span class="doc-icon">' + d.icon + '</span>'
            + '<div class="doc-label">' + d.label + '</div>'
            + '<div class="doc-count' + (n === 0 ? ' zero' : '') + '">' + n + '</div>'
            + '</div>';
    });
    html += '</div>';

    if (total === 0) {
        html += '<p style="text-align:center;color:#9ca3af;font-size:0.85rem;margin-top:4px">No document requests yet.</p>';
    }

    body.innerHTML = html;
}

function closeDocsModal() {
    document.getElementById('viewDocsModal').classList.remove('active');
}



function closeAddModal() {
    document.getElementById('addStudentModal').classList.remove('active');
}

function handleAddOverlayClick(e) {
    if (e.target === document.getElementById('addStudentModal')) closeAddModal();
}

function resetForm() {
    ['f-first', 'f-last', 'f-email', 'f-contact', 'f-password',
     'f-lrn', 'f-dob', 'f-syear'].forEach(function (id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.value = '';
        el.classList.remove('error');
        if (id === 'f-password') {
            el.type = 'password';
            var btn = el.parentElement.querySelector('.pw-toggle');
            if (btn) btn.textContent = '👁';
        }
    });

    ['f-role', 'f-grade', 'f-strand'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) { el.value = ''; el.classList.remove('error'); }
    });

    var strandGrp = document.getElementById('group-strand');
    if (strandGrp) {
        strandGrp.style.opacity      = '0.45';
        strandGrp.style.pointerEvents = 'none';
    }

    document.querySelectorAll('.field-error').forEach(function (el) {
        el.textContent = ''; el.style.display = 'none';
        if (el.closest('.form-group')) el.closest('.form-group').classList.remove('has-error');
    });
    document.getElementById('btn-save-student').disabled = false;
    document.getElementById('btn-save-student').classList.remove('loading');
}

/* ── Photo preview ── */
function previewModalAvatar(input) {
    if (!input.files || !input.files[0]) return;
    var file   = input.files[0];
    var reader = new FileReader();
    reader.onload = function (e) {
        document.getElementById('modal-avatar-display').innerHTML =
            '<img src="' + e.target.result + '" style="width:100%;height:100%;object-fit:cover;">';
    };
    reader.readAsDataURL(file);
    document.getElementById('modal-photo-name').textContent    = file.name;
    document.getElementById('modal-photo-clear').style.display = 'block';
}

function clearModalAvatar() {
    document.getElementById('f-photo').value                   = '';
    document.getElementById('modal-photo-name').textContent    = '';
    document.getElementById('modal-photo-clear').style.display = 'none';
    document.getElementById('modal-avatar-display').innerHTML  = '👤';
}

/* ── Password toggle ── */
function togglePw(btn) {
    var input = document.getElementById('f-password');
    var hide  = input.type === 'password';
    input.type      = hide ? 'text' : 'password';
    btn.textContent = hide ? '🙈' : '👁';
}

/* ── Validation ── */
function setFieldError(fieldId, errId, msg) {
    var f = document.getElementById(fieldId), e = document.getElementById(errId);
    if (!f || !e) return;
    f.classList.add('error'); e.textContent = msg; e.style.display = 'block';
    f.closest('.form-group').classList.add('has-error');
}

function clearFieldError(fieldId, errId) {
    var f = document.getElementById(fieldId), e = document.getElementById(errId);
    if (!f || !e) return;
    f.classList.remove('error'); e.textContent = ''; e.style.display = 'none';
    f.closest('.form-group').classList.remove('has-error');
}

function validateForm() {
    var valid = true;
    var rules = [
        { id: 'f-first',    err: 'err-first',    test: function (v) { return v.trim().length > 0; },                          msg: 'First name is required.' },
        { id: 'f-last',     err: 'err-last',      test: function (v) { return v.trim().length > 0; },                         msg: 'Last name is required.' },
        { id: 'f-role',     err: 'err-role',      test: function (v) { return v === 'Student' || v === 'Registrar'; },         msg: 'Please select a role.' },
        { id: 'f-email',    err: 'err-email',     test: function (v) { return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v.trim()); }, msg: 'Enter a valid email address.' },
        { id: 'f-password', err: 'err-password',
          test: function (v) { return editingStudentId ? (v === '' || v.length >= 5) : v.length >= 5; },
          msg: editingStudentId ? 'If changing, enter at least 5 characters.' : 'Password is required.' }
    ];
    rules.forEach(function (r) {
        var val = (document.getElementById(r.id) || {}).value || '';
        if (!r.test(val)) { setFieldError(r.id, r.err, r.msg); valid = false; }
        else              { clearFieldError(r.id, r.err); }
    });
    return valid;
}

/* ── Submit ── */
function submitStudent() {
    if (!validateForm()) return;
    var btn = document.getElementById('btn-save-student');
    btn.disabled = true; btn.classList.add('loading');

    var fd = new FormData();
    fd.append('first_name', document.getElementById('f-first').value.trim());
    fd.append('last_name',  document.getElementById('f-last').value.trim());
    fd.append('role',       document.getElementById('f-role').value);
    fd.append('email',      document.getElementById('f-email').value.trim());
    fd.append('contact',    document.getElementById('f-contact').value.trim());
    fd.append('password',   document.getElementById('f-password').value);

    var lrnEl    = document.getElementById('f-lrn');
    var dobEl    = document.getElementById('f-dob');
    var gradeEl  = document.getElementById('f-grade');
    var strandEl = document.getElementById('f-strand');
    var syearEl  = document.getElementById('f-syear');

    if (lrnEl)    fd.append('lrn',                        lrnEl.value.trim());
    if (dobEl)    fd.append('date_of_birth',              dobEl.value);
    if (gradeEl)  fd.append('grade_level',                gradeEl.value);
    if (strandEl) fd.append('strand',                     strandEl.value);
    if (syearEl)  fd.append('school_year_last_attended',  syearEl.value.trim());

    var photoFile = document.getElementById('f-photo');
    if (photoFile && photoFile.files[0]) fd.append('profile_photo', photoFile.files[0]);

    var url = 'phpLogics/addStud.php';
    if (editingStudentId) {
        url = 'phpLogics/editStud.php';
        fd.append('student_id', editingStudentId);
        fd.append('old_email',  originalEmail);
    }
    fd.append('csrf_token', CSRF_TOKEN);

    fetch(url, { method: 'POST', body: fd })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            btn.disabled = false; btn.classList.remove('loading');
            if (data.success) {
                closeAddModal();
                showToast(editingStudentId ? '✏️ Student updated!' : '✅ ' + data.full_name + ' added!', 'success');
                loadStudents();
            } else {
                showToast('❌ ' + data.message, 'error');
            }
        })
        .catch(function () {
            btn.disabled = false; btn.classList.remove('loading');
            showToast('❌ Network error. Please try again.', 'error');
        });
}

/* ── Toast ── */
var toastTimer = null;
function showToast(msg, type) {
    var t = document.getElementById('toast');
    t.textContent = msg; t.className = 'toast ' + type + ' show';
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { t.classList.remove('show'); }, 4000);
}