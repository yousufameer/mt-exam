<?php
/**
 * Plugin Name: Exam Management
 * Plugin URI: https://yousufameer.com
 * Description: WordPress plugin for managing exams, students, subjects, and results with frontend dashboard.
 * Version: 1.3
 * Author: Yousuf Ameer
 * Author URI: https://yousufameer.com
 */

if (!defined('ABSPATH')) exit;

define('EM_PLUGIN_URL', plugin_dir_url(__FILE__));

/* --------------------------------------------------
   REGISTER CPTs
-------------------------------------------------- */

class EM_CPT {

    public static function init() {
        add_action('init', [__CLASS__, 'register_cpts']);
        add_action('init', [__CLASS__, 'register_taxonomy']);
    }

    public static function register_cpts() {

        register_post_type('em_student', [
            'labels'       => ['name' => 'Students', 'singular_name' => 'Student'],
            'public'       => true,
            'supports'     => ['title'],
            'menu_icon'    => 'dashicons-groups',
            'show_in_rest' => true,
        ]);

        register_post_type('em_subject', [
            'labels'       => ['name' => 'Subjects', 'singular_name' => 'Subject'],
            'public'       => true,
            'supports'     => ['title'],
            'menu_icon'    => 'dashicons-book',
            'show_in_rest' => true,
        ]);

        register_post_type('em_exam', [
            'labels'       => ['name' => 'Exams', 'singular_name' => 'Exam'],
            'public'       => true,
            'supports'     => ['title'],
            'menu_icon'    => 'dashicons-welcome-learn-more',
            'show_in_rest' => false, // Disabled to ensure classic meta box save flow works correctly
        ]);

        register_post_type('em_result', [
            'labels'       => ['name' => 'Results', 'singular_name' => 'Result'],
            'public'       => true,
            'supports'     => ['title'],
            'menu_icon'    => 'dashicons-chart-bar',
            'show_in_rest' => true,
        ]);
    }

    public static function register_taxonomy() {

        register_taxonomy('em_term', ['em_exam'], [
            'labels'             => ['name' => 'Terms', 'singular_name' => 'Term'],
            'public'             => true,
            'hierarchical'       => true,
            'show_admin_column'  => true,
            'show_in_rest'       => true,
            'meta_box_cb'        => false, // Hide default taxonomy meta box — term is handled inside Exam Details
        ]);
    }
}

add_action('plugins_loaded', function () {
    EM_CPT::init();
});

/* --------------------------------------------------
   TERM META
-------------------------------------------------- */

add_action('em_term_add_form_fields', function () { ?>
    <div class="form-field">
        <label>Start Date</label>
        <input type="date" name="term_start_date">
    </div>
    <div class="form-field">
        <label>End Date</label>
        <input type="date" name="term_end_date">
    </div>
    <?php wp_nonce_field('em_term_meta_nonce', 'em_term_meta_nonce_field'); ?>
<?php });

add_action('em_term_edit_form_fields', function ($term) {
    $start = get_term_meta($term->term_id, 'term_start_date', true);
    $end   = get_term_meta($term->term_id, 'term_end_date', true);
    ?>
    <tr class="form-field">
        <th><label>Start Date</label></th>
        <td><input type="date" name="term_start_date" value="<?php echo esc_attr($start); ?>"></td>
    </tr>
    <tr class="form-field">
        <th><label>End Date</label></th>
        <td><input type="date" name="term_end_date" value="<?php echo esc_attr($end); ?>"></td>
    </tr>
    <tr>
        <th></th>
        <td><?php wp_nonce_field('em_term_meta_nonce', 'em_term_meta_nonce_field'); ?></td>
    </tr>
<?php });

add_action('created_em_term', 'em_save_term_meta');
add_action('edited_em_term',  'em_save_term_meta');

function em_save_term_meta($term_id) {

    // Verify nonce before saving any term meta
    if (!isset($_POST['em_term_meta_nonce_field']) ||
        !wp_verify_nonce($_POST['em_term_meta_nonce_field'], 'em_term_meta_nonce')) {
        return;
    }

    if (!current_user_can('manage_categories')) return;

    $start = isset($_POST['term_start_date']) ? sanitize_text_field($_POST['term_start_date']) : '';
    $end   = isset($_POST['term_end_date'])   ? sanitize_text_field($_POST['term_end_date'])   : '';

    // Both dates are required
    if (empty($start) || empty($end)) {
        set_transient('em_term_error_' . get_current_user_id(), 'missing_dates', 45);
        return;
    }

    // End date must be after start date
    if (strtotime($end) <= strtotime($start)) {
        set_transient('em_term_error_' . get_current_user_id(), 'invalid_dates', 45);
        return;
    }

    update_term_meta($term_id, 'term_start_date', $start);
    update_term_meta($term_id, 'term_end_date',   $end);
}

/* --------------------------------------------------
   EXAM META BOX
-------------------------------------------------- */

add_action('add_meta_boxes', function () {
    add_meta_box('em_exam_details', 'Exam Details', 'em_exam_details_callback', 'em_exam');
});

function em_exam_details_callback($post) {

    $start   = get_post_meta($post->ID, 'em_exam_start',   true);
    $end     = get_post_meta($post->ID, 'em_exam_end',     true);
    $subject = get_post_meta($post->ID, 'em_exam_subject', true);

    // Convert stored Y-m-d H:i:s to datetime-local format for the input
    $start_value = $start ? date('Y-m-d\TH:i', strtotime($start)) : '';
    $end_value   = $end   ? date('Y-m-d\TH:i', strtotime($end))   : '';

    wp_nonce_field('em_exam_nonce', 'em_exam_nonce_field');

    $subjects = get_posts(['post_type' => 'em_subject', 'numberposts' => -1]);

    $terms = get_terms([
        'taxonomy'   => 'em_term',
        'hide_empty' => false,
    ]);

    $assigned_terms   = wp_get_object_terms($post->ID, 'em_term');
    $assigned_term_id = !empty($assigned_terms) ? $assigned_terms[0]->term_id : '';
    ?>

    <style>
    .em-meta-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px 24px;
        padding: 16px 4px 8px;
    }
    .em-meta-field { display: flex; flex-direction: column; gap: 6px; }
    .em-meta-field label {
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #757575;
    }
    .em-meta-field input[type="datetime-local"],
    .em-meta-field select {
        width: 100%;
        padding: 8px 10px;
        border: 1px solid #dcdcde;
        border-radius: 4px;
        font-size: 13px;
        color: #1e1e1e;
        background: #fff;
        box-sizing: border-box;
        height: 36px;
    }
    .em-meta-field input[type="datetime-local"]:focus,
    .em-meta-field select:focus {
        border-color: #2271b1;
        outline: 2px solid rgba(34,113,177,0.15);
    }
    .em-meta-required {
        color: #d63638;
        margin-left: 2px;
    }
    .em-meta-divider {
        grid-column: 1 / -1;
        border: none;
        border-top: 1px solid #f0f0f0;
        margin: 4px 0;
    }
    </style>

    <div class="em-meta-grid">

        <div class="em-meta-field">
            <label>Start Date &amp; Time <span class="em-meta-required">*</span></label>
            <input type="datetime-local" name="em_exam_start"
                   value="<?php echo esc_attr($start_value); ?>" required>
        </div>

        <div class="em-meta-field">
            <label>End Date &amp; Time <span class="em-meta-required">*</span></label>
            <input type="datetime-local" name="em_exam_end"
                   value="<?php echo esc_attr($end_value); ?>" required>
        </div>

        <hr class="em-meta-divider">

        <div class="em-meta-field">
            <label>Subject <span class="em-meta-required">*</span></label>
            <select name="em_exam_subject" required>
                <option value="">— Select Subject —</option>
                <?php foreach ($subjects as $sub) : ?>
                    <option value="<?php echo intval($sub->ID); ?>"
                        <?php selected($subject, $sub->ID); ?>>
                        <?php echo esc_html($sub->post_title); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="em-meta-field">
            <label>Academic Term <span class="em-meta-required">*</span></label>
            <select name="em_exam_term" required>
                <option value="">— Select Term —</option>
                <?php foreach ($terms as $term) : ?>
                    <option value="<?php echo intval($term->term_id); ?>"
                        <?php selected($assigned_term_id, $term->term_id); ?>>
                        <?php echo esc_html($term->name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

    </div>

    <?php
}

