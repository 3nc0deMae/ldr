
const APP = {
    apiBase: (window.BASE_URL || '') + '/api',
    csrfToken: document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
};

// ============================================================
// STYLED ALERT MODAL
// Replaces native browser alert() with a modal that matches the
// design/colors of the other modals (e.g. privacyModal).
// ============================================================
function showAlertModal(message, options = {}) {
    options = options || {};
    const title = options.title || 'Notice';
    const icon = options.icon || 'info-circle-fill';
    const type = options.type || 'primary';

    const typeColors = {
        primary: 'var(--prof-primary, #0066fe)',
        success: '#28a745',
        warning: '#FFC107',
        danger:  '#DC3545',
        info:    '#17A2B8'
    };
    const accent = typeColors[type] || typeColors.primary;

    let modal = document.getElementById('appAlertModal');
    if (!modal) {
        const backdrop = '<div class="modal fade" id="appAlertModal" tabindex="-1" aria-hidden="true">' +
            '<div class="modal-dialog modal-dialog-centered">' +
            '<div class="modal-content" style="background:#1a1a2e; border:1px solid rgba(255,255,255,0.1); color:#f0ece4;">' +
            '<div class="modal-header" style="border-bottom:1px solid rgba(255,255,255,0.1);">' +
            '<h5 class="modal-title" id="appAlertModalTitle" style="font-weight:700;"></h5>' +
            '<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>' +
            '</div>' +
            '<div class="modal-body" id="appAlertModalBody" style="font-size:14px; line-height:1.6;"></div>' +
            '<div class="modal-footer" style="border-top:1px solid rgba(255,255,255,0.1);">' +
            '<button type="button" class="btn btn-primary" data-bs-dismiss="modal" id="appAlertModalOk">OK</button>' +
            '</div>' +
            '</div></div></div>';
        document.body.insertAdjacentHTML('beforeend', backdrop);
        modal = document.getElementById('appAlertModal');
    }

    const titleEl = modal.querySelector('.modal-title');
    const bodyEl = modal.querySelector('.modal-body');
    titleEl.innerHTML = '<i class="bi bi-' + icon + ' me-2" style="color:' + accent + ';"></i>' + title;
    bodyEl.textContent = message;

    const okBtn = modal.querySelector('#appAlertModalOk');
    okBtn.style.backgroundColor = accent;
    okBtn.style.borderColor = accent;
    okBtn.style.color = (type === 'warning') ? '#1a1a2e' : '#ffffff';

    const modalInstance = bootstrap.Modal.getOrCreateInstance(modal);
    modalInstance.show();
}

// ============================================================
// AJAX HELPER
// ============================================================
/**
 * Make an AJAX POST request to API
 * @param {string} endpoint - API file name (e.g. 'students.php')
 * @param {Object} data - Form data
 * @returns {Promise<Object>}
 */
async function apiRequest(endpoint, data = {}) {
    try {
        const formData = new FormData();
        Object.entries(data).forEach(([key, value]) => {
            formData.append(key, value);
        });

        const response = await fetch(`${APP.apiBase}/${endpoint}`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': APP.csrfToken
            },
            body: formData
        });

        const result = await response.json();

        if (!response.ok) {
            throw new Error(result.error || 'Request failed');
        }

        return result;
    } catch (error) {
        console.error(`API Error [${endpoint}]:`, error);
        throw error;
    }
}

// ============================================================
// TOAST NOTIFICATIONS
// ============================================================
/**
 * Show a toast notification
 * @param {string} message
 * @param {string} type - success, danger, warning, info
 * @param {number} duration - ms to show (default 4000)
 */
function showToast(message, type = 'info', duration = 4000) {
    let container = document.querySelector('.toast-container');
    if (!container) {
        container = document.createElement('div');
        container.className = 'toast-container';
        document.body.appendChild(container);
    }

    const iconMap = {
        success: 'bi-check-circle-fill',
        danger:  'bi-exclamation-circle-fill',
        warning: 'bi-exclamation-triangle-fill',
        info:    'bi-info-circle-fill'
    };

    const toast = document.createElement('div');
    toast.className = `custom-toast toast-${type}`;
    toast.innerHTML = `
        <i class="bi ${iconMap[type] || iconMap.info}" style="font-size:20px;flex-shrink:0;"></i>
        <div style="flex:1;font-size:13.5px;">${message}</div>
        <button onclick="this.parentElement.remove()" style="background:none;border:none;
                color:#adb5bd;font-size:18px;cursor:pointer;padding:0;line-height:1;">
            &times;
        </button>
    `;

    container.appendChild(toast);

    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(100%)';
        toast.style.transition = 'all 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, duration);
}

