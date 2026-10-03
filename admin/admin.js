/* ═══════════════════════════════════════════════════
   HEHMS — Admin Dashboard JS  (admin.js)
   One page: views, announcements, accounts, settings,
   own profile.
   ═══════════════════════════════════════════════════ */

/* ══════════ View switcher ══════════ */
function showView(view) {
    ['overview', 'announcements', 'accounts', 'settings', 'information', 'audit', 'account', 'requests'].forEach(function (v) {
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

/* ══════════ Site settings (design theme, colors, info) ══════════ */
var logoFile = null;
var selectedTheme = document.querySelector('#theme-picker .theme-option.selected');

function initThemePicker() {
    document.querySelectorAll('#theme-picker .theme-option').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('#theme-picker .theme-option').forEach(function (b) {
                b.classList.remove('selected');
            });
            btn.classList.add('selected');
            selectedTheme = btn;
        });
    });
}

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
    // Site Settings = branding only (logo, design theme, theme color).
    // Office hours / contact info are saved from the Information view.
    fd.append('theme_color', document.getElementById('theme-color').value);
    fd.append('design_theme', selectedTheme ? selectedTheme.dataset.theme : 'classic');
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

/* ══════════ Information view: office hours & contact info ══════════ */
function saveInfo() {
    var btn = document.getElementById('btn-save-info');
    btn.disabled = true;
    btn.textContent = '⏳ Saving…';

    var fd = new FormData();
    fd.append('office_hours',      document.getElementById('set-office-hours').value.trim());
    fd.append('contact_email',     document.getElementById('set-contact-email').value.trim());
    fd.append('contact_phone',     document.getElementById('set-contact-phone').value.trim());
    fd.append('contact_location',  document.getElementById('set-contact-location').value.trim());
    fd.append('csrf_token', CSRF_TOKEN);

    fetch('../phpLogics/saveSiteSettings.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                showToast('Information saved!', 'success');
                setTimeout(function () { location.reload(); }, 800);
            } else {
                btn.disabled = false;
                btn.textContent = '💾 Save Information';
                showToast(data.message || 'Failed to save information.', 'error');
            }
        })
        .catch(function () {
            btn.disabled = false;
            btn.textContent = '💾 Save Information';
            showToast('Network error. Please try again.', 'error');
        });
}

/* ══════════ Information view: requestable documents ══════════ */
function loadDocTypes() {
    var box = document.getElementById('doc-type-list');
    if (!box) return;

    fetch('../phpLogics/documentTypeAction.php?action=list')
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) renderDocTypes(data.items || []);
            else box.innerHTML = '<p class="empty-state">Failed to load documents.</p>';
        })
        .catch(function () {
            box.innerHTML = '<p class="empty-state">Failed to load documents.</p>';
        });
}

function renderDocTypes(items) {
    var box = document.getElementById('doc-type-list');
    if (!box) return;

    if (!items.length) {
        box.innerHTML = '<div class="empty-state"><div class="empty-icon">📄</div>' +
            '<p>No documents yet — students cannot request anything until one is added.</p></div>';
        return;
    }

    var html = '';
    items.forEach(function (it) {
        var id      = parseInt(it.id, 10);
        var active  = parseInt(it.is_active, 10) === 1;
        var needsGl = parseInt(it.requires_grade_level, 10) === 1;
        var used    = parseInt(it.usage_count, 10) || 0;

        html += '<div class="doc-item' + (active ? '' : ' inactive') + '">' +
                '<span class="doc-item-name">📄 ' + escapeHtml(it.name) + '</span>' +
                '<span class="doc-chip ' + (active ? 'active' : 'inactive') + '">' + (active ? 'Active' : 'Removed') + '</span>' +
                (needsGl ? '<span class="doc-chip gl" title="The request form asks for the Grade Level">+ Grade Level</span>' : '') +
                '<span class="doc-usage">' + used + ' request' + (used === 1 ? '' : 's') + '</span>' +
                '<span class="doc-item-actions">' +
                    (active
                        ? '<button type="button" class="btn-cancel-req" onclick="toggleDocType(' + id + ', \'remove\')">📦 Remove</button>'
                        : '<button type="button" class="btn-edit" onclick="toggleDocType(' + id + ', \'restore\')">♻️ Restore</button>') +
                    (used === 0
                        ? '<button type="button" class="btn-cancel-req" onclick="deleteDocType(' + id + ', ' + JSON.stringify(it.name) + ')">🗑 Delete</button>'
                        : '') +
                '</span>' +
            '</div>';
    });

    box.innerHTML = html;
}

