<?php

require_once __DIR__ . '/../config.php';
requireRole(['admin']);

// ============================================================
// PAGE DATA
// ============================================================
$pageTitle = 'Admin Dashboard';

$stats = getDashboardStats($db);
$recentLogins = [];
try { $stmt = $db->query("SELECT al.*,u.email,u.role FROM audit_logs al LEFT JOIN users u ON al.user_id=u.id WHERE al.action='login' ORDER BY al.created_at DESC LIMIT 5"); $recentLogins = $stmt->fetchAll(); } catch (Exception $e) {}
$recentStudents = [];
try { $stmt = $db->query("SELECT * FROM students ORDER BY created_at DESC LIMIT 5"); $recentStudents = $stmt->fetchAll(); } catch (Exception $e) {}

$weeklyData = [];
$weekAgo = date('Y-m-d', strtotime('-6 days'));
try {
    $stmt = $db->prepare(
        "SELECT DATE(d) as day,
                SUM(CASE WHEN status='present' THEN 1 ELSE 0 END) as present,
                SUM(CASE WHEN status='absent' THEN 1 ELSE 0 END) as absent,
                SUM(CASE WHEN status='late' THEN 1 ELSE 0 END) as late
         FROM (
             SELECT date AS d, status FROM attendance WHERE date >= ?
             UNION ALL
             SELECT DATE(scan_time) AS d, status FROM attendance_records WHERE scan_time >= ?
         ) t GROUP BY DATE(d)"
    );
    $stmt->execute([$weekAgo, $weekAgo . ' 00:00:00']);
    $weeklyByDay = [];
    foreach ($stmt->fetchAll() as $row) {
        $weeklyByDay[$row['day']] = $row;
    }
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-{$i} days"));
        $row = $weeklyByDay[$date] ?? [];
        $weeklyData[] = [
            'day' => date('D', strtotime("-{$i} days")),
            'present' => (int)($row['present'] ?? 0),
            'absent' => (int)($row['absent'] ?? 0),
            'late' => (int)($row['late'] ?? 0)
        ];
    }
} catch (Exception $e) {
    $weeklyData = [];
}

$monthlyDailyData = [];
$daysAgo30 = date('Y-m-d', strtotime('-29 days'));
try {
    $stmt = $db->prepare(
        "SELECT DATE(d) as day,
                SUM(CASE WHEN status='present' THEN 1 ELSE 0 END) as present,
                SUM(CASE WHEN status='absent' THEN 1 ELSE 0 END) as absent,
                SUM(CASE WHEN status='late' THEN 1 ELSE 0 END) as late
         FROM (
             SELECT date AS d, status FROM attendance WHERE date >= ?
             UNION ALL
             SELECT DATE(scan_time) AS d, status FROM attendance_records WHERE scan_time >= ?
         ) t GROUP BY DATE(d)"
    );
    $stmt->execute([$daysAgo30, $daysAgo30 . ' 00:00:00']);
    $monthlyByDay = [];
    foreach ($stmt->fetchAll() as $row) {
        $monthlyByDay[$row['day']] = $row;
    }
    for ($i = 29; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-{$i} days"));
        $row = $monthlyByDay[$date] ?? [];
        $monthlyDailyData[] = [
            'day' => date('M j', strtotime("-{$i} days")),
            'present' => (int)($row['present'] ?? 0),
            'absent' => (int)($row['absent'] ?? 0),
            'late' => (int)($row['late'] ?? 0)
        ];
    }
} catch (Exception $e) {
    $monthlyDailyData = [];
}

$monthlyData = [];
$monthsAgo6 = date('Y-m-01', strtotime('-5 months'));
try {
    $stmt = $db->prepare(
        "SELECT DATE_FORMAT(d, '%Y-%m') as month,
                COUNT(*) as total,
                SUM(CASE WHEN status='present' THEN 1 ELSE 0 END) as present
         FROM (
             SELECT date AS d, status FROM attendance WHERE date >= ?
             UNION ALL
             SELECT scan_time AS d, status FROM attendance_records WHERE scan_time >= ?
         ) t GROUP BY DATE_FORMAT(d, '%Y-%m')"
    );
    $stmt->execute([$monthsAgo6, $monthsAgo6 . ' 00:00:00']);
    $monthlyByMonth = [];
    foreach ($stmt->fetchAll() as $row) {
        $monthlyByMonth[$row['month']] = $row;
    }
    for ($i = 5; $i >= 0; $i--) {
        $month = date('Y-m', strtotime("-{$i} months"));
        $row = $monthlyByMonth[$month] ?? [];
        $total = (int)($row['total'] ?? 0);
        $present = (int)($row['present'] ?? 0);
        $monthlyData[] = [
            'month' => date('M Y', strtotime("-{$i} months")),
            'rate' => $total > 0 ? round(($present / $total) * 100, 1) : 0,
            'total' => $total
        ];
    }
} catch (Exception $e) {
    $monthlyData = [];
}