// ============================================================
// CONFIRM DIALOG
// ============================================================
/**
 * Show confirmation dialog before action
 * @param {string} message
 * @param {Function} onConfirm
 */
function confirmAction(message, onConfirm) {
    const modal = document.createElement('div');
    modal.className = 'modal fade';
    modal.id = 'confirmModal_' + Date.now();
    modal.innerHTML = `
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content">
                <div class="modal-body text-center p-4">
                    <i class="bi bi-exclamation-triangle text-warning" style="font-size:48px;"></i>
                    <h6 class="mt-3 fw-bold">Confirm Action</h6>
                    <p class="text-muted" style="font-size:13.5px;">${message}</p>
                    <div class="d-flex gap-2 justify-content-center mt-3">
                        <button class="btn btn-outline-secondary btn-sm px-3" data-bs-dismiss="modal">Cancel</button>
                        <button class="btn btn-danger btn-sm px-3" id="confirmBtn_${modal.id}">Confirm</button>
                    </div>
                </div>
            </div>
        </div>
    `;

    document.body.appendChild(modal);
    const bsModal = new bootstrap.Modal(modal);
    bsModal.show();

    modal.querySelector(`#confirmBtn_${modal.id}`).addEventListener('click', () => {
        bsModal.hide();
        onConfirm();
    });

    modal.addEventListener('hidden.bs.modal', () => modal.remove());
}

/**
 * Confirm delete with URL redirect
 */
function confirmDelete(url, name) {
    confirmAction(
        `Are you sure you want to delete "<strong>${name}</strong>"? This action cannot be undone.`,
        () => window.location.href = url
    );
}

// ============================================================
// LOADING OVERLAY
// ============================================================
/**
 * Show loading overlay
 * @param {string} text
 */
function showLoading(text = 'Loading...') {
    let overlay = document.getElementById('loadingOverlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'loadingOverlay';
        overlay.className = 'spinner-overlay';
        overlay.innerHTML = `
            <div class="spinner-border" role="status"></div>
            <div class="spinner-text">${text}</div>
        `;
        document.body.appendChild(overlay);
    } else {
        overlay.querySelector('.spinner-text').textContent = text;
        overlay.style.display = 'flex';
    }
}

/**
 * Hide loading overlay
 */
function hideLoading() {
    const overlay = document.getElementById('loadingOverlay');
    if (overlay) overlay.style.display = 'none';
}

// ============================================================
// FORMAT HELPERS
// ============================================================
/**
 * Format date for display
 */
function formatDate(dateStr) {
    if (!dateStr) return '-';
    const date = new Date(dateStr);
    return date.toLocaleDateString('en-US', {
        year: 'numeric', month: 'long', day: 'numeric'
    });
}

/**
 * Format time for display (12-hour)
 */
function formatTime(timeStr) {
    if (!timeStr) return '-';
    const [hours, minutes] = timeStr.split(':');
    const h = parseInt(hours);
    const ampm = h >= 12 ? 'PM' : 'AM';
    const h12 = h % 12 || 12;
    return `${h12}:${minutes} ${ampm}`;
}

/**
 * Format datetime
 */
function formatDateTime(datetimeStr) {
    if (!datetimeStr) return '-';
    return `${formatDate(datetimeStr)} ${formatTime(datetimeStr)}`;
}

/**
 * Get attendance badge HTML
 */
function getStatusBadge(status) {
    const map = {
        present:  '<span class="badge-status badge-present">Present</span>',
        absent:   '<span class="badge-status badge-absent">Absent</span>',
        late:     '<span class="badge-status badge-late">Late</span>',
        active:   '<span class="badge-status badge-active">Active</span>',
        inactive: '<span class="badge-status badge-inactive">Inactive</span>',
        'time-in':  '<span class="badge-status badge-present">Time-In</span>',
        'time-out': '<span class="badge-status badge-late">Time-Out</span>'
    };
    return map[status] || `<span class="badge-status">${status}</span>`;
}

// ============================================================
// DATATABLES INIT
// ============================================================
function initDataTables() {
    if (typeof $.fn.DataTable !== 'undefined') {
        document.querySelectorAll('.datatable').forEach(table => {
            if (!$.fn.DataTable.isDataTable(table)) {
                $(table).DataTable({
                    responsive: true,
                    pageLength: 10,
                    language: {
                        search: '_INPUT_',
                        searchPlaceholder: 'Search records...',
                        lengthMenu: 'Show _MENU_ entries',
                        emptyTable: 'No records found',
                        zeroRecords: 'No matching records found'
                    },
                    dom: '<"row align-items-center"<"col-sm-6"l><"col-sm-6"f>>rtip'
                });
            }
        });
    }
}

