<?php
// Reports dashboard for admin/staff — revenue, occupancy, and maintenance stats, plus a date-range report.
require_once __DIR__ . '/config/database.php';
$pageTitle = 'Reports';
requireRole(['admin', 'staff']);

$db = getDB();

// Summary stats
$totalRevenue = $db->query("SELECT COALESCE(SUM(amount),0) FROM rent_payments")->fetchColumn();
$thisMonthRevenue = $db->query("SELECT COALESCE(SUM(amount),0) FROM rent_payments WHERE MONTH(payment_date)=MONTH(CURRENT_DATE()) AND YEAR(payment_date)=YEAR(CURRENT_DATE())")->fetchColumn();
$lastMonthRevenue = $db->query("SELECT COALESCE(SUM(amount),0) FROM rent_payments WHERE MONTH(payment_date)=MONTH(DATE_SUB(CURRENT_DATE(), INTERVAL 1 MONTH)) AND YEAR(payment_date)=YEAR(DATE_SUB(CURRENT_DATE(), INTERVAL 1 MONTH))")->fetchColumn();

$totalRooms = $db->query("SELECT COUNT(*) FROM rooms")->fetchColumn();
$occupiedRooms = $db->query("SELECT COUNT(*) FROM rooms WHERE status='occupied'")->fetchColumn();
$occupancyRate = $totalRooms > 0 ? round($occupiedRooms / $totalRooms * 100) : 0;

$totalTenants = $db->query("SELECT COUNT(*) FROM users WHERE role='tenant' AND is_active=1")->fetchColumn();
$totalMaintenance = $db->query("SELECT COUNT(*) FROM maintenance_requests")->fetchColumn();
$resolvedMaintenance = $db->query("SELECT COUNT(*) FROM maintenance_requests WHERE status='resolved' OR status='closed'")->fetchColumn();
$unpaidBills = $db->query("SELECT COALESCE(SUM(amount),0) FROM utility_bills WHERE status='unpaid'")->fetchColumn();

// Monthly revenue data for chart (last 6 months)
$monthlyData = $db->query("SELECT DATE_FORMAT(payment_date, '%Y-%m') as month, SUM(amount) as total FROM rent_payments GROUP BY month ORDER BY month DESC LIMIT 6")->fetchAll();
$monthlyData = array_reverse($monthlyData);

// Room type breakdown
$roomBreakdown = $db->query("SELECT room_type, COUNT(*) as count, SUM(CASE WHEN status='occupied' THEN 1 ELSE 0 END) as occupied FROM rooms GROUP BY room_type")->fetchAll();

// Maintenance breakdown
$maintBreakdown = $db->query("SELECT category, COUNT(*) as count FROM maintenance_requests GROUP BY category ORDER BY count DESC")->fetchAll();

// Top tenants by payment
$topTenants = $db->query("SELECT u.full_name, SUM(rp.amount) as total_paid FROM rent_payments rp JOIN users u ON rp.tenant_id = u.id GROUP BY rp.tenant_id ORDER BY total_paid DESC LIMIT 5")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<!-- Report Filters -->
<div class="filter-bar">
    <input type="date" id="reportDateFrom" class="form-control" style="max-width:160px;" placeholder="From">
    <input type="date" id="reportDateTo" class="form-control" style="max-width:160px;" placeholder="To">
    <button class="btn btn-primary" onclick="generateReport()">Generate Report</button>
    <button class="btn btn-secondary" onclick="printReport()"><i class='bx bx-printer'></i> Print Report</button>
</div>

<!-- Summary Cards -->
<div class="report-summary">
    <div class="report-box">
        <h5>Total Revenue</h5>
        <div class="value" style="color:var(--success);"><?= formatCurrency($totalRevenue) ?></div>
    </div>
    <div class="report-box">
        <h5>This Month</h5>
        <div class="value"><?= formatCurrency($thisMonthRevenue) ?></div>
    </div>
    <div class="report-box">
        <h5>Last Month</h5>
        <div class="value"><?= formatCurrency($lastMonthRevenue) ?></div>
    </div>
    <div class="report-box">
        <h5>Occupancy Rate</h5>
        <div class="value"><?= $occupancyRate ?>%</div>
    </div>
    <div class="report-box">
        <h5>Active Tenants</h5>
        <div class="value"><?= $totalTenants ?></div>
    </div>
    <div class="report-box">
        <h5>Unpaid Bills</h5>
        <div class="value" style="color:var(--danger);"><?= formatCurrency($unpaidBills) ?></div>
    </div>
