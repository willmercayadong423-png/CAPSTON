/* ═══════════════════════════════════════════════════
   HEHMS — Admin Dashboard JS  (admin.js)
   One page: views, announcements, accounts, settings,
   own profile.
   ═══════════════════════════════════════════════════ */

/* ══════════ View switcher ══════════ */
function showView(view) {
    ['overview', 'announcements', 'accounts', 'settings', 'account'].forEach(function (v) {
        var el = document.getElementById('view-' + v);
        if (el) el.style.display = (v === view) ? 'block' : 'none';
        var nav = document.getElementById('nav-' + v);
        if (nav) nav.classList.toggle('active', v === view);
    });
    history.replaceState({}, '', '?view=' + view);
    closeProfileMenu();
}

/* ══════════ Header profile dropdown ══════════ */
function toggleProfileMenu(event) {
    event.stopPropagation();
    document.getElementById("profileDropdown").classList.toggle("show");
}
function closeProfileMenu() {
    var m = document.getElementById("profileDropdown");
    if (m) m.classList.remove("show");
}
document.addEventListener("click", function (e) {
    var menu = document.getElementById("profileDropdown");
    var wrapper = document.querySelector(".header-avatar-wrapper");
    if (menu && wrapper && !wrapper.contains(e.target)) menu.classList.remove("show");
});

/* ══════════ Toast ══════════ */
var toastTimer = null;
function showToast(msg, type) {
    var t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast ' + (type || 'success') + ' show';
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { t.classList.remove('show'); }, 4000);
}

/* ══════════ Announcements ══════════ */
function postAnnouncement(action, extra, done) {
    var fd = new FormData();
    fd.append('action', action);
    fd.append('csrf_token', CSRF_TOKEN);
    Object.keys(extra || {}).forEach(function (k) { fd.append(k, extra[k]); });

    fetch('../phpLogics/announcementAction.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) { showToast(done, 'success'); setTimeout(function () { location.reload(); }, 700); }
            else showToast(data.message || 'Something went wrong.', 'error');
        })
        .catch(function () { showToast('Network error. Please try again.', 'error'); });
}

function addAnnouncement() {
    var title = document.getElementById('ann-title').value.trim();
    var msg   = document.getElementById('ann-message').value.trim();
    if (!title || !msg) { showToast('Please fill in both title and message.', 'error'); return; }
    var btn = document.getElementById('btn-add-announcement');
    btn.disabled = true;
    postAnnouncement('add', { title: title, message: msg }, 'Announcement published!');
}

function toggleAnnouncement(id) { postAnnouncement('toggle', { id: id }, 'Announcement updated.'); }

function deleteAnnouncement(id) {
    if (!confirm('Delete this announcement permanently?')) return;
    postAnnouncement('delete', { id: id }, 'Announcement deleted.');
}

/* ══════════ Site settings ══════════ */
var logoFile = null;

function applyPreviewColor(hex) {
    function mix(a, b, amt) {
        a = a.replace('#', ''); b = b.replace('#', '');
        var out = '#';
        for (var i = 0; i < 3; i++) {
            var va = parseInt(a.substr(i * 2, 2), 16);
            var vb = parseInt(b.substr(i * 2, 2), 16);
            var v  = Math.round(va + (vb - va) * amt);
            out += ('0' + v.toString(16)).slice(-2);
        }
        return out;
    }
    var r = document.documentElement.style;
    r.setProperty('--green-mid',   hex);
    r.setProperty('--green-dark',  mix(hex, '#000000', 0.30));
    r.setProperty('--green-light', mix(hex, '#ffffff', 0.22));
    r.setProperty('--green-pale',  mix(hex, '#ffffff', 0.74));
}

function saveSettings() {
    var btn = document.getElementById('btn-save-settings');
    btn.disabled = true;
    btn.textContent = '⏳ Saving…';

    var fd = new FormData();
    fd.append('theme_color', document.getElementById('theme-color').value);
    if (logoFile) fd.append('logo', logoFile);
    fd.append('csrf_token', CSRF_TOKEN);

    fetch('../phpLogics/saveSiteSettings.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                showToast('Site settings saved!', 'success');
                setTimeout(function () { location.reload(); }, 800);
            } else {
                btn.disabled = false;
                btn.textContent = '💾 Save Settings';
                showToast(data.message || 'Failed to save settings.', 'error');
            }
        })
        .catch(function () {
            btn.disabled = false;
            btn.textContent = '💾 Save Settings';
            showToast('Network error. Please try again.', 'error');
        });
}