$totalToday = $stats['present_today'] + $stats['absent_today'] + $stats['late_today'];
$attendanceRate = $totalToday > 0 ? round(($stats['present_today']/$totalToday)*100,1) : 0;

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<!-- Fonts -->

<!-- Stylesheets — pages-theme.css first (sidebar lives there), pages-navbar.css second (everything else) -->
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pages-theme.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pages-navbar.css">
<style>@media(max-width:767px){.mobile-title{display:block!important}}</style>

<!-- ═══ TOP NAVBAR ═══ -->
<?php require_once __DIR__ . '/../includes/pages-topnavbar.php'; ?>

<!-- NOTE: main-content has NO sidebar-collapsed class -->
<div class="main-content" id="mainContent">

    <!-- ═══ CONTENT ═══ -->
    <div class="content-area">
        <?= displayFlashMessage() ?>
        <div class="d-none d-md-flex justify-content-between align-items-center gap-3 mb-3">
            <div class="page-title mb-0">
                <h5 class="mb-0">Admin Dashboard</h5>
                <small>Welcome back, <strong><?= sanitize($_SESSION['user_email']) ?></strong></small>
            </div>
        </div>
        <div class="page-title mobile-title"><div class="mobile-title-inner"><div class="mobile-title-left"><h5>Admin Dashboard</h5><small>Welcome back, <?= sanitize($_SESSION['user_email']) ?></small></div></div></div>
        <!-- STAT CARDS -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div><div class="stat-value"><?= number_format($stats['total_students']) ?></div><div class="stat-label">Total Students</div></div>
                        <div class="stat-icon bg-primary-soft"><i class="bi bi-people-fill"></i></div>
                    </div>
                    <div class="stat-change positive"><i class="bi bi-arrow-up"></i> Active enrollment</div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div><div class="stat-value"><?= number_format($stats['total_teachers']) ?></div><div class="stat-label">Total Teachers</div></div>
                        <div class="stat-icon bg-success-soft"><i class="bi bi-person-badge-fill"></i></div>
                    </div>
                    <div class="stat-change positive"><i class="bi bi-check-circle"></i> Faculty members</div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div><div class="stat-value"><?= number_format($stats['total_subjects']) ?></div><div class="stat-label">Total Subjects</div></div>
                        <div class="stat-icon bg-warning-soft"><i class="bi bi-book-fill"></i></div>
                    </div>
                    <div class="stat-change positive"><i class="bi bi-collection"></i> All grade levels</div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div><div class="stat-value"><?= $attendanceRate ?>%</div><div class="stat-label">Attendance Rate</div></div>
                        <div class="stat-icon bg-info-soft"><i class="bi bi-graph-up-arrow"></i></div>
                    </div>
                    <div class="stat-change <?= $attendanceRate >= 80 ? 'positive' : 'negative' ?>">
                        <i class="bi bi-<?= $attendanceRate >= 80 ? 'arrow-up' : 'arrow-down' ?>"></i> Today
                    </div>
                </div>
            </div>
        </div>

        <!-- MINI STATS -->
        <div class="row g-3 mb-4">
            <div class="col-4"><div class="stat-card-mini"><div class="stat-value" style="color:#34D399;"><?= $stats['present_today'] ?></div><div class="stat-label"><i class="bi bi-check-circle-fill" style="color:#34D399;"></i> Present Today</div></div></div>
            <div class="col-4"><div class="stat-card-mini"><div class="stat-value" style="color:#F87171;"><?= $stats['absent_today'] ?></div><div class="stat-label"><i class="bi bi-x-circle-fill" style="color:#F87171;"></i> Absent Today</div></div></div>
            <div class="col-4"><div class="stat-card-mini"><div class="stat-value" style="color:#FBBF24;"><?= $stats['late_today'] ?></div><div class="stat-label"><i class="bi bi-clock-fill" style="color:#FBBF24;"></i> Late Today</div></div></div>
        </div>

        <!-- CHARTS -->
        <div class="row g-3 mb-4">
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header">
                        <span><i class="bi bi-bar-chart-fill" style="margin-right:2px"></i>Daily Attendance Overview</span>
                        <select class="form-select form-select-sm" id="chartPeriod" style="width:auto;padding:0.2rem 0.5rem;font-size:0.75rem;"><option value="week" selected>This Week</option><option value="month">This Month</option></select>
                    </div>
                    <div class="card-body"><div class="chart-wrapper chart-wrapper-lg"><canvas id="dailyAttendanceChart"></canvas></div></div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header" style="justify-content:flex-start;gap:2px;"><i class="bi bi-pie-chart-fill" style="margin-right:0"></i>Today's Attendance</div>
                    <div class="card-body d-flex align-items-center justify-content-center">
                        <div class="pie-chart-container">
                            <canvas id="attendancePieChart"></canvas>
                            <div class="pie-chart-overlay"><div class="pie-chart-value"><?= $attendanceRate ?>%</div><div class="pie-chart-label">Rate</div></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- MONTHLY TREND -->
        <div class="row g-3 mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header"><span><i class="bi bi-graph-up" style="margin-right:2px"></i>Monthly Attendance Trend</span><span style="font-size:12px;color:rgba(255,255,255,0.4);">Last 6 months</span></div>
                    <div class="card-body"><div class="chart-wrapper"><canvas id="monthlyChart"></canvas></div></div>
                </div>
            </div>
        </div>

        <!-- RECENT ACTIVITIES -->
        <div class="row g-3">
            <div class="col-lg-4">
                <div class="card h-100">
                    <div class="card-header" style="justify-content:flex-start;gap:2px;"><i class="bi bi-box-arrow-in-right" style="margin-right:0"></i>Recent Logins</div>
                    <div class="card-body p-0">
                        <?php if (empty($recentLogins)): ?>
                            <div class="empty-state"><div class="empty-icon"><i class="bi bi-person-check"></i></div><h6>No Recent Logins</h6><p>Login activity will appear here</p></div>
                        <?php else: ?>
                            <ul class="activity-list px-3">
                                <?php foreach ($recentLogins as $login): ?>
                                    <li class="activity-item">
                                        <div class="activity-icon bg-secondary-soft"><i class="bi bi-person"></i></div>
                                        <div>
                                            <div class="activity-text"><strong><?= sanitize($login['email'] ?? 'Unknown') ?></strong></div>
                                            <div class="activity-time"><?= formatDateTime($login['created_at']) ?> <span class="badge-role role-<?= $login['role'] ?? 'admin' ?> ms-1"><?= ucfirst($login['role'] ?? '') ?></span></div>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card h-100">
                    <div class="card-header" style="justify-content:flex-start;gap:2px;"><i class="bi bi-calendar-check" style="margin-right:0"></i>Recent Sessions</div>
                    <div class="card-body p-0">
                        <?php $recentSessions = []; try { $stmt = $db->query("SELECT s.*,sub.subject_name,u.email FROM attendance_sessions s LEFT JOIN subjects sub ON s.subject_id=sub.id LEFT JOIN users u ON s.created_by=u.id ORDER BY s.created_at DESC LIMIT 5"); $recentSessions = $stmt->fetchAll(); } catch (Exception $e) {} ?>
                        <?php if (empty($recentSessions)): ?>
                            <div class="empty-state"><div class="empty-icon"><i class="bi bi-camera-video"></i></div><h6>No Sessions Yet</h6><p>Attendance sessions will appear here</p></div>
                        <?php else: ?>
                            <ul class="activity-list px-3">
                                <?php foreach ($recentSessions as $session): ?>
                                    <li class="activity-item">
                                        <div class="activity-icon <?= $session['status']==='active' ? 'bg-success-soft' : 'bg-info-soft' ?>"><i class="bi bi-<?= $session['session_type']==='gate' ? 'door-open' : 'book' ?>"></i></div>
                                        <div>
                                            <div class="activity-text"><strong><?= sanitize($session['subject_name'] ?? ucfirst($session['session_type'])) ?></strong></div>
                                            <div class="activity-time"><?= formatDateTime($session['created_at']) ?> <span class="badge-status badge-<?= $session['status']==='active' ? 'active' : 'inactive' ?> ms-1"><?= ucfirst($session['status']) ?></span></div>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card h-100">
                    <div class="card-header" style="justify-content:flex-start;gap:2px;"><i class="bi bi-person-plus" style="margin-right:0"></i>New Students</div>
                    <div class="card-body p-0">
                        <?php if (empty($recentStudents)): ?>
                            <div class="empty-state"><div class="empty-icon"><i class="bi bi-people"></i></div><h6>No Students Yet</h6><p>Recently added students will appear here</p></div>
                        <?php else: ?>
                            <ul class="activity-list px-3">
                                <?php foreach ($recentStudents as $student): ?>
                                    <li class="activity-item">
                                        <div class="activity-icon bg-primary-soft"><i class="bi bi-person"></i></div>
                                        <div>
                                            <div class="activity-text"><strong><?= sanitize($student['first_name'] . ' ' . $student['last_name']) ?></strong></div>
                                            <div class="activity-time">Grade <?= $student['grade_level'] ?> - <?= sanitize($student['section'] ?? 'N/A') ?><br><small><?= formatDate($student['created_at'], 'M j, Y g:i A') ?></small></div>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>


