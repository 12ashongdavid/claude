<?php
// Lets a tenant pay rent online or view their history, and lets staff/admin record and review payments.
require_once __DIR__ . '/config/database.php';
$pageTitle = 'Rent Payments';
requireLogin();

$db = getDB();
$user = currentUser();
$isAdmin = in_array($user['role'], ['admin', 'staff']);

// Paystack return feedback
if (isset($_GET['paid'])) {
    if ($_GET['paid'] == 1) {
        setFlash('success', 'Payment successful and recorded automatically. Thank you!');
    } else {
        setFlash('error', 'Payment was not completed — no charge was made. You can try again.');
    }
}

// Get tenants and rooms for admin forms
if ($isAdmin) {
    $tenants = $db->query("SELECT id, full_name FROM users WHERE role='tenant' AND is_active=1 ORDER BY full_name")->fetchAll();
    $rooms = $db->query("SELECT id, room_number, rental_price FROM rooms WHERE status='occupied' ORDER BY room_number")->fetchAll();
    // Map each tenant to the residence in their active tenancy (one account = one residence)
    $payTenantRooms = [];
    $rows = $db->query("SELECT t.tenant_id, r.id AS room_id, r.room_number, r.rental_price FROM tenancies t JOIN rooms r ON t.room_id = r.id WHERE t.status = 'active'")->fetchAll();
    foreach ($rows as $row) {
        $payTenantRooms[$row['tenant_id']] = ['room_id' => intval($row['room_id']), 'room_number' => $row['room_number'], 'rental_price' => $row['rental_price']];
    }
}

// Tenant's active tenancy for the online payment form
$tenancy = null;
if (!$isAdmin) {
    $stmt = $db->prepare("SELECT t.*, r.room_number, r.rental_price, r.room_type, rt.charge_period FROM tenancies t JOIN rooms r ON t.room_id = r.id LEFT JOIN room_types rt ON rt.name = r.room_type WHERE t.tenant_id = ? AND t.status = 'active' ORDER BY t.id DESC LIMIT 1");
    $stmt->execute([$user['id']]);
    $tenancy = $stmt->fetch();
}

// Tenant account summary
$paidThrough = '—';
$myOwing = 0.0;
$nextDueMonth = null;
$paidMonths = [];
$fullyPaid = false;
$maxYm = date('Y-m', strtotime('+11 months'));
if (!$isAdmin && $tenancy) {
    $arrears = computeTenantArrears($user['id']);
    $myOwing = $arrears['owing'];
    $paidThrough = getTenantPaidThroughRange($user['id']) ?: '—';
    $nextDueMonth = getTenantNextDueMonth($user['id']);
    $paidMonths = getTenantPaidMonths($user['id']);
    // Paid ahead beyond the one-year advance limit — nothing more to pay right now
    $fullyPaid = $nextDueMonth > $maxYm;
}
// Month the form defaults to (never beyond the one-year advance window)
$defaultMonth = $nextDueMonth && $nextDueMonth <= $maxYm ? $nextDueMonth : $maxYm;

// Admin/staff summary strip
$summary = ['totalCollected' => 0.0, 'thisMonth' => 0.0, 'outstanding' => 0.0, 'activeTenancies' => 0];
if ($isAdmin) {
    $summary['totalCollected'] = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM rent_payments")->fetchColumn();
    $summary['thisMonth'] = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM rent_payments WHERE MONTH(payment_date)=MONTH(CURRENT_DATE()) AND YEAR(payment_date)=YEAR(CURRENT_DATE())")->fetchColumn();
    $summary['activeTenancies'] = (int)$db->query("SELECT COUNT(*) FROM tenancies WHERE status='active'")->fetchColumn();
    $ids = $db->query("SELECT id FROM users WHERE role='tenant' AND is_active=1")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        $summary['outstanding'] += computeTenantArrears($id)['owing'];
    }
}

include __DIR__ . '/includes/header.php';
?>

