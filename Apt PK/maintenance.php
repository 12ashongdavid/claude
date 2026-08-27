<?php
// Maintenance requests — tenants submit issues here, admin/staff track and update their status.
require_once __DIR__ . '/config/database.php';
$pageTitle = 'Maintenance Requests';
requireLogin();

$db = getDB();
$user = currentUser();
$isAdmin = in_array($user['role'], ['admin', 'staff']);

// Tenant's rooms
$myRooms = [];
if (!$isAdmin) {
    $stmt = $db->prepare("SELECT r.id, r.room_number FROM tenancies t JOIN rooms r ON t.room_id = r.id WHERE t.tenant_id = ? AND t.status = 'active'");
    $stmt->execute([$user['id']]);
    $myRooms = $stmt->fetchAll();
}

include __DIR__ . '/includes/header.php';
?>

<div class="filter-bar">
    <?php if ($isAdmin): ?>
    <select id="filterPriority" onchange="loadRequests()" class="form-control" style="max-width:140px;">
        <option value="">All Priority</option>
        <option value="urgent">Urgent</option>
        <option value="high">High</option>
        <option value="medium">Medium</option>
        <option value="low">Low</option>
    </select>
    <select id="filterStatus" onchange="loadRequests()" class="form-control" style="max-width:140px;">
        <option value="">All Status</option>
        <option value="submitted">Submitted</option>
        <option value="in_progress">In Progress</option>
        <option value="resolved">Resolved</option>
        <option value="closed">Closed</option>
    </select>
    <?php endif; ?>

    <?php if ($myRooms): ?>
    <button class="btn btn-primary" onclick="openModal('submitReqModal')">+ Submit Request</button>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-header">
        <h3><?= $isAdmin ? 'All Maintenance Requests' : 'My Maintenance Requests' ?></h3>
    </div>
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <?php if ($isAdmin): ?><th>Tenant</th><?php endif; ?>
                    <th>Room</th>
                    <th>Category</th>
                    <th>Subject</th>
                    <th>Priority</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th>Assigned To</th>
                    <?php if ($isAdmin): ?><th>Action</th><?php endif; ?>
                </tr>
            </thead>
            <tbody id="reqBody"></tbody>
        </table>
    </div>
</div>