/* --------------------------------------------------
   SAVE EXAM
-------------------------------------------------- */

add_action('save_post_em_exam', 'em_save_exam');

function em_save_exam($post_id) {

    // Block REST API saves — meta boxes only work via classic editor POST
    if (defined('REST_REQUEST') && REST_REQUEST) return;

    // Verify nonce
    if (!isset($_POST['em_exam_nonce_field']) ||
        !wp_verify_nonce($_POST['em_exam_nonce_field'], 'em_exam_nonce')) {
        return;
    }

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    $start   = isset($_POST['em_exam_start'])   ? sanitize_text_field($_POST['em_exam_start'])  : '';
    $end     = isset($_POST['em_exam_end'])     ? sanitize_text_field($_POST['em_exam_end'])    : '';
    $subject = isset($_POST['em_exam_subject']) ? intval($_POST['em_exam_subject'])              : 0;
    $term    = isset($_POST['em_exam_term'])    ? intval($_POST['em_exam_term'])                 : 0;

    $start_ts = $start ? strtotime($start) : false;
    $end_ts   = $end   ? strtotime($end)   : false;

    $user_id = get_current_user_id();

    /* REQUIRED FIELD CHECK */
    if (empty($start) || empty($end) || empty($subject) || empty($term) || !$start_ts || !$end_ts) {

        em_revert_post_meta($post_id);

        // Store error in transient keyed to this user so it survives the redirect
        set_transient('em_exam_error_' . $user_id, 'missing_fields', 45);

        return;
    }

    /* END TIME MUST BE AFTER START TIME */
    if ($end_ts <= $start_ts) {

        em_revert_post_meta($post_id);

        set_transient('em_exam_error_' . $user_id, 'invalid_time', 45);

        return;
    }

    /* ALL VALID — SAVE */
    update_post_meta($post_id, 'em_exam_start',   date('Y-m-d H:i:s', $start_ts));
    update_post_meta($post_id, 'em_exam_end',     date('Y-m-d H:i:s', $end_ts));
    update_post_meta($post_id, 'em_exam_subject', $subject);

    wp_set_object_terms($post_id, $term, 'em_term');
}

/**
 * Restore previously saved meta when validation fails
 * so invalid data never actually gets stored.
 */
function em_revert_post_meta($post_id) {

    $old_start   = get_post_meta($post_id, 'em_exam_start',   true);
    $old_end     = get_post_meta($post_id, 'em_exam_end',     true);
    $old_subject = get_post_meta($post_id, 'em_exam_subject', true);

    if ($old_start)   update_post_meta($post_id, 'em_exam_start',   $old_start);
    if ($old_end)     update_post_meta($post_id, 'em_exam_end',     $old_end);
    if ($old_subject) update_post_meta($post_id, 'em_exam_subject', $old_subject);
}

/* --------------------------------------------------
   ADMIN ERROR NOTICES
-------------------------------------------------- */

add_action('admin_notices', function () {

    $screen  = get_current_screen();
    $user_id = get_current_user_id();

    // Term date validation errors — show on term edit/list screens
    if ($screen && isset($screen->taxonomy) && $screen->taxonomy === 'em_term') {

        $term_error = get_transient('em_term_error_' . $user_id);

        if ($term_error) {
            delete_transient('em_term_error_' . $user_id);
        }

        if ($term_error === 'missing_dates') {
            echo '<div class="notice notice-error is-dismissible">
                <p><strong>Validation Error:</strong> Both Start Date and End Date are required for a term.</p>
            </div>';
        }

        if ($term_error === 'invalid_dates') {
            echo '<div class="notice notice-error is-dismissible">
                <p><strong>Validation Error:</strong> End date must be after the start date.</p>
            </div>';
        }
    }

    // Exam validation errors — show on exam edit screen
    if (!$screen || $screen->post_type !== 'em_exam') return;

    $user_id       = get_current_user_id();
    $transient_key = 'em_exam_error_' . $user_id;
    $error         = get_transient($transient_key);

    // Delete immediately so it only shows once
    if ($error) {
        delete_transient($transient_key);
    }

    if ($error === 'missing_fields') {
        echo '<div class="notice notice-error is-dismissible">
            <p><strong>Validation Error:</strong> Please fill in all required fields — Start Date, End Date, Subject, and Term.</p>
        </div>';
    }

    if ($error === 'invalid_time') {
        echo '<div class="notice notice-error is-dismissible">
            <p><strong>Validation Error:</strong> End date and time must be after the start date and time. Your previous values have been restored.</p>
        </div>';
    }
});

/* --------------------------------------------------
   RESULT META BOX
-------------------------------------------------- */

add_action('add_meta_boxes', function () {
    add_meta_box('em_result_details', 'Result Details', 'em_result_details_callback', 'em_result');
});

