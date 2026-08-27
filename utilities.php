<?php
// Lets tenants view and pay their utility bills, and lets admin/staff add bills and mark them paid.
require_once __DIR__ . '/config/database.php';
$pageTitle = 'Utility Bills';
requireLogin();

$db = getDB();
$user = currentUser();
$isAdmin = in_array($user['role'], ['admin', 'staff']);

// Paystack return feedback
if (isset($_GET['paid'])) {
    if ($_GET['paid'] == 1) {
        setFlash('success', 'Utility bill paid successfully. Thank you!');
    } else {
        setFlash('error', 'Payment was not completed — no charge was made. You can try again.');
    }
}

if ($isAdmin) {
    $tenants = $db->query("SELECT id, full_name FROM users WHERE role='tenant' AND is_active=1 ORDER BY full_name")->fetchAll();
    $occupiedRooms = $db->query("SELECT id, room_number FROM rooms WHERE status='occupied' ORDER BY room_number")->fetchAll();
    // Map each tenant to the residence in their active tenancy (one account = one residence)
    $tenantRooms = [];
    $rows = $db->query("SELECT t.tenant_id, r.id AS room_id, r.room_number FROM tenancies t JOIN rooms r ON t.room_id = r.id WHERE t.status = 'active'")->fetchAll();
    foreach ($rows as $row) {
        $tenantRooms[$row['tenant_id']] = ['room_id' => intval($row['room_id']), 'room_number' => $row['room_number']];
    }
}

include __DIR__ . '/includes/header.php';
?>

<?php if ($isAdmin): ?>
<div class="filter-bar">
    <select id="filterMonth" onchange="loadBills()" class="form-control" style="max-width:160px;">
        <option value="">All Months</option>
        <?php
        $months = $db->query("SELECT DISTINCT billing_month FROM utility_bills ORDER BY billing_month DESC")->fetchAll();
        foreach ($months as $m): ?>
            <option value="<?= $m['billing_month'] ?>"><?= date('M Y', strtotime($m['billing_month'] . '-01')) ?></option>
        <?php endforeach; ?>
    </select>
    <select id="filterBillStatus" onchange="loadBills()" class="form-control" style="max-width:140px;">
        <option value="">All Status</option>
        <option value="paid">Paid</option>
        <option value="unpaid">Unpaid</option>
        <option value="overdue">Overdue</option>
    </select>
    <button class="btn btn-primary" onclick="openModal('addBillModal')">+ Add Bill</button>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h3><?= $isAdmin ? 'All Utility Bills' : 'My Utility Bills' ?></h3>
    </div>
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <?php if ($isAdmin): ?><th>Tenant</th><?php endif; ?>
                    <th>Room</th>
                    <th>Bill Type</th>
                    <th>Amount</th>
                    <th>Billing Month</th>
                    <th>Status</th>
                    <th>Payment Date</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="billsBody"></tbody>
        </table>
    </div>
</div>

