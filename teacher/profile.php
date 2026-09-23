<?php

require_once __DIR__ . '/../config.php';
requireRole(['teacher']);

$pageTitle = 'My Profile';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

$userEmail = $_SESSION['user_email'] ?? '';
$userRole  = $_SESSION['user_role'] ?? 'Teacher';
$user      = [];
$teacher   = [];

try {
    $stmt = $db->prepare("SELECT * FROM users WHERE email = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$userEmail]);
    $user = $stmt->fetch();

    if ($user) {
        $stmt = $db->prepare("SELECT * FROM teachers WHERE user_id = ? LIMIT 1");
        $stmt->execute([$user['id']]);
        $teacher = $stmt->fetch();
    }
} catch (Exception $e) {}

$advisorySectionRecords = [];
try {
    $advisorySectionRecords = getAdvisorySectionRecords($db);
} catch (Exception $e) {}

$createdAt = $user['created_at'] ?? '';
$lastLogin = '';
try {
    $stmt = $db->prepare("SELECT created_at FROM audit_logs WHERE user_id = ? AND action = 'login' ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$user['id'] ?? 0]);
    $lastLogin = $stmt->fetchColumn();
} catch (Exception $e) {}

$passwordAgeDays = '';
if ($createdAt) {
    $passwordAgeDays = round((time() - strtotime($createdAt)) / 86400);
}

$__avatarUrl = null;
if ($user['id'] ?? 0) {
    $exts = ['jpg', 'jpeg', 'png', 'webp'];
    foreach ($exts as $ext) {
        $path = ROOT_PATH . '/uploads/avatars/' . ($user['id'] ?? 0) . '.' . $ext;
        if (file_exists($path)) {
            $__avatarUrl = BASE_URL . '/uploads/avatars/' . ($user['id'] ?? 0) . '.' . $ext;
            break;
        }
    }
}
$userInitial = isset($teacher['first_name']) && $teacher['first_name'] ? strtoupper(substr($teacher['first_name'], 0, 1)) : 'T';

$teacherFullName = '';
if ($teacher) {
    $teacherFullName = $teacher['first_name'] ?? '';
    if (!empty($teacher['middle_name'])) {
        $teacherFullName .= ' ' . strtoupper($teacher['middle_name'][0]) . '.';
    }
    $teacherFullName .= ' ' . ($teacher['last_name'] ?? '');
}
if (empty($teacherFullName)) {
    $teacherFullName = sanitize($user['email'] ?? $userEmail);
}
?>

<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pages-theme.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pages-navbar.css">
<script src="<?= BASE_URL ?>/assets/vendor/js/tailwind.js"></script>
<script>
    tailwind.config = { corePlugins: { preflight: false } }
</script>

<style>
:root {
    --prof-font: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
    --prof-mono: 'JetBrains Mono', monospace;
    --prof-primary: #4f46e5;
    --prof-primary-light: rgba(79,70,229,0.15);
    --prof-success: #10b981;
    --prof-success-light: rgba(16,185,129,0.15);
    --prof-radius: 14px;
    --prof-radius-sm: 10px;
    --prof-transition: 0.2s cubic-bezier(0.4,0,0.2,1);
}
.content-area { padding: 24px 28px 40px; }
.page-title h5 { font-size: 20px; font-weight: 800; letter-spacing: -0.03em; margin: 0; }
.page-title small { font-size: 13px; font-weight: 500; color: rgba(255,255,255,0.5); }
.mobile-title-left h5 { font-size: 18px; font-weight: 800; letter-spacing: -0.03em; margin: 0; }
.mobile-title-left small { font-size: 12px; font-weight: 500; }
.card {
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(255,255,255,0.06);
    border-radius: var(--prof-radius);
    box-shadow: 0 1px 3px rgba(0,0,0,0.2);
    margin-bottom: 20px;
    overflow: hidden;
}
.card-header {
    background: transparent;
    border-bottom: 1px solid rgba(255,255,255,0.06);
    padding: 14px 20px;
    font-size: 14px;
    font-weight: 700;
    color: #f0ece4;
    letter-spacing: -0.01em;
}
.card-header i { color: #fff; font-size: 15px; }
.card-body { padding: 20px; color: #f0ece4; }
.profile-avatar {
    width: 96px; height: 96px; border-radius: 50%;
    background: linear-gradient(135deg, rgba(79,70,229,0.2), rgba(124,58,237,0.2));
    border: 2px solid rgba(79,70,229,0.3);
    display: flex; align-items: center; justify-content: center;
    font-size: 40px; color: rgba(255,255,255,0.8);
    margin-bottom: 16px; overflow: hidden;
}
.profile-name { font-size: 22px; font-weight: 800; letter-spacing: -0.02em; }
.profile-role {
    display: inline-block; margin-top: 6px;
    font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em;
    padding: 4px 12px; border-radius: 8px;
    background: var(--prof-primary-light); color: var(--prof-primary);
}
.profile-info-grid {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px;
}
.profile-info-item {
    background: rgba(255,255,255,0.03);
    border: 1px solid rgba(255,255,255,0.05);
    border-radius: var(--prof-radius-sm);
    padding: 14px 16px;
}
.profile-info-label {
    font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em;
    color: rgba(255,255,255,0.35); margin-bottom: 4px;
}
.profile-info-value { font-size: 14px; font-weight: 600; color: #f0ece4; word-break: break-all; }
@media (max-width: 767px) {
    .content-area { padding: 10px 12px 28px; }
    .profile-avatar { width: 72px; height: 72px; font-size: 30px; border-radius: 16px; }
    .profile-name { font-size: 18px; }
    .mobile-title { display: block; }
}
.status-dot {
    height: 10px; width: 10px; border-radius: 50%; display: inline-block;
    background: #10b981; box-shadow: 0 0 0 3px rgba(16,185,129,0.2);
}
.badge-role {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em;
    padding: 5px 14px; border-radius: 8px;
    background: var(--prof-primary-light); color: var(--prof-primary);
}
.account-id {
    font-family: var(--prof-mono); font-size: 13px; font-weight: 500;
    color: rgba(255,255,255,0.5); letter-spacing: 0.02em;
}
.profile-advisory-badge {
    display: inline-flex; align-items: center; gap: 5px;
    background: var(--prof-primary-light); color: var(--prof-primary);
    border: 1px solid rgba(79,70,229,0.25);
    border-radius: 8px; padding: 4px 10px; margin: 2px 5px 2px 0;
    font-size: 12px; font-weight: 600;
}
.profile-advisory-badge::before {
    content: ''; width: 6px; height: 6px; border-radius: 50%;
    background: var(--prof-primary); flex-shrink: 0;
}
</style>

<?php require_once __DIR__ . '/../includes/pages-topnavbar.php'; ?>
<div class="main-content">

    <div class="content-area">
        <?= displayFlashMessage() ?>

        <div class="d-none d-md-flex justify-content-between align-items-center gap-3 mb-3">
            <div class="page-title mb-0">
                <h5 class="mb-0">My Profile</h5>
                <small>Manage your account information</small>
            </div>
        </div>

        <div class="page-title mobile-title">
            <div class="mobile-title-inner">
                <div class="mobile-title-left">
                    <h5>My Profile</h5>
                    <small>Manage your account information</small>
                </div>
            </div>
        </div>

        <!-- Identity Card -->
        <div class="card">
            <div class="card-body">
                <div class="d-flex align-items-center gap-3 flex-wrap" id="profileIdentityCard" style="cursor:pointer;">
                    <div class="profile-avatar" id="profilePageAvatarUpload" style="position:relative; flex-shrink:0;">
                        <img id="profilePageAvatarImg" src="<?= $__avatarUrl ? htmlspecialchars($__avatarUrl) : 'data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 100 100\'%3E%3Ccircle cx=\'50\' cy=\'50\' r=\'50\' fill=\'url(%23grad)\'/%3E%3Cdefs%3E%3ClinearGradient id=\'grad\' x1=\'0%25\' y1=\'0%25\' x2=\'100%25\' y2=\'100%25\'%3E%3Cstop offset=\'0%25\' style=\'stop-color:%237c3aed;stop-opacity:1\' /%3E%3Cstop offset=\'100%25\' style=\'stop-color:%234f46e5;stop-opacity:1\' /%3E%3C/linearGradient%3E%3C/defs%3E%3Ctext x=\'50\' y=\'60\' text-anchor=\'middle\' font-size=\'45\' font-weight=\'bold\' fill=\'white\' font-family=\'Arial\'%3EA%3C/text%3E%3C/svg%3E' ?>" alt="Profile" style="<?= $__avatarUrl ? 'display:block;' : 'display:none;' ?>">
                        <span id="profilePageAvatarText" style="font-weight:700;<?= $__avatarUrl ? 'display:none;' : '' ?>"><?= $userInitial ?></span>
                        <div class="navbar-profile-avatar-upload-hint" style="position:absolute; bottom:8px; right:8px; background:var(--prof-primary); border-radius:50%; width:24px; height:24px; display:flex; align-items:center; justify-content:center; cursor:pointer; border:2px solid rgba(255,255,255,0.1);">
                            <i class="bi bi-camera-fill" style="font-size:11px; color:#fff;"></i>
                        </div>
                        <?php if ($__avatarUrl): ?>
                        <button type="button" id="removeAvatarBtn" title="Remove profile picture" style="position:absolute; top:0; right:0; background:#ef4444; border:none; color:#fff; width:24px; height:24px; border-radius:50%; cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:14px; z-index:20; padding:0;">×</button>
                        <?php endif; ?>
                        <input type="file" id="profilePageAvatarInput" class="navbar-profile-avatar-input" accept="image/*">
                    </div>
                    <div class="d-flex align-items-center gap-2 flex-wrap flex-grow-1">
                        <div class="profile-name" style="font-size:22px;"><?= $teacherFullName ?></div>
                        <span class="badge-role">
                            <span class="status-dot"></span>
                            <?= ucfirst($userRole) ?>
                        </span>
                        <span class="account-id">ID: <?= $user['id'] ?? 'N/A' ?></span>
                        <?php if (($user['status'] ?? 'active') === 'active'): ?>
                            <span style="color:#10b981; font-size:13px; font-weight:600;">
                                <i class="bi bi-check-circle me-1"></i>Active
                            </span>
                        <?php else: ?>
                            <span style="color:#ef4444; font-size:13px; font-weight:600;">
                                <i class="bi bi-x-circle me-1"></i>Inactive
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content Grid -->
        <div class="row g-4">
            <!-- Left: Account Information -->
            <div class="col-lg-12">
                <div class="card h-100">
                    <div class="card-header">
                        <span style="display:inline-flex;align-items:center;gap:4px;"><i class="bi bi-info-circle"></i> Account Information</span>
                    </div>
                    <div class="card-body">
                        <div class="profile-info-grid" style="grid-template-columns: repeat(3, 1fr);">
                            <div class="profile-info-item">
                                <div class="profile-info-label">Email Address</div>
                                <div class="profile-info-value"><?= sanitize($user['email'] ?? $userEmail) ?></div>
                            </div>
                            <div class="profile-info-item">
                                <div class="profile-info-label">Role</div>
                                <div class="profile-info-value"><?= ucfirst($userRole) ?></div>
                            </div>
                            <div class="profile-info-item">
                                <div class="profile-info-label">Phone Number</div>
                                <div class="profile-info-value"><?= sanitize($teacher['phone'] ?? 'N/A') ?></div>
                            </div>
                            <div class="profile-info-item">
                                <div class="profile-info-label">Account Created</div>
                                <div class="profile-info-value"><?= $createdAt ? formatDateTime($createdAt) : 'N/A' ?></div>
                            </div>
                            <div class="profile-info-item">
                                <div class="profile-info-label">Last Login</div>
                                <div class="profile-info-value"><?= $lastLogin ? formatDateTime($lastLogin) : 'N/A' ?></div>
                            </div>
                            <div class="profile-info-item">
                                <div class="profile-info-label">Password Age</div>
                                <div class="profile-info-value">
                                    <?php if ($passwordAgeDays): ?>
                                        <?= $passwordAgeDays ?> days (est.)
                                    <?php else: ?>
                                        N/A
                                    <?php endif; ?>
                                </div>
                            </div>
            </div>
        </div>
    </div>
</div>

        <!-- Role-Specific Card: Teacher Institutional Data -->
        <?php if ($_SESSION['user_role'] === 'teacher'): ?>
        <div class="card">
            <div class="card-header">
                <span style="display:inline-flex;align-items:center;gap:4px;"><i class="bi bi-person-badge"></i> Institutional Data</span>
            </div>
            <div class="card-body">
                <div class="profile-info-grid">
                    <div class="profile-info-item">
                        <div class="profile-info-label">Department</div>
                        <div class="profile-info-value"><?= sanitize($teacher['department'] ?? 'N/A') ?></div>
                    </div>
                    <div class="profile-info-item">
                        <div class="profile-info-label">Subjects Handled</div>
                        <div class="profile-info-value"><?= sanitize($teacher['subjects_handled'] ?? 'N/A') ?></div>
                    </div>
                    <div class="profile-info-item">
                        <div class="profile-info-label">Advisory Section(s)</div>
                        <div class="profile-info-value">
                            <?php if (!empty($advisorySectionRecords)): ?>
                                <?php foreach ($advisorySectionRecords as $advRec): ?>
                                    <span class="profile-advisory-badge"><?= sanitize(formatAdvisoryClassLabel($advRec['grade_level'] ?? '', $advRec['section_name'] ?? '', $advRec['strand_name'] ?? '')) ?></span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                N/A
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Data Privacy Notice Modal -->
<div class="modal fade" id="privacyModal" tabindex="-1" aria-labelledby="privacyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content" style="background:#1a1a2e; border:1px solid rgba(255,255,255,0.1); color:#f0ece4;">
            <div class="modal-header" style="border-bottom:1px solid rgba(255,255,255,0.1);">
                <h5 class="modal-title" id="privacyModalLabel" style="font-weight:700;">
                    <i class="bi bi-shield-check me-2" style="color:#fff;"></i>System Data Privacy Notice
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" style="font-size:14px; line-height:1.7;">
                <div style="margin-bottom:16px;">
                    <strong style="color:#fff;">1. Introduction</strong><br>
                    This Data Privacy Agreement ("Agreement") governs the collection, processing, storage, and protection of personal and sensitive personal information within the Web-Based Facial Recognition Attendance System ("System") of Liceo de Baleno. By accessing, registering, or interacting with this System, you explicitly acknowledge that you have read, understood, and consented to the processing of your data in accordance with the Republic Act No. 10173, otherwise known as the Data Privacy Act of 2012 (DPA).
                </div>
                <div style="margin-bottom:16px;">
                    <strong style="color:#fff;">2. Scope of Data Collection</strong><br>
                    To fulfill its functions, the System processes the following information:
                    <ul style="margin-left:20px; margin-top:4px;">
                        <li><strong>Biometric Data:</strong> Multi-angle facial images (Front, Left, and Right profiles) converted into encrypted, mathematical biometric templates.</li>
                        <li><strong>Student Personal Information:</strong> Full name, Learner Reference Number (LRN), grade level, section, and official school email.</li>
                        <li><strong>Guardian Personal Information:</strong> Full name, relationship to the student, active mobile number, and contact details.</li>
                        <li><strong>Logistical Data:</strong> Automated attendance timestamps, kiosk interaction logs, and historical tracking metrics.</li>
                    </ul>
                </div>
                <div style="margin-bottom:16px;">
                    <strong style="color:#fff;">3. Purpose of Data Processing</strong><br>
                    All collected information is processed strictly under the principles of transparency, legitimate purpose, and proportionality for the following objectives:
                    <ul style="margin-left:20px; margin-top:4px;">
                        <li>Automating, verifying, and securing daily student attendance records.</li>
                        <li>Triggering real-time SMS/system notifications to registered guardians regarding student arrival and departure.</li>
                        <li>Academic research, system evaluation, and technical validation within the scope of institutional optimization and authorized research development.</li>
                    </ul>
                </div>
                <div style="margin-bottom:16px;">
                    <strong style="color:#fff;">4. Data Storage and Security</strong><br>
                    Liceo de Baleno implements rigorous organizational, physical, and technical security measures:
                    <ul style="margin-left:20px; margin-top:4px;">
                        <li>Data is hosted on secure, encrypted servers with strict role-based access control lists (ACLs).</li>
                        <li>Facial photographs are processed into one-way, non-reversible digital hashes to prevent reverse engineering of facial images.</li>
                        <li>Access is tightly restricted to authorized system administrators, institutional authorities, and designated researchers.</li>
                    </ul>
                </div>
                <div style="margin-bottom:16px;">
                    <strong style="color:#fff;">5. Data Retention and Disposal</strong><br>
                    <ul style="margin-left:20px; margin-top:4px;">
                        <li>Personal and biometric data will be retained only for the duration of the student's enrollment or the active lifecycle evaluation of this research system.</li>
                        <li>Upon graduation, transfer, withdrawal of consent, or formal system decommissioning, all biometric vectors and personal identifiers will be permanently deleted, overwritten, or anonymized beyond recovery.</li>
                    </ul>
                </div>
                <div style="margin-bottom:16px;">
                    <strong style="color:#fff;">6. Data Subject Rights</strong><br>
                    Under the DPA of 2012, students (and their legal guardians) are afforded the following rights:
                    <ul style="margin-left:20px; margin-top:4px;">
                        <li><strong>Right to be Informed:</strong> Knowing how, why, and when their biometric data is processed.</li>
                        <li><strong>Right to Object/Opt-out:</strong> The right to withhold or withdraw consent to biometric tracking without academic penalty (alternative manual attendance mechanisms will be provided).</li>
                        <li><strong>Right to Access and Rectification:</strong> Requesting a copy of stored records or correcting clerical errors in guardian contact details.</li>
                    </ul>
                </div>
                <div style="margin-bottom:16px;">
                    <strong style="color:#fff;">7. Third-Party Disclosures</strong><br>
                    Biometric templates and personal data will never be shared, rented, or sold to third-party commercial entities. Data transmission is limited exclusively to authorized school personnel and targeted SMS gateways used solely for transmitting automated guardian attendance updates.
                </div>
                <div style="margin-bottom:16px;">
                    <strong style="color:#fff;">8. Limitation of Liability</strong><br>
                    While the development team and Liceo de Baleno implement industry-standard encryption and safety protocols, no digital system is entirely immune to malicious breaches. The institution shall not be held liable for unforeseen system disruptions or unauthorized access occurring outside reasonable technical control, provided all statutory security updates and best practices have been strictly maintained.
                </div>
                <div style="margin-bottom:16px;">
                    <strong style="color:#fff;">9. Governing Law</strong><br>
                    This Agreement shall be governed, interpreted, and enforced in absolute accordance with the laws of the Republic of the Philippines, under the regulatory oversight of the National Privacy Commission (NPC).
                </div>
            </div>
            <div class="modal-footer" style="border-top:1px solid rgba(255,255,255,0.1);">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius:8px;">Close</button>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
<script>
(function(){
    var avatarUpload = document.getElementById('profilePageAvatarUpload');
    var avatarInput = document.getElementById('profilePageAvatarInput');
    var avatarImg = document.getElementById('profilePageAvatarImg');
    var identityCard = document.getElementById('profileIdentityCard');
    if(!avatarUpload||!avatarInput)return;

    avatarUpload.addEventListener('click',function(e){
        if (e.target && e.target.tagName === 'INPUT' && e.target.type === 'file') return;
        e.preventDefault();
        e.stopPropagation();
        avatarInput.click();
    });

    if (identityCard) {
        identityCard.addEventListener('click', function(e) {
            if (e.target && e.target.tagName === 'INPUT' && e.target.type === 'file') return;
            avatarInput.click();
        });
    }

    var removeBtn = document.getElementById('removeAvatarBtn');
    if (removeBtn) {
        removeBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            removeAvatar();
        });
    }

    avatarInput.addEventListener('change',function(e){
        var file = e.target.files[0];
        if(!file)return;
        if(!file.type.startsWith('image/')){
            showToast('Please select a valid image file','warning');
            return;
        }
        if(file.size>5*1024*1024){
            showToast('Image size must be less than 5MB','warning');
            return;
        }
        var reader = new FileReader();
        reader.onload = function(event){
            var dataUrl = event.target.result;
            if(avatarImg){ avatarImg.src = dataUrl; avatarImg.style.display = 'block'; }
            var text = document.getElementById('profilePageAvatarText');
            if(text){ text.style.display = 'none'; }
            var formData = new FormData();
            formData.append('action','upload');
            formData.append('image',file);
            var csrf = document.querySelector('meta[name="csrf-token"]');
            if(csrf) formData.append('csrf_token', csrf.getAttribute('content'));
            fetch(window.BASE_URL+'/api/avatar.php',{method:'POST',credentials:'same-origin',body:formData})
            .then(function(res){return res.json()})
            .then(function(data){
                if(data && data.success){
                    showToast(data.message || 'Profile picture updated','success');
                    if(avatarImg){ avatarImg.src = data.avatar_url; avatarImg.style.display = 'block'; }
                    if(text){ text.style.display = 'none'; }
                    var navImg = document.getElementById('navbarProfileAvatarImg');
                    if(navImg){ navImg.src = data.avatar_url; navImg.style.display = 'block'; }
                    var navText = document.getElementById('navbarProfileAvatarText');
                    if(navText){ navText.style.display = 'none'; }
                } else {
                    showToast(data && data.error ? data.error : 'Upload failed','danger');
                }
            })
            .catch(function(err){ console.error('Avatar upload error:',err); showToast('Upload failed','danger'); });
        };
        reader.readAsDataURL(file);
    });

    function removeAvatar() {
        var formData = new FormData();
        formData.append('action', 'delete');
        var csrf = document.querySelector('meta[name="csrf-token"]');
        if (csrf) formData.append('csrf_token', csrf.getAttribute('content'));
        
        showToast('Removing profile picture...', 'info');
        
        fetch(window.BASE_URL + '/api/avatar.php', {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        })
        .then(function(res){return res.json()})
        .then(function(data){
            if(data && data.success){
                showToast(data.message || 'Profile picture removed','success');
                if(avatarImg){ avatarImg.src = ''; avatarImg.style.display = 'none'; }
                var text = document.getElementById('profilePageAvatarText');
                if(text){ text.style.display = 'block'; }
                var navImg = document.getElementById('navbarProfileAvatarImg');
                if(navImg){ navImg.src = ''; navImg.style.display = 'none'; }
                var navText = document.getElementById('navbarProfileAvatarText');
                if(navText){ navText.style.display = 'block'; }
                var removeBtn = document.getElementById('removeAvatarBtn');
                if(removeBtn){ removeBtn.style.display = 'none'; }
            } else {
                showToast(data && data.error ? data.error : 'Remove failed','danger');
            }
        })
        .catch(function(err){ console.error('Avatar remove error:',err); showToast('Remove failed','danger'); });
    }
})();
</script>