function em_result_details_callback($post) {

    wp_nonce_field('em_result_nonce', 'em_result_nonce_field');

    $selected_exam = get_post_meta($post->ID, 'em_result_exam', true);
    $students      = get_posts(['post_type' => 'em_student', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC']);
    ?>

    <style>
    .em-result-wrap { padding: 12px 4px 4px; }

    /* Exam section */
    .em-exam-linked {
        display: flex;
        align-items: center;
        gap: 10px;
        background: #f0f6fc;
        border: 1px solid #c3d9f0;
        border-radius: 6px;
        padding: 10px 14px;
        margin-bottom: 20px;
    }
    .em-exam-linked .em-exam-icon {
        width: 32px; height: 32px;
        background: #2271b1;
        border-radius: 6px;
        display: flex; align-items: center; justify-content: center;
        flex-shrink: 0;
        color: #fff;
        font-size: 15px;
    }
    .em-exam-linked .em-exam-info { flex: 1; }
    .em-exam-linked .em-exam-info strong {
        display: block;
        font-size: 13px;
        color: #1e1e1e;
    }
    .em-exam-linked .em-exam-info span {
        font-size: 11px;
        color: #757575;
    }
    .em-exam-select-wrap { margin-bottom: 20px; }
    .em-exam-select-wrap label {
        display: block;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #757575;
        margin-bottom: 6px;
    }
    .em-exam-select-wrap select {
        width: 100%;
        max-width: 400px;
        padding: 8px 10px;
        border: 1px solid #dcdcde;
        border-radius: 4px;
        font-size: 13px;
        height: 36px;
    }

    /* Section heading */
    .em-marks-heading {
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #757575;
        margin: 0 0 12px;
        padding-bottom: 8px;
        border-bottom: 1px solid #f0f0f0;
    }

    /* Student marks grid */
    .em-marks-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 12px;
    }
    .em-mark-card {
        border: 1px solid #e2e4e7;
        border-radius: 6px;
        padding: 12px 14px;
        background: #fff;
        transition: border-color 0.15s;
    }
    .em-mark-card:focus-within { border-color: #2271b1; }

    .em-mark-card .em-mark-student {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 10px;
    }
    .em-mark-avatar {
        width: 28px; height: 28px;
        border-radius: 50%;
        background: #e8f0fe;
        color: #1a73e8;
        font-size: 10px;
        font-weight: 600;
        display: flex; align-items: center; justify-content: center;
        flex-shrink: 0;
    }
    .em-mark-name {
        font-size: 12px;
        font-weight: 500;
        color: #1e1e1e;
        line-height: 1.3;
    }

    .em-mark-input-row {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .em-mark-input-row input[type="number"] {
        width: 70px;
        padding: 6px 8px;
        border: 1px solid #dcdcde;
        border-radius: 4px;
        font-size: 14px;
        font-weight: 600;
        text-align: center;
        color: #1e1e1e;
    }
    .em-mark-input-row input[type="number"]:focus {
        border-color: #2271b1;
        outline: 2px solid rgba(34,113,177,0.15);
    }

    /* Status badge */
    .em-mark-status {
        font-size: 10px;
        font-weight: 600;
        padding: 3px 8px;
        border-radius: 20px;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        transition: all 0.2s;
    }
    .em-status-empty    { background: #f5f5f5; color: #aaa; }
    .em-status-poor     { background: #fdecea; color: #c62828; }
    .em-status-fair     { background: #fff3cd; color: #856404; }
    .em-status-good     { background: #e8f5e9; color: #2e7d32; }
    .em-status-excellent{ background: #e3f2fd; color: #1565c0; }

    .em-out-of { font-size: 10px; color: #aaa; margin-left: auto; }
    </style>

    <div class="em-result-wrap">

        <?php if ( $selected_exam ) :
            $exam_title    = get_the_title($selected_exam);
            $exam_subject  = get_post_meta($selected_exam, 'em_exam_subject', true);
            $subject_title = $exam_subject ? get_the_title($exam_subject) : '';
        ?>

            <?php /* Exam already linked — show as read-only, hidden input keeps the value */ ?>
            <input type="hidden" name="em_result_exam" value="<?php echo intval($selected_exam); ?>">

            <div class="em-exam-linked">
                <div class="em-exam-icon">&#128196;</div>
                <div class="em-exam-info">
                    <strong><?php echo esc_html($exam_title); ?></strong>
                    <?php if ($subject_title): ?>
                        <span>Subject: <?php echo esc_html($subject_title); ?></span>
                    <?php endif; ?>
                </div>
            </div>

        <?php else : ?>

            <?php /* No exam linked yet — show the dropdown */ ?>
            <div class="em-exam-select-wrap">
                <label>Link to Exam <span style="color:#d63638">*</span></label>
                <select name="em_result_exam">
                    <option value="">— Select an Exam —</option>
                    <?php
                    $exams = get_posts(['post_type' => 'em_exam', 'numberposts' => -1]);
                    foreach ($exams as $exam) :
                    ?>
                        <option value="<?php echo intval($exam->ID); ?>">
                            <?php echo esc_html($exam->post_title); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

        <?php endif; ?>

        <p class="em-marks-heading">Student Marks</p>

        <div class="em-marks-grid">
        <?php foreach ($students as $student) :
            $marks    = get_post_meta($post->ID, 'marks_' . $student->ID, true);
            $parts    = explode(' ', trim($student->post_title));
            $initials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
        ?>
            <div class="em-mark-card">
                <div class="em-mark-student">
                    <div class="em-mark-avatar"><?php echo esc_html($initials); ?></div>
                    <span class="em-mark-name"><?php echo esc_html($student->post_title); ?></span>
                </div>
                <div class="em-mark-input-row">
                    <input type="number"
                           name="marks[<?php echo intval($student->ID); ?>]"
                           value="<?php echo esc_attr($marks); ?>"
                           min="0" max="100"
                           class="em-mark-input"
                           data-student="<?php echo intval($student->ID); ?>"
                           oninput="emUpdateStatus(this)">
                    <span class="em-mark-status em-status-<?php
                        if ($marks === '') echo 'empty';
                        elseif ($marks < 40)  echo 'poor';
                        elseif ($marks < 60)  echo 'fair';
                        elseif ($marks < 80)  echo 'good';
                        else                   echo 'excellent';
                    ?>" id="em-status-<?php echo intval($student->ID); ?>"><?php
                        if ($marks === '')     echo '—';
                        elseif ($marks < 40)  echo 'Poor';
                        elseif ($marks < 60)  echo 'Fair';
                        elseif ($marks < 80)  echo 'Good';
                        else                   echo 'Excellent';
                    ?></span>
                    <span class="em-out-of">/ 100</span>
                </div>
            </div>
        <?php endforeach; ?>
        </div>

    </div>

    <script>
    function emUpdateStatus(input) {
        var val      = input.value.trim();
        var sid      = input.getAttribute('data-student');
        var badge    = document.getElementById('em-status-' + sid);
        if (!badge) return;

        badge.className = 'em-mark-status ';
        if (val === '' || isNaN(val)) {
            badge.classList.add('em-status-empty');
            badge.textContent = '—';
        } else {
            var v = parseInt(val, 10);
            if (v < 40)       { badge.classList.add('em-status-poor');      badge.textContent = 'Poor'; }
            else if (v < 60)  { badge.classList.add('em-status-fair');      badge.textContent = 'Fair'; }
            else if (v < 80)  { badge.classList.add('em-status-good');      badge.textContent = 'Good'; }
            else              { badge.classList.add('em-status-excellent');  badge.textContent = 'Excellent'; }
        }
    }
    </script>

    <?php
}

/* --------------------------------------------------
   SAVE RESULTS
-------------------------------------------------- */

add_action('save_post_em_result', function ($post_id) {

    if (!isset($_POST['em_result_nonce_field']) ||
        !wp_verify_nonce($_POST['em_result_nonce_field'], 'em_result_nonce')) return;

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

    if (isset($_POST['em_result_exam'])) {
        update_post_meta($post_id, 'em_result_exam', intval($_POST['em_result_exam']));
    }

    if (isset($_POST['marks'])) {
        foreach ($_POST['marks'] as $student_id => $mark) {
            update_post_meta($post_id, 'marks_' . intval($student_id), intval($mark));
        }
    }
});

/* --------------------------------------------------
   AJAX EXAM LIST — ORDERED: Ongoing → Upcoming → Completed
-------------------------------------------------- */

add_action('wp_ajax_em_get_exams',        'em_get_exams_ordered');
add_action('wp_ajax_nopriv_em_get_exams', 'em_get_exams_ordered');

function em_get_exams_ordered() {

    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'em_ajax_nonce')) {
        wp_send_json_error('Invalid request.', 403);
    }

    $paged       = isset($_POST['page']) ? intval($_POST['page']) : 1;
    $term_filter = isset($_POST['term']) ? intval($_POST['term']) : 0;
    $per_page    = 6;
    $now         = current_time('timestamp');

    $args = [
        'post_type'      => 'em_exam',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ];

    if ($term_filter) {
        $args['tax_query'] = [[
            'taxonomy' => 'em_term',
            'field'    => 'term_id',
            'terms'    => $term_filter,
        ]];
    }

    $all_ids = get_posts($args);

    if (empty($all_ids)) {
        wp_send_json(['exams' => [], 'total_pages' => 0]);
    }

    $ongoing   = [];
    $upcoming  = [];
    $completed = [];

    foreach ($all_ids as $id) {

        $start_ts = strtotime(get_post_meta($id, 'em_exam_start', true));
        $end_ts   = strtotime(get_post_meta($id, 'em_exam_end',   true));

        if ($now >= $start_ts && $now <= $end_ts) {
            $ongoing[] = $id;
        } elseif ($now < $start_ts) {
            $upcoming[] = $id;
        } else {
            $completed[] = $id;
        }
    }

    // Upcoming: soonest first
    usort($upcoming, function ($a, $b) {
        return strtotime(get_post_meta($a, 'em_exam_start', true))
             - strtotime(get_post_meta($b, 'em_exam_start', true));
    });

    // Completed: most recent first
    usort($completed, function ($a, $b) {
        return strtotime(get_post_meta($b, 'em_exam_end', true))
             - strtotime(get_post_meta($a, 'em_exam_end', true));
    });

    $sorted_ids  = array_merge($ongoing, $upcoming, $completed);
    $total       = count($sorted_ids);
    $total_pages = $total > 0 ? ceil($total / $per_page) : 0;
    $offset      = ($paged - 1) * $per_page;
    $page_ids    = array_slice($sorted_ids, $offset, $per_page);

    $exams = [];

    foreach ($page_ids as $id) {

        $start      = get_post_meta($id, 'em_exam_start',   true);
        $end        = get_post_meta($id, 'em_exam_end',     true);
        $subject_id = get_post_meta($id, 'em_exam_subject', true);
        $subject    = $subject_id ? get_the_title($subject_id) : 'N/A';

        $terms      = wp_get_post_terms($id, 'em_term');
        $term_names = [];
        foreach ($terms as $t) {
            $term_names[] = $t->name;
        }

        $start_ts = strtotime($start);
        $end_ts   = strtotime($end);

        if ($now >= $start_ts && $now <= $end_ts) {
            $status = 'Ongoing';
        } elseif ($now < $start_ts) {
            $status = 'Upcoming';
        } else {
            $status = 'Completed';
        }

        $exams[] = [
            'title'   => get_the_title($id),
            'start'   => $start,
            'end'     => $end,
            'subject' => $subject,
            'term'    => $term_names,
            'status'  => $status,
        ];
    }

    wp_send_json([
        'exams'       => $exams,
        'total_pages' => $total_pages,
    ]);
}

/* --------------------------------------------------
   STUDENT RESULT LOOKUP AJAX
-------------------------------------------------- */

add_action('wp_ajax_search_student_result',        'search_student_result');
add_action('wp_ajax_nopriv_search_student_result', 'search_student_result');

function search_student_result() {

    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'em_ajax_nonce')) {
        echo 'Invalid request.';
        wp_die();
    }

    $name = sanitize_text_field($_POST['student'] ?? '');

    if (empty($name)) {
        echo 'Please enter a student name.';
        wp_die();
    }

    $students = get_posts([
        'post_type'   => 'em_student',
        's'           => $name,
        'numberposts' => 1,
    ]);

    if (!$students) {
        echo 'No student found.';
        wp_die();
    }

    $student = $students[0];

    $results = get_posts([
        'post_type'   => 'em_result',
        'numberposts' => -1,
    ]);

    $found = false;

    foreach ($results as $result) {

        $mark = get_post_meta($result->ID, 'marks_' . $student->ID, true);

        if ($mark !== '') {
            $exam_id = get_post_meta($result->ID, 'em_result_exam', true);
            $exam    = get_the_title($exam_id);
            echo '<p><strong>' . esc_html($exam) . ':</strong> ' . intval($mark) . '</p>';
            $found = true;
        }
    }

    if (!$found) {
        echo '<p>No results found for this student.</p>';
    }

    wp_die();
}

/* --------------------------------------------------
   RESULTS LIST — TERM FILTER DROPDOWN
   Adds a "Filter by Term" dropdown to the Results
   admin list page, plus an Exam column.
-------------------------------------------------- */

// Add term dropdown above the Results list
add_action('restrict_manage_posts', function($post_type) {

    if ($post_type !== 'em_result') return;

    $terms = get_terms([
        'taxonomy'   => 'em_term',
        'hide_empty' => false,
        'orderby'    => 'meta_value',
        'order'      => 'DESC',
        'meta_key'   => 'term_start_date',
    ]);

    if (empty($terms) || is_wp_error($terms)) return;

    $selected = isset($_GET['em_term_filter']) ? intval($_GET['em_term_filter']) : 0;

    echo '<select name="em_term_filter" id="em_term_filter">';
    echo '<option value="">All Terms</option>';
    foreach ($terms as $term) {
        printf(
            '<option value="%d" %s>%s</option>',
            $term->term_id,
            selected($selected, $term->term_id, false),
            esc_html($term->name)
        );
    }
    echo '</select>';
});

// Filter the query when term is selected
add_filter('parse_query', function($query) {

    global $pagenow;

    if ($pagenow !== 'edit.php') return;
    if (!isset($query->query_vars['post_type']) || $query->query_vars['post_type'] !== 'em_result') return;
    if (empty($_GET['em_term_filter'])) return;

    $term_id    = intval($_GET['em_term_filter']);
    $exam_ids   = get_objects_in_term($term_id, 'em_term');

    if (empty($exam_ids) || is_wp_error($exam_ids)) {
        // No exams in this term — return nothing
        $query->query_vars['meta_query'] = [[
            'key'   => 'em_result_exam',
            'value' => '0',
            'compare' => '=',
        ]];
        return;
    }

    $query->query_vars['meta_query'] = [[
        'key'     => 'em_result_exam',
        'value'   => array_map('intval', $exam_ids),
        'compare' => 'IN',
    ]];
});

// Add custom columns to Results list
add_filter('manage_em_result_posts_columns', function($columns) {
    $new = [];
    foreach ($columns as $key => $label) {
        $new[$key] = $label;
        if ($key === 'title') {
            $new['em_result_term']    = 'Term';
            $new['em_result_exam']    = 'Exam';
            $new['em_result_students'] = 'Students';
        }
    }
    return $new;
});

// Populate the custom columns
add_action('manage_em_result_posts_custom_column', function($column, $post_id) {

    if ($column === 'em_result_exam') {
        $exam_id = get_post_meta($post_id, 'em_result_exam', true);
        if ($exam_id) {
            echo '<strong>' . esc_html(get_the_title($exam_id)) . '</strong>';
        } else {
            echo '<span style="color:#aaa">—</span>';
        }
    }

    if ($column === 'em_result_term') {
        $exam_id = get_post_meta($post_id, 'em_result_exam', true);
        if ($exam_id) {
            $terms = wp_get_post_terms($exam_id, 'em_term');
            if (!empty($terms) && !is_wp_error($terms)) {
                foreach ($terms as $term) {
                    echo '<span style="display:inline-block;padding:2px 8px;background:#e8f0fe;color:#1a73e8;border-radius:4px;font-size:11px;font-weight:600">'
                        . esc_html($term->name) . '</span> ';
                }
            } else {
                echo '<span style="color:#aaa">—</span>';
            }
        }
    }

    if ($column === 'em_result_students') {
        global $wpdb;
        // Count how many students have marks on this result
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta}
             WHERE post_id = %d
             AND meta_key LIKE 'marks_%%'
             AND meta_value != ''",
            $post_id
        ));
        echo '<span style="font-weight:500">' . intval($count) . '</span>'
           . '<span style="color:#aaa;font-size:11px"> student(s)</span>';
    }

}, 10, 2);

/* --------------------------------------------------
   ASSETS
-------------------------------------------------- */

add_action('wp_enqueue_scripts', function () {

    wp_enqueue_script(
        'em-exam-ajax',
        EM_PLUGIN_URL . 'js/exam-ajax.js',
        ['jquery'],
        '1.3',
        true
    );

    wp_localize_script('em-exam-ajax', 'em_ajax', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('em_ajax_nonce'),
    ]);

    wp_enqueue_style('em-style', EM_PLUGIN_URL . 'css/exam-style.css');
});

/* --------------------------------------------------
   SHORTCODES
-------------------------------------------------- */

add_shortcode('exam_list', function () {

    $terms   = get_terms(['taxonomy' => 'em_term', 'hide_empty' => false]);
    $options = '<option value="">All Terms</option>';

    foreach ($terms as $term) {
        $options .= '<option value="' . intval($term->term_id) . '">' . esc_html($term->name) . '</option>';
    }

    return '
        <select id="exam-term-filter">' . $options . '</select>
        <div id="exam-list">Loading Exams...</div>
        <div id="exam-pagination"></div>
    ';
});

add_shortcode('student_result_lookup', function () {
    return '
        <input type="text" id="student-search" placeholder="Enter Student Name">
        <button id="search-result">Search</button>
        <div id="result-output"></div>
    ';
});

/* --------------------------------------------------
   TOP STUDENTS SHORTCODE  [em_top_students]
   Top 3 students per term, latest term first.
-------------------------------------------------- */

add_shortcode('em_top_students', 'em_top_students_shortcode');

function em_top_students_shortcode() {

    global $wpdb;

    $terms = get_terms([
        'taxonomy'   => 'em_term',
        'hide_empty' => false,
        'orderby'    => 'meta_value',
        'order'      => 'DESC',
        'meta_key'   => 'term_start_date',
    ]);

    if (empty($terms) || is_wp_error($terms)) {
        return '<p>No academic terms found.</p>';
    }

    ob_start();

    echo '<div class="em-top-students-wrap">';

    foreach ($terms as $term) {

        echo '<div class="em-term-block">';
        echo '<h2 class="em-term-title">' . esc_html($term->name) . '</h2>';

        $exam_ids = get_objects_in_term($term->term_id, 'em_term');

        if (empty($exam_ids) || is_wp_error($exam_ids)) {
            echo '<p>No exams in this term.</p></div>';
            continue;
        }

        $exam_ids_in = implode(',', array_map('intval', $exam_ids));

        $result_ids = $wpdb->get_col(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = 'em_result_exam'
             AND CAST(meta_value AS UNSIGNED) IN ({$exam_ids_in})"
        );

        if (empty($result_ids)) {
            echo '<p>No results recorded for this term.</p></div>';
            continue;
        }

        $result_ids_in = implode(',', array_map('intval', $result_ids));

        $students = get_posts([
            'post_type'      => 'em_student',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]);

        if (empty($students)) {
            echo '<p>No students found.</p></div>';
            continue;
        }

        $student_totals = [];

        foreach ($students as $student_id) {

            $meta_key = 'marks_' . $student_id;

            $total = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT SUM(CAST(meta_value AS UNSIGNED))
                     FROM {$wpdb->postmeta}
                     WHERE post_id IN ({$result_ids_in})
                     AND meta_key = %s
                     AND meta_value != ''",
                    $meta_key
                )
            );

            if (!is_null($total) && $total > 0) {
                $student_totals[$student_id] = (int) $total;
            }
        }

        if (empty($student_totals)) {
            echo '<p>No marks recorded for this term.</p></div>';
            continue;
        }

        arsort($student_totals);
        $top3 = array_slice($student_totals, 0, 3, true);

        echo '<ol class="em-top-students-list">';

        $rank = 1;
        foreach ($top3 as $student_id => $total_marks) {
            echo '<li class="em-top-student-item">';
            echo '<span class="em-rank">#' . $rank . '</span> ';
            echo '<span class="em-student-name">' . esc_html(get_the_title($student_id)) . '</span>';
            echo ' &mdash; <span class="em-marks">' . $total_marks . ' marks</span>';
            echo '</li>';
            $rank++;
        }

        echo '</ol>';
        echo '</div>';
    }

    echo '</div>';

    return ob_get_clean();
}