<!-- Submit Request Modal (Tenant) -->
<div class="modal-overlay" id="submitReqModal">
    <div class="modal">
        <div class="modal-header">
            <h3>Submit Maintenance Request</h3>
            <button class="modal-close" onclick="closeModal('submitReqModal')">&times;</button>
        </div>
        <div class="modal-body">
            <form id="submitReqForm" onsubmit="submitRequest(event)">
                <div class="form-row">
                    <div class="form-group">
                        <label>Room *</label>
                        <select name="room_id" class="form-control" required>
                            <?php foreach ($myRooms as $r): ?>
                            <option value="<?= $r['id'] ?>"><?= sanitize($r['room_number']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Category *</label>
                        <select name="category" class="form-control" required>
                            <option value="plumbing">Plumbing</option>
                            <option value="electrical">Electrical</option>
                            <option value="structural">Structural</option>
                            <option value="pest_control">Pest Control</option>
                            <option value="appliance">Appliance</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Priority *</label>
                        <select name="priority" class="form-control" required>
                            <option value="low">Low</option>
                            <option value="medium" selected>Medium</option>
                            <option value="high">High</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>Subject *</label>
                    <input type="text" name="subject" class="form-control" placeholder="Brief description of the issue" required>
                </div>
                <div class="form-group">
                    <label>Description *</label>
                    <textarea name="description" class="form-control" rows="3" placeholder="Describe the issue in detail..." required></textarea>
                </div>
                <div class="modal-footer" style="padding:0;border:none;margin-top:16px;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('submitReqModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Submit Request</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Update Status Modal (Admin/Staff) -->
<div class="modal-overlay" id="updateStatusModal">
    <div class="modal">
        <div class="modal-header">
            <h3>Update Request Status</h3>
            <button class="modal-close" onclick="closeModal('updateStatusModal')">&times;</button>
        </div>
        <div class="modal-body">
            <form id="updateStatusForm" onsubmit="submitStatusUpdate(event)">
                <input type="hidden" name="id" id="updateReqId">
                <div class="form-row">
                    <div class="form-group">
                        <label>Status *</label>
                        <select name="status" class="form-control" required>
                            <option value="submitted">Submitted</option>
                            <option value="in_progress">In Progress</option>
                            <option value="resolved">Resolved</option>
                            <option value="closed">Closed</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Assignee Name</label>
                        <input type="text" name="assigned_name" id="updateAssignedName" class="form-control" placeholder="e.g. Kofi Mensah">
                    </div>
                    <div class="form-group">
                        <label>Assignee Phone</label>
                        <input type="tel" name="assigned_phone" id="updateAssignedPhone" class="form-control" placeholder="e.g. 0241234567" pattern="[0-9]{10}" maxlength="10" oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                    </div>
                </div>
                <div class="form-group" id="resolutionGroup" style="display:none;">
                    <label>Resolution Notes</label>
                    <textarea name="resolution_notes" class="form-control" rows="3" placeholder="How was this resolved?"></textarea>
                </div>
                <div class="modal-footer" style="padding:0;border:none;margin-top:16px;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('updateStatusModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Request Detail Modal -->
<div class="modal-overlay" id="detailModal">
    <div class="modal">
        <div class="modal-header">
            <h3 id="detailTitle">Request Details</h3>
            <button class="modal-close" onclick="closeModal('detailModal')">&times;</button>
        </div>
        <div class="modal-body" id="detailBody"></div>
    </div>
</div>

<script>
let allReqs = [];
const isAdmin = <?= json_encode($isAdmin) ?>;

async function loadRequests() {
    let url = 'api/maintenance.php';
    <?php if (!$isAdmin): ?>
    url += '?mine=1';
    <?php else: ?>
    const priority = document.getElementById('filterPriority')?.value || '';
    const status = document.getElementById('filterStatus')?.value || '';
    const params = new URLSearchParams();
    if (priority) params.set('priority', priority);
    if (status) params.set('status', status);
    if (params.toString()) url += '?' + params.toString();
    <?php endif; ?>

    const res = await fetch(url);
    allReqs = await res.json();
    renderReqs(allReqs);
}

function renderReqs(reqs) {
    const tbody = document.getElementById('reqBody');
    if (!reqs.length) {
        const cols = isAdmin ? 9 : 7;
        tbody.innerHTML = `<tr><td colspan="${cols}" class="text-center text-muted" style="padding:30px;">No maintenance requests found</td></tr>`;
        return;
    }
    const priorityColors = { urgent: 'danger', high: 'warning', medium: 'info', low: 'secondary' };
    const statusColors = { submitted: 'info', in_progress: 'warning', resolved: 'success', closed: 'secondary' };

    tbody.innerHTML = reqs.map(r => `
        <tr style="cursor:pointer;" onclick="viewDetail(${r.id})">
            ${isAdmin ? `<td>${esc(r.tenant_name)}</td>` : ''}
            <td>${esc(r.room_number)}</td>
            <td>${esc(r.category.replace('_',' ')).replace(/\b\w/g,c=>c.toUpperCase())}</td>
            <td>${esc(r.subject)}</td>
            <td><span class="badge badge-${priorityColors[r.priority] || 'secondary'}">${esc(r.priority)}</span></td>
            <td><span class="badge badge-${statusColors[r.status] || 'secondary'}">${esc(r.status.replace('_',' '))}</span></td>
            <td class="text-muted">${new Date(r.created_at).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'})}</td>
            <td>${r.assigned_name ? `${esc(r.assigned_name)}${r.assigned_phone ? '<br><small class="text-muted">' + esc(r.assigned_phone) + '</small>' : ''}` : '<span class="text-muted">Unassigned</span>'}</td>
            ${isAdmin ? `<td><button class="btn btn-sm btn-outline" onclick="event.stopPropagation();openUpdateStatus(${r.id})">Update</button></td>` : ''}
        </tr>
    `).join('');
}

function viewDetail(id) {
    const r = allReqs.find(x => x.id == id);
    if (!r) return;
    const priorityColors = { urgent: 'danger', high: 'warning', medium: 'info', low: 'secondary' };
    const statusColors = { submitted: 'info', in_progress: 'warning', resolved: 'success', closed: 'secondary' };

    document.getElementById('detailTitle').textContent = r.subject;
    document.getElementById('detailBody').innerHTML = `
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;">
            <div><strong>Room:</strong> ${esc(r.room_number)}</div>
            <div><strong>Category:</strong> ${esc(r.category.replace('_',' '))}</div>
            <div><strong>Priority:</strong> <span class="badge badge-${priorityColors[r.priority]}">${esc(r.priority)}</span></div>
            <div><strong>Status:</strong> <span class="badge badge-${statusColors[r.status]}">${esc(r.status.replace('_',' '))}</span></div>
            ${isAdmin ? `<div><strong>Tenant:</strong> ${esc(r.tenant_name)}</div>` : ''}
            <div><strong>Assigned To:</strong> ${esc(r.assigned_name || 'Unassigned')}</div>
            ${r.assigned_phone ? `<div><strong>Assignee Phone:</strong> ${esc(r.assigned_phone)}</div>` : ''}
        </div>
        <div style="margin-bottom:12px;">
            <strong>Description:</strong>
            <p style="background:var(--surface-alt);padding:12px;border-radius:6px;margin-top:6px;font-size:0.88rem;">${esc(r.description)}</p>
        </div>
        ${r.resolution_notes ? `<div><strong>Resolution Notes:</strong><p style="background:rgba(76,175,80,0.08);padding:12px;border-radius:6px;margin-top:6px;font-size:0.88rem;">${esc(r.resolution_notes)}</p></div>` : ''}
        <div style="font-size:0.78rem;color:var(--text-muted);margin-top:12px;">
            Submitted: ${new Date(r.created_at).toLocaleString('en-GB')}
            ${r.resolved_date ? `<br>Resolved: ${new Date(r.resolved_date).toLocaleString('en-GB')}` : ''}
        </div>
    `;
    openModal('detailModal');
}

function openUpdateStatus(id) {
    const r = allReqs.find(x => x.id == id);
    if (!r) return;
    document.getElementById('updateReqId').value = r.id;
    document.getElementById('updateStatusForm').querySelector('[name="status"]').value = r.status;
    document.getElementById('updateAssignedName').value = r.assigned_name || '';
    document.getElementById('updateAssignedPhone').value = r.assigned_phone || '';
    toggleResolutionNotes(r.status);
    openModal('updateStatusModal');
}

function toggleResolutionNotes(status) {
    const group = document.getElementById('resolutionGroup');
    group.style.display = status === 'resolved' ? 'block' : 'none';
}

document.querySelector('#updateStatusForm [name="status"]')?.addEventListener('change', function() {
    toggleResolutionNotes(this.value);
});

async function submitRequest(e) {
    e.preventDefault();
    const form = new FormData(e.target);
    form.append('action', 'submit');
    form.append('csrf_token', getCsrfToken());
    const res = await fetch('api/maintenance.php', { method: 'POST', body: form });
    const data = await res.json();
    if (data.success) {
        showToast('Maintenance request submitted!', 'success');
        closeModal('submitReqModal');
        e.target.reset();
        loadRequests();
    } else {
        showToast(data.error || 'Error submitting request', 'error');
    }
}

async function submitStatusUpdate(e) {
    e.preventDefault();
    const form = new FormData(e.target);
    const phone = (form.get('assigned_phone') || '').trim();
    if (phone && !/^[0-9]{10}$/.test(phone)) {
        showToast('Assignee phone must be exactly 10 digits (numbers only).', 'error');
        return;
    }
    form.append('action', 'update_status');
    form.append('csrf_token', getCsrfToken());
    const res = await fetch('api/maintenance.php', { method: 'POST', body: form });
    const data = await res.json();
    if (data.success) {
        showToast('Request updated!', 'success');
        closeModal('updateStatusModal');
        loadRequests();
    } else {
        showToast(data.error || 'Error updating request', 'error');
    }
}

function esc(s) { const d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

loadRequests();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
