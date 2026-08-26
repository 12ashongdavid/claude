<?php
// =====================================================
// Staff Management Page (Admin only)
// PK's Luxury Apartments — Apartment Management System
// =====================================================
require_once __DIR__ . '/config/database.php';
$pageTitle = 'Staff';
requireRole(['admin']);

include __DIR__ . '/includes/header.php';
?>

<div class="filter-bar">
    <div class="search-box">
        <span class="search-icon"><i class='bx bx-search'></i></span>
        <input type="text" id="searchStaff" placeholder="Search staff..." oninput="filterStaff()">
    </div>
    <button class="btn btn-primary" onclick="openModal('addStaffModal')">+ Add Staff</button>
</div>

<div class="card">
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Username</th>
                    <th>Phone</th>
                    <th>Email</th>
                    <th>Status</th>
                    <th>Registered</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="staffBody"></tbody>
        </table>
    </div>
</div>

<!-- Add Staff Modal -->
<div class="modal-overlay" id="addStaffModal">
    <div class="modal">
        <div class="modal-header">
            <h3>Add New Staff</h3>
            <button class="modal-close" onclick="closeModal('addStaffModal')">&times;</button>
        </div>
        <div class="modal-body">
            <form id="addStaffForm" onsubmit="submitStaff(event)">
                <div class="form-row">
                    <div class="form-group">
                        <label>Full Name *</label>
                        <input type="text" name="full_name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Username *</label>
                        <input type="text" name="username" class="form-control" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Phone *</label>
                        <input type="tel" name="phone" class="form-control" pattern="[0-9]{10}" maxlength="10" oninput="this.value=this.value.replace(/[^0-9]/g,'')" required>
                    </div>
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" class="form-control">
                    </div>
                </div>
                <div style="display:flex;align-items:center;gap:8px;padding:10px 12px;background:var(--accent-light);border-radius:var(--radius-sm);font-size:0.78rem;color:var(--text-secondary);">
                    <i class="bx bx-message-square-dots" style="font-size:1.1rem;"></i>
                    A temporary password will be sent to the staff member's phone via SMS. They'll change it on first login.
                </div>
                <div class="modal-footer" style="padding:0;border:none;margin-top:16px;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('addStaffModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Staff</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let allStaff = [];

async function loadStaff() {
    const res = await fetch('api/users.php?role=staff');
    allStaff = await res.json();
    renderStaff(allStaff);
}

function filterStaff() {
    const search = document.getElementById('searchStaff').value.toLowerCase();
    const filtered = allStaff.filter(s => {
        return s.full_name.toLowerCase().includes(search) || s.username.toLowerCase().includes(search) || (s.phone || '').includes(search) || (s.email || '').toLowerCase().includes(search);
    });
    renderStaff(filtered);
}

function renderStaff(staff) {
    const tbody = document.getElementById('staffBody');
    if (!staff.length) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted" style="padding:30px;">No staff found</td></tr>';
        return;
    }
    tbody.innerHTML = staff.map(s => `
        <tr>
            <td style="font-weight:600;">${esc(s.full_name)}</td>
            <td>${esc(s.username)}</td>
            <td>${esc(s.phone || '—')}</td>
            <td>${esc(s.email || '—')}</td>
            <td><span class="badge badge-${s.is_active ? 'success' : 'danger'}">${s.is_active ? 'Active' : 'Inactive'}</span></td>
            <td class="text-muted">${new Date(s.created_at).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'})}</td>
            <td style="white-space:nowrap;">
                <button class="btn btn-sm btn-outline" onclick="resetPassword(${s.id})"><i class='bx bx-key'></i> Reset PW</button>
                ${s.is_active
                    ? `<button class="btn btn-sm btn-danger" onclick="deactivateStaff(${s.id})">Deactivate</button>`
                    : `<button class="btn btn-sm btn-success" onclick="activateStaff(${s.id})">Activate</button>`}
            </td>
        </tr>
    `).join('');
}

async function submitStaff(e) {
    e.preventDefault();
    const form = new FormData(e.target);
    const phone = (form.get('phone') || '').trim();
    if (phone && !/^[0-9]{10}$/.test(phone)) {
        showToast('Phone number must be exactly 10 digits (numbers only).', 'error');
        return;
    }
    form.append('action', 'add_staff');
    form.append('csrf_token', getCsrfToken());
    const res = await fetch('api/users.php', { method: 'POST', body: form });
    const data = await res.json();
    if (data.success) {
        showToast('Staff member added successfully!', 'success');
        closeModal('addStaffModal');
        e.target.reset();
        loadStaff();
    } else {
        showToast(data.error || 'Error adding staff', 'error');
    }
}

async function resetPassword(id) {
    showConfirm('Reset this staff member\'s password? A new temporary password will be sent via SMS.', () => doResetPassword(id), 'Reset Password');
}

async function doResetPassword(id) {
    const form = new FormData();
    form.append('action', 'reset_password');
    form.append('id', id);
    form.append('csrf_token', getCsrfToken());
    const res = await fetch('api/users.php', { method: 'POST', body: form });
    const data = await res.json();
    if (data.success) {
        showToast('Password reset. Temporary password sent via SMS.', 'success');
    } else {
        showToast(data.error || 'Error resetting password', 'error');
    }
}

async function deactivateStaff(id) {
    showConfirm('Deactivate this staff member? They will no longer be able to log in.', () => doDeactivate(id), 'Deactivate');
}

async function doDeactivate(id) {
    const form = new FormData();
    form.append('action', 'deactivate_user');
    form.append('id', id);
    form.append('csrf_token', getCsrfToken());
    const res = await fetch('api/users.php', { method: 'POST', body: form });
    const data = await res.json();
    if (data.success) {
        showToast('Staff member deactivated.', 'success');
        loadStaff();
    } else {
        showToast(data.error || 'Error deactivating staff member', 'error');
    }
}

async function activateStaff(id) {
    showConfirm('Activate this staff member? They will be able to log in again.', () => doActivate(id), 'Activate');
}

async function doActivate(id) {
    const form = new FormData();
    form.append('action', 'activate_user');
    form.append('id', id);
    form.append('csrf_token', getCsrfToken());
    const res = await fetch('api/users.php', { method: 'POST', body: form });
    const data = await res.json();
    if (data.success) {
        showToast('Staff member activated.', 'success');
        loadStaff();
    } else {
        showToast(data.error || 'Error activating staff member', 'error');
    }
}

function esc(s) { const d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

loadStaff();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