function postDocType(action, extra, doneMsg) {
    var fd = new FormData();
    fd.append('action', action);
    fd.append('csrf_token', CSRF_TOKEN);
    Object.keys(extra || {}).forEach(function (k) { fd.append(k, extra[k]); });

    return fetch('../phpLogics/documentTypeAction.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                showToast(data.message || doneMsg, data.deactivated ? 'success' : 'success');
                loadDocTypes();
            } else {
                showToast(data.message || 'Something went wrong.', 'error');
            }
        })
        .catch(function () { showToast('Network error. Please try again.', 'error'); });
}

function addDocType() {
    var nameEl  = document.getElementById('doc-type-name');
    var errEl   = document.getElementById('err-doc-type');
    var glEl    = document.getElementById('doc-type-gl');
    var name    = nameEl.value.trim();

    errEl.textContent = '';
    errEl.style.display = 'none';

    if (!name) {
        errEl.textContent = 'Please enter the document name.';
        errEl.style.display = 'block';
        nameEl.focus();
        return;
    }

    var btn = document.getElementById('btn-add-doc-type');
    btn.disabled = true;

    postDocType('add', { name: name, requires_grade_level: glEl.checked ? '1' : '' }, 'Document added.')
        .then(function () {
            btn.disabled = false;
            nameEl.value = '';
            if (glEl) glEl.checked = false;
        });
}

function toggleDocType(id, mode) {
    var question = mode === 'remove'
        ? 'Remove this document from the student request form?\n\nExisting requests keep it in their history.'
        : 'Restore this document to the student request form?';
    if (!confirm(question)) return;
    postDocType('toggle', { id: id }, mode === 'remove' ? 'Document removed.' : 'Document restored.');
}

function deleteDocType(id, name) {
    if (!confirm('Permanently delete "' + name + '"?\n\nThis document was never requested, so nothing else will change.')) return;
    postDocType('delete', { id: id }, 'Document deleted.');
}

/* ══════════ Own account ══════════ */
var photoFile = null;

