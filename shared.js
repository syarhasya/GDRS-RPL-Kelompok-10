/* shared.js — GDRS common utilities */

// ── Toast notification system ─────────────────────────────────────────────
(function () {
  const container = document.createElement('div');
  container.id = 'toast-container';
  document.body.appendChild(container);

  window.showToast = function (message, type, duration) {
    type     = type     || 'info';
    duration = duration || 3200;
    const icons = { success: 'fa-circle-check', error: 'fa-circle-xmark', info: 'fa-circle-info' };
    const t = document.createElement('div');
    t.className = 'toast ' + type;
    t.innerHTML = '<i class="fa-solid ' + (icons[type] || icons.info) + '"></i> ' + message;
    container.appendChild(t);
    setTimeout(function () {
      t.style.opacity    = '0';
      t.style.transition = 'opacity .3s';
      setTimeout(function () { t.remove(); }, 300);
    }, duration);
  };
})();

// ── Session helpers ────────────────────────────────────────────────────────
// Using var instead of const so that re-loading this file does not throw
// "Identifier 'GDRS' has already been declared" and abort script execution.
var GDRS = {
  API: 'http://localhost/gdrs/api.php',

  getStudentId: function () { return localStorage.getItem('studentId'); },
  getAdminId:   function () { return localStorage.getItem('adminId'); },

  requireStudent: function () {
    if (!this.getStudentId()) { window.location.href = 'indexstudent.html'; return false; }
    return true;
  },
  requireAdmin: function () {
    if (!this.getAdminId()) { window.location.href = 'indexadmin.html'; return false; }
    return true;
  },

  api: async function (action, params) {
    params = params || {};
    var url = new URL(this.API);
    url.searchParams.set('action', action);
    for (var k in params) {
      if (Object.prototype.hasOwnProperty.call(params, k)) {
        url.searchParams.set(k, params[k]);
      }
    }
    try {
      var res = await fetch(url.toString());
      return await res.json();
    } catch (_) {
      return null;
    }
  },

  post: async function (action, body) {
    try {
      var res = await fetch(this.API + '?action=' + action, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      });
      return await res.json();
    } catch (_) {
      return null;
    }
  },

  loadStudentHeader: async function () {
    var id = this.getStudentId();
    if (!id) return;
    try {
      var user = await this.api('get_student_profile', { id: id });
      if (user && user.full_name) {
        document.querySelectorAll('.js-user-name').forEach(function (el) { el.textContent = user.full_name; });
        document.querySelectorAll('.js-user-role').forEach(function (el) { el.textContent = 'Student'; });
      }
    } catch (_) {}
  },

  loadAdminHeader: async function () {
    var id = this.getAdminId();
    if (!id) return;
    try {
      var user = await this.api('get_admin_profile', { id: id });
      if (user && user.full_name) {
        document.querySelectorAll('.js-user-name').forEach(function (el) { el.textContent = user.full_name; });
        document.querySelectorAll('.js-user-role').forEach(function (el) { el.textContent = 'Admin'; });
      }
    } catch (_) {}
  },
};

// ── Modal helpers ──────────────────────────────────────────────────────────
window.openModal  = function (id) { document.getElementById(id).classList.add('open'); };
window.closeModal = function (id) { document.getElementById(id).classList.remove('open'); };

// ── Notification system ────────────────────────────────────────────────────

function _notifRelTime(isoString) {
  var diff = Math.floor((Date.now() - new Date(isoString).getTime()) / 1000);
  if (diff < 60)    return 'Baru saja';
  if (diff < 3600)  return Math.floor(diff / 60)   + ' menit yang lalu';
  if (diff < 86400) return Math.floor(diff / 3600)  + ' jam yang lalu';
  return                  Math.floor(diff / 86400) + ' hari yang lalu';
}

function _buildNotifPanel(btn) {
  var panel = document.createElement('div');
  panel.className = 'notif-panel';
  panel.id = 'notif-panel';
  panel.innerHTML =
    '<div class="notif-panel-header">' +
      '<strong>Notifikasi</strong>' +
      '<span id="notif-subtext"></span>' +
    '</div>' +
    '<div class="notif-list" id="notif-list">' +
      '<div class="notif-empty">Memuat...</div>' +
    '</div>';
  btn.appendChild(panel);
  return panel;
}

function _setNotifBadge(btn, count) {
  var badge = btn.querySelector('.notif-badge');
  if (count <= 0) {
    if (badge) badge.remove();
    return;
  }
  if (!badge) {
    badge = document.createElement('span');
    badge.className = 'notif-badge';
    btn.appendChild(badge);
  }
  badge.textContent = count > 99 ? '99+' : count;
}