<?php if ($isAdmin): ?>
<!-- Admin/staff summary -->
<div class="report-summary">
    <div class="report-box">
        <h5>Total Collected</h5>
        <div class="value" style="color:var(--success);"><?= formatCurrency($summary['totalCollected']) ?></div>
    </div>
    <div class="report-box">
        <h5>This Month</h5>
        <div class="value"><?= formatCurrency($summary['thisMonth']) ?></div>
    </div>
    <div class="report-box">
        <h5>Total Outstanding</h5>
        <div class="value" style="color:<?= $summary['outstanding'] > 0 ? 'var(--danger)' : 'var(--success)' ?>;"><?= formatCurrency($summary['outstanding']) ?></div>
    </div>
    <div class="report-box">
        <h5>Active Tenancies</h5>
        <div class="value"><?= $summary['activeTenancies'] ?></div>
    </div>
</div>
<div class="filter-bar">
    <div class="search-box">
        <span class="search-icon"><i class='bx bx-search'></i></span>
        <input type="text" id="searchPayments" placeholder="Search by name, room, or reference..." oninput="filterPayments()">
    </div>
    <input type="date" id="dateFrom" class="form-control" style="max-width:160px;" onchange="filterPayments()">
    <input type="date" id="dateTo" class="form-control" style="max-width:160px;" onchange="filterPayments()">
    <button class="btn btn-primary" onclick="openModal('recordPaymentModal')">+ Record Payment</button>
</div>
<?php endif; ?>

<?php if (!$isAdmin && $tenancy): ?>
<!-- Tenant account summary -->
<div class="report-summary">
    <div class="report-box">
        <h5>Monthly Rent</h5>
        <div class="value"><?= formatCurrency($tenancy['monthly_rent']) ?></div>
    </div>
    <div class="report-box">
        <h5>Rate Type</h5>
        <div class="value" style="font-size:1.05rem;"><?= ($tenancy['charge_period'] ?? 'monthly') === 'daily' ? 'Daily' : 'Monthly' ?></div>
    </div>
    <div class="report-box">
        <h5>Paid Through</h5>
        <div class="value" style="font-size:1.05rem;"><?= $paidThrough ?></div>
    </div>
    <div class="report-box">
        <h5>Amount Owing</h5>
        <div class="value" style="color:<?= $myOwing > 0 ? 'var(--danger)' : 'var(--success)' ?>;"><?= formatCurrency($myOwing) ?></div>
    </div>
</div>

