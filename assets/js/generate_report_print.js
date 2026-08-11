(function () {
    var selectedOption = null;
    var modal = null;

    function getModal() {
        if (!modal) {
            modal = document.getElementById('printFormatModal');
        }
        return modal;
    }

function getSelectedGradeLevel() {
    var el = document.querySelector('select[name="grade_level"]#gradeLevelFilter');
    if (el) return el.value || '';
    var params = new URLSearchParams(window.location.search);
    return params.get('grade_level') || '';
}

function getSelectedSection() {
    var el = document.querySelector('select[name="section"]#sectionFilter');
    if (el) return el.value || '';
    var params = new URLSearchParams(window.location.search);
    return params.get('section') || '';
}

    function getSelectedSubjectId() {
        var el = document.querySelector('input[name="subject_id"], select[name="subject_id"], #subjectIdFilter, #subjectFilter');
        if (el) return el.value || '';
        var params = new URLSearchParams(window.location.search);
        return params.get('subject_id') || '';
    }

    function getDateFrom() {
        var el = document.querySelector('input[name="date_from"], #dateFromFilter, #dateFrom');
        if (el) return el.value || '';
        var params = new URLSearchParams(window.location.search);
        return params.get('date_from') || '';
    }

    function getDateTo() {
        var el = document.querySelector('input[name="date_to"], #dateToFilter, #dateTo');
        if (el) return el.value || '';
        var params = new URLSearchParams(window.location.search);
        return params.get('date_to') || '';
    }

    function getReportMonthYear() {
        var dateFrom = getDateFrom();
        var dateTo = getDateTo();
        var base = dateFrom || dateTo;
        var d = base ? new Date(base + 'T00:00:00') : new Date();
        if (isNaN(d.getTime())) d = new Date();
        return { month: d.getMonth() + 1, year: d.getFullYear() };
    }

    window.openPrintModal = function () {
        var m = getModal();
        if (!m) return;
        m.classList.add('active');
        selectedOption = null;
        var cards = m.querySelectorAll('.print-option-card');
        cards.forEach(function (c) { c.classList.remove('selected'); });
        var confirmBtn = document.getElementById('printModalConfirm');
        if (confirmBtn) confirmBtn.disabled = true;
        var exportBtn = document.getElementById('printModalExport');
        if (exportBtn) exportBtn.disabled = true;
    };

    window.closePrintModal = function () {
        var m = getModal();
        if (!m) return;
        m.classList.remove('active');
        selectedOption = null;
    };

    window.selectPrintOption = function (option) {
        selectedOption = option;
        var cards = document.querySelectorAll('.print-option-card');
        cards.forEach(function (c) {
            c.classList.toggle('selected', c.getAttribute('data-option') === option);
        });
        var confirmBtn = document.getElementById('printModalConfirm');
        if (confirmBtn) confirmBtn.disabled = false;
        var exportBtn = document.getElementById('printModalExport');
        if (exportBtn) exportBtn.disabled = false;
    };

    window.executePrintOption = function () {
        if (!selectedOption) return;

        if (selectedOption === 'standard') {
            closePrintModal();
            if (typeof printReport === 'function') {
                printReport();
            } else {
                window.print();
            }
            return;
        }

        if (selectedOption === 'sf2_format') {
            closePrintModal();
            var gradeLevel = getSelectedGradeLevel();
            var section = getSelectedSection();
            var subjectId = getSelectedSubjectId();
            var dateFrom = getDateFrom();
            var dateTo = getDateTo();

            if (!gradeLevel || !section) {
                showAlertModal('Please select Grade and Section filters before printing the School Form 2.', { title: 'Notice', type: 'warning' });
                return;
            }

            var my = getReportMonthYear();
            var classId = gradeLevel + '-' + section;

            var url = window.BASE_URL + '/print_sf2_template.php?class_id=' + encodeURIComponent(classId)
                + '&grade_level=' + encodeURIComponent(gradeLevel)
                + '&section=' + encodeURIComponent(section)
                + '&month=' + my.month + '&year=' + my.year;
            if (subjectId) url += '&subject_id=' + encodeURIComponent(subjectId);
            if (dateFrom) url += '&date_from=' + encodeURIComponent(dateFrom);
            if (dateTo) url += '&date_to=' + encodeURIComponent(dateTo);

            var pw = window.open(url, '_blank', 'width=1400,height=900');
            if (!pw) {
                window.location.href = url;
            }
            return;
        }

        if (typeof showAlertModal === 'function') {
            showAlertModal('Unknown print option selected. Please close and reopen the print dialog.', { title: 'Notice', type: 'warning' });
        }
    };

    window.exportReportOption = function () {
        var gradeLevel = getSelectedGradeLevel();
        var section = getSelectedSection();
        var subjectId = getSelectedSubjectId();
        var dateFrom = getDateFrom();
        var dateTo = getDateTo();

        if (selectedOption === 'sf2_format') {
            if (!gradeLevel || !section) {
                showAlertModal('Please select Grade and Section filters before exporting the School Form 2.', { title: 'Notice', type: 'warning' });
                return;
            }

            var my = getReportMonthYear();
            var classId = gradeLevel + '-' + section;

            var exportUrl = window.BASE_URL + '/export_sf2_excel.php'
                + '?class_id=' + encodeURIComponent(classId)
                + '&subject_id=' + encodeURIComponent(subjectId || 0)
                + '&month=' + my.month
                + '&year=' + my.year;

            closePrintModal();
            window.location.href = exportUrl;
            return;
        }

        var format = 'standard';

        closePrintModal();

        var url = window.BASE_URL + '/teacher/export_report.php?format=' + format + '&grade_level=' + encodeURIComponent(gradeLevel) + '&section=' + encodeURIComponent(section) + '&subject_id=' + encodeURIComponent(subjectId);
        if (dateFrom) url += '&date_from=' + encodeURIComponent(dateFrom);
        if (dateTo) url += '&date_to=' + encodeURIComponent(dateTo);

        window.location.href = url;
    };

    var modalOverlay = document.getElementById('printFormatModal');
    if (modalOverlay) {
        modalOverlay.addEventListener('click', function (e) {
            if (e.target === modalOverlay || e.target.classList.contains('print-modal-backdrop')) {
                closePrintModal();
            }
        });
    }

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        var m = getModal();
        if (m && m.classList.contains('active')) {
            closePrintModal();
        }
    }
});

document.addEventListener('DOMContentLoaded', function () {
    var openBtn = document.getElementById('openPrintModalBtn');
    if (openBtn) {
        openBtn.addEventListener('click', function () {
            openPrintModal();
        });
    }
});
})();