/* --------------------------------------------------
   BULK CSV IMPORT
   Admin: Results → Import CSV
   Format: student_id, exam_id, marks
-------------------------------------------------- */

add_action('admin_menu', 'em_add_import_menu');

function em_add_import_menu() {
    add_submenu_page(
        'edit.php?post_type=em_result',
        'Import Results via CSV',
        'Import CSV',
        'manage_options',
        'em-import-csv',
        'em_import_csv_page'
    );
}

function em_import_csv_page() {

    $messages = [];
    $errors   = [];

    if (isset($_POST['em_import_csv_nonce']) &&
        wp_verify_nonce($_POST['em_import_csv_nonce'], 'em_import_csv')) {

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        if (!isset($_FILES['em_csv_file']) || $_FILES['em_csv_file']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Please upload a valid CSV file.';
        } else {

            // Check file size — reject anything over 2MB
            if ($_FILES['em_csv_file']['size'] > 2 * 1024 * 1024) {
                $errors[] = 'File too large. Maximum size is 2MB.';
            }
            // Verify it is actually a CSV by checking MIME type and extension
            elseif (!in_array($_FILES['em_csv_file']['type'], ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'], true) ||
                     strtolower(pathinfo($_FILES['em_csv_file']['name'], PATHINFO_EXTENSION)) !== 'csv') {
                $errors[] = 'Invalid file type. Please upload a .csv file only.';
            } else {

            $file    = $_FILES['em_csv_file']['tmp_name'];
            $handle  = fopen($file, 'r');
            $row_num = 0;
            $imported = 0;
            $skipped  = 0;

            if ($handle === false) {
                $errors[] = 'Could not read the uploaded file.';
            } else {

                while (($row = fgetcsv($handle, 1000, ',')) !== false) {

                    $row_num++;

                    // Skip header row
                    if ($row_num === 1 && strtolower(trim($row[0])) === 'student_id') {
                        continue;
                    }

                    if (count($row) < 3) {
                        $errors[] = "Row {$row_num}: Not enough columns. Expected student_id, exam_id, marks.";
                        $skipped++;
                        continue;
                    }

                    $student_id = intval(trim($row[0]));
                    $exam_id    = intval(trim($row[1]));
                    $marks      = intval(trim($row[2]));

                    if (get_post_type($student_id) !== 'em_student') {
                        $errors[] = "Row {$row_num}: Student ID {$student_id} not found.";
                        $skipped++;
                        continue;
                    }

                    if (get_post_type($exam_id) !== 'em_exam') {
                        $errors[] = "Row {$row_num}: Exam ID {$exam_id} not found.";
                        $skipped++;
                        continue;
                    }

                    if ($marks < 0 || $marks > 100) {
                        $errors[] = "Row {$row_num}: Marks must be between 0 and 100. Got {$marks}.";
                        $skipped++;
                        continue;
                    }

                    $existing_results = get_posts([
                        'post_type'      => 'em_result',
                        'posts_per_page' => 1,
                        'meta_key'       => 'em_result_exam',
                        'meta_value'     => $exam_id,
                        'fields'         => 'ids',
                    ]);

                    if (!empty($existing_results)) {
                        $result_id = $existing_results[0];
                    } else {
                        $result_id = wp_insert_post([
                            'post_type'   => 'em_result',
                            'post_title'  => 'Result - ' . get_the_title($exam_id),
                            'post_status' => 'publish',
                        ]);

                        if (is_wp_error($result_id)) {
                            $errors[] = "Row {$row_num}: Could not create result post.";
                            $skipped++;
                            continue;
                        }

                        update_post_meta($result_id, 'em_result_exam', $exam_id);
                    }

                    update_post_meta($result_id, 'marks_' . $student_id, $marks);
                    $imported++;
                }

                fclose($handle);
                $messages[] = "Import complete. {$imported} record(s) imported, {$skipped} skipped.";
            }
            } // end file type/size check
        }
    }

    ?>
    <div class="wrap" id="em-import-wrap">

    <style>
    #em-import-wrap { max-width: 860px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }

    .em-import-header { margin: 0 0 24px; }
    .em-import-header h1 { font-size: 22px; font-weight: 600; color: #1e1e1e; margin: 0 0 4px; }
    .em-import-header p  { font-size: 13px; color: #757575; margin: 0; }

    .em-import-layout {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        align-items: start;
    }

    /* Card base */
    .em-import-card {
        background: #fff;
        border: 1px solid #e2e4e7;
        border-radius: 8px;
        overflow: hidden;
    }
    .em-import-card-header {
        padding: 14px 20px;
        border-bottom: 1px solid #f0f0f0;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .em-import-card-icon {
        width: 32px; height: 32px;
        border-radius: 6px;
        display: flex; align-items: center; justify-content: center;
        font-size: 15px;
        flex-shrink: 0;
    }
    .em-icon-upload { background: #e8f0fe; }
    .em-icon-format { background: #e6f4ea; }
    .em-import-card-header h2 {
        font-size: 13px;
        font-weight: 600;
        color: #1e1e1e;
        margin: 0;
    }
    .em-import-card-body { padding: 20px; }

    /* Upload zone */
    .em-upload-zone {
        border: 2px dashed #dcdcde;
        border-radius: 6px;
        padding: 28px 20px;
        text-align: center;
        cursor: pointer;
        transition: border-color 0.15s, background 0.15s;
        margin-bottom: 16px;
        position: relative;
    }
    .em-upload-zone:hover,
    .em-upload-zone.dragover { border-color: #2271b1; background: #f0f6fc; }
    .em-upload-zone input[type="file"] {
        position: absolute; inset: 0;
        opacity: 0; cursor: pointer; width: 100%; height: 100%;
    }
    .em-upload-icon { font-size: 28px; margin-bottom: 8px; }
    .em-upload-label { font-size: 13px; font-weight: 500; color: #1e1e1e; margin: 0 0 4px; }
    .em-upload-hint  { font-size: 11px; color: #aaa; margin: 0; }
    .em-upload-filename {
        display: none;
        font-size: 12px;
        color: #2271b1;
        font-weight: 500;
        margin-top: 6px;
    }

    /* Rules list */
    .em-rules { list-style: none; margin: 0 0 16px; padding: 0; display: flex; flex-direction: column; gap: 6px; }
    .em-rules li {
        display: flex; align-items: flex-start; gap: 8px;
        font-size: 12px; color: #555; line-height: 1.5;
    }
    .em-rules li::before {
        content: '';
        width: 5px; height: 5px;
        border-radius: 50%;
        background: #2271b1;
        flex-shrink: 0;
        margin-top: 5px;
    }
    .em-rules code {
        background: #f0f0f0;
        padding: 1px 5px;
        border-radius: 3px;
        font-size: 11px;
    }

    /* Submit button */
    .em-submit-btn {
        width: 100%;
        background: #2271b1;
        color: #fff;
        border: none;
        border-radius: 6px;
        padding: 10px 16px;
        font-size: 13px;
        font-weight: 500;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        transition: background 0.15s;
    }
    .em-submit-btn:hover { background: #135e96; }

    /* Format table */
    .em-format-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 12px;
    }
    .em-format-table thead tr { background: #f6f7f7; }
    .em-format-table th {
        padding: 8px 12px;
        text-align: left;
        font-size: 10px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #757575;
        border-bottom: 1px solid #e2e4e7;
    }
    .em-format-table td {
        padding: 9px 12px;
        border-bottom: 1px solid #f5f5f5;
        color: #3c3c3c;
        font-family: monospace;
        font-size: 12px;
    }
    .em-format-table tbody tr:last-child td { border-bottom: none; }
    .em-format-table tbody tr:hover { background: #fafafa; }

    .em-col-badge {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 4px;
        font-size: 10px;
        font-weight: 600;
        text-transform: uppercase;
    }
    .em-col-student { background: #e8f0fe; color: #1a73e8; }
    .em-col-exam    { background: #fce8b2; color: #b06000; }
    .em-col-marks   { background: #e6f4ea; color: #1e7e34; }

    /* Results summary */
    .em-import-result {
        display: flex;
        gap: 12px;
        margin-bottom: 20px;
    }
    .em-result-stat {
        flex: 1;
        background: #f6f7f7;
        border-radius: 6px;
        padding: 12px;
        text-align: center;
    }
    .em-result-stat .num { font-size: 24px; font-weight: 600; }
    .em-result-stat .lbl { font-size: 11px; color: #757575; margin-top: 2px; }
    .em-result-imported .num { color: #1e7e34; }
    .em-result-skipped  .num { color: #c62828; }

    /* Error list */
    .em-error-list {
        max-height: 200px;
        overflow-y: auto;
        background: #fff8f8;
        border: 1px solid #f5c6c6;
        border-radius: 6px;
        padding: 10px 14px;
        margin-top: 12px;
    }
    .em-error-list p {
        font-size: 12px;
        color: #c62828;
        margin: 4px 0;
        line-height: 1.5;
    }
    </style>

        <div class="em-import-header">
            <h1>&#128196; Import Results via CSV</h1>
            <p>Bulk upload student marks by uploading a formatted CSV file.</p>
        </div>

        <?php
        // Parse imported/skipped from message for stat display
        $imported_count = 0;
        $skipped_count  = 0;
        if (!empty($messages)) {
            preg_match('/(\d+) record/', $messages[0], $m1);
            preg_match('/(\d+) skipped/', $messages[0], $m2);
            $imported_count = isset($m1[1]) ? intval($m1[1]) : 0;
            $skipped_count  = isset($m2[1]) ? intval($m2[1]) : 0;
        }
        ?>

        <?php if (!empty($messages)) : ?>
        <div class="em-import-result">
            <div class="em-result-stat em-result-imported">
                <div class="num"><?php echo $imported_count; ?></div>
                <div class="lbl">Records Imported</div>
            </div>
            <div class="em-result-stat em-result-skipped">
                <div class="num"><?php echo $skipped_count; ?></div>
                <div class="lbl">Rows Skipped</div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($errors)) : ?>
        <div class="em-error-list">
            <?php foreach ($errors as $err) : ?>
                <p>&#9888; <?php echo esc_html($err); ?></p>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="em-import-layout">

            <!-- Upload Card -->
            <div class="em-import-card">
                <div class="em-import-card-header">
                    <div class="em-import-card-icon em-icon-upload">&#128228;</div>
                    <h2>Upload CSV File</h2>
                </div>
                <div class="em-import-card-body">

                    <ul class="em-rules">
                        <li>Columns must be in order: <code>student_id</code>, <code>exam_id</code>, <code>marks</code></li>
                        <li>First row can be a header — skipped automatically</li>
                        <li>Marks must be a number between <code>0</code> and <code>100</code></li>
                        <li>Maximum file size: <code>2MB</code></li>
                    </ul>

                    <form method="post" enctype="multipart/form-data">
                        <?php wp_nonce_field('em_import_csv', 'em_import_csv_nonce'); ?>

                        <div class="em-upload-zone" id="em-drop-zone">
                            <input type="file" name="em_csv_file" id="em_csv_file"
                                   accept=".csv" required
                                   onchange="emShowFilename(this)">
                            <div class="em-upload-icon">&#128196;</div>
                            <p class="em-upload-label">Click to choose a file</p>
                            <p class="em-upload-hint">or drag and drop your CSV here</p>
                            <p class="em-upload-filename" id="em-filename"></p>
                        </div>

                        <button type="submit" class="em-submit-btn">
                            &#8679; Import CSV
                        </button>
                    </form>

                </div>
            </div>

            <!-- Format Card -->
            <div class="em-import-card">
                <div class="em-import-card-header">
                    <div class="em-import-card-icon em-icon-format">&#128203;</div>
                    <h2>Expected Format</h2>
                </div>
                <div class="em-import-card-body" style="padding: 0;">
                    <table class="em-format-table">
                        <thead>
                            <tr>
                                <th><span class="em-col-badge em-col-student">student_id</span></th>
                                <th><span class="em-col-badge em-col-exam">exam_id</span></th>
                                <th><span class="em-col-badge em-col-marks">marks</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td>10</td><td>47</td><td>85</td></tr>
                            <tr><td>12</td><td>47</td><td>72</td></tr>
                            <tr><td>13</td><td>48</td><td>90</td></tr>
                            <tr><td>14</td><td>48</td><td>55</td></tr>
                            <tr><td>15</td><td>20</td><td>78</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

    </div>

    <script>
    function emShowFilename(input) {
        var el = document.getElementById('em-filename');
        if (input.files && input.files[0]) {
            el.textContent = '&#10003; ' + input.files[0].name;
            el.style.display = 'block';
        }
    }

    // Drag and drop highlight
    var zone = document.getElementById('em-drop-zone');
    if (zone) {
        zone.addEventListener('dragover',  function(e){ e.preventDefault(); zone.classList.add('dragover'); });
        zone.addEventListener('dragleave', function(){ zone.classList.remove('dragover'); });
        zone.addEventListener('drop',      function(e){
            e.preventDefault();
            zone.classList.remove('dragover');
            var files = e.dataTransfer.files;
            if (files.length) {
                document.getElementById('em_csv_file').files = files;
                emShowFilename(document.getElementById('em_csv_file'));
            }
        });
    }
    </script>

    <?php
}

/* --------------------------------------------------
   STUDENT STATISTICS REPORT + PDF EXPORT
   Admin: Results → Student Report
-------------------------------------------------- */

add_action('admin_menu', 'em_add_report_menu');

function em_add_report_menu() {
    add_submenu_page(
        'edit.php?post_type=em_result',
        'Student Statistics Report',
        'Student Report',
        'manage_options',
        'em-student-report',
        'em_student_report_page'
    );
}

function em_student_report_page() {

    global $wpdb;

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Unauthorized' );
    }

    $terms = get_terms([
        'taxonomy'   => 'em_term',
        'hide_empty' => false,
        'orderby'    => 'meta_value',
        'order'      => 'DESC',
        'meta_key'   => 'term_start_date',
    ]);

    if ( is_wp_error( $terms ) ) {
        $terms = [];
    }

    $students = get_posts([
        'post_type'      => 'em_student',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
    ]);

    /**
     * Performance: load everything in 2 raw queries,
     * then compute in PHP — no per-student DB calls.
     *
     * Fix for duplicate marks:
     * A student can only have ONE mark per exam.
     * We track seen exam IDs per student to prevent
     * double-counting if marks exist on multiple result posts.
     */

    // Map: result_id → exam_id
    $result_exam_map = [];
    $rows = $wpdb->get_results(
        "SELECT post_id as result_id, meta_value as exam_id
         FROM {$wpdb->postmeta}
         WHERE meta_key = 'em_result_exam'"
    );
    foreach ( $rows as $row ) {
        $result_exam_map[ intval( $row->result_id ) ] = intval( $row->exam_id );
    }

    // Map: result_id → student_id → marks
    $marks_map = [];
    $rows = $wpdb->get_results(
        "SELECT post_id as result_id, meta_key, meta_value
         FROM {$wpdb->postmeta}
         WHERE meta_key LIKE 'marks_%'
         AND meta_value != ''"
    );
    foreach ( $rows as $row ) {
        $sid = intval( str_replace( 'marks_', '', $row->meta_key ) );
        $marks_map[ intval( $row->result_id ) ][ $sid ] = intval( $row->meta_value );
    }

    // Map: exam_id → term_id
    $exam_term_map = [];
    foreach ( $terms as $term ) {
        $exam_ids = get_objects_in_term( $term->term_id, 'em_term' );
        if ( ! is_wp_error( $exam_ids ) ) {
            foreach ( $exam_ids as $exam_id ) {
                $exam_term_map[ intval( $exam_id ) ] = $term->term_id;
            }
        }
    }

    // Build report — one mark per student per exam (deduped)
    $report = [];

    foreach ( $students as $student ) {

        $term_totals  = []; // term_id => total marks
        $term_counts  = []; // term_id => number of exams
        $seen_exams   = []; // prevent double-counting same exam

        foreach ( $marks_map as $result_id => $student_marks ) {

            if ( ! isset( $student_marks[ $student->ID ] ) ) continue;

            $exam_id = $result_exam_map[ $result_id ] ?? null;
            if ( ! $exam_id ) continue;

            // Skip if we already counted this exam for this student
            if ( isset( $seen_exams[ $exam_id ] ) ) continue;
            $seen_exams[ $exam_id ] = true;

            $term_id = $exam_term_map[ $exam_id ] ?? null;
            if ( ! $term_id ) continue;

            if ( ! isset( $term_totals[ $term_id ] ) ) {
                $term_totals[ $term_id ] = 0;
                $term_counts[ $term_id ] = 0;
            }

            $term_totals[ $term_id ] += $student_marks[ $student->ID ];
            $term_counts[ $term_id ]++;
        }

        $overall_total = array_sum( $term_totals );
        $overall_count = array_sum( $term_counts );

        // Average = total marks divided by number of exams taken
        $average = $overall_count > 0 ? round( $overall_total / $overall_count, 1 ) : null;

        $report[ $student->ID ] = [
            'name'        => $student->post_title,
            'term_totals' => $term_totals,
            'term_counts' => $term_counts,
            'average'     => $average,
        ];
    }

    // Sort by overall average DESC
    uasort( $report, function( $a, $b ) {
        return ( $b['average'] ?? -1 ) <=> ( $a['average'] ?? -1 );
    });

    ?>
    <div class="wrap" id="em-report-wrap">

    <style>
    #em-report-wrap { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }

    .em-report-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin: 0 0 24px;
        flex-wrap: wrap;
        gap: 12px;
    }
    .em-report-header h1 {
        margin: 0 0 6px;
        font-size: 28px;
        font-weight: 700;
        color: #0a0a0a;
        letter-spacing: -0.5px;
        line-height: 1.2;
    }
    .em-report-header p {
        margin: 0;
        font-size: 13px;
        color: #888;
    }

    .em-export-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: #1d2327;
        color: #fff !important;
        border: none;
        border-radius: 6px;
        padding: 10px 18px;
        font-size: 13px;
        font-weight: 500;
        cursor: pointer;
        text-decoration: none;
    }
    .em-export-btn:hover { background: #2c3338; }

    .em-stats-wrap {
        background: #fff;
        border: 1px solid #e2e4e7;
        border-radius: 8px;
        overflow: hidden;
    }

    #em-stats-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 14px;
    }
    #em-stats-table thead tr {
        background: #f6f7f7;
        border-bottom: 2px solid #e2e4e7;
    }
    #em-stats-table thead th {
        padding: 14px 16px;
        text-align: left;
        font-size: 12px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.6px;
        color: #1e1e1e;
        white-space: nowrap;
        border-bottom: 2px solid #d0d5dd;
    }
    #em-stats-table thead tr { background: #f0f2f5; border-bottom: none; }

    #em-stats-table tbody tr {
        border-bottom: 1px solid #f0f0f0;
        transition: background 0.1s;
    }
    #em-stats-table tbody tr:last-child { border-bottom: none; }
    #em-stats-table tbody tr:hover { background: #f9f9f9; }

    #em-stats-table tbody td {
        padding: 14px 16px;
        vertical-align: middle;
        color: #3c3c3c;
    }

    .em-student-cell {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .em-avatar {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background: #e8f0fe;
        color: #1a73e8;
        font-size: 12px;
        font-weight: 600;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    .em-student-name { font-weight: 500; color: #1e1e1e; font-size: 14px; }

    .em-marks-cell { font-weight: 600; color: #1e1e1e; font-size: 16px; }
    .em-marks-cell .em-pct-sign {
        font-size: 11px;
        font-weight: 500;
        color: #888;
        margin-left: 1px;
    }
    .em-marks-cell small {
        display: block;
        font-size: 11px;
        font-weight: 400;
        color: #bbb;
        margin-top: 3px;
    }
    .em-dash { color: #ddd; font-size: 16px; }

    .em-avg-badge {
        display: inline-flex;
        align-items: baseline;
        gap: 1px;
        padding: 6px 14px;
        border-radius: 20px;
        font-size: 15px;
        font-weight: 700;
    }
    .em-avg-badge .em-pct-sign {
        font-size: 11px;
        font-weight: 500;
        opacity: 0.75;
    }
    .em-avg-high  { background: #e6f4ea; color: #1e7e34; }
    .em-avg-mid   { background: #fff3cd; color: #856404; }
    .em-avg-low   { background: #fdecea; color: #c62828; }
    .em-avg-none  { background: #f5f5f5; color: #aaa; }

    .em-rank-cell { color: #aaa; font-size: 13px; font-weight: 600; width: 40px; }

    /* Pagination */
    .em-pagination {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 14px 20px;
        border-top: 1px solid #e2e4e7;
        background: #fafafa;
        border-radius: 0 0 8px 8px;
    }
    .em-pagination-info { font-size: 12px; color: #888; }
    .em-pagination-btns { display: flex; gap: 6px; }
    .em-page-btn {
        padding: 6px 14px;
        border: 1px solid #dcdcde;
        border-radius: 4px;
        background: #fff;
        font-size: 12px;
        font-weight: 500;
        cursor: pointer;
        color: #1e1e1e;
        transition: all 0.15s;
    }
    .em-page-btn:hover:not(:disabled) { background: #f0f6fc; border-color: #2271b1; color: #2271b1; }
    .em-page-btn:disabled { opacity: 0.4; cursor: not-allowed; }
    .em-page-btn.active { background: #2271b1; border-color: #2271b1; color: #fff; }
    .em-loading-row td { text-align: center; padding: 32px; color: #888; font-size: 13px; }
    .em-rank-1 { color: #EF9F27; }
    .em-rank-2 { color: #888; }
    .em-rank-3 { color: #D85A30; }

    @media print {
        @page { size: A4 landscape; margin: 12mm; }
        @page portrait { size: A4 portrait; margin: 12mm; }

        * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }

        #adminmenumain, #wpadminbar, #wpfooter,
        #adminmenuback, #adminmenuwrap,
        .notice, .em-export-btn,
        #em-report-wrap .em-report-header > div:last-child { display: none !important; }

        body, #wpcontent, #wpbody, #wpbody-content,
        .wrap, #em-report-wrap {
            margin: 0 !important;
            padding: 0 !important;
            width: 100% !important;
            float: none !important;
        }

        .em-report-header { margin-bottom: 12px; }
        .em-report-header h1 { font-size: 16px; }
        .em-report-header p { font-size: 11px; }

        .em-stats-wrap {
            border: none !important;
            border-radius: 0 !important;
            width: 100% !important;
            overflow: visible !important;
        }

        #em-stats-table {
            width: 100% !important;
            table-layout: fixed;
            font-size: 10px;
            border-collapse: collapse;
        }

        #em-stats-table thead tr { background: #f5f5f5 !important; }
        #em-stats-table thead th {
            border: 1px solid #ccc !important;
            padding: 6px 8px !important;
            font-size: 9px;
        }
        #em-stats-table tbody td {
            border: 1px solid #ddd !important;
            padding: 7px 8px !important;
            word-break: break-word;
        }
        #em-stats-table tbody tr:hover { background: none !important; }

        .em-avatar { display: none !important; }
        .em-student-cell { gap: 0 !important; }
        .em-student-name { font-size: 11px; }
        .em-marks-cell small { display: none !important; }
        .em-avg-badge {
            background: none !important;
            padding: 0 !important;
            font-size: 11px;
            color: #000 !important;
        }
        .em-pct-sign { font-size: 9px; }
        .em-rank-cell { font-size: 10px; }
    }
    </style>

        <div class="em-report-header">
            <div>
                <h1>Student Statistics Report</h1>
                <p>Average marks per exam per term &mdash; total marks and exam count shown below each figure</p>
            </div>
            <button onclick="window.print()" class="em-export-btn">
                &#128438; Export as PDF
            </button>
        </div>

        <?php if ( empty( $report ) ) : ?>
            <p>No student data found.</p>
        <?php else :

        // Encode report data for JS pagination
        $per_page    = 10;
        $total_rows  = count( $report );
        $report_json = json_encode( array_values( array_map( function( $student_id, $data ) use ( $terms ) {
            $parts    = explode( ' ', trim( $data['name'] ) );
            $initials = strtoupper( substr( $parts[0], 0, 1 ) . ( isset( $parts[1] ) ? substr( $parts[1], 0, 1 ) : '' ) );

            $term_data = [];
            foreach ( $terms as $term ) {
                if ( isset( $data['term_totals'][ $term->term_id ] ) ) {
                    $t_total = $data['term_totals'][ $term->term_id ];
                    $t_count = $data['term_counts'][ $term->term_id ];
                    $term_data[] = [
                        'avg'   => $t_count > 0 ? round( $t_total / $t_count, 1 ) : 0,
                        'count' => $t_count,
                        'total' => $t_total,
                    ];
                } else {
                    $term_data[] = null;
                }
            }

            return [
                'name'      => $data['name'],
                'initials'  => $initials,
                'average'   => $data['average'],
                'term_data' => $term_data,
            ];
        }, array_keys( $report ), $report ) ) );

        $term_names_json = json_encode( array_values( array_map( function( $term ) {
            return $term->name;
        }, $terms ) ) );
        ?>

        <div class="em-stats-wrap">
        <table id="em-stats-table">
            <thead>
                <tr>
                    <th style="width:40px">#</th>
                    <th>Student</th>
                    <?php foreach ( $terms as $term ) : ?>
                        <th>
                            <?php echo esc_html( $term->name ); ?>
                            <span style="font-weight:500;color:#888;font-size:10px;text-transform:none;letter-spacing:0;display:block;margin-top:2px">avg / exam</span>
                        </th>
                    <?php endforeach; ?>
                    <th>Overall Avg</th>
                </tr>
            </thead>
            <tbody id="em-report-tbody">
                <tr class="em-loading-row"><td colspan="<?php echo 3 + count($terms); ?>">Loading...</td></tr>
            </tbody>
        </table>
        <div class="em-pagination" id="em-report-pagination" style="display:none">
            <span class="em-pagination-info" id="em-page-info"></span>
            <div class="em-pagination-btns" id="em-page-btns"></div>
        </div>
        </div>

        <?php endif; ?>

    </div>

    <script>
    (function(){
        var data     = <?php echo $report_json ?? '[]'; ?>;
        var terms    = <?php echo $term_names_json ?? '[]'; ?>;
        var perPage  = <?php echo $per_page; ?>;
        var total    = data.length;
        var pages    = Math.ceil(total / perPage);
        var current  = 1;

        function badgeClass(avg) {
            if (avg === null) return 'em-avg-none';
            if (avg >= 75)   return 'em-avg-high';
            if (avg >= 50)   return 'em-avg-mid';
            return 'em-avg-low';
        }

        function rankClass(rank) {
            if (rank === 1) return 'em-rank-1';
            if (rank === 2) return 'em-rank-2';
            if (rank === 3) return 'em-rank-3';
            return '';
        }

        function renderPage(page) {
            current = page;
            var start  = (page - 1) * perPage;
            var slice  = data.slice(start, start + perPage);
            var tbody  = document.getElementById('em-report-tbody');
            var html   = '';

            slice.forEach(function(row, i) {
                var rank     = start + i + 1;
                var avg      = row.average;
                var avgLabel = avg !== null ? avg + '<span class="em-pct-sign">%</span>' : 'N/A';
                var termCells = '';

                row.term_data.forEach(function(t) {
                    if (t === null) {
                        termCells += '<td class="em-marks-cell"><span class="em-dash">—</span></td>';
                    } else {
                        termCells += '<td class="em-marks-cell">'
                            + t.avg + '<span class="em-pct-sign">%</span>'
                            + '<small>' + t.count + ' exam(s) &middot; ' + t.total + ' total marks</small>'
                            + '</td>';
                    }
                });

                html += '<tr>'
                    + '<td class="em-rank-cell ' + rankClass(rank) + '">' + rank + '</td>'
                    + '<td><div class="em-student-cell">'
                    + '<div class="em-avatar">' + row.initials + '</div>'
                    + '<span class="em-student-name">' + row.name + '</span>'
                    + '</div></td>'
                    + termCells
                    + '<td><span class="em-avg-badge ' + badgeClass(avg) + '">' + avgLabel + '</span></td>'
                    + '</tr>';
            });

            tbody.innerHTML = html;
            renderPagination();
        }

        function renderPagination() {
            if (total <= perPage) return;

            var wrap  = document.getElementById('em-report-pagination');
            var info  = document.getElementById('em-page-info');
            var btns  = document.getElementById('em-page-btns');
            var start = (current - 1) * perPage + 1;
            var end   = Math.min(current * perPage, total);

            info.textContent = 'Showing ' + start + '–' + end + ' of ' + total + ' students';
            wrap.style.display = 'flex';

            var html = '';

            // Prev button
            html += '<button class="em-page-btn" onclick="emReportPage(' + (current - 1) + ')"'
                  + (current === 1 ? ' disabled' : '') + '>&#8592; Prev</button>';

            // Page number buttons — show max 5 around current
            var startPage = Math.max(1, current - 2);
            var endPage   = Math.min(pages, current + 2);

            for (var p = startPage; p <= endPage; p++) {
                html += '<button class="em-page-btn' + (p === current ? ' active' : '') + '"'
                      + ' onclick="emReportPage(' + p + ')">' + p + '</button>';
            }

            // Next button
            html += '<button class="em-page-btn" onclick="emReportPage(' + (current + 1) + ')"'
                  + (current === pages ? ' disabled' : '') + '>Next &#8594;</button>';

            btns.innerHTML = html;
        }

        // Expose to global so onclick works
        window.emReportPage = function(page) {
            if (page < 1 || page > pages) return;
            renderPage(page);
            // Scroll back to top of table smoothly
            var wrap = document.getElementById('em-stats-table');
            if (wrap) wrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
        };

        // Initial render
        renderPage(1);
    })();
    </script>

    <?php
}