// ── Student notification init ──────────────────────────────────────
window.initStudentNotif = function initStudentNotif() {
  var btn = document.querySelector('.topbar-notif');
  if (!btn) return;

  var panel    = _buildNotifPanel(btn);
  var studentId = GDRS.getStudentId();
  if (!studentId) return;

  var isOpen = false;

  async function refreshBadge() {
    try {
      var notifs = await GDRS.api('get_student_notifications', { id: studentId });
      if (!Array.isArray(notifs)) return;
      var unread = notifs.filter(function (n) { return n.is_read === false || n.is_read === 'f'; }).length;
      _setNotifBadge(btn, unread);
    } catch (_) {}
  }

  function renderList(notifs) {
    var list = document.getElementById('notif-list');
    var sub  = document.getElementById('notif-subtext');
    if (!notifs.length) {
      list.innerHTML  = '<div class="notif-empty">Tidak ada notifikasi.</div>';
      sub.textContent = '';
      return;
    }
    var unread = notifs.filter(function (n) { return n.is_read === false || n.is_read === 'f'; }).length;
    sub.textContent = unread > 0 ? (unread + ' belum dibaca') : 'Semua sudah dibaca';
    list.innerHTML = notifs.map(function (n) {
      var read = (n.is_read === true || n.is_read === 't');
      return '<div class="notif-item ' + (read ? '' : 'unread') + '">' +
               '<div class="notif-dot ' + (read ? 'read' : '') + '"></div>' +
               '<div style="flex:1;">' +
                 '<div class="notif-text">' + n.message + '</div>' +
                 '<div class="notif-time">' + _notifRelTime(n.created_at) + '</div>' +
               '</div>' +
             '</div>';
    }).join('');
  }

  btn.addEventListener('click', async function (e) {
    e.stopPropagation();
    isOpen = !isOpen;
    panel.classList.toggle('open', isOpen);

    if (isOpen) {
      try {
        var notifs = await GDRS.api('get_student_notifications', { id: studentId });
        if (!Array.isArray(notifs)) {
          document.getElementById('notif-list').innerHTML = '<div class="notif-empty">Tidak ada notifikasi.</div>';
        } else {
          renderList(notifs);
          await GDRS.api('mark_notifications_read', { id: studentId });
          _setNotifBadge(btn, 0);
        }
      } catch (_) {
        document.getElementById('notif-list').innerHTML = '<div class="notif-empty">Gagal memuat notifikasi.</div>';
      }
    }
  });

  document.addEventListener('click', function () {
    if (isOpen) { isOpen = false; panel.classList.remove('open'); }
  });
  panel.addEventListener('click', function (e) { e.stopPropagation(); });

  refreshBadge();
};

// ── Admin notification init ────────────────────────────────────────
window.initAdminNotif = function initAdminNotif() {
  var btn = document.querySelector('.topbar-notif');
  if (!btn) return;

  var panel   = _buildNotifPanel(btn);
  var adminId = GDRS.getAdminId();
  if (!adminId) return;

  var isOpen   = false;
  var newCount = 0;

  async function refreshBadge() {
    try {
      var res = await GDRS.api('get_admin_new_reports', { id: adminId });
      if (!res || typeof res.count === 'undefined') return;
      newCount = res.count || 0;
      _setNotifBadge(btn, newCount);
    } catch (_) {}
  }

  function renderAdminPanel(count) {
    var list = document.getElementById('notif-list');
    var sub  = document.getElementById('notif-subtext');
    sub.textContent = '';
    if (count === 0) {
      list.innerHTML = '<div class="notif-empty">Tidak ada laporan baru sejak terakhir aktif.</div>';
    } else {
      list.innerHTML =
        '<div class="notif-item unread">' +
          '<div class="notif-dot"></div>' +
          '<div style="flex:1;">' +
            '<div class="notif-text">Terdapat <strong>' + count + '</strong> laporan baru yang masuk sejak Anda terakhir aktif.</div>' +
          '</div>' +
        '</div>';
    }
  }

  btn.addEventListener('click', async function (e) {
    e.stopPropagation();
    isOpen = !isOpen;
    panel.classList.toggle('open', isOpen);

    if (isOpen) {
      renderAdminPanel(newCount);
      _setNotifBadge(btn, 0);
      newCount = 0;
    }
  });

  document.addEventListener('click', function () {
    if (isOpen) { isOpen = false; panel.classList.remove('open'); }
  });
  panel.addEventListener('click', function (e) { e.stopPropagation(); });

  document.querySelectorAll('.btn-keluar-admin').forEach(function (el) {
    el.addEventListener('click', function (e) {
      if (!confirm('Yakin ingin keluar dari akun admin?')) { e.preventDefault(); return; }
      try {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', GDRS.API + '?action=record_admin_logout', false);
        xhr.setRequestHeader('Content-Type', 'application/json');
        xhr.send(JSON.stringify({ admin_id: parseInt(adminId) }));
      } catch (_) {}
      localStorage.removeItem('adminId');
      window.location.href = 'indexadmin.html';
      e.preventDefault();
    });
  });

  refreshBadge();
};
