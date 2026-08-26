<?php
// =====================================================
// Announcements Page
// PK's Luxury Apartments — Apartment Management System
// =====================================================
require_once __DIR__ . '/config/database.php';
$pageTitle = 'Announcements';
requireLogin();

$db = getDB();
$user = currentUser();

include __DIR__ . '/includes/header.php';
?>

<?php if (in_array($user['role'], ['admin', 'staff'])): ?>
<div class="card">
    <div class="card-header"><h3>Post New Announcement</h3></div>
    <form id="announcementForm" onsubmit="postAnnouncement(event)">
        <div class="form-group">
            <label>Title *</label>
            <input type="text" name="title" class="form-control" placeholder="e.g. Water Maintenance Notice" required maxlength="150">
        </div>
        <div class="form-group">
            <label>Content *</label>
            <textarea name="content" class="form-control" rows="4" placeholder="Write your announcement here..." required></textarea>
        </div>
        <div class="form-group">
            <label>Send To</label>
            <select name="target_tenant_id" id="targetTenant" class="form-control">
                <option value="0">All Tenants (general announcement)</option>
                <?php
                $tenants = $db->query("SELECT id, full_name, phone FROM users WHERE role = 'tenant' AND is_active = 1 ORDER BY full_name")->fetchAll();
                foreach ($tenants as $tenant):
                ?>
                <option value="<?= (int)$tenant['id'] ?>"><?= htmlspecialchars($tenant['full_name']) ?> (<?= htmlspecialchars($tenant['phone']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <div style="display:flex;align-items:center;gap:8px;padding:10px 14px;background:linear-gradient(135deg,var(--accent),var(--accent-dark));border-radius:var(--radius-sm);font-size:0.8rem;color:#fff;font-weight:600;">
                <i class="bx bx-message-square-dots" style="font-size:1.1rem;"></i>
                Selected tenant(s) will be notified instantly in-app and via SMS.
            </div>
        </div>
        <button type="submit" class="btn btn-primary"><i class="bx bx-send" style="vertical-align:-1px;"></i> Post Announcement</button>
    </form>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><h3>All Announcements</h3></div>
    <div id="announcementList">
        <div class="empty-state">
            <div class="empty-icon"><i class="bx bx-loader bx-spin"></i></div>
            <h4>Loading...</h4>
        </div>
    </div>
</div>

<script>
async function loadAnnouncements() {
    const res = await fetch('api/announcements.php');
    const items = await res.json();
    const el = document.getElementById('announcementList');
    if (!items.length) {
        el.innerHTML = '<div class="empty-state"><div class="empty-icon"><i class="bx bx-broadcast" style="font-size:3rem;"></i></div><h4>No announcements yet</h4></div>';
        return;
    }
    el.innerHTML = items.map(a => `
        <div class="notif-item" style="border-radius:var(--radius-sm);margin-bottom:10px;${!a.is_active ? 'opacity:0.55;' : ''}" id="ann-${a.id}">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;">
                <div style="flex:1;">
                    <div class="notif-title">
                        <i class="bx bx-broadcast" style="color:var(--accent);"></i>
                        ${esc(a.title)}
                        ${a.target_tenant_id ? '<span class="badge" style="background:var(--accent-light);color:var(--accent-dark);">Private to ' + esc(a.target_name || 'a tenant') + '</span>' : '<span class="badge badge-info">All Tenants</span>'}
                        ${!a.is_active ? '<span class="badge badge-warning">Hidden</span>' : ''}
                    </div>
                    <div class="notif-msg" style="white-space:pre-line;">${esc(a.content)}</div>
                    <div class="notif-time" style="margin-top:6px;">Posted by ${esc(a.author)} &bull; ${new Date(a.created_at).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'})} ${new Date(a.created_at).toLocaleTimeString('en-GB',{hour:'2-digit',minute:'2-digit'})}</div>
                </div>
                ${window.__isMgmt ? `
                <div style="text-align:right;display:flex;flex-direction:column;gap:6px;min-width:90px;">
                    <button class="btn btn-sm btn-outline" onclick="toggleAnnouncement(${a.id})">${a.is_active ? 'Hide' : 'Show'}</button>
                    ${window.__isAdmin ? `<button class="btn btn-sm btn-danger" onclick="deleteAnnouncement(${a.id})">Delete</button>` : ''}
                </div>` : ''}
            </div>
        </div>
    `).join('');
}

async function postAnnouncement(e) {
    e.preventDefault();
    const form = new FormData(e.target);
    form.append('action', 'add');
    form.append('csrf_token', getCsrfToken());
    const res = await fetch('api/announcements.php', { method: 'POST', body: form });
    const data = await res.json();
    if (data.success) {
        showToast('Announcement posted! Tenants have been notified via SMS.', 'success');
        e.target.reset();
        document.getElementById('targetTenant').value = '0';
        loadAnnouncements();
    } else {
        showToast(data.error || 'Error posting announcement', 'error');
    }
}

async function toggleAnnouncement(id) {
    const form = new FormData();
    form.append('action', 'toggle');
    form.append('id', id);
    form.append('csrf_token', getCsrfToken());
    const res = await fetch('api/announcements.php', { method: 'POST', body: form });
    const data = await res.json();
    if (data.success) {
        showToast('Announcement updated.', 'success');
        loadAnnouncements();
    } else {
        showToast(data.error || 'Error', 'error');
    }
}

async function deleteAnnouncement(id) {
    if (!confirm('Delete this announcement?')) return;
    const form = new FormData();
    form.append('action', 'delete');
    form.append('id', id);
    form.append('csrf_token', getCsrfToken());
    const res = await fetch('api/announcements.php', { method: 'POST', body: form });
    const data = await res.json();
    if (data.success) {
        showToast('Announcement deleted.', 'success');
        loadAnnouncements();
    } else {
        showToast(data.error || 'Error', 'error');
    }
}

function esc(s) { const d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

window.__isMgmt = <?= in_array($user['role'], ['admin', 'staff']) ? 'true' : 'false' ?>;
window.__isAdmin = <?= $user['role'] === 'admin' ? 'true' : 'false' ?>;
loadAnnouncements();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