<div class="card" style="border-left:4px solid var(--accent);">
    <div class="card-header">
        <h3><i class="bx bx-credit-card" style="vertical-align:-2px;color:var(--accent);"></i> Pay Rent Online</h3>
        <span class="text-muted" style="font-size:0.82rem;">Residence <?= sanitize($tenancy['room_number']) ?> &bull; <?= ($tenancy['charge_period'] ?? 'monthly') === 'daily' ? 'Daily Rate' : 'Monthly Rate' ?></span>
    </div>
    <div class="card-body">
        <?php if ($fullyPaid): ?>
        <div class="alert alert-success" style="margin-bottom:16px;">
            <i class="bx bx-check-circle"></i>
            <span>Your rent is already paid through <strong><?= date('F Y', strtotime($maxYm . '-01')) ?></strong>, which is the one-year advance limit. There is nothing to pay right now — when your paid period moves into the allowed window again, you will be able to extend from here.</span>
        </div>
        <?php endif; ?>
        <input type="hidden" id="chargePeriod" value="<?= ($tenancy['charge_period'] ?? 'monthly') === 'daily' ? 'daily' : 'monthly' ?>">
        <form id="payRentForm" onsubmit="payRentNow(event)">
            <?php if (($tenancy['charge_period'] ?? 'monthly') === 'daily'): ?>
            <div class="form-row">
                <div class="form-group">
                    <label>Rate Per Day (GH&#8373;)</label>
                    <input type="text" class="form-control" value="<?= formatCurrency($tenancy['monthly_rent']) ?>" readonly style="font-weight:700;">
                </div>
                <div class="form-group">
                    <label>Start Date *</label>
                    <input type="date" name="start_date" id="rentStartDate" class="form-control" value="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d') ?>" onchange="updateDailyRentCalc()" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Number of Days *</label>
                    <input type="number" name="days" id="rentDays" class="form-control" value="1" min="1" max="365" oninput="updateDailyRentCalc()" required>
                </div>
                <div class="form-group">
                    <label>Amount (GH&#8373;) *</label>
                    <input type="number" name="amount" id="rentAmount" class="form-control" step="0.01" min="0.01" value="<?= $tenancy['monthly_rent'] ?>" readonly required>
                    <small id="rentRangeLabel" style="color:var(--text-muted);font-size:0.72rem;"></small>
                </div>
            </div>
            <input type="hidden" name="month_covered" id="rentStartMonth" value="<?= date('Y-m') ?>">
            <input type="hidden" name="months" id="rentMonths" value="1">
            <small style="color:var(--text-muted);font-size:0.72rem;">Auto-calculated: days &times; daily rate.</small>
            <div style="display:flex;align-items:flex-end;justify-content:flex-end;margin-top:8px;">
                <button type="submit" class="btn btn-primary" id="payRentBtn" style="min-width:140px;"><i class="bx bx-credit-card"></i> Pay</button>
            </div>
            <?php else: ?>
            <div class="form-row">
                <div class="form-group">
                    <label>Paying From (Month) *</label>
                    <input type="month" name="month_covered" id="rentStartMonth" class="form-control" value="<?= $defaultMonth ?>" min="<?= date('Y-m') ?>" max="<?= $maxYm ?>" onchange="updateRentCalc()" required>
                    <small id="rentFromHint" style="color:var(--text-muted);font-size:0.72rem;"></small>
                </div>
                <div class="form-group">
                    <label>Number of Months *</label>
                    <input type="number" name="months" id="rentMonths" class="form-control" value="1" min="1" max="12" oninput="updateRentCalc()" required>
                    <small id="rentRangeLabel" style="color:var(--text-muted);font-size:0.72rem;"></small>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Amount (GH&#8373;) *</label>
                    <input type="number" name="amount" id="rentAmount" class="form-control" step="0.01" min="0.01" value="<?= $tenancy['monthly_rent'] ?>" readonly required>
                    <small style="color:var(--text-muted);font-size:0.72rem;">Auto-calculated: months &times; monthly rent. You can pay up to one year in advance, and only for months you have not already paid.</small>
                </div>
                <div class="form-group" style="display:flex;align-items:flex-end;">
                    <button type="submit" class="btn btn-primary btn-block" id="payRentBtn"><i class="bx bx-credit-card"></i> Pay</button>
                </div>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>
<?php elseif (!$isAdmin): ?>
<div class="card" style="border-left:4px solid var(--warning);">
    <div class="card-header"><h3>Pay Rent Online</h3></div>
    <div class="card-body">
        <div class="empty-state">
            <div class="empty-icon"><i class="bx bx-building-house"></i></div>
            <h4>No active tenancy</h4>
            <p class="text-muted">You do not have an active residence assignment yet. Contact management.</p>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h3><?= $isAdmin ? '<i class="bx bx-receipt" style="vertical-align:-2px;color:var(--primary);"></i> All Rent Payments' : '<i class="bx bx-history" style="vertical-align:-2px;color:var(--primary);"></i> My Payment History' ?></h3>
        <?php if ($isAdmin): ?><span class="text-muted" style="font-size:0.82rem;" id="paymentCountLabel"></span><?php endif; ?>
    </div>
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <?php if ($isAdmin): ?><th>Tenant</th><?php endif; ?>
                    <th>Room</th>
                    <th>Amount</th>
                    <th>Date</th>
                    <th>Month Covered</th>
                    <th>Method</th>
                    <th>Reference</th>
                    <th>Status</th>
                    <th>Receipt</th>
                </tr>
            </thead>
            <tbody id="paymentsBody">
                <!-- Populated by JS -->
            </tbody>
        </table>
    </div>
</div>

<!-- Record Payment Modal (Admin/Staff only) -->
<?php if ($isAdmin): ?>
<div class="modal-overlay" id="recordPaymentModal">
    <div class="modal">
        <div class="modal-header">
            <h3>Record Rent Payment</h3>
            <button class="modal-close" onclick="closeModal('recordPaymentModal')">&times;</button>
        </div>
        <div class="modal-body">
            <form id="recordPaymentForm" onsubmit="submitPayment(event)">
                <div class="form-row">
                    <div class="form-group">
                        <label>Tenant *</label>
                        <select name="tenant_id" class="form-control" required onchange="autoFillRoom(this.value)">
                            <option value="">Select tenant...</option>
                            <?php foreach ($tenants as $t): ?>
                            <option value="<?= $t['id'] ?>"><?= sanitize($t['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Room *</label>
                        <select name="room_id" id="payRoomId" class="form-control" required>
                            <option value="">Select room...</option>
                            <?php foreach ($rooms as $r): ?>
                            <option value="<?= $r['id'] ?>" data-price="<?= $r['rental_price'] ?>"><?= sanitize($r['room_number']) ?> — <?= formatCurrency($r['rental_price']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small id="payRoomHint" style="color:var(--text-muted);font-size:0.72rem;"></small>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Amount (GH&#8373;) *</label>
                        <input type="number" name="amount" id="payAmount" class="form-control" step="0.01" min="0" required>
                    </div>
                    <div class="form-group">
                        <label>Payment Date *</label>
                        <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Payment Method *</label>
                        <select name="payment_method" class="form-control" required>
                            <option value="mobile_money">Mobile Money</option>
                            <option value="cash">Cash</option>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="cheque">Cheque</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Month Covered *</label>
                        <input type="month" name="month_covered" class="form-control" value="<?= date('Y-m') ?>" min="<?= date('Y-m') ?>" max="<?= date('Y-m', strtotime('+11 months')) ?>" required>
                        <small style="color:var(--text-muted);font-size:0.72rem;">Advance rent allowed up to one year.</small>
                    </div>
                </div>
                <div class="form-group">
                    <label>Notes</label>
                    <textarea name="notes" class="form-control" rows="2" placeholder="Optional notes..."></textarea>
                </div>
                <div class="modal-footer" style="padding:0;border:none;margin-top:16px;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('recordPaymentModal')">Cancel</button>
                    <button type="submit" class="btn btn-success">Record Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
let allPayments = [];
const isAdmin = <?= json_encode($isAdmin) ?>;
const myId = <?= json_encode($user['id']) ?>;

async function loadPayments() {
    let url = 'api/payments.php';
    <?php if (!$isAdmin): ?>
    // Tenant sees only their payments (filter client-side)
    <?php endif; ?>
    const res = await fetch(url);
    allPayments = await res.json();

    <?php if (!$isAdmin): ?>
    allPayments = allPayments.filter(p => p.tenant_id == myId);
    <?php endif; ?>

    renderPayments(allPayments);
}

function filterPayments() {
    const search = (document.getElementById('searchPayments')?.value || '').toLowerCase();
    const dateFrom = document.getElementById('dateFrom')?.value || '';
    const dateTo = document.getElementById('dateTo')?.value || '';

    let filtered = allPayments.filter(p => {
        if (search && !p.tenant_name.toLowerCase().includes(search) && !p.room_number.toLowerCase().includes(search) && !(p.reference_number || '').toLowerCase().includes(search)) return false;
        if (dateFrom && p.payment_date < dateFrom) return false;
        if (dateTo && p.payment_date > dateTo) return false;
        return true;
    });
    renderPayments(filtered);
}

function renderPayments(payments) {
    const tbody = document.getElementById('paymentsBody');
    const countLabel = document.getElementById('paymentCountLabel');
    if (countLabel) countLabel.textContent = payments.length + ' payment(s) shown';
    if (!payments.length) {
        const cols = isAdmin ? 9 : 8;
        tbody.innerHTML = `<tr><td colspan="${cols}" class="text-center text-muted" style="padding:30px;">No payments found</td></tr>`;
        return;
    }
    tbody.innerHTML = payments.map(p => `
        <tr>
            ${isAdmin ? `<td>${esc(p.tenant_name)}</td>` : ''}
            <td>${esc(p.room_number)}</td>
            <td style="font-weight:600;color:var(--success);">GH&#8373; ${parseFloat(p.amount).toLocaleString('en',{minimumFractionDigits:2})}</td>
            <td>${new Date(p.payment_date).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'})}</td>
            <td>${esc(p.month_covered)}</td>
            <td>${esc(p.payment_method.replace('_',' ')).replace(/\b\w/g,c=>c.toUpperCase())}</td>
            <td><code style="font-size:0.78rem;">${esc(p.reference_number || '')}</code></td>
            <td><span class="badge badge-${p.status === 'completed' ? 'success' : 'warning'}">${esc(p.status)}</span></td>
            <td><a href="receipt.php?id=${p.id}" class="btn btn-sm btn-outline" target="_blank">PDF</a></td>
        </tr>
    `).join('');
}

const payTenantRooms = <?= json_encode($payTenantRooms ?? []) ?>;

function autoFillRoom(tenantId) {
    const roomSelect = document.getElementById('payRoomId');
    const info = payTenantRooms[tenantId];
    if (info) {
        roomSelect.value = info.room_id;
        roomSelect.disabled = true;
        document.getElementById('payRoomHint').textContent = 'Residence ' + info.room_number + ' auto-selected from this tenant\'s active tenancy.';
    } else {
        roomSelect.value = '';
        roomSelect.disabled = false;
        document.getElementById('payRoomHint').textContent = '';
    }
}

async function submitPayment(e) {
    e.preventDefault();
    const form = new FormData(e.target);
    const roomSelect = document.getElementById('payRoomId');
    if (roomSelect.disabled && roomSelect.value) form.append('room_id', roomSelect.value);
    form.append('action', 'record');
    form.append('csrf_token', getCsrfToken());
    const res = await fetch('api/payments.php', { method: 'POST', body: form });
    const data = await res.json();
    if (data.success) {
        showToast('Payment recorded! Reference: ' + data.reference, 'success');
        closeModal('recordPaymentModal');
        e.target.reset();
        roomSelect.disabled = false;
        document.getElementById('payRoomHint').textContent = '';
        loadPayments();
    } else {
        showToast(data.error || 'Error recording payment', 'error');
    }
}

const rentMonthlyRent = <?= $tenancy ? (float)$tenancy['monthly_rent'] : 0 ?>;
const rentTodayYm = '<?= date('Y-m') ?>';
const rentMaxYm = '<?= date('Y-m', strtotime('+11 months')) ?>';
const rentPaidMonths = <?= json_encode($paidMonths) ?>;
const rentNextDueYm = <?= json_encode($nextDueMonth) ?>;

function ymAdd(ym, n) {
    const p = ym.split('-').map(Number);
    const d = new Date(p[0], p[1] - 1 + n, 1);
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
}
function ymDiff(a, b) { // months from a to b (a <= b)
    const pa = a.split('-').map(Number), pb = b.split('-').map(Number);
    return (pb[0] * 12 + pb[1]) - (pa[0] * 12 + pa[1]);
}

function fmtMonthLabel(ym) {
    const p = ym.split('-').map(Number);
    return new Date(p[0], p[1] - 1, 1).toLocaleDateString('en-GB', { month: 'long', year: 'numeric' });
}

function updateRentCalc() {
    const startInput = document.getElementById('rentStartMonth');
    if (!startInput) return;
    const monthsInput = document.getElementById('rentMonths');
    const label = document.getElementById('rentRangeLabel');
    const fromHint = document.getElementById('rentFromHint');
    const amountInput = document.getElementById('rentAmount');
    const start = startInput.value;
    if (!start) { label.textContent = ''; return; }
    // Clamp months so start + months - 1 never exceeds the one-year advance limit
    const maxM = 1 + ymDiff(start, rentMaxYm);
    let m = parseInt(monthsInput.value, 10) || 1;
    if (m < 1) m = 1;
    if (m > maxM) m = maxM;
    monthsInput.value = m;
    const end = ymAdd(start, m - 1);
    amountInput.value = (m * rentMonthlyRent).toFixed(2);
    // Flag any month in the range the tenant has already paid for
    const overlap = [];
    for (let i = 0; i < m; i++) {
        const ym = ymAdd(start, i);
        if (rentPaidMonths.includes(ym)) overlap.push(ym);
    }
    if (rentNextDueYm && start < rentNextDueYm) {
        fromHint.textContent = 'Your next unpaid month is ' + fmtMonthLabel(rentNextDueYm) + '.';
        fromHint.style.color = 'var(--warning)';
    } else {
        fromHint.textContent = '';
    }
    label.textContent = 'Covers ' + start + ' to ' + end + ' (' + m + ' month' + (m > 1 ? 's' : '') + ').';
    if (overlap.length) {
        label.textContent += ' Includes month(s) already paid: ' + overlap.map(fmtMonthLabel).join(', ') + '. Adjust your selection.';
        label.style.color = 'var(--danger)';
    } else {
        label.style.color = 'var(--text-muted)';
    }
}

function updateDailyRentCalc() {
    const daysInput = document.getElementById('rentDays');
    const startDateInput = document.getElementById('rentStartDate');
    if (!daysInput) return;
    const label = document.getElementById('rentRangeLabel');
    const amountInput = document.getElementById('rentAmount');
    const days = parseInt(daysInput.value, 10) || 1;
    amountInput.value = (days * rentMonthlyRent).toFixed(2);
    const startDate = startDateInput ? startDateInput.value : '';
    const endDate = startDate ? new Date(new Date(startDate).getTime() + (days - 1) * 86400000) : null;
    const fmtD = d => d ? d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '';
    label.textContent = fmtD(new Date(startDate + 'T00:00:00')) + ' — ' + fmtD(endDate) + ' (' + days + ' day' + (days > 1 ? 's' : '') + ')';
    label.style.color = 'var(--text-muted)';
    if (startDateInput) {
        const startYm = startDate.substring(0, 7);
        document.getElementById('rentStartMonth').value = startYm;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('chargePeriod') && document.getElementById('chargePeriod').value === 'daily') {
        updateDailyRentCalc();
    } else if (document.getElementById('rentStartMonth')) {
        updateRentCalc();
    }
});

async function payRentNow(e) {
    e.preventDefault();
    const chargePeriod = (document.getElementById('chargePeriod') || {}).value || 'monthly';
    const btn = document.getElementById('payRentBtn');

    if (chargePeriod === 'monthly') {
        const startInput = document.getElementById('rentStartMonth');
        if (!startInput) return;
        const start = startInput.value;
        const m = parseInt(document.getElementById('rentMonths').value, 10) || 1;
        const paidInRange = [];
        for (let i = 0; i < m; i++) {
            const ym = ymAdd(start, i);
            if (rentPaidMonths.includes(ym)) paidInRange.push(fmtMonthLabel(ym));
        }
        if (paidInRange.length) {
            showToast('You cannot pay for a month you have already paid for: ' + paidInRange.join(', ') + '. Please adjust your selection.', 'error');
            updateRentCalc();
            return;
        }
    }
    btn.disabled = true;
    btn.innerHTML = '<i class="bx bx-loader bx-spin"></i> Processing...';
    try {
        const form = new FormData(e.target);
        form.append('action', 'initialize');
        form.append('type', 'rent');
        form.append('csrf_token', getCsrfToken());
        const res = await fetch('api/paystack.php', { method: 'POST', body: form });
        let data = null;
        try {
            data = await res.json();
        } catch (parseErr) {
            showToast('The payment service returned an unexpected response. Please try again.', 'error');
            return;
        }
        if (data.success) {
            window.location.href = data.authorization_url;
            return;
        }
        showToast(data.error || 'Unable to start payment. Please try again.', 'error');
    } catch (err) {
        showToast('Network error while starting payment. Please try again.', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bx bx-credit-card"></i> Pay';
    }
}

loadPayments();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
