/* ═══════════════════════════════════════════════════
   HEHMS — Registrar Account Info JS  (accountInfo.js)
   ═══════════════════════════════════════════════════ */

var photoFile = null;
var toastTimer = null;

function showToast(msg, type) {
    var t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast ' + (type || 'success') + ' show';
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { t.classList.remove('show'); }, 4000);
}

document.addEventListener('DOMContentLoaded', function () {

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

    document.getElementById('btn-save-account').addEventListener('click', function () {
        var first    = document.getElementById('acc-first').value.trim();
        var last     = document.getElementById('acc-last').value.trim();
        var email    = document.getElementById('acc-email').value.trim();
        var contact  = document.getElementById('acc-contact').value.trim();
        var password = document.getElementById('acc-password').value;

        if (!first || !last) { showToast('First and last name are required.', 'error'); return; }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { showToast('Please enter a valid email.', 'error'); return; }

        var btn = this;
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
    });
});