</div>

<div class="grid-2">
    <!-- Monthly Revenue Chart (Simple CSS) -->
    <div class="card">
        <div class="card-header"><h3>Monthly Revenue</h3></div>
        <div style="display:flex;align-items:flex-end;gap:12px;height:200px;padding-top:10px;">
            <?php
            $maxRevenue = max(array_column($monthlyData, 'total') ?: [1]);
            foreach ($monthlyData as $m):
                $height = $maxRevenue > 0 ? round($m['total'] / $maxRevenue * 100) : 0;
            ?>
            <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:4px;">
                <span style="font-size:0.7rem;font-weight:600;color:var(--text);"><?= formatCurrency($m['total']) ?></span>
                <div style="width:100%;height:<?= $height ?>%;background:linear-gradient(180deg, var(--primary), var(--primary-light));border-radius:4px 4px 0 0;min-height:4px;transition:height 0.5s ease;"></div>
                <span style="font-size:0.7rem;color:var(--text-muted);"><?= date('M', strtotime($m['month'] . '-01')) ?></span>
            </div>
            <?php endforeach; ?>
            <?php if (empty($monthlyData)): ?>
            <div style="flex:1;text-align:center;color:var(--text-muted);font-size:0.85rem;">No data yet</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Room Type Breakdown -->
    <div class="card">
        <div class="card-header"><h3>Room Occupancy by Type</h3></div>
        <?php foreach ($roomBreakdown as $rb):
            $occRate = $rb['count'] > 0 ? round($rb['occupied'] / $rb['count'] * 100) : 0;
        ?>
        <div style="margin-bottom:16px;">
            <div style="display:flex;justify-content:space-between;margin-bottom:4px;font-size:0.88rem;">
                <span style="text-transform:capitalize;font-weight:600;"><?= sanitize($rb['room_type']) ?></span>
                <span class="text-muted"><?= $rb['occupied'] ?>/<?= $rb['count'] ?> occupied</span>
            </div>
            <div class="progress-bar">
                <div class="progress-bar-fill <?= $occRate >= 80 ? 'red' : ($occRate >= 50 ? 'orange' : 'green') ?>" style="width:<?= $occRate ?>%"></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Maintenance Breakdown -->
    <div class="card">
        <div class="card-header">
            <h3>Maintenance by Category</h3>
            <span class="text-muted" style="font-size:0.82rem;"><?= $resolvedMaintenance ?>/<?= $totalMaintenance ?> resolved</span>
        </div>
        <?php foreach ($maintBreakdown as $mb): ?>
        <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border-light);font-size:0.88rem;">
            <span style="text-transform:capitalize;"><?= sanitize(str_replace('_', ' ', $mb['category'])) ?></span>
            <span style="font-weight:600;"><?= $mb['count'] ?></span>
        </div>
        <?php endforeach; ?>
        <?php if (empty($maintBreakdown)): ?>
        <p class="text-center text-muted" style="padding:20px;">No maintenance data</p>
        <?php endif; ?>
    </div>

    <!-- Top Paying Tenants -->
    <div class="card">
        <div class="card-header"><h3>Top Tenants by Revenue</h3></div>
        <?php foreach ($topTenants as $i => $tt): ?>
        <div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--border-light);">
            <span style="width:28px;height:28px;border-radius:50%;background:var(--primary);color:#fff;display:flex;align-items:center;justify-content:center;font-size:0.78rem;font-weight:700;"><?= $i + 1 ?></span>
            <div style="flex:1;">
                <div style="font-weight:600;font-size:0.9rem;"><?= sanitize($tt['full_name']) ?></div>
            </div>
            <div style="font-weight:700;color:var(--success);"><?= formatCurrency($tt['total_paid']) ?></div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($topTenants)): ?>
        <p class="text-center text-muted" style="padding:20px;">No payment data</p>
        <?php endif; ?>
    </div>