/* ══════════ Own account ══════════ */
var photoFile = null;

function saveAccount() {
    var first    = document.getElementById('acc-first').value.trim();
    var last     = document.getElementById('acc-last').value.trim();
    var email    = document.getElementById('acc-email').value.trim();
    var contact  = document.getElementById('acc-contact').value.trim();
    var password = document.getElementById('acc-password').value;

    if (!first || !last) { showToast('First and last name are required.', 'error'); return; }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { showToast('Please enter a valid email.', 'error'); return; }

    var btn = document.getElementById('btn-save-account');
    btn.disabled = true;

    var fd = new FormData();
    fd.append('first_name', first);
    fd.append('last_name', last);
    fd.append('email', email);
    fd.append('contact', contact);
    if (password) fd.append('password', password);
    if (photoFile) fd.append('profile_photo', photoFile);
    fd.append('csrf_token', CSRF_TOKEN);

    fetch('../phpLogics/updateOwnAccount.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            btn.disabled = false;
            if (data.success) {
                showToast('Account updated successfully!', 'success');
                setTimeout(function () { location.reload(); }, 800);
            } else {
                showToast(data.message || 'Failed to update account.', 'error');
            }
        })
        .catch(function () {
            btn.disabled = false;
            showToast('Network error. Please try again.', 'error');
        });
}

/* ══════════ Accounts management ══════════ */
var currentView       = 'enrolled';
var editingStudentId  = null;
var originalEmail     = null;
var currentRoleFilter = '';

function escapeHtml(str) {
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function loadStudents() {
    fetch('../phpLogics/getStud.php')
        .then(function (res) { return res.json(); })
        .then(function (data) { renderStudents(data); })
        .catch(function () {
            document.getElementById('records-tbody').innerHTML =
                '<tr><td colspan="6"><div class="empty-state"><div class="empty-icon">❌</div><p>Failed to load accounts.</p></div></td></tr>';
        });
}

function renderStudents(students) {
    var tbody = document.getElementById('records-tbody');
    tbody.innerHTML = '';
    var total = 0, registrars = 0, archived = 0;

    students.forEach(function (s) {
        var fullName   = escapeHtml((s.first_name + ' ' + s.last_name).trim());
        var status     = (s.status || '').toLowerCase().trim();
        var role       = (s.role   || '').toLowerCase().trim();
        var isArchived = (status === 'archived' || status === 'archive');

        if (isArchived) archived++;
        else {
            if (role === 'student') total++;
            if (role === 'registrar' || role === 'admin') registrars++;
        }

        var contact = escapeHtml(s.contact_number || s.contact || '');
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

        row.dataset.searchIndex = [
            s.student_id, fullName, s.role, s.email, contact,
            s.lrn || '', s.grade_level || '', s.strand || ''
        ].join(' ').toLowerCase();

        row.innerHTML =
            '<td><strong>' + escapeHtml(s.student_id) + '</strong></td>' +
            '<td><div class="rec-name-cell">' + avatarHtml(s) + '<span>' + fullName + '</span></div></td>' +
            '<td><span class="badge-grade">' + escapeHtml(s.role) + '</span></td>' +
            '<td><small>' + escapeHtml(s.email) + '</small></td>' +
            '<td>' + contact + '</td>' +
            '<td><div class="dropdown">' +
                '<button class="dots-btn">⋮</button>' +
                '<div class="dropdown-content">' +
                    '<a href="#" class="edit">✏️ Edit Record</a>' +
                    '<a href="#" class="view-docs">📄 View Documents</a>' +
                    (isArchived
                        ? '<a href="#" class="restore">♻️ Unarchive</a>'
                        : '<a href="#" class="delete">📦 Archive</a>') +
                '</div></div></td>';

        tbody.appendChild(row);
    });

    applyFilters();
}

function avatarHtml(s) {
    var name     = escapeHtml((s.first_name || '') + ' ' + (s.last_name || ''));
    var initials = escapeHtml(((s.first_name || '').charAt(0) + (s.last_name || '').charAt(0)).toUpperCase());
    if (s.profile_photo) {
        return '<img src="../' + escapeHtml(s.profile_photo) + '" alt="' + name +
               '" class="rec-avatar" onerror="this.outerHTML=\'<span class=&quot;rec-avatar-initials&quot;>' + initials + '</span>\'">';
    }
    return '<span class="rec-avatar-initials">' + initials + '</span>';
}

function switchRecView(view) {
    currentView = view;
    document.getElementById('tab-enrolled').classList.toggle('active', view === 'enrolled');
    document.getElementById('tab-alumni').classList.toggle('active', view === 'alumni');
    applyFilters();
}

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
}

