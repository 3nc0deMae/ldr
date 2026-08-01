<?php

require_once __DIR__ . '/../config.php';
requireRole(['admin']);

// ============================================================
// AJAX HANDLER — runs before any HTML output
// ============================================================
try {
    $db->exec("CREATE TABLE IF NOT EXISTS calendar_events (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        description TEXT,
        event_date DATE NOT NULL,
        event_time TIME DEFAULT NULL,
        event_type ENUM('meeting','deadline','reminder','general') DEFAULT 'general',
        created_by INT DEFAULT NULL,
        is_completed TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_event_date (event_date),
        INDEX idx_created_by (created_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
    error_log('calendar_events table: ' . $e->getMessage());
}

if (isset($_POST['ajax_action']) || isset($_GET['ajax_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['ajax_action'] ?? $_GET['ajax_action'] ?? '';
    switch ($action) {
        case 'create_event':
            $title       = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $eventDate   = trim($_POST['event_date'] ?? '');
            $eventTime   = !empty(trim($_POST['event_time'] ?? '')) ? trim($_POST['event_time']) : null;
            $eventType   = trim($_POST['event_type'] ?? 'general');
            if (empty($title) || empty($eventDate)) { echo json_encode(['success'=>false,'message'=>'Title and date required.']); exit; }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) { echo json_encode(['success'=>false,'message'=>'Invalid date.']); exit; }
            if (!empty($eventTime) && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $eventTime)) $eventTime = null;
            $validTypes = ['meeting','deadline','reminder','general'];
            if (!in_array($eventType, $validTypes)) $eventType = 'general';
            try {
                $stmt = $db->prepare("INSERT INTO calendar_events (title,description,event_date,event_time,event_type,created_by) VALUES (?,?,?,?,?,?)");
                $stmt->execute([$title,$description,$eventDate,$eventTime,$eventType,$_SESSION['user_id']??null]);
                echo json_encode(['success'=>true,'message'=>'Event created.','event'=>['id'=>(int)$db->lastInsertId(),'title'=>$title,'description'=>$description,'event_date'=>$eventDate,'event_time'=>$eventTime,'event_type'=>$eventType,'is_completed'=>0]]);
            } catch (Exception $e) { error_log('create_event: '.$e->getMessage()); echo json_encode(['success'=>false,'message'=>'Database error.']); }
            exit;
        case 'delete_event':
            $eventId = (int)($_POST['event_id'] ?? 0);
            if ($eventId <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid ID.']); exit; }
            try { $db->prepare("DELETE FROM calendar_events WHERE id=?")->execute([$eventId]); echo json_encode(['success'=>true,'message'=>'Deleted.']); }
            catch (Exception $e) { echo json_encode(['success'=>false,'message'=>'Error.']); }
            exit;
        case 'toggle_complete':
            $eventId = (int)($_POST['event_id'] ?? 0);
            if ($eventId <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid ID.']); exit; }
            try { $db->prepare("UPDATE calendar_events SET is_completed=NOT is_completed WHERE id=?")->execute([$eventId]); echo json_encode(['success'=>true,'message'=>'Updated.']); }
            catch (Exception $e) { echo json_encode(['success'=>false,'message'=>'Error.']); }
            exit;
        case 'get_upcoming':
            try { $stmt = $db->prepare("SELECT * FROM calendar_events WHERE event_date >= CURDATE() AND event_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND is_completed = 0 ORDER BY event_date ASC, event_time IS NULL, event_time ASC LIMIT 20"); $stmt->execute(); echo json_encode(['success'=>true,'events'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]); }
            catch (Exception $e) { echo json_encode(['success'=>false,'events'=>[]]); }
            exit;
        default: echo json_encode(['success'=>false,'message'=>'Unknown action.']); exit;
    }
}

// ============================================================
// PAGE DATA
// ============================================================
$pageTitle = 'My Calendar';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

$calendarEvents = []; $calendarSessions = []; $adminEvents = [];
for ($m = -2; $m <= 2; $m++) {
    $monthKey = date('Y-m', strtotime("{$m} months"));
    try { $stmt = $db->prepare("SELECT DATE(scan_time) as event_date, SUM(CASE WHEN status='present' THEN 1 ELSE 0 END) as present, SUM(CASE WHEN status='absent' THEN 1 ELSE 0 END) as absent, SUM(CASE WHEN status='late' THEN 1 ELSE 0 END) as late, COUNT(*) as total FROM attendance_records WHERE DATE_FORMAT(scan_time,'%Y-%m')=? GROUP BY DATE(scan_time)"); $stmt->execute([$monthKey]); foreach ($stmt->fetchAll() as $row) { $d=$row['event_date']; if(!isset($calendarEvents[$d]))$calendarEvents[$d]=['present'=>0,'absent'=>0,'late'=>0,'total'=>0]; $calendarEvents[$d]['present']+=(int)$row['present']; $calendarEvents[$d]['absent']+=(int)$row['absent']; $calendarEvents[$d]['late']+=(int)$row['late']; $calendarEvents[$d]['total']+=(int)$row['total']; } } catch (Exception $e) {}
    try { $stmt = $db->prepare("SELECT date as event_date, SUM(CASE WHEN status='present' THEN 1 ELSE 0 END) as present, SUM(CASE WHEN status='absent' THEN 1 ELSE 0 END) as absent, SUM(CASE WHEN status='late' THEN 1 ELSE 0 END) as late, COUNT(*) as total FROM attendance WHERE DATE_FORMAT(date,'%Y-%m')=? GROUP BY date"); $stmt->execute([$monthKey]); foreach ($stmt->fetchAll() as $row) { $d=$row['event_date']; if(!isset($calendarEvents[$d]))$calendarEvents[$d]=['present'=>0,'absent'=>0,'late'=>0,'total'=>0]; $calendarEvents[$d]['present']+=(int)$row['present']; $calendarEvents[$d]['absent']+=(int)$row['absent']; $calendarEvents[$d]['late']+=(int)$row['late']; $calendarEvents[$d]['total']+=(int)$row['total']; } } catch (Exception $e) {}
    try { $stmt = $db->prepare("SELECT s.*,sub.subject_name,u.email FROM attendance_sessions s LEFT JOIN subjects sub ON s.subject_id=sub.id LEFT JOIN users u ON s.created_by=u.id WHERE DATE_FORMAT(s.created_at,'%Y-%m')=? ORDER BY s.created_at ASC"); $stmt->execute([$monthKey]); foreach ($stmt->fetchAll() as $row) { $d=date('Y-m-d',strtotime($row['created_at'])); if(!isset($calendarSessions[$d]))$calendarSessions[$d]=[]; $calendarSessions[$d][]=['subject'=>$row['subject_name']??ucfirst($row['session_type']??'Session'),'time'=>date('g:i A',strtotime($row['created_at'])),'type'=>$row['session_type']??'manual','status'=>$row['status']??'completed']; } } catch (Exception $e) {}
    try { $stmt = $db->prepare("SELECT * FROM calendar_events WHERE DATE_FORMAT(event_date,'%Y-%m')=? ORDER BY event_time IS NULL, event_time ASC, created_at ASC"); $stmt->execute([$monthKey]); foreach ($stmt->fetchAll() as $row) { $d=$row['event_date']; if(!isset($adminEvents[$d]))$adminEvents[$d]=[]; $adminEvents[$d][]=['id'=>(int)$row['id'],'title'=>$row['title'],'description'=>$row['description'],'event_time'=>$row['event_time'],'event_type'=>$row['event_type'],'is_completed'=>(int)$row['is_completed']]; } } catch (Exception $e) {}
}

$upcomingEvents = [];
try { $stmt = $db->prepare("SELECT * FROM calendar_events WHERE event_date >= CURDATE() AND event_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND is_completed = 0 ORDER BY event_date ASC, event_time IS NULL, event_time ASC LIMIT 20"); $stmt->execute(); $upcomingEvents = $stmt->fetchAll(); } catch (Exception $e) {}
?>

<!-- Fonts -->

<!-- Stylesheets — pages-theme.css first (sidebar lives there), pages-navbar.css second (everything else) -->
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pages-theme.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pages-navbar.css">
<style>@media(max-width:767px){.mobile-title{display:block!important}}</style>

<!-- ═══ CONTENT ═══ -->
<?php require_once __DIR__ . '/../includes/pages-topnavbar.php'; ?>

<!-- NOTE: main-content has NO sidebar-collapsed class -->
<div class="main-content" id="mainContent">
    <div class="content-area">
        <?= displayFlashMessage() ?>

        <div class="d-none d-md-flex justify-content-between align-items-center gap-3 mb-3">
            <div class="page-title mb-0">
                <h5 class="mb-0">My Calendar</h5>
                <small>View attendance, sessions, and events</small>
            </div>
        </div>
        <div class="page-title mobile-title"><div class="mobile-title-inner"><div class="mobile-title-left"><h5>My Calendar</h5><small>View attendance, sessions, and events</small></div></div></div>

        <!-- CALENDAR -->
        <div class="row g-3 mb-4">
            <div class="col-lg-7">
                <div class="card calendar-card">
                    <div class="card-header calendar-card-header p-3">
                        <div class="cal-header-left"><i class="bi bi-calendar3-event me-2"></i><span class="cal-title">Attendance Calendar</span></div>
                        <div class="cal-header-right">
                            <button class="cal-nav-btn" id="calPrev"><i class="bi bi-chevron-left"></i></button>
                            <span class="cal-month-label" id="calMonthLabel"></span>
                            <button class="cal-nav-btn" id="calNext"><i class="bi bi-chevron-right"></i></button>
                            <button class="cal-today-btn" id="calToday">Today</button>
                        </div>
                    </div>
                    <div class="card-body p-2 p-md-3">
                        <div class="cal-weekdays"><div class="cal-weekday">Sun</div><div class="cal-weekday">Mon</div><div class="cal-weekday">Tue</div><div class="cal-weekday">Wed</div><div class="cal-weekday">Thu</div><div class="cal-weekday">Fri</div><div class="cal-weekday">Sat</div></div>
                        <div class="cal-grid" id="calGrid"></div>
                        <div class="cal-legend">
                            <div class="cal-legend-item"><span class="cal-legend-dot" style="background:#34D399;"></span> Present</div>
                            <div class="cal-legend-item"><span class="cal-legend-dot" style="background:#F87171;"></span> Absent</div>
                            <div class="cal-legend-item"><span class="cal-legend-dot" style="background:#FBBF24;"></span> Late</div>
                            <div class="cal-legend-item"><span class="cal-legend-dot cal-legend-dot-event"></span> Event</div>
                            <div class="cal-legend-item"><span class="cal-legend-dot cal-legend-dot-today"></span> Today</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card detail-card" id="detailCard">
                    <div class="detail-placeholder" id="detailPlaceholder">
                        <div class="detail-placeholder-icon"><i class="bi bi-calendar-check"></i></div>
                        <h6>Select a Date</h6>
                        <p>Click on any day to view attendance details, sessions, and events.</p>
                    </div>
                    <div class="detail-content" id="detailContent" style="display:none;">
                        <div class="detail-header">
                            <div class="detail-date-wrapper">
                                <div class="detail-day-num" id="detailDayNum">19</div>
                                <div><div class="detail-day-name" id="detailDayName">Friday</div><div class="detail-month-name" id="detailMonthName">June 2026</div></div>
                            </div>
                            <button class="cal-nav-btn detail-close-btn" id="detailClose"><i class="bi bi-x-lg"></i></button>
                        </div>
                        <div class="detail-section">
                            <div class="detail-section-title"><i class="bi bi-bar-chart-steps"></i> Attendance Breakdown</div>
                            <div class="detail-stats-row">
                                <div class="detail-stat-box detail-stat-present"><div class="detail-stat-num" id="detailPresent">0</div><div class="detail-stat-lbl">Present</div></div>
                                <div class="detail-stat-box detail-stat-absent"><div class="detail-stat-num" id="detailAbsent">0</div><div class="detail-stat-lbl">Absent</div></div>
                                <div class="detail-stat-box detail-stat-late"><div class="detail-stat-num" id="detailLate">0</div><div class="detail-stat-lbl">Late</div></div>
                                <div class="detail-stat-box detail-stat-total"><div class="detail-stat-num" id="detailTotal">0</div><div class="detail-stat-lbl">Total</div></div>
                            </div>
                            <div class="detail-bar-container">
                                <div class="detail-bar"><div class="detail-bar-segment detail-bar-present" id="barPresent"></div><div class="detail-bar-segment detail-bar-late" id="barLate"></div><div class="detail-bar-segment detail-bar-absent" id="barAbsent"></div></div>
                                <div class="detail-bar-labels"><span id="barLabelPresent">0%</span><span id="barLabelLate">0%</span><span id="barLabelAbsent">0%</span></div>
                            </div>
                            <div class="detail-rate-wrapper"><span class="detail-rate-badge" id="detailRateBadge"><i class="bi bi-graph-up-arrow"></i> <span id="detailRateText">0%</span> attendance rate</span></div>
                        </div>
                        <div class="detail-section">
                            <div class="detail-section-title"><i class="bi bi-camera-video"></i> Sessions</div>
                            <div class="detail-sessions" id="detailSessions"><div class="detail-no-sessions"><i class="bi bi-inbox"></i><span>No sessions recorded</span></div></div>
                        </div>
                        <div class="detail-section detail-events-section">
                            <div class="detail-section-title"><i class="bi bi-calendar-event"></i> Events</div>
                            <div class="detail-events-list" id="detailEvents"><div class="detail-no-events"><i class="bi bi-inbox"></i><span>No events on this day</span></div></div>
                            <button class="btn-add-event" id="btnAddEvent"><i class="bi bi-plus-circle me-1"></i> Add Event</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- MODAL -->
<div class="event-modal-overlay" id="eventModalOverlay">
    <div class="event-modal" id="eventModal">
        <div class="event-modal-header">
            <div class="event-modal-title"><i class="bi bi-calendar-plus"></i><span>Add New Event</span></div>
            <button class="event-modal-close" id="eventModalClose"><i class="bi bi-x-lg"></i></button>
        </div>
        <form id="eventForm" autocomplete="off">
            <div class="event-modal-body">
                <div class="evt-field"><label for="evtTitle">Event Title <span class="required">*</span></label><input type="text" id="evtTitle" name="title" placeholder="e.g., Staff Meeting, Exam Week..." required maxlength="255"></div>
                <div class="evt-field"><label for="evtDescription">Description</label><textarea id="evtDescription" name="description" placeholder="Optional details..." rows="3" maxlength="1000"></textarea></div>
                <div class="evt-row">
                    <div class="evt-field evt-flex-1"><label for="evtDate">Date <span class="required">*</span></label><input type="date" id="evtDate" name="event_date" required></div>
                    <div class="evt-field evt-flex-1"><label for="evtTime">Time</label><input type="time" id="evtTime" name="event_time"></div>
                </div>
                <div class="evt-field">
                    <label>Event Type</label>
                    <div class="evt-type-selector" id="evtTypeSelector">
                        <button type="button" class="evt-type-btn active" data-type="general"><i class="bi bi-circle-fill evt-type-dot"></i> General</button>
                        <button type="button" class="evt-type-btn" data-type="meeting"><i class="bi bi-circle-fill evt-type-dot"></i> Meeting</button>
                        <button type="button" class="evt-type-btn" data-type="deadline"><i class="bi bi-circle-fill evt-type-dot"></i> Deadline</button>
                        <button type="button" class="evt-type-btn" data-type="reminder"><i class="bi bi-circle-fill evt-type-dot"></i> Reminder</button>
                    </div>
                    <input type="hidden" id="evtType" name="event_type" value="general">
                </div>
            </div>
            <div class="event-modal-footer">
                <button type="button" class="evt-btn evt-btn-cancel" id="evtCancel">Cancel</button>
                <button type="submit" class="evt-btn evt-btn-save" id="evtSave"><i class="bi bi-check-lg me-1"></i> Save Event</button>
            </div>
        </form>
    </div>
</div>

<div class="toast-container" id="toastContainer"></div>

<!-- Sidebar toggle + Calendar + Events + Notifications -->
<script>
(function(){'use strict';

// ═══ CALENDAR DATA ═══
var calEv=<?=json_encode($calendarEvents,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>,
    calSe=<?=json_encode($calendarSessions,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>,
    admEv=<?=json_encode($adminEvents,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;

var grid=document.getElementById('calGrid'),mLabel=document.getElementById('calMonthLabel'),
    bP=document.getElementById('calPrev'),bN=document.getElementById('calNext'),bT=document.getElementById('calToday'),
    dP=document.getElementById('detailPlaceholder'),dC=document.getElementById('detailContent'),dCl=document.getElementById('detailClose');
var vY,vM,selD=null,today=new Date(),tStr=fmt(today),AJ=window.location.pathname;

function init(){ vY=today.getFullYear(); vM=today.getMonth(); render(); bind(); checkToday(); initSwipe(); initDrag(); }
function bind(){
    bP.addEventListener('click',function(){ vM--; if(vM<0){vM=11;vY--} render(); });
    bN.addEventListener('click',function(){ vM++; if(vM>11){vM=0;vY++} render(); });
    bT.addEventListener('click',function(){ vY=today.getFullYear(); vM=today.getMonth(); render(); });
    dCl.addEventListener('click',function(){ selD=null; dP.style.display=''; dC.style.display='none'; render(); });
}
function render(){
    var ms=['January','February','March','April','May','June','July','August','September','October','November','December'];
    mLabel.textContent=ms[vM]+' '+vY;
    grid.innerHTML='';
    var f=new Date(vY,vM,1).getDay(),dm=new Date(vY,vM+1,0).getDate(),dp=new Date(vY,vM,0).getDate();
    for(var i=f-1;i>=0;i--) grid.appendChild(cell(new Date(vY,vM-1,dp-i),dp-i,true));
    for(var d=1;d<=dm;d++) grid.appendChild(cell(new Date(vY,vM,d),d,false));
    var r=grid.children.length%7===0?0:7-(grid.children.length%7);
    for(var d2=1;d2<=r;d2++) grid.appendChild(cell(new Date(vY,vM+1,d2),d2,true));
}
function cell(dObj,dn,isO){
    var el=document.createElement('div'); el.className='cal-day';
    var ds=fmt(dObj);
    if(isO) el.classList.add('other-month');
    if(ds===tStr) el.classList.add('today');
    if(ds===selD) el.classList.add('selected');
    var n=document.createElement('span'); n.className='cal-day-num'; n.textContent=dn; el.appendChild(n);
    var ev=calEv[ds],se=calSe[ds];
    if(ev||se){
        el.classList.add('has-data');
        var dots=document.createElement('div'); dots.className='event-dots';
        if(ev){
            if(ev.present/(ev.total||1)>=0.8&&ev.present>0) dots.appendChild(mkDot('dot-good'));
            if(ev.absent>0) dots.appendChild(mkDot('dot-poor'));
            if(ev.late>0) dots.appendChild(mkDot('dot-late'));
        }
        if(se&&se.length>0) dots.appendChild(mkDot('dot-session'));
        el.appendChild(dots);
    }
    var aev=admEv[ds];
    if(aev&&aev.length>0&&!isO){
        el.classList.add('has-event');
        var ind=document.createElement('div'); ind.className='cal-event-indicator';
        aev.slice(0,3).forEach(function(e){ var p=document.createElement('span'); p.className='evt-pip type-'+e.event_type; ind.appendChild(p); });
        el.appendChild(ind);
    }
    el.addEventListener('click',function(){
        selDay(ds,dObj);
        if(window.innerWidth<=767){ var dc=document.getElementById('detailCard'); if(dc) setTimeout(function(){ dc.scrollIntoView({behavior:'smooth',block:'nearest'}); },120); }
    });
    return el;
}
function mkDot(c){ var d=document.createElement('span'); d.className='event-dot '+c; return d; }
function selDay(ds,dObj){
    selD=ds; render();
    dP.style.display='none'; dC.style.display='block';
    var dn=['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'],
        mn=['January','February','March','April','May','June','July','August','September','October','November','December'];
    document.getElementById('detailDayNum').textContent=dObj.getDate();
    document.getElementById('detailDayName').textContent=dn[dObj.getDay()];
    document.getElementById('detailMonthName').textContent=mn[dObj.getMonth()]+' '+dObj.getFullYear();
    var a=calEv[ds]||{present:0,absent:0,late:0,total:0},t=a.total||0,
        pp=t>0?Math.round(a.present/t*100):0,ap=t>0?Math.round(a.absent/t*100):0,lp=t>0?Math.round(a.late/t*100):0;
    animN('detailPresent',a.present); animN('detailAbsent',a.absent); animN('detailLate',a.late); animN('detailTotal',t);
    document.getElementById('barPresent').style.width=pp+'%';
    document.getElementById('barLate').style.width=lp+'%';
    document.getElementById('barAbsent').style.width=ap+'%';
    document.getElementById('barLabelPresent').textContent=pp+'%';
    document.getElementById('barLabelLate').textContent=lp+'%';
    document.getElementById('barLabelAbsent').textContent=ap+'%';
    var bg=document.getElementById('detailRateBadge'),rt=document.getElementById('detailRateText');
    bg.className='detail-rate-badge';
    if(t===0){bg.classList.add('rate-none');rt.textContent='No data';}
    else if(pp>=80){bg.classList.add('rate-good');rt.textContent=pp+'%';}
    else if(pp>=60){bg.classList.add('rate-ok');rt.textContent=pp+'%';}
    else{bg.classList.add('rate-poor');rt.textContent=pp+'%';}
    var ss=calSe[ds]||[],sc=document.getElementById('detailSessions');
    if(!ss.length){sc.innerHTML='<div class="detail-no-sessions"><i class="bi bi-inbox"></i><span>No sessions recorded</span></div>';}
    else{sc.innerHTML='';ss.forEach(function(s,i){var ic=s.type==='gate'?'door-open':'book',cl=s.type==='gate'?'session-gate':'session-subject',st=s.status==='active'?'active':'completed',it=document.createElement('div');it.className='session-item';if(window.innerWidth>767)it.style.animationDelay=(i*0.08)+'s';else{it.style.animation='none';it.style.opacity='1'}it.innerHTML='<div class="session-icon '+cl+'"><i class="bi bi-'+ic+'"></i></div><div class="session-info"><div class="session-name">'+esc(s.subject)+'</div><div class="session-meta"><span><i class="bi bi-clock me-1"></i>'+esc(s.time)+'</span></div></div><span class="session-status '+st+'">'+esc(s.status)+'</span>';sc.appendChild(it);});}
    renderEv(ds);
}
function renderEv(ds){
    var c=document.getElementById('detailEvents'),ev=admEv[ds]||[];
    if(!ev.length){c.innerHTML='<div class="detail-no-events"><i class="bi bi-inbox"></i><span>No events on this day</span></div>';return;}
    c.innerHTML='';
    ev.forEach(function(e,i){
        var it=document.createElement('div');it.className='detail-event-item'+(e.is_completed?' completed':'');
        if(window.innerWidth>767)it.style.animationDelay=(i*0.08)+'s';else{it.style.animation='none';it.style.opacity='1'}
        var ts=e.event_time?formatT(e.event_time):'All day',ds2=e.description?esc(e.description):'';
        it.innerHTML='<div class="detail-event-bar type-'+e.event_type+'"></div><div class="detail-event-body"><div class="detail-event-title">'+esc(e.title)+'</div>'+(ds2?'<div class="detail-event-desc" title="'+ds2+'">'+ds2+'</div>':'')+'<div class="detail-event-meta"><span><i class="bi bi-clock me-1"></i>'+ts+'</span><span class="detail-event-tag tag-'+e.event_type+'">'+cap(e.event_type)+'</span></div></div><div class="detail-event-actions"><button class="evt-action-btn complete-btn '+(e.is_completed?'is-completed':'')+'" data-id="'+e.id+'"><i class="bi bi-check-circle'+(e.is_completed?'-fill':'')+'"></i></button><button class="evt-action-btn delete-btn" data-id="'+e.id+'"><i class="bi bi-trash3"></i></button></div>';
        c.appendChild(it);
    });
    c.querySelectorAll('.delete-btn').forEach(function(b){b.addEventListener('click',function(ev){ev.stopPropagation();delE(parseInt(b.dataset.id));});});
    c.querySelectorAll('.complete-btn').forEach(function(b){b.addEventListener('click',function(ev){ev.stopPropagation();togC(parseInt(b.dataset.id));});});
}
function initSwipe(){
    var cb=grid.parentElement,sx=0,sy=0,th=50;
    cb.addEventListener('touchstart',function(e){sx=e.touches[0].clientX;sy=e.touches[0].clientY;},{passive:true});
    cb.addEventListener('touchend',function(e){var dx=e.changedTouches[0].clientX-sx,dy=Math.abs(e.changedTouches[0].clientY-sy);if(Math.abs(dx)>th&&dy<Math.abs(dx)){if(dx<0){vM++;if(vM>11){vM=0;vY++}}else{vM--;if(vM<0){vM=11;vY--}}render();}},{passive:true});
}
function initDrag(){
    var md=document.getElementById('eventModal'),ov=document.getElementById('eventModalOverlay');
    if(!md||!ov)return;var sy=0,cy=0,dr=false;
    md.addEventListener('touchstart',function(e){if(e.touches[0].clientY-md.getBoundingClientRect().top>40)return;sy=e.touches[0].clientY;dr=true;md.style.transition='none';},{passive:true});
    md.addEventListener('touchmove',function(e){if(!dr)return;cy=e.touches[0].clientY-sy;if(cy<0)cy=0;md.style.transform='translateY('+cy+'px)';ov.style.opacity=1-(cy/400);},{passive:true});
    md.addEventListener('touchend',function(){if(!dr)return;dr=false;md.style.transition='';ov.style.transition='';if(cy>120)closeM();else{md.style.transform='';ov.style.opacity='';}cy=0;},{passive:true});
}

var mO=document.getElementById('eventModalOverlay'),mC=document.getElementById('eventModalClose'),
    eC=document.getElementById('evtCancel'),eF=document.getElementById('eventForm'),
    bA=document.getElementById('btnAddEvent'),tS=document.getElementById('evtTypeSelector');
bA.addEventListener('click',function(){if(!selD)return;openM(selD);});
mC.addEventListener('click',closeM);eC.addEventListener('click',closeM);
mO.addEventListener('click',function(e){if(e.target===mO)closeM();});
tS.querySelectorAll('.evt-type-btn').forEach(function(b){b.addEventListener('click',function(){tS.querySelectorAll('.evt-type-btn').forEach(function(x){x.classList.remove('active');});b.classList.add('active');document.getElementById('evtType').value=b.dataset.type;});});

function openM(ds){
    document.getElementById('evtTitle').value='';document.getElementById('evtDescription').value='';
    document.getElementById('evtDate').value=ds||tStr;document.getElementById('evtTime').value='';
    document.getElementById('evtType').value='general';
    tS.querySelectorAll('.evt-type-btn').forEach(function(b){b.classList.toggle('active',b.dataset.type==='general');});
    mO.classList.add('show');document.body.style.overflow='hidden';
    setTimeout(function(){document.getElementById('evtTitle').focus();},150);
}
function closeM(){mO.classList.remove('show');document.body.style.overflow='';document.getElementById('eventModal').style.transform='';mO.style.opacity='';}

eF.addEventListener('submit',function(e){
    e.preventDefault();var sb=document.getElementById('evtSave');
    sb.classList.add('loading');sb.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span> Saving...';
    var fd=new FormData(eF);fd.append('ajax_action','create_event');
    fetch(AJ,{method:'POST',body:fd}).then(function(r){if(!r.ok)throw new Error;return r.json();}).then(function(d){
        if(d.success){showT('Event created: '+d.event.title,'success');var dt=d.event.event_date;if(!admEv[dt])admEv[dt]=[];admEv[dt].push(d.event);render();if(selD===dt)renderEv(dt);closeM();refNotif();}else showT(d.message||'Failed.','error');
    }).catch(function(){showT('Something went wrong.','error');}).finally(function(){sb.classList.remove('loading');sb.innerHTML='<i class="bi bi-check-lg me-1"></i> Save Event';});
});
function delE(id){
    if(!confirm('Delete this event?'))return;
    var fd=new FormData();fd.append('ajax_action','delete_event');fd.append('event_id',id);
    fetch(AJ,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
        if(d.success){showT('Event deleted.','info');for(var dt in admEv){admEv[dt]=admEv[dt].filter(function(e){return e.id!==id;});if(!admEv[dt].length)delete admEv[dt];}render();if(selD)renderEv(selD);refNotif();}else showT(d.message||'Failed.','error');
    }).catch(function(){showT('Something went wrong.','error');});
}
function togC(id){
    var fd=new FormData();fd.append('ajax_action','toggle_complete');fd.append('event_id',id);
    fetch(AJ,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
        if(d.success){for(var dt in admEv)admEv[dt].forEach(function(e){if(e.id===id)e.is_completed=e.is_completed?0:1;});if(selD)renderEv(selD);refNotif();}
    }).catch(function(){showT('Something went wrong.','error');});
}

function refNotif(){ if(window.LDB && LDB.navbar && typeof LDB.navbar.refNotif==='function') LDB.navbar.refNotif(); }

function showT(msg,type){
    type=type||'success';var c=document.getElementById('toastContainer'),t=document.createElement('div');
    t.className='toast-notification toast-'+type;
    var icons={success:'check-circle-fill',info:'info-circle-fill',warning:'exclamation-triangle-fill',error:'exclamation-circle-fill'};
    t.innerHTML='<i class="bi bi-'+(icons[type]||'info-circle-fill')+'"></i><span>'+esc(msg)+'</span>';
    c.appendChild(t);setTimeout(function(){if(t.parentNode)t.remove();},4200);
}
function checkToday(){var ev=admEv[tStr]||[],p=ev.filter(function(e){return !e.is_completed;});if(p.length>0)setTimeout(function(){showT('You have '+p.length+' event'+(p.length>1?'s':'')+' today!','info');},800);}
function animN(id,target){var el=document.getElementById(id),st=parseInt(el.textContent)||0,t0=performance.now();(function step(now){var p=Math.min((now-t0)/400,1);el.textContent=Math.round(st+(target-st)*(1-Math.pow(1-p,3)));if(p<1)requestAnimationFrame(step);})(performance.now());}
function fmt(d){return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0');}
function esc(s){if(!s)return '';var d=document.createElement('div');d.appendChild(document.createTextNode(s));return d.innerHTML;}
function cap(s){return s?s.charAt(0).toUpperCase()+s.slice(1):'';}
function formatT(ts){if(!ts)return '';var p=ts.split(':'),h=parseInt(p[0]),m=p[1],ap=h>=12?'PM':'AM';if(h>12)h-=12;if(h===0)h=12;return h+':'+m+' '+ap;}

function refNotif(){ if(window.LDB && LDB.navbar && typeof LDB.navbar.refNotif==='function') LDB.navbar.refNotif(); }

function showT(msg,type){
    type=type||'success';var c=document.getElementById('toastContainer'),t=document.createElement('div');
    t.className='toast-notification toast-'+type;
    var icons={success:'check-circle-fill',info:'info-circle-fill',warning:'exclamation-triangle-fill',error:'exclamation-circle-fill'};
    t.innerHTML='<i class="bi bi-'+(icons[type]||'info-circle-fill')+'"></i><span>'+esc(msg)+'</span>';
    c.appendChild(t);setTimeout(function(){if(t.parentNode)t.remove();},4200);
}
function checkToday(){var ev=admEv[tStr]||[],p=ev.filter(function(e){return !e.is_completed;});if(p.length>0)setTimeout(function(){showT('You have '+p.length+' event'+(p.length>1?'s':'')+' today!','info');},800);}
function animN(id,target){var el=document.getElementById(id),st=parseInt(el.textContent)||0,t0=performance.now();(function step(now){var p=Math.min((now-t0)/400,1);el.textContent=Math.round(st+(target-st)*(1-Math.pow(1-p,3)));if(p<1)requestAnimationFrame(step);})(performance.now());}
function fmt(d){return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0');}
function esc(s){if(!s)return '';var d=document.createElement('div');d.appendChild(document.createTextNode(s));return d.innerHTML;}
function cap(s){return s?s.charAt(0).toUpperCase()+s.slice(1):'';}
function formatT(ts){if(!ts)return '';var p=ts.split(':'),h=parseInt(p[0]),m=p[1],ap=h>=12?'PM':'AM';if(h>12)h-=12;if(h===0)h=12;return h+':'+m+' '+ap;}

init();
refNotif();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