function saveAccount() {
    var first    = document.getElementById('acc-first').value.trim();
    var last     = document.getElementById('acc-last').value.trim();
    var email    = document.getElementById('acc-email').value.trim();
    var contact  = document.getElementById('acc-contact').value.trim();
    var password = document.getElementById('acc-password').value;
    var currentPwEl = document.getElementById('acc-current-password');
    var currentPw   = currentPwEl ? currentPwEl.value : '';

    if (!first || !last) { showToast('First and last name are required.', 'error'); return; }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { showToast('Please enter a valid email.', 'error'); return; }
    // A password change must be authorized by the CURRENT password
    if (password && !currentPw) {
        showToast('Please enter your current password to set a new one.', 'error');
        return;
    }

    var btn = document.getElementById('btn-save-account');
    btn.disabled = true;

    var fd = new FormData();
    fd.append('first_name', first);
    fd.append('last_name', last);
    fd.append('email', email);
    fd.append('contact', contact);
    if (password) {
        fd.append('password', password);
        fd.append('current_password', currentPw);
    }
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
                '<tr><td colspan="7"><div class="empty-state"><div class="empty-icon">❌</div><p>Failed to load accounts.</p></div></td></tr>';
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

        var contact = String(s.contact_number || s.contact || '');
        var row     = document.createElement('tr');
        row.className            = isArchived ? 'view-alumni' : 'view-enrolled';
        row.dataset.profilePhoto = s.profile_photo || '';
        row.dataset.id           = s.student_id;
        // ⚠ Store RAW values here — dataset assignment is attribute-safe by
        // itself. Storing pre-escaped values made the edit form write back
        // "O&#039;Brien" into the DB on save. Escape only when rendering HTML
        // (see fullName / roleBadge / the row.innerHTML below).
        row.dataset.fullname     = (s.first_name + ' ' + s.last_name).trim();
        row.dataset.role         = s.role;
        row.dataset.email        = s.email;
        row.dataset.contact      = contact;
        row.dataset.lrn          = s.lrn                        || '';

        row.dataset.searchIndex = [
            s.student_id, row.dataset.fullname, s.role, s.email, contact,
            s.lrn || ''
        ].join(' ').toLowerCase();

        // ── Role badge — clearly identifies Student / Registrar / Admin ──
        var roleLabel = { student: 'Student', registrar: 'Registrar', admin: 'Admin' }[role]
                        || escapeHtml(s.role || '—');
        var roleBadge = '<span class="role-badge role-' + (role || 'other') + '">' + roleLabel + '</span>';

        // ── Status badge (Active / Archived) ──
        var statusBadge = isArchived
            ? '<span class="acc-status archived">Archived</span>'
            : '<span class="acc-status active">Active</span>';

        // ── "View Documents" only makes sense for student accounts —
        //    registrars and admins never place requests.
        var viewDocsItem = (role === 'student')
            ? '<a href="#" class="view-docs">📄 View Documents</a>'
            : '';

        row.innerHTML =
            '<td><strong>' + escapeHtml(s.student_id) + '</strong></td>' +
            '<td><div class="rec-name-cell">' + avatarHtml(s) + '<span>' + fullName + '</span></div></td>' +
            '<td>' + roleBadge + '</td>' +
            '<td><small>' + escapeHtml(s.email) + '</small></td>' +
            '<td>' + escapeHtml(contact) + '</td>' +
            '<td>' + statusBadge + '</td>' +
            '<td><div class="dropdown">' +
                '<button class="dots-btn">⋮</button>' +
                '<div class="dropdown-content">' +
                    '<a href="#" class="edit">✏️ Edit Record</a>' +
                    viewDocsItem +
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
            noRow.innerHTML = '<td colspan="7" style="text-align:center;padding:2rem;color:#888;">No records found for "<strong>' + escapeHtml(search) + '</strong>"</td>';
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

    if (lrnEl)    lrnEl.value    = row.dataset.lrn    || '';


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
     'f-lrn'].forEach(function (id) {
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

    ['f-role'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) { el.value = ''; el.classList.remove('error'); }
    });


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

    if (lrnEl)    fd.append('lrn',                       lrnEl.value.trim());

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
        { key: 'certificate_of_enrollment',            label: 'Cert. of Enrollment',      icon: '📝' },
        { key: 'certificate_of_grades',                label: 'Cert. of Grades',          icon: '📊' },
        { key: 'certificate_of_good_moral',            label: 'Good Moral',               icon: '🏅' },
        { key: 'certificate_of_transfer',              label: 'Cert. of Transfer',        icon: '🔁' },
        { key: 'certificate_of_completion_graduation', label: 'Completion / Graduation',  icon: '🎓' },
        { key: 'other',                                label: 'Other',                    icon: '📁' }
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

    var display = ['first_name','last_name','role','email','contact','lrn'];
    var labels  = {
        first_name:'First Name', last_name:'Last Name', role:'Role', email:'Email',
        contact:'Contact', lrn:'LRN'
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

    // Design theme picker (Site Settings)
    initThemePicker();

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

    // Settings (branding only — hours/contacts moved to the Information view)
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

    // Information view — office hours & contact info
    document.getElementById('btn-save-info').addEventListener('click', saveInfo);

    // Information view — requestable documents (add / remove / restore / delete)
    loadDocTypes();
    document.getElementById('btn-add-doc-type').addEventListener('click', addDocType);
    document.getElementById('doc-type-name').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); addDocType(); }
    });

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