/* ── Row action dropdown ── */
var MENU_WIDTH      = 165;
var _activeDropdown = null;

function openDropdown(btn) {
    closeAllDropdowns();
    var dropdown = btn.closest('.dropdown');
    var menu     = dropdown.querySelector('.dropdown-content');
    menu._origin = dropdown;
    menu._row    = btn.closest('tr');
    document.body.appendChild(menu);
    menu.style.display = 'block';
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
        if (!row || !confirm('Move this account to archive?')) return;
        fetch('../phpLogics/archStud.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'student_id=' + encodeURIComponent(row.dataset.id) + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
        })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.success) { showToast('📦 Account archived', 'success'); loadStudents(); }
                else showToast(d.message || 'Failed to archive.', 'error');
            });
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
        if (!row || !confirm('Restore this account?')) return;
        fetch('../phpLogics/unArch.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'student_id=' + encodeURIComponent(row.dataset.id) + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
        })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.success) { showToast('♻️ Account restored', 'success'); loadStudents(); }
                else showToast(d.message || 'Failed to restore.', 'error');
            });
        return;
    }

    closeAllDropdowns();
});

/* ── Create / Edit modal ── */
function openAddModal() {
    editingStudentId = null;
    originalEmail    = null;
    resetForm();
    document.getElementById('addModalTitle').textContent        = '➕ Create an Account';
    document.getElementById('pw-required').style.display        = 'inline';
    document.getElementById('f-password').placeholder           = 'Password will be auto-filled from LRN if left blank';
    document.getElementById('modal-avatar-display').textContent = '';
    document.getElementById('modal-photo-name').textContent     = '';
    document.getElementById('modal-photo-clear').style.display  = 'none';
    document.getElementById('addStudentModal').classList.add('active');
}

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

    var btn = document.getElementById('btn-save-student');
    btn.disabled = false;

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
            ? '<img src="../' + escapeHtml(photo) + '" alt="avatar" style="width:100%;height:100%;object-fit:cover;" onerror="this.parentElement.textContent=\'' + initials + '\'">'
            : initials;
    }

    document.getElementById('addStudentModal').classList.add('active');
}

function closeAddModal() {
    document.getElementById('addStudentModal').classList.remove('active');
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
        strandGrp.style.opacity       = '0.45';
        strandGrp.style.pointerEvents  = 'none';
    }

    document.querySelectorAll('.field-error').forEach(function (el) {
        el.textContent = ''; el.style.display = 'none';
        if (el.closest('.form-group')) el.closest('.form-group').classList.remove('has-error');
    });
    document.getElementById('btn-save-student').disabled = false;
}

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

function togglePw(btn) {
    var input = document.getElementById('f-password');
    var hide  = input.type === 'password';
    input.type      = hide ? 'text' : 'password';
    btn.textContent = hide ? '🙈' : '👁';
}

/* ── Validation & submit ── */
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
        { id: 'f-first',    err: 'err-first',    test: function (v) { return v.trim().length > 0; },                  msg: 'First name is required.' },
        { id: 'f-last',     err: 'err-last',     test: function (v) { return v.trim().length > 0; },                  msg: 'Last name is required.' },
        { id: 'f-role',     err: 'err-role',     test: function (v) { return ['Student','Registrar','Admin'].includes(v); }, msg: 'Please select a role.' },
        { id: 'f-email',    err: 'err-email',    test: function (v) { return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v.trim()); }, msg: 'Enter a valid email address.' },
        { id: 'f-contact',  err: 'err-contact',  test: function (v) { return /^[0-9]{7,11}$/.test(v.trim()); },        msg: 'Contact must be 7–11 digits.' },
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

