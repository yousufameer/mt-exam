jQuery(document).ready(function ($) {

    let currentPage = 1;
    let currentTerm = '';

    /* -----------------------------
    Format Date & Time
    ----------------------------- */

    function formatDateTime(datetime) {

        if (!datetime) return '';

        let date = new Date(datetime);

        if (isNaN(date.getTime())) return datetime;

        return date.toLocaleString("en-US", {
            year: "numeric",
            month: "short",
            day: "numeric",
            hour: "2-digit",
            minute: "2-digit"
        });

    }

    /* -----------------------------
    Get Status Badge HTML
    ----------------------------- */

    function getStatusBadge(status) {

        let statusClass = '';

        if (status === 'Upcoming')  statusClass = 'status-upcoming';
        if (status === 'Ongoing')   statusClass = 'status-ongoing';
        if (status === 'Completed') statusClass = 'status-completed';

        return `<span class="${statusClass}">${status}</span>`;

    }

    /* -----------------------------
    Load Exams (AJAX)
    ----------------------------- */

    function loadExams(page, term) {

        page = page || 1;
        term = term || '';

        // Show loading state
        $('#exam-list').html('<p class="em-loading">Loading exams...</p>');
        $('#exam-pagination').html('');

        $.ajax({
            url: em_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'em_get_exams',
                nonce: em_ajax.nonce,
                page: page,
                term: term
            },

            success: function (res) {

                let html = '';

                if (!res || !res.exams || res.exams.length === 0) {

                    html = '<p class="em-no-results">No exams found.</p>';

                } else {

                    res.exams.forEach(function (exam) {

                        let termNames = Array.isArray(exam.term) && exam.term.length
                            ? exam.term.join(', ')
                            : 'N/A';

                        html += `
                        <div class="exam-card">

                            <h3 class="exam-title">${exam.title}</h3>

                            <p><strong>Subject:</strong> ${exam.subject || 'N/A'}</p>

                            <p><strong>Term:</strong> ${termNames}</p>

                            <p><strong>Start:</strong> ${formatDateTime(exam.start)}</p>

                            <p><strong>End:</strong> ${formatDateTime(exam.end)}</p>

                            <p><strong>Status:</strong> ${getStatusBadge(exam.status)}</p>

                        </div>
                        `;

                    });

                }

                $('#exam-list').html(html);

                /* Pagination */

                let pagination = '';
                let totalPages = res.total_pages || 1;

                if (page > 1) {
                    pagination += `<button id="prev-page" class="em-btn">&#8592; Previous</button>`;
                }

                pagination += `<span class="em-page-info">Page ${page} of ${totalPages}</span>`;

                if (page < totalPages) {
                    pagination += `<button id="next-page" class="em-btn">Next &#8594;</button>`;
                }

                $('#exam-pagination').html(pagination);

                currentPage = page;

            },

            error: function () {
                $('#exam-list').html('<p class="em-error">Something went wrong. Please try again.</p>');
            }

        });

    }

    /* -----------------------------
    Initial Load
    ----------------------------- */

    if ($('#exam-list').length) {
        loadExams(1, '');
    }

    /* -----------------------------
    Pagination
    ----------------------------- */

    $(document).on('click', '#next-page', function () {
        loadExams(currentPage + 1, currentTerm);
    });

    $(document).on('click', '#prev-page', function () {
        loadExams(currentPage - 1, currentTerm);
    });

    /* -----------------------------
    Term Filter
    ----------------------------- */

    $(document).on('change', '#exam-term-filter', function () {

        currentTerm = $(this).val();
        currentPage = 1;

        loadExams(currentPage, currentTerm);

    });

    /* -----------------------------
    Student Result Lookup
    ----------------------------- */

    $(document).on('click', '#search-result', function () {

        let student = $.trim($('#student-search').val());

        if (!student) {
            $('#result-output').html('<p class="em-error">Please enter a student name.</p>');
            return;
        }

        $('#result-output').html('<p class="em-loading">Searching...</p>');

        $.ajax({
            url: em_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'search_student_result',
                nonce: em_ajax.nonce,
                student: student
            },

            success: function (response) {
                $('#result-output').html(response || '<p class="em-no-results">No results found.</p>');
            },

            error: function () {
                $('#result-output').html('<p class="em-error">Something went wrong. Please try again.</p>');
            }

        });

    });

    /* -----------------------------
    Allow search on Enter key
    ----------------------------- */

    $(document).on('keypress', '#student-search', function (e) {
        if (e.which === 13) {
            $('#search-result').trigger('click');
        }
    });

});