/* ═══════════════════════════════════════════════════════════════
   STUDENT REQUESTS (adopted from the Registrar dashboard)
   Accept / reject / view details / release — identical behaviour,
   identical modals. Elements live inside #view-requests and the four
   body-level modals (#file-modal, #release-modal, #reject-modal,
   #doc-lightbox).
   ═══════════════════════════════════════════════════════════════ */

(function () {
    'use strict';

    /* ── Main/History sub-tab switch inside the Requests view ── */
    window.switchReqTab = function (tab) {
        var isMain = tab === 'main';
        var vMain = document.getElementById('view-main');
        var vArch = document.getElementById('view-archived');
        if (!vMain || !vArch) return;
        vMain.style.display = isMain ? '' : 'none';
        vArch.style.display = isMain ? 'none' : '';
        document.getElementById('tab-main').classList.toggle('active', isMain);
        document.getElementById('tab-archived').classList.toggle('active', !isMain);
    };

    /* ── Search + status-chip filtering (combined) ── */
    var rowFilters = {
        main:     { query: '', status: '' },
        archived: { query: '', status: '' }
    };

    function applyRowFilter(scope) {
        var tbody = document.getElementById(scope === 'main' ? 'main-tbody' : 'archived-tbody');
        if (!tbody) return;

        var state = rowFilters[scope];
        var q = state.query.toLowerCase();
        var rows = tbody.getElementsByTagName('tr');

        for (var i = 0; i < rows.length; i++) {
            var tr = rows[i];
            if (tr.classList.contains('empty-row')) continue;

            var text = tr.textContent.toLowerCase();
            var matchesQuery  = !q || text.indexOf(q) !== -1;
            var matchesStatus = !state.status || tr.getAttribute('data-status') === state.status;
            tr.style.display = (matchesQuery && matchesStatus) ? '' : 'none';
        }
    }

    function setQuery(scope, q) {
        rowFilters[scope].query = q;
        applyRowFilter(scope);
    }

    function setupChips(scope) {
        var chipBox = document.getElementById('chips-' + scope);
        if (!chipBox) return;
        chipBox.addEventListener('click', function (e) {
            var chip = e.target.closest('.chip');
            if (!chip) return;
            chipBox.querySelectorAll('.chip').forEach(function (c) { c.classList.remove('active'); });
            chip.classList.add('active');
            rowFilters[scope].status = chip.getAttribute('data-filter') || '';
            applyRowFilter(scope);
        });
    }

    /* ── Escape helper for HTML/attributes ── */
    function escapeAttr(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    /* ── Status badge (mirrors the PHP statusBadge helper) ── */
    function statusBadgeHtml(status, cancelledBy) {
        if (status === 'Cancelled') {
            if (cancelledBy === 'registrar') return '<span class="status rejected">Rejected</span>';
            return '<span class="status cancelled">Cancelled</span>';
        }
        var cls = {
            'Pending': 'pending', 'Processing': 'processing',
            'Ready for Pickup': 'ready', 'Released': 'released'
        }[status] || 'pending';
        return '<span class="status ' + cls + '">' + escapeAttr(status) + '</span>';
    }

    function initialsOf(name) {
        var parts = String(name).trim().split(/\s+/);
        var first = (parts[0] || ' ')[0] || '';
        var last  = parts.length > 1 ? (parts[parts.length - 1][0] || '') : '';
        return (first + last).toUpperCase();
    }

    /* ── Document requirement card metadata ── */
    var DOC_META = {
        idPhoto: { icon: '🪪', title: 'Valid ID',      tag: 'Required', cls: 'tag-req',  field: 'id_photo' },
        eCert:   { icon: '🎓', title: 'E-Certificate', tag: 'Released', cls: 'tag-cert', field: 'e_certificate' }
    };

    /* ── Request details modal ── */
    function openRequestModal(btn) {
        var d = btn.dataset;

        document.getElementById('modal-req-id').textContent =
            'Request #' + String(d.reqId || '').padStart(4, '0');
        document.getElementById('ms-student').textContent = d.student || '—';
        document.getElementById('ms-doc').textContent     = d.doc    || '—';
        document.getElementById('ms-date').textContent    = d.date   || '—';
        document.getElementById('ms-status-wrap').innerHTML =
            statusBadgeHtml(d.status || '', d.cancelled || '');

        var av = document.getElementById('ms-avatar');
        av.innerHTML = '';
        av.textContent = initialsOf(d.student || '');
        if (d.avatar) {
            var img = document.createElement('img');
            img.src = '../' + d.avatar;
            img.alt = '';
            img.onerror = function () { img.remove(); };
            av.appendChild(img);
        }

        document.getElementById('modal-purpose').textContent = d.purpose || '—';

        renderDocs(d);

        document.getElementById('student-info').innerHTML =
            '<p class="history-loading">Loading…</p>';
        loadRequestHistory(d.reqId);

        document.getElementById('file-modal').style.display = 'flex';
        document.getElementById('file-modal').querySelector('.modal-scroll').scrollTop = 0;
        document.body.style.overflow = 'hidden';
    }

    function renderDocs(d) {
        var grid = document.getElementById('docs-grid');
        grid.innerHTML = '';
        var slots = [['idPhoto', 'id-photo'], ['eCert', 'e-cert']];
        var shown = 0;
        slots.forEach(function (pair) {
            if (!d[pair[0]]) return;
            grid.appendChild(buildDocCard(DOC_META[pair[0]], d[pair[0]], d.reqId));
            shown++;
        });
        if (!shown) grid.innerHTML = '<p class="docs-empty">No files were attached to this request.</p>';
    }

    function buildDocCard(meta, filePath, reqId) {
        var url  = '../phpLogics/downloadReqFile.php?req_id=' + encodeURIComponent(reqId) +
                   '&field=' + encodeURIComponent(meta.field);
        var name = filePath.split('/').pop();
        var ext  = name.split('.').pop().toLowerCase();
        var isImg = ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext);
        var isPdf = ext === 'pdf';

        var card = document.createElement('div');
        card.className = 'doc-card';

        var head =
            '<div class="doc-card-head">' +
                '<span class="doc-card-icon">' + meta.icon + '</span>' +
                '<span class="doc-card-title">' + escapeAttr(meta.title) + '</span>' +
                '<span class="doc-card-tag ' + meta.cls + '">' + meta.tag + '</span>' +
            '</div>';

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

        var foot =
            '<div class="doc-card-foot">' +
                '<span class="doc-filename" title="' + escapeAttr(name) + '">' + escapeAttr(name) + '</span>' +
                '<span class="doc-actions">' +
                    (isPdf ? '<a class="doc-act" href="' + escapeAttr(url) + '" target="_blank" rel="noopener">👁 View</a>' : '') +
                    '<a class="doc-act doc-act-dl" href="' + escapeAttr(url) + '" download="' + escapeAttr(name) + '">⬇ Download</a>' +
                '</span>' +
            '</div>';

        card.innerHTML = head + prev + foot;
        return card;
    }

    /* ── Fullscreen lightbox (image / PDF) ── */
    function openLightbox(url, caption, isPdf) {
        document.getElementById('lightbox-caption').textContent = caption;
        document.getElementById('lightbox-open').href = url;

        var img   = document.getElementById('lightbox-img');
        var frame = document.getElementById('lightbox-frame');

        if (isPdf) {
            img.style.display   = 'none';  img.src   = '';
            frame.style.display = 'block'; frame.src = url;
        } else {
            frame.style.display = 'none';  frame.src = 'about:blank';
            img.style.display   = 'block'; img.src   = url;
        }
        document.getElementById('doc-lightbox').classList.add('open');
    }

    function closeLightbox() {
        var lb = document.getElementById('doc-lightbox');
        if (!lb.classList.contains('open')) return;
        lb.classList.remove('open');
        document.getElementById('lightbox-img').src   = '';
        document.getElementById('lightbox-frame').src = 'about:blank';
        if (document.getElementById('file-modal').style.display !== 'flex') {
            document.body.style.overflow = '';
        }
    }

    /* ── Student info + request history (single AJAX call) ── */
    function loadRequestHistory(reqId) {
        var box = document.getElementById('content-history');
        if (!box) return;
        box.innerHTML = '<p class="history-loading">Loading…</p>';
        if (!reqId) { box.innerHTML = '<p class="history-loading">—</p>'; return; }

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

    function renderStudentInfo(s) {
        var box = document.getElementById('student-info');
        if (!s) {
            box.innerHTML = '<p class="history-loading">No student record found.</p>';
            return;
        }
        var fields = [
            ['Student ID',     s.student_id],
            ['LRN',            s.lrn],
            ['Email',          s.email],
            ['Contact Number', s.contact]
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

    /* ── Modal close helpers ── */
    function closeModal() {
        closeLightbox();
        var fm = document.getElementById('file-modal');
        if (fm) fm.style.display = 'none';
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

    /* ── Reject reason modal (exposed: inline onclick uses it) ── */
    window.openRejectModal = function (reqId) {
        document.getElementById('reject-req-id').value = reqId;
        document.getElementById('reject-modal').classList.add('open');
        document.getElementById('reject-reason-input').value = '';
        setTimeout(function () { document.getElementById('reject-reason-input').focus(); }, 60);
    };
    window.closeRejectModal = function () {
        document.getElementById('reject-modal').classList.remove('open');
    };

    /* ── Wire everything once the DOM is ready ── */
    document.addEventListener('DOMContentLoaded', function () {
        var tMain = document.getElementById('tab-main');
        var tArch = document.getElementById('tab-archived');
        if (tMain) tMain.addEventListener('click', function () { switchReqTab('main'); });
        if (tArch) tArch.addEventListener('click', function () { switchReqTab('archived'); });

        var sMain = document.getElementById('search-main');
        var sArch = document.getElementById('search-archived');
        if (sMain) sMain.addEventListener('input', function () { setQuery('main', this.value); });
        if (sArch) sArch.addEventListener('input', function () { setQuery('archived', this.value); });

        setupChips('main');
        setupChips('archived');

        /* Clickable stat cards → jump to the filtered table */
        document.querySelectorAll('.reqmgr .card-click').forEach(function (card) {
            card.addEventListener('click', function () {
                showView('requests');
                var tab = card.dataset.tab === 'archived' ? 'archived' : 'main';
                switchReqTab(tab);

                var status = card.dataset.goto || '';
                var chips = document.getElementById('chips-' + tab);
                if (chips) {
                    var chip = chips.querySelector('[data-filter="' + status + '"]');
                    if (chip) chip.click();
                    else {
                        var all = chips.querySelector('[data-filter=""]');
                        if (all) all.click();
                    }
                }
                document.getElementById(tab === 'main' ? 'view-main' : 'view-archived')
                    .scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        });

        /* Request detail modal — delegated open */
        document.body.addEventListener('click', function (e) {
            var btn = e.target.closest('.btn-view-files');
            if (btn) openRequestModal(btn);
        });

        /* Doc previews → lightbox */
        var docsGrid = document.getElementById('docs-grid');
        if (docsGrid) {
            docsGrid.addEventListener('click', function (e) {
                var thumb = e.target.closest('.doc-thumb');
                if (!thumb) return;
                var cardEl = thumb.closest('.doc-card');
                var title = cardEl ? cardEl.querySelector('.doc-card-title').textContent : 'Document';
                if (thumb.dataset.pdf) {
                    openLightbox(thumb.dataset.pdf, title + ' — PDF Document', true);
                } else {
                    var img = thumb.querySelector('img');
                    if (img) openLightbox(img.getAttribute('src'), title, false);
                }
            });
        }

        var closeBtn = document.getElementById('closeFileModal');
        if (closeBtn) closeBtn.addEventListener('click', closeModal);
        var fileModal = document.getElementById('file-modal');
        if (fileModal) fileModal.addEventListener('click', function (e) { if (e.target === this) closeModal(); });

        var lbClose = document.getElementById('lightbox-close');
        if (lbClose) lbClose.addEventListener('click', closeLightbox);
        var lightbox = document.getElementById('doc-lightbox');
        if (lightbox) lightbox.addEventListener('click', function (e) { if (e.target === this) closeLightbox(); });

        /* Release modal — delegated open + defaults fetch */
        document.body.addEventListener('click', function (e) {
            var btn = e.target.closest('.btn-release');
            if (!btn) return;

            document.getElementById('rel-req-id').textContent =
                'Request #' + String(btn.dataset.reqId || '').padStart(4, '0');
            document.getElementById('rel-req-id-input').value = btn.dataset.reqId || '';
            document.getElementById('rel-student').textContent = btn.dataset.student || '—';
            document.getElementById('rel-doc').textContent     = btn.dataset.doc || '—';

            fetch('../phpLogics/previewCertificate.php?req_id=' + encodeURIComponent(btn.dataset.reqId) + '&format=json')
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data || !data.ok) return;
                    var d = data.defaults || {};
                    document.getElementById('rf-title').value         = d.title || '';
                    document.getElementById('rf-date').value          = d.cert_date || '';
                    document.getElementById('rf-officer').value       = d.officer_name || '';
                    document.getElementById('rf-officer-title').value = d.officer_title || '';
                    document.getElementById('rf-body').value          = d.body || '';
                    document.getElementById('rf-remarks').value       = d.remarks || '';
                    refreshCertPreview();
                })
                .catch(function () {
                    document.getElementById('rel-preview').src = 'about:blank';
                });

            document.getElementById('release-modal').style.display = 'flex';
        });

        var closeRel = document.getElementById('closeReleaseModal');
        if (closeRel) closeRel.addEventListener('click', closeReleaseModal);
        var relModal = document.getElementById('release-modal');
        if (relModal) relModal.addEventListener('click', function (e) { if (e.target === this) closeReleaseModal(); });

        var refreshBtn = document.getElementById('btn-refresh-preview');
        if (refreshBtn) refreshBtn.addEventListener('click', refreshCertPreview);

        var fullBtn = document.getElementById('btn-fullscreen-preview');
        if (fullBtn) fullBtn.addEventListener('click', function () {
            var reqId = document.getElementById('rel-req-id-input').value;
            if (!reqId) return;
            window.open('../phpLogics/previewCertificate.php?' + buildPreviewParams(reqId).toString(), '_blank');
        });

        ['rf-title', 'rf-date', 'rf-officer', 'rf-officer-title', 'rf-body', 'rf-remarks'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.addEventListener('change', refreshCertPreview);
        });

        /* Reject modal: backdrop close */
        document.addEventListener('click', function (e) {
            var modal = document.getElementById('reject-modal');
            if (modal && e.target === modal) closeRejectModal();
        });

        /* Keyboard: Esc closes lightbox → detail modal → release modal → reject */
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            var lb = document.getElementById('doc-lightbox');
            var rm = document.getElementById('release-modal');
            var fm = document.getElementById('file-modal');
            var rj = document.getElementById('reject-modal');
            if (lb && lb.classList.contains('open')) closeLightbox();
            else if (rm && rm.style.display === 'flex') closeReleaseModal();
            else if (fm && fm.style.display === 'flex') closeModal();
            else if (rj && rj.classList.contains('open')) closeRejectModal();
        });
    });
})();