function submitStudent() {
    if (!validateForm()) return;
    var btn = document.getElementById('btn-save-student');
    btn.disabled = true;

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

    if (lrnEl)    fd.append('lrn',                       lrnEl.value.trim());
    if (dobEl)    fd.append('date_of_birth',             dobEl.value);
    if (gradeEl)  fd.append('grade_level',               gradeEl.value);
    if (strandEl) fd.append('strand',                    strandEl.value);
    if (syearEl)  fd.append('school_year_last_attended', syearEl.value.trim());

    var photoFile = document.getElementById('f-photo');
    if (photoFile && photoFile.files[0]) fd.append('profile_photo', photoFile.files[0]);

    var url = '../phpLogics/addStud.php';
    if (editingStudentId) {
        url = '../phpLogics/editStud.php';
        fd.append('student_id', editingStudentId);
        fd.append('old_email',  originalEmail);
    }
    fd.append('csrf_token', CSRF_TOKEN);

    fetch(url, { method: 'POST', body: fd })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            btn.disabled = false;
            if (data.success) {
                closeAddModal();
                showToast(editingStudentId ? '✏️ Account updated!' : '✅ ' + data.full_name + ' added!', 'success');
                loadStudents();
            } else {
                showToast('❌ ' + (data.message || 'Failed to save.'), 'error');
            }
        })
        .catch(function () {
            btn.disabled = false;
            showToast('❌ Network error. Please try again.', 'error');
        });
}

/* ── View documents modal ── */
function openDocsModal(row) {
    var studentId   = row.dataset.id;
    var studentName = row.dataset.fullname;

    document.getElementById('viewDocsTitle').textContent = '📄 Document Requests';
    var body = document.getElementById('docs-modal-body');
    body.innerHTML = '<p class="empty-state">Loading…</p>';
    document.getElementById('viewDocsModal').classList.add('active');

    fetch('../phpLogics/getStudDocs.php?student_id=' + encodeURIComponent(studentId))
        .then(function (res) { return res.json(); })
        .then(function (data) { renderDocsModal(body, studentName, data); })
        .catch(function () {
            body.innerHTML = '<p class="empty-state">❌ Failed to load requests.</p>';
        });
}

function renderDocsModal(body, studentName, counts) {
    var docTypes = [
        { key: 'good_moral',    label: 'Good Moral',    icon: '🏅' },
        { key: 'diploma',       label: 'Diploma',       icon: '🎓' },
        { key: 'form_137',      label: 'Form 137',      icon: '📋' },
        { key: 'certification', label: 'Certification', icon: '📝' },
        { key: 'yearbook',      label: 'Yearbook',      icon: '📒' },
        { key: 'other',         label: 'Other',         icon: '📁' }
    ];

    var total = docTypes.reduce(function (sum, d) { return sum + (parseInt(counts[d.key]) || 0); }, 0);

    var html = '<div class="docs-student-name">Requests for <strong>' + escapeHtml(studentName) +
        '</strong> — <span style="color:var(--green-dark);font-weight:600">' + total + ' total</span></div>';

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
        html += '<p class="empty-state">No document requests yet.</p>';
    }

    body.innerHTML = html;
}

function closeDocsModal() {
    document.getElementById('viewDocsModal').classList.remove('active');
}

/* ── CSV import ── */
function handleCsvImport(file) {
    var reader = new FileReader();
    reader.onload = function (e) {
        var lines = e.target.result.split(/\r?\n/).filter(function (l) { return l.trim(); });
        if (lines.length < 2) { showToast('❌ CSV is empty or has no data rows.', 'error'); return; }

        var headers = lines[0].split(',').map(function (h) { return h.trim().toLowerCase().replace(/\s+/g, '_'); });

        var required = ['first_name', 'last_name', 'role', 'email', 'contact'];
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
    var overlay = document.getElementById('csvPreviewModal');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id        = 'csvPreviewModal';
        overlay.className = 'modal-overlay';
        overlay.innerHTML =
            '<div class="modal-box modal-box--wide">' +
                '<div class="modal-header">' +
                    '<h3>📥 Import Preview</h3>' +
                    '<button id="btn-close-csv-modal" class="modal-close" type="button">✕</button>' +
                '</div>' +
                '<div class="modal-body" id="csv-preview-body" style="overflow-x:auto"></div>' +
                '<div class="modal-footer" style="display:flex;gap:.75rem;justify-content:flex-end">' +
                    '<button id="btn-cancel-import" class="btn-cancel-req" type="button">Cancel</button>' +
                    '<button id="btn-confirm-import" class="save-btn" type="button">✅ Import All</button>' +
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

        var pw = r.password || '';
        if (!pw && r.lrn && r.lrn.length >= 5) {
            pw = new Date().getFullYear() + '-' + r.lrn.slice(-5);
        }
        fd.append('password', pw);
        fd.append('csrf_token', CSRF_TOKEN);

        fetch('../phpLogics/addStud.php', { method: 'POST', body: fd })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.success) success++; else failed++;
                importNext(idx + 1);
            })
            .catch(function () { failed++; importNext(idx + 1); });
    }

    importNext(0);
}