</div>

<div class="card" id="reportResults" style="display:none;">
    <div class="card-header">
        <h3>Generated Report</h3>
        <span class="text-muted" id="reportRangeLabel" style="font-size:0.82rem;"></span>
    </div>
    <div class="report-summary" style="margin-bottom:16px;">
        <div class="report-box">
            <h5>Total Revenue</h5>
            <div class="value" style="color:var(--success);" id="repTotalRevenue">GH&#8373; 0.00</div>
        </div>
        <div class="report-box">
            <h5>Rent Payments</h5>
            <div class="value" id="repRentRevenue">GH&#8373; 0.00</div>
            <small class="text-muted" id="repRentCount"></small>
        </div>
        <div class="report-box">
            <h5>Utility Bills Paid</h5>
            <div class="value" id="repBillRevenue">GH&#8373; 0.00</div>
            <small class="text-muted" id="repBillCount"></small>
        </div>
    </div>
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Tenant</th>
                    <th>Residence</th>
                    <th>Reference</th>
                    <th>Method</th>
                    <th>Period</th>
                    <th>Amount</th>
                </tr>
            </thead>
            <tbody id="reportRows">
                <tr><td colspan="8" class="text-muted" style="text-align:center;padding:16px;">No transactions in the selected range.</td></tr>
            </tbody>
        </table>
    </div>
</div>

<script>
function generateReport() {
    const from = document.getElementById('reportDateFrom').value;
    const to = document.getElementById('reportDateTo').value;
    if (from && to && from > to) {
        showToast('Start date must be before end date.', 'error');
        return;
    }
    showToast('Generating report...', 'info');
    const q = new URLSearchParams();
    if (from) q.set('from', from);
    if (to) q.set('to', to);
    fetch('api/reports.php?' + q.toString(), { cache: 'no-store' })
        .then(r => r.json())
        .then(data => {
            if (data.error) {
                showToast(data.error, 'error');
                return;
            }
            const label = (data.from ? data.from : 'beginning') + ' to ' + (data.to ? data.to : 'today');
            document.getElementById('reportRangeLabel').textContent = 'Range: ' + label;
            document.getElementById('repTotalRevenue').textContent = 'GH\u20B5 ' + Number(data.totalRevenue).toFixed(2);
            document.getElementById('repRentRevenue').textContent = 'GH\u20B5 ' + Number(data.rentRevenue).toFixed(2);
            document.getElementById('repRentCount').textContent = data.rentCount + ' payment(s)';
            document.getElementById('repBillRevenue').textContent = 'GH\u20B5 ' + Number(data.billRevenue).toFixed(2);
            document.getElementById('repBillCount').textContent = data.billCount + ' bill(s)';

            const tbody = document.getElementById('reportRows');
            tbody.innerHTML = '';
            if (!data.rows.length) {
                tbody.innerHTML = '<tr><td colspan="8" class="text-muted" style="text-align:center;padding:16px;">No transactions in the selected range.</td></tr>';
            } else {
                data.rows.forEach(row => {
                    const tr = document.createElement('tr');
                    const methodLabel = row.method ? row.method.replace(/_/g, ' ') : '-';
                    tr.innerHTML =
                        '<td>' + esc(row.d) + '</td>' +
                        '<td><span class="badge" style="background:' + (row.kind === 'rent' ? 'var(--success)' : 'var(--accent)') + ';">' + row.kind + '</span></td>' +
                        '<td>' + esc(row.tenant) + '</td>' +
                        '<td>' + esc(row.room) + '</td>' +
                        '<td>' + esc(row.ref) + '</td>' +
                        '<td style="text-transform:capitalize;">' + esc(methodLabel) + '</td>' +
                        '<td>' + esc(row.note) + '</td>' +
                        '<td style="font-weight:700;color:var(--success);">GH\u20B5 ' + Number(row.amount).toFixed(2) + '</td>';
                    tbody.appendChild(tr);
                });
            }
            document.getElementById('reportResults').style.display = '';
            showToast('Report generated.', 'success');
        })
        .catch(() => showToast('Could not generate report.', 'error'));
}

function printReport() {
    window.print();
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