// ============================================================
// AUTO-DISMISS ALERTS
// ============================================================
function autoDismissAlerts() {
    setTimeout(() => {
        document.querySelectorAll('.alert-dismissible').forEach(alert => {
            alert.style.transition = 'opacity 0.5s ease';
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 500);
        });
    }, 5000);
}

// ============================================================
// CAMERA HELPERS
// ============================================================
let cameraStream = null;

/**
 * Start camera on a video element
 * @param {string} videoId
 * @returns {Promise<MediaStream>}
 */
async function startCamera(videoId) {
    try {
        const stream = await navigator.mediaDevices.getUserMedia({
            video: { width: 640, height: 480, facingMode: 'user' }
        });
        const video = document.getElementById(videoId);
        if (video) {
            video.srcObject = stream;
            video.play();
        }
        cameraStream = stream;
        return stream;
    } catch (error) {
        showToast('Camera access denied. Please allow camera permissions.', 'danger');
        throw error;
    }
}

/**
 * Stop camera
 */
function stopCamera() {
    if (cameraStream) {
        cameraStream.getTracks().forEach(track => track.stop());
        cameraStream = null;
    }
}

/**
 * Capture frame from video as base64
 * @param {string} videoId
 * @returns {string} base64 image data
 */
function captureFrame(videoId) {
    const video = document.getElementById(videoId);
    if (!video) return null;

    const canvas = document.createElement('canvas');
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(video, 0, 0);
    return canvas.toDataURL('image/jpeg', 0.9);
}

/**
 * Capture frame from video, cropping to the central detection zone.
 * This reduces bandwidth and ensures the server only receives the
 * region where a face should be positioned.
 *
 * @param {string} videoId
 * @param {number} [zoneMargin=0.15] - fraction inset from each edge (0.15 = 15%)
 * @returns {string|null} base64 image data
 */
function captureFrameInZone(videoId, zoneMargin = 0.15) {
    const video = document.getElementById(videoId);
    if (!video) return null;

    const vw = video.videoWidth;
    const vh = video.videoHeight;
    if (vw <= 0 || vh <= 0) return null;

    const zoneX = Math.floor(vw * zoneMargin);
    const zoneY = Math.floor(vh * zoneMargin);
    const zoneW = Math.floor(vw * (1.0 - 2 * zoneMargin));
    const zoneH = Math.floor(vh * (1.0 - 2 * zoneMargin));

    if (zoneW <= 0 || zoneH <= 0) return null;

    const canvas = document.createElement('canvas');
    canvas.width = zoneW;
    canvas.height = zoneH;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(video, zoneX, zoneY, zoneW, zoneH, 0, 0, zoneW, zoneH);
    return canvas.toDataURL('image/jpeg', 0.9);
}

// ============================================================
// CHART HELPERS
// ============================================================
const chartColors = {
    primary:   '#0066FE',
    success:   '#28A745',
    warning:   '#FFC107',
    danger:    '#DC3545',
    info:      '#17A2B8',
    primaryBg: 'rgba(0, 102, 254, 0.1)',
    successBg: 'rgba(40, 167, 69, 0.1)',
    warningBg: 'rgba(255, 193, 7, 0.1)',
    dangerBg:  'rgba(220, 53, 69, 0.1)'
};

const defaultChartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: {
            position: 'top',
            labels: {
                usePointStyle: true,
                padding: 16,
                font: { size: 12, family: 'Inter' }
            }
        }
    },
    scales: {
        y: {
            beginAtZero: true,
            grid: { color: 'rgba(0,0,0,0.04)' },
            ticks: { font: { size: 11, family: 'Inter' } }
        },
        x: {
            grid: { display: false },
            ticks: { font: { size: 11, family: 'Inter' } }
        }
    }
};

// ============================================================
// INIT ON DOM READY
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    // CSRF token for jQuery AJAX
    if (typeof $ !== 'undefined' && $.ajaxSetup) {
        $.ajaxSetup({
            headers: { 'X-CSRF-TOKEN': APP.csrfToken }
        });
    }

    // Auto-dismiss alerts
    autoDismissAlerts();

    // Initialize DataTables
    initDataTables();

    // Sidebar toggle handled by navbar.js
});