/* ══════════ Boot ══════════ */
document.addEventListener('DOMContentLoaded', function () {

    // Views / nav
    var v = new URLSearchParams(window.location.search).get('view');
    if (v && document.getElementById('view-' + v)) showView(v);

    // Announcements
    document.getElementById('btn-add-announcement').addEventListener('click', addAnnouncement);

    // Accounts
    loadStudents();
    document.getElementById('student-search').addEventListener('input', applyFilters);
    document.getElementById('btn-open-modal').addEventListener('click', function (e) {
        e.preventDefault();
        openAddModal();
    });
    document.getElementById('btn-close-modal').addEventListener('click', closeAddModal);
    document.getElementById('btn-save-student').addEventListener('click', submitStudent);
    document.getElementById('addStudentModal').addEventListener('click', function (e) {
        if (e.target === this) closeAddModal();
    });

    document.getElementById('tab-enrolled').addEventListener('click', function () { switchRecView('enrolled'); });
    document.getElementById('tab-alumni').addEventListener('click', function () { switchRecView('alumni'); });

    document.getElementById('f-photo').addEventListener('change', function () { previewModalAvatar(this); });
    document.getElementById('modal-photo-clear').addEventListener('click', clearModalAvatar);
    document.querySelectorAll('.pw-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () { togglePw(btn); });
    });

    // CSV import
    document.getElementById('btn-import-csv').addEventListener('click', function (e) {
        e.preventDefault();
        document.getElementById('csv-file-input').click();
    });
    document.getElementById('csv-file-input').addEventListener('change', function () {
        if (this.files && this.files[0]) handleCsvImport(this.files[0]);
        this.value = '';
    });

    // Auto-fill password from LRN
    document.getElementById('f-lrn').addEventListener('input', function () {
        var lrn = this.value.trim();
        var pwField = document.getElementById('f-password');
        if (!pwField || editingStudentId) return;
        pwField.value = lrn.length >= 5 ? new Date().getFullYear() + '-' + lrn.slice(-5) : '';
    });

    // Role filter dropdown
    var roleBtn  = document.getElementById('role-filter-btn');
    var roleDrop = document.getElementById('role-filter-dropdown');
    if (roleBtn && roleDrop) {
        document.body.appendChild(roleDrop);
        roleDrop.style.position = 'fixed';

        roleBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            var isOpen = roleDrop.classList.contains('open');
            roleDrop.classList.toggle('open', !isOpen);
            roleBtn.classList.toggle('active', !isOpen);
            if (!isOpen) {
                var rect = roleBtn.getBoundingClientRect();
                roleDrop.style.top  = (rect.bottom + 4) + 'px';
                roleDrop.style.left = Math.min(rect.left, window.innerWidth - 170) + 'px';
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

    // Docs modal
    document.getElementById('btn-close-docs-modal').addEventListener('click', closeDocsModal);
    var docsOverlay = document.getElementById('viewDocsModal');
    docsOverlay.addEventListener('click', function (e) {
        if (e.target === docsOverlay) closeDocsModal();
    });

    // Settings
    var colorInput = document.getElementById('theme-color');
    document.querySelectorAll('.swatch').forEach(function (sw) {
        sw.addEventListener('click', function () {
            colorInput.value = sw.dataset.color;
            applyPreviewColor(sw.dataset.color);
        });
    });
    colorInput.addEventListener('input', function () { applyPreviewColor(this.value); });
    document.getElementById('logo-input').addEventListener('change', function () {
        logoFile = this.files && this.files[0] ? this.files[0] : null;
        document.getElementById('logo-name').textContent = logoFile ? logoFile.name : '';
        if (logoFile) {
            var reader = new FileReader();
            reader.onload = function (e) {
                document.getElementById('logo-preview').src = e.target.result;
            };
            reader.readAsDataURL(logoFile);
        }
    });
    document.getElementById('btn-save-settings').addEventListener('click', saveSettings);

    // Own account
    document.getElementById('photo-input').addEventListener('change', function () {
        photoFile = this.files && this.files[0] ? this.files[0] : null;
        document.getElementById('photo-name').textContent = photoFile ? photoFile.name : '';
        if (photoFile) {
            var reader = new FileReader();
            reader.onload = function (e) {
                document.getElementById('avatar-display').innerHTML =
                    '<img src="' + e.target.result + '" alt="avatar">';
            };
            reader.readAsDataURL(photoFile);
        }
    });
    document.getElementById('btn-save-account').addEventListener('click', saveAccount);
});