<!-- Chart.js -->
<script>
(function(){'use strict';
var weeklyData=<?=json_encode($weeklyData,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>,
    monthlyData=<?=json_encode($monthlyData,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>,
    monthlyDailyData=<?=json_encode($monthlyDailyData,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>,
    presentToday=<?=(int)$stats['present_today']?>,
    absentToday=<?=(int)$stats['absent_today']?>,
    lateToday=<?=(int)$stats['late_today']?>;

var dailyChartInstance=null;
function buildDailyChart(labels,present,absent,late){
    var ctx=document.getElementById('dailyAttendanceChart');
    if(!ctx)return;
    if(dailyChartInstance){dailyChartInstance.data.labels=labels;dailyChartInstance.data.datasets[0].data=present;dailyChartInstance.data.datasets[1].data=absent;dailyChartInstance.data.datasets[2].data=late;dailyChartInstance.update();return;}
    dailyChartInstance=new Chart(ctx.getContext('2d'),{type:'bar',data:{labels:labels,datasets:[{label:'Present',data:present,backgroundColor:'rgba(52,211,153,0.85)',borderRadius:6,barPercentage:0.6},{label:'Absent',data:absent,backgroundColor:'rgba(248,113,113,0.85)',borderRadius:6,barPercentage:0.6},{label:'Late',data:late,backgroundColor:'rgba(251,191,36,0.85)',borderRadius:6,barPercentage:0.6}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'top',labels:{usePointStyle:true,padding:20,font:{size:12},color:'rgba(255,255,255,0.7)'}}},scales:{y:{beginAtZero:true,grid:{color:'rgba(255,255,255,0.06)'},ticks:{font:{size:11},color:'rgba(255,255,255,0.4)'}},x:{grid:{display:false},ticks:{font:{size:11},color:'rgba(255,255,255,0.4)'}}}}});
}
buildDailyChart(weeklyData.map(function(d){return d.day}),weeklyData.map(function(d){return d.present}),weeklyData.map(function(d){return d.absent}),weeklyData.map(function(d){return d.late}));

var chartPeriod=document.getElementById('chartPeriod');
if(chartPeriod){
    chartPeriod.addEventListener('change',function(){
        if(this.value==='month'){buildDailyChart(monthlyDailyData.map(function(d){return d.day}),monthlyDailyData.map(function(d){return d.present}),monthlyDailyData.map(function(d){return d.absent}),monthlyDailyData.map(function(d){return d.late}));}
        else{buildDailyChart(weeklyData.map(function(d){return d.day}),weeklyData.map(function(d){return d.present}),weeklyData.map(function(d){return d.absent}),weeklyData.map(function(d){return d.late}));}
    });
}

var pc=document.getElementById('attendancePieChart');
if(pc) new Chart(pc.getContext('2d'),{type:'doughnut',data:{labels:['Present','Absent','Late'],datasets:[{data:[presentToday,absentToday,lateToday],backgroundColor:['rgba(52,211,153,0.85)','rgba(248,113,113,0.85)','rgba(251,191,36,0.85)'],borderWidth:0,spacing:2}]},options:{responsive:true,maintainAspectRatio:true,cutout:'72%',plugins:{legend:{position:'bottom',labels:{usePointStyle:true,padding:16,font:{size:11},color:'rgba(255,255,255,0.6)'}}}}});

var mc=document.getElementById('monthlyChart');
if(mc){var ctx=mc.getContext('2d'),grad=ctx.createLinearGradient(0,0,0,200);grad.addColorStop(0,'rgba(96,165,250,0.20)');grad.addColorStop(1,'rgba(96,165,250,0.0)');new Chart(ctx,{type:'line',data:{labels:monthlyData.map(function(d){return d.month}),datasets:[{label:'Attendance Rate (%)',data:monthlyData.map(function(d){return d.rate}),borderColor:'#60A5FA',backgroundColor:grad,fill:true,tension:0.4,pointBackgroundColor:'#60A5FA',pointBorderColor:'rgba(10,34,76,0.8)',pointBorderWidth:2,pointRadius:5,pointHoverRadius:7,borderWidth:2.5}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'top',labels:{usePointStyle:true,padding:20,font:{size:12},color:'rgba(255,255,255,0.7)'}}},scales:{y:{beginAtZero:true,max:100,grid:{color:'rgba(255,255,255,0.06)'},ticks:{font:{size:11},color:'rgba(255,255,255,0.4)',callback:function(v){return v+'%'}}},x:{grid:{display:false},ticks:{font:{size:11},color:'rgba(255,255,255,0.4)'}}}}})}
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>