<?php if ($isAdmin): ?>
<!-- Add Bill Modal -->
<div class="modal-overlay" id="addBillModal">
    <div class="modal">
        <div class="modal-header">
            <h3>Add Utility Bill</h3>
            <button class="modal-close" onclick="closeModal('addBillModal')">&times;</button>
        </div>
        <div class="modal-body">
            <form id="addBillForm" onsubmit="submitBill(event)">
                <div class="form-row">
                    <div class="form-group">
                        <label>Tenant *</label>
                        <select name="tenant_id" class="form-control" required onchange="autofillRoom(this)">
                            <option value="">Select tenant...</option>
                            <?php foreach ($tenants as $t): ?>
                            <option value="<?= $t['id'] ?>"><?= sanitize($t['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Residence *</label>
                        <select name="room_id" id="billRoomSelect" class="form-control" required>
                            <option value="">Select residence...</option>
                            <?php foreach ($occupiedRooms as $r): ?>
                            <option value="<?= $r['id'] ?>"><?= sanitize($r['room_number']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small id="billRoomHint" style="color:var(--text-muted);font-size:0.72rem;"></small>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Bill Type *</label>
                        <select name="bill_type" class="form-control" required>
                            <option value="water">Water</option>
                            <option value="electricity">Electricity</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Amount (GH&#8373;) *</label>
                        <input type="number" name="amount" class="form-control" step="0.01" min="0" required>
                    </div>
                </div>
                <div class="form-group">
                    <label>Billing Month *</label>
                    <input type="month" name="billing_month" class="form-control" value="<?= date('Y-m') ?>" max="<?= date('Y-m') ?>" required>
                </div>
                <div class="modal-footer" style="padding:0;border:none;margin-top:16px;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('addBillModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Bill</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Mark Paid Modal -->
<div class="modal-overlay" id="markPaidModal">
    <div class="modal" style="max-width:400px;">
        <div class="modal-header">
            <h3>Mark as Paid</h3>
            <button class="modal-close" onclick="closeModal('markPaidModal')">&times;</button>
        </div>
        <div class="modal-body">
            <form id="markPaidForm" onsubmit="submitMarkPaid(event)">
                <input type="hidden" name="id" id="markPaidId">
                <div class="form-group">
                    <label>Payment Date</label>
                    <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="form-group">
                    <label>Payment Method</label>
                    <select name="payment_method" class="form-control" required>
                        <option value="cash">Cash</option>
                        <option value="mobile_money">Mobile Money</option>
                        <option value="bank_transfer">Bank Transfer</option>
                        <option value="cheque">Cheque</option>
                    </select>
                </div>
                <div class="modal-footer" style="padding:0;border:none;margin-top:16px;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('markPaidModal')">Cancel</button>
                    <button type="submit" class="btn btn-success">Confirm Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
let allBills = [];
const isAdmin = <?= json_encode($isAdmin) ?>;
const myId = <?= json_encode($user['id']) ?>;
const tenantRooms = <?= $isAdmin ? json_encode($tenantRooms) : '{}' ?>;

function autofillRoom(sel) {
    const roomSel = document.getElementById('billRoomSelect');
    const hint = document.getElementById('billRoomHint');
    const info = tenantRooms[sel.value];
    if (info) {
        roomSel.value = info.room_id;
        roomSel.disabled = true;
        if (hint) hint.textContent = 'Residence ' + info.room_number + ' auto-selected from this tenant\'s active tenancy.';
    } else {
        roomSel.value = '';
        roomSel.disabled = false;
        if (hint) hint.textContent = '';
    }
}

async function loadBills() {
    let url = 'api/utilities.php';
    const month = document.getElementById('filterMonth')?.value || '';
    const status = document.getElementById('filterBillStatus')?.value || '';
    const params = new URLSearchParams();
    if (month) params.set('billing_month', month);
    if (status) params.set('status', status);
    if (params.toString()) url += '?' + params.toString();

    const res = await fetch(url);
    allBills = await res.json();

    <?php if (!$isAdmin): ?>
    allBills = allBills.filter(b => b.tenant_id == myId);
    <?php endif; ?>

    renderBills(allBills);
}

function renderBills(bills) {
    const tbody = document.getElementById('billsBody');
    if (!bills.length) {
        const cols = isAdmin ? 8 : 7;
        tbody.innerHTML = `<tr><td colspan="${cols}" class="text-center text-muted" style="padding:30px;">No utility bills found</td></tr>`;
        return;
    }
    tbody.innerHTML = bills.map(b => `
        <tr>
            ${isAdmin ? `<td>${esc(b.tenant_name)}</td>` : ''}
            <td>${esc(b.room_number)}</td>
            <td>${esc(b.bill_type.charAt(0).toUpperCase() + b.bill_type.slice(1))}</td>
            <td style="font-weight:600;">GH&#8373; ${parseFloat(b.amount).toFixed(2)}</td>
            <td>${new Date(b.billing_month + '-01').toLocaleDateString('en-GB',{month:'long',year:'numeric'})}</td>
            <td><span class="badge badge-${b.status === 'paid' ? 'success' : (b.status === 'overdue' ? 'danger' : 'warning')}">${esc(b.status)}</span></td>
            <td>${b.payment_date ? new Date(b.payment_date).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'}) : '—'}</td>
            ${isAdmin ? `<td>${b.status !== 'paid'
                ? `<button class="btn btn-sm btn-success" onclick="openMarkPaid(${b.id})">Mark Paid</button>`
                : `<a href="utility_receipt.php?id=${b.id}" target="_blank" class="btn btn-sm btn-primary"><i class='bx bx-printer'></i> Receipt</a>`}</td>`
                : `<td>${b.status === 'paid' ? `<a href="utility_receipt.php?id=${b.id}" target="_blank" class="btn btn-sm btn-primary"><i class='bx bx-printer'></i> Receipt</a>` : `<button class="btn btn-sm btn-primary" onclick="payNowBill(${b.id})"><i class='bx bx-credit-card'></i> Pay Now</button>`}</td>`}
        </tr>
    `).join('');
}

async function submitBill(e) {
    e.preventDefault();
    const form = new FormData(e.target);
    const roomSel = document.getElementById('billRoomSelect');
    if (roomSel.disabled && roomSel.value) form.append('room_id', roomSel.value);
    form.append('action', 'add');
    form.append('csrf_token', getCsrfToken());
    const res = await fetch('api/utilities.php', { method: 'POST', body: form });
    const data = await res.json();
    if (data.success) {
        showToast('Utility bill added!', 'success');
        closeModal('addBillModal');
        e.target.reset();
        roomSel.disabled = false;
        const hint = document.getElementById('billRoomHint');
        if (hint) hint.textContent = '';
        loadBills();
    } else {
        showToast(data.error || 'Error adding bill', 'error');
    }
}

function openMarkPaid(id) {
    document.getElementById('markPaidId').value = id;
    openModal('markPaidModal');
}

async function submitMarkPaid(e) {
    e.preventDefault();
    const form = new FormData(e.target);
    form.append('action', 'mark_paid');
    form.append('csrf_token', getCsrfToken());
    const res = await fetch('api/utilities.php', { method: 'POST', body: form });
    const data = await res.json();
    if (data.success) {
        showToast('Marked as paid!', 'success');
        closeModal('markPaidModal');
        loadBills();
    }
}

async function payNowBill(billId) {
    if (!confirm('You will be taken to an online payment page to pay this bill. Continue?')) return;
    const form = new FormData();
    form.append('action', 'initialize');
    form.append('type', 'utility');
    form.append('bill_id', billId);
    form.append('csrf_token', getCsrfToken());
    const res = await fetch('api/paystack.php', { method: 'POST', body: form });
    const data = await res.json();
    if (data.success) {
        window.location.href = data.authorization_url;
    } else {
        showToast(data.error || 'Unable to start payment', 'error');
    }
}

loadBills();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
