<?php
/**
 * Plugin Name: PlanningIndex Jobs
 * Description: Job management for PlanningIndex CRM – convert won leads into jobs, track status, dates, workers, and progress.
 * Version: 1.0.0
 * Author: Planning Index
 */

if (!defined('ABSPATH')) {
    exit;
}

define('PI_JOB_CPT', 'pi_job');
define('PI_JOB_META_PREFIX', '_pi_job_');

/**
 * Register the Job custom post type.
 */
function pi_jobs_register_post_type() {
    $labels = [
        'name'               => __('Jobs', 'planningindex'),
        'singular_name'      => __('Job', 'planningindex'),
        'add_new'            => __('Add New Job', 'planningindex'),
        'add_new_item'       => __('Add New Job', 'planningindex'),
        'edit_item'          => __('Edit Job', 'planningindex'),
        'new_item'           => __('New Job', 'planningindex'),
        'view_item'          => __('View Job', 'planningindex'),
        'search_items'       => __('Search Jobs', 'planningindex'),
        'not_found'          => __('No jobs found', 'planningindex'),
        'not_found_in_trash' => __('No jobs found in Trash', 'planningindex'),
        'menu_name'          => __('Jobs', 'planningindex'),
    ];

    $args = [
        'labels'             => $labels,
        'public'             => false,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'supports'           => ['title', 'editor', 'custom-fields'],
        'has_archive'        => false,
        'rewrite'            => ['slug' => 'job'],
        'capability_type'    => 'post',
        'map_meta_cap'       => true,
        'menu_icon'          => 'dashicons-hammer',
    ];

    register_post_type(PI_JOB_CPT, $args);

    // Ensure basic caps exist for subscribers and admins to edit their own jobs if needed.
    $subscriber = get_role('subscriber');
    if ($subscriber && !$subscriber->has_cap('edit_pi_job')) {
        $subscriber->add_cap('edit_pi_job');
    }
    $admin = get_role('administrator');
    if ($admin && !$admin->has_cap('edit_pi_job')) {
        $admin->add_cap('edit_pi_job');
    }
}
add_action('init', 'pi_jobs_register_post_type');

/**
 * Activation: register CPT and flush rewrites.
 */
function pi_jobs_activate() {
    pi_jobs_register_post_type();
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'pi_jobs_activate');

/**
 * Generate a sequential job code like JOB-YYYYMM-001.
 */
function pi_jobs_generate_job_code() {
    $year_month = date('Ym');
    $option_key = 'pi_job_seq_' . $year_month;
    $seq        = (int) get_option($option_key, 0) + 1;
    update_option($option_key, $seq);

    $code = 'JOB-' . $year_month . '-' . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
    return $code;
}

/**
 * Helper: title case helper if not already defined (mirrors workspace helper).
 */
if (!function_exists('pi_jobs_title_case_address')) {
    function pi_jobs_title_case_address($s) {
        $s = (string) $s;
        return preg_replace_callback(
            '/(^|[\s\-\/\(\)\,\.])([a-z0-9])/i',
            static function ($m) {
                return $m[1] . strtoupper($m[2]);
            },
            strtolower($s)
        );
    }
}

/**
 * Create a Job post from a lead when the stage becomes "won".
 *
 * Hooked into the existing workspace action: pi_lead_stage_changed.
 *
 * @param int    $lead_id   Lead post ID.
 * @param int    $user_id   Owner user ID.
 * @param string $old_stage Previous stage.
 * @param string $new_stage New stage.
 */
function pi_jobs_convert_won_lead_to_job($lead_id, $user_id, $old_stage, $new_stage) {
    if ($new_stage !== 'won') {
        return;
    }

    if (!defined('PI_LEAD_CPT') || !defined('PI_LEAD_META_PREFIX')) {
        // Workspace plugin not active.
        return;
    }

    if (get_post_type($lead_id) !== PI_LEAD_CPT) {
        return;
    }

    // Only allow the owner to generate jobs.
    $owner_id = (int) get_post_meta($lead_id, PI_LEAD_META_PREFIX . 'owner_user_id', true);
    if ($owner_id !== (int) $user_id) {
        return;
    }

    // Avoid duplicate jobs for the same lead.
    $existing = get_posts([
        'post_type'      => PI_JOB_CPT,
        'posts_per_page' => 1,
        'post_status'    => 'any',
        'meta_key'       => PI_JOB_META_PREFIX . 'lead_id',
        'meta_value'     => $lead_id,
        'fields'         => 'ids',
    ]);
    if (!empty($existing)) {
        return;
    }

    // Gather planning application context from linked planning_app (if any).
    $planning_app_id = (int) get_post_meta($lead_id, PI_LEAD_META_PREFIX . 'linked_planning_app_id', true);
    $site_address    = '';
    $description     = '';
    $council_ref     = '';
    $date_received   = '';

    if ($planning_app_id && get_post_type($planning_app_id) === 'planning_app') {
        $meta       = get_post_meta($planning_app_id);
        $rawAddress = $meta['address'][0] ?? '';
        $post       = get_post($planning_app_id);

        if (!$rawAddress && $post && $post->post_content) {
            $dom = new DOMDocument();
            @$dom->loadHTML('<?xml encoding="UTF-8">' . $post->post_content);
            foreach ($dom->getElementsByTagName('p') as $p) {
                $t = trim($p->textContent);
                if (preg_match('/^Address:/i', $t)) {
                    $rawAddress = preg_replace('/^Address:\s*/i', '', $t);
                    break;
                }
            }
        }

        if (!$rawAddress && $post) {
            $rawAddress = $post->post_title ?: '';
        }

        $site_address  = $rawAddress ? pi_jobs_title_case_address($rawAddress) : '';
        $description   = $meta['description'][0] ?? ($post ? wp_strip_all_tags($post->post_content) : '');
        $council_ref   = $meta['council_reference'][0] ?? '';
        $date_received = $meta['date_received'][0] ?? '';
    }

    // Derive customer name if available on lead.
    $customer_name = get_post_meta($lead_id, PI_LEAD_META_PREFIX . 'customer_name', true);

    $job_code = pi_jobs_generate_job_code();

    $job_id = wp_insert_post([
        'post_type'   => PI_JOB_CPT,
        'post_status' => 'publish',
        'post_title'  => $job_code,
        'post_name'   => sanitize_title($job_code),
        'post_author' => $owner_id,
        'post_content'=> '',
    ]);

    if (!$job_id || is_wp_error($job_id)) {
        return;
    }

    update_post_meta($job_id, PI_JOB_META_PREFIX . 'lead_id', $lead_id);
    update_post_meta($job_id, PI_JOB_META_PREFIX . 'owner_user_id', $owner_id);
    update_post_meta($job_id, PI_JOB_META_PREFIX . 'planning_app_id', $planning_app_id);
    update_post_meta($job_id, PI_JOB_META_PREFIX . 'status', 'planning'); // planning, active, completed
    update_post_meta($job_id, PI_JOB_META_PREFIX . 'site_address', $site_address);
    update_post_meta($job_id, PI_JOB_META_PREFIX . 'description', $description);
    update_post_meta($job_id, PI_JOB_META_PREFIX . 'council_reference', $council_ref);
    update_post_meta($job_id, PI_JOB_META_PREFIX . 'date_received', $date_received);
    update_post_meta($job_id, PI_JOB_META_PREFIX . 'customer_name', $customer_name);
    update_post_meta($job_id, PI_JOB_META_PREFIX . 'progress', 0);
    update_post_meta($job_id, PI_JOB_META_PREFIX . 'start_date', '');
    update_post_meta($job_id, PI_JOB_META_PREFIX . 'end_date', '');
    update_post_meta($job_id, PI_JOB_META_PREFIX . 'assigned_workers', []);

    $activity = [
        current_time('mysql') . ': Job created from won lead #' . $lead_id,
    ];
    update_post_meta($job_id, PI_JOB_META_PREFIX . 'activity', $activity);
}
add_action('pi_lead_stage_changed', 'pi_jobs_convert_won_lead_to_job', 10, 4);

/**
 * Helper to ensure the current user owns a job.
 */
function pi_jobs_user_owns_job($job_id, $user_id = null) {
    $user_id = $user_id ? (int) $user_id : get_current_user_id();
    $owner   = (int) get_post_meta($job_id, PI_JOB_META_PREFIX . 'owner_user_id', true);
    return $owner === $user_id;
}

/**
 * Append an item to the job activity timeline.
 */
function pi_jobs_add_activity($job_id, $message) {
    $activity = get_post_meta($job_id, PI_JOB_META_PREFIX . 'activity', true);
    if (!is_array($activity)) {
        $activity = [];
    }
    $activity[] = current_time('mysql') . ': ' . $message;
    update_post_meta($job_id, PI_JOB_META_PREFIX . 'activity', $activity);
}

/**
 * REST API: Jobs endpoints.
 */
add_action('rest_api_init', function () {
    $namespace = 'pi/v1';

    // GET /pi/v1/jobs - list jobs for current user.
    register_rest_route($namespace, '/jobs', [
        'methods'             => 'GET',
        'permission_callback' => static function () {
            return is_user_logged_in();
        },
        'callback'            => static function () {
            $user_id = get_current_user_id();
            $jobs    = get_posts([
                'post_type'      => PI_JOB_CPT,
                'posts_per_page' => -1,
                'post_status'    => ['publish', 'draft', 'pending', 'private'],
                'meta_key'       => PI_JOB_META_PREFIX . 'owner_user_id',
                'meta_value'     => $user_id,
            ]);

            $out = [];
            foreach ($jobs as $job) {
                $meta        = get_post_meta($job->ID);
                $status      = $meta[PI_JOB_META_PREFIX . 'status'][0] ?? 'planning';
                $progress    = (int) ($meta[PI_JOB_META_PREFIX . 'progress'][0] ?? 0);
                $start_date  = $meta[PI_JOB_META_PREFIX . 'start_date'][0] ?? '';
                $end_date    = $meta[PI_JOB_META_PREFIX . 'end_date'][0] ?? '';
                $lead_id     = (int) ($meta[PI_JOB_META_PREFIX . 'lead_id'][0] ?? 0);
                $site_address= $meta[PI_JOB_META_PREFIX . 'site_address'][0] ?? '';
                $customer    = $meta[PI_JOB_META_PREFIX . 'customer_name'][0] ?? '';
                $workers_raw = $meta[PI_JOB_META_PREFIX . 'assigned_workers'][0] ?? [];
                $workers     = is_array($workers_raw) ? $workers_raw : maybe_unserialize($workers_raw);
                if (!is_array($workers)) {
                    $workers = [];
                }

                $worker_names = [];
                foreach ($workers as $wid) {
                    $u = get_user_by('id', (int) $wid);
                    if ($u) {
                        $worker_names[] = $u->display_name;
                    }
                }

                $out[] = [
                    'id'            => $job->ID,
                    'code'          => $job->post_title,
                    'status'        => $status,
                    'progress'      => $progress,
                    'start_date'    => $start_date,
                    'end_date'      => $end_date,
                    'lead_id'       => $lead_id,
                    'site_address'  => $site_address,
                    'customer_name' => $customer,
                    'assigned_workers'      => $workers,
                    'assigned_worker_names' => $worker_names,
                ];
            }

            return rest_ensure_response($out);
        },
    ]);

    // GET /pi/v1/jobs/{id} - single job details.
    register_rest_route($namespace, '/jobs/(?P<id>\d+)', [
        'methods'             => 'GET',
        'permission_callback' => static function (WP_REST_Request $req) {
            if (!is_user_logged_in()) {
                return false;
            }
            $job_id = (int) $req['id'];
            return pi_jobs_user_owns_job($job_id);
        },
        'callback'            => static function (WP_REST_Request $req) {
            $job_id = (int) $req['id'];
            $job    = get_post($job_id);
            if (!$job || $job->post_type !== PI_JOB_CPT) {
                return new WP_Error('not_found', 'Job not found', ['status' => 404]);
            }

            $meta         = get_post_meta($job_id);
            $status       = $meta[PI_JOB_META_PREFIX . 'status'][0] ?? 'planning';
            $progress     = (int) ($meta[PI_JOB_META_PREFIX . 'progress'][0] ?? 0);
            $start_date   = $meta[PI_JOB_META_PREFIX . 'start_date'][0] ?? '';
            $end_date     = $meta[PI_JOB_META_PREFIX . 'end_date'][0] ?? '';
            $lead_id      = (int) ($meta[PI_JOB_META_PREFIX . 'lead_id'][0] ?? 0);
            $site_address = $meta[PI_JOB_META_PREFIX . 'site_address'][0] ?? '';
            $customer     = $meta[PI_JOB_META_PREFIX . 'customer_name'][0] ?? '';
            $activity     = maybe_unserialize($meta[PI_JOB_META_PREFIX . 'activity'][0] ?? 'a:0:{}');
            if (!is_array($activity)) {
                $activity = [];
            }
            $workers_raw = $meta[PI_JOB_META_PREFIX . 'assigned_workers'][0] ?? [];
            $workers     = is_array($workers_raw) ? $workers_raw : maybe_unserialize($workers_raw);
            if (!is_array($workers)) {
                $workers = [];
            }

            return rest_ensure_response([
                'id'            => $job_id,
                'code'          => $job->post_title,
                'status'        => $status,
                'progress'      => $progress,
                'start_date'    => $start_date,
                'end_date'      => $end_date,
                'lead_id'       => $lead_id,
                'site_address'  => $site_address,
                'customer_name' => $customer,
                'activity'      => $activity,
                'assigned_workers' => $workers,
            ]);
        },
    ]);

    // POST /pi/v1/jobs/{id}/update - update job fields.
    register_rest_route($namespace, '/jobs/(?P<id>\d+)/update', [
        'methods'             => 'POST',
        'permission_callback' => static function (WP_REST_Request $req) {
            if (!is_user_logged_in()) {
                return false;
            }
            $job_id = (int) $req['id'];
            return pi_jobs_user_owns_job($job_id);
        },
        'callback'            => static function (WP_REST_Request $req) {
            $job_id = (int) $req['id'];
            $fields = $req->get_json_params();

            $allowed_fields = [
                'status',
                'start_date',
                'end_date',
                'site_address',
                'progress',
                'customer_name',
                'notes',
                'assigned_workers',
            ];

            $changes = [];

            foreach ($fields as $key => $value) {
                if (!in_array($key, $allowed_fields, true)) {
                    continue;
                }

                $meta_key = PI_JOB_META_PREFIX . $key;

                if ($key === 'assigned_workers') {
                    $sanitized = array_map('intval', is_array($value) ? $value : []);
                } elseif ($key === 'progress') {
                    $sanitized = max(0, min(100, (int) $value));
                } elseif ($key === 'notes') {
                    // Notes are appended into activity timeline.
                    $note = sanitize_textarea_field((string) $value);
                    if ($note !== '') {
                        pi_jobs_add_activity($job_id, 'Note: ' . $note);
                        $changes[] = 'notes';
                    }
                    continue;
                } else {
                    $sanitized = sanitize_text_field((string) $value);
                }

                $old = get_post_meta($job_id, $meta_key, true);
                if ($old != $sanitized) {
                    update_post_meta($job_id, $meta_key, $sanitized);
                    $changes[] = $key;
                }
            }

            if (!empty($changes)) {
                pi_jobs_add_activity($job_id, 'Updated: ' . implode(', ', $changes));
            }

            return rest_ensure_response([
                'updated'  => true,
                'changed'  => $changes,
                'job_id'   => $job_id,
            ]);
        },
    ]);

    // POST /pi/v1/jobs/from_lead - explicitly create a job from a lead (optional helper).
    register_rest_route($namespace, '/jobs/from_lead', [
        'methods'             => 'POST',
        'permission_callback' => static function () {
            return is_user_logged_in();
        },
        'callback'            => static function (WP_REST_Request $req) {
            if (!defined('PI_LEAD_META_PREFIX')) {
                return new WP_Error('workspace_inactive', 'Workspace plugin not active', ['status' => 400]);
            }

            $lead_id = (int) $req['lead_id'];
            if (!$lead_id || get_post_type($lead_id) !== (defined('PI_LEAD_CPT') ? PI_LEAD_CPT : 'pi_lead')) {
                return new WP_Error('invalid_lead', 'Invalid lead', ['status' => 400]);
            }

            $owner_id = (int) get_post_meta($lead_id, PI_LEAD_META_PREFIX . 'owner_user_id', true);
            if ($owner_id !== get_current_user_id()) {
                return new WP_Error('forbidden', 'Not your lead', ['status' => 403]);
            }

            // If stage isn't already won, allow opt-in creation but log in activity.
            $stage = get_post_meta($lead_id, PI_LEAD_META_PREFIX . 'stage', true);

            // Reuse conversion logic but ensure we only run once.
            $existing = get_posts([
                'post_type'      => PI_JOB_CPT,
                'posts_per_page' => 1,
                'post_status'    => 'any',
                'meta_key'       => PI_JOB_META_PREFIX . 'lead_id',
                'meta_value'     => $lead_id,
                'fields'         => 'ids',
            ]);
            if (!empty($existing)) {
                return rest_ensure_response([
                    'created' => false,
                    'job_id'  => (int) $existing[0],
                    'message' => 'Job already exists for this lead.',
                ]);
            }

            // Temporarily call the same converter (simulating a won stage).
            pi_jobs_convert_won_lead_to_job($lead_id, $owner_id, (string) $stage, 'won');

            $jobs = get_posts([
                'post_type'      => PI_JOB_CPT,
                'posts_per_page' => 1,
                'post_status'    => 'any',
                'meta_key'       => PI_JOB_META_PREFIX . 'lead_id',
                'meta_value'     => $lead_id,
                'fields'         => 'ids',
            ]);

            if (empty($jobs)) {
                return new WP_Error('create_failed', 'Unable to create job from lead', ['status' => 500]);
            }

            return rest_ensure_response([
                'created' => true,
                'job_id'  => (int) $jobs[0],
            ]);
        },
    ]);
});

/**
 * Shortcode: [planningindex_jobs]
 *
 * Renders a lightweight job dashboard using existing CRM styles.
 */
function pi_jobs_shortcode() {
    if (!is_user_logged_in()) {
        return '<div class="pi-crm-wrapper">
            <div class="pi-auth-required">
                <div class="pi-auth-icon">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                    </svg>
                </div>
                <h3>Sign In Required</h3>
                <p>Please log in to view and manage your jobs.</p>
            </div>
        </div>';
    }

    // Reuse existing Inter font + workspace styling for perfect visual consistency.
    wp_enqueue_style(
        'google-fonts-inter',
        'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap',
        [],
        null
    );

    // Plugin-specific CSS and JS (only when shortcode is rendered)
    wp_enqueue_style(
        'planningindex-jobs-style',
        plugin_dir_url(__FILE__) . 'assets/style.css',
        [],
        '1.0.0'
    );
    wp_enqueue_script(
        'planningindex-jobs-script',
        plugin_dir_url(__FILE__) . 'assets/script.js',
        ['jquery'],
        '1.0.0',
        true
    );

    // Server-render jobs for the current user to avoid requiring extra JS.
    $user_id = get_current_user_id();
    $jobs    = get_posts([
        'post_type'      => PI_JOB_CPT,
        'posts_per_page' => -1,
        'post_status'    => ['publish', 'draft', 'pending', 'private'],
        'meta_key'       => PI_JOB_META_PREFIX . 'owner_user_id',
        'meta_value'     => $user_id,
    ]);

    $total_jobs   = count($jobs);
    $active_jobs  = 0;
    $completed    = 0;
    $planning_cnt = 0;

    $rows = [];

    foreach ($jobs as $job) {
        $meta         = get_post_meta($job->ID);
        $status       = $meta[PI_JOB_META_PREFIX . 'status'][0] ?? 'planning';
        $progress     = (int) ($meta[PI_JOB_META_PREFIX . 'progress'][0] ?? 0);
        $start_date   = $meta[PI_JOB_META_PREFIX . 'start_date'][0] ?? '';
        $end_date     = $meta[PI_JOB_META_PREFIX . 'end_date'][0] ?? '';
        $site_address = $meta[PI_JOB_META_PREFIX . 'site_address'][0] ?? '';
        $customer     = $meta[PI_JOB_META_PREFIX . 'customer_name'][0] ?? '';

        if ($status === 'active') {
            $active_jobs++;
        } elseif ($status === 'completed') {
            $completed++;
        } else {
            $planning_cnt++;
        }

        $rows[] = [
            'id'           => $job->ID,
            'code'         => $job->post_title,
            'status'       => $status,
            'progress'     => $progress,
            'start_date'   => $start_date,
            'end_date'     => $end_date,
            'site_address' => $site_address,
            'customer'     => $customer,
        ];
    }

    // Expose basic aggregates to JS for richer UI (no business logic changes).
    wp_localize_script(
        'planningindex-jobs-script',
        'PI_Jobs',
        [
            'rest_base' => esc_url_raw(rest_url('pi/v1/jobs')),
            'nonce'     => wp_create_nonce('wp_rest'),
            'stats'     => [
                'total'     => (int) $total_jobs,
                'active'    => (int) $active_jobs,
                'planning'  => (int) $planning_cnt,
                'completed' => (int) $completed,
            ],
        ]
    );

    ob_start();
    ?>
    <div class="pi-dashboard pi-crm-wrapper pi-jobs-page">

        <!-- Page Header -->
        <div class="pi-jobs-header">
            <div class="pi-jobs-header-left">
                <div class="pi-jobs-header-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>
                    </svg>
                </div>
                <div>
                    <h1>Jobs</h1>
                    <p class="pi-jobs-header-sub"><?php echo esc_html($total_jobs); ?> job<?php echo $total_jobs !== 1 ? 's' : ''; ?> total</p>
                </div>
            </div>
        </div>

        <!-- Stats Grid -->
        <div class="pi-jobs-stats-grid">
            <div class="pi-jobs-stat-card pi-jobs-stat-total">
                <div class="pi-jobs-stat-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>
                </div>
                <div class="pi-jobs-stat-content">
                    <span class="pi-jobs-stat-value" data-count="<?php echo esc_attr($total_jobs); ?>">0</span>
                    <span class="pi-jobs-stat-label">Total Jobs</span>
                </div>
            </div>
            <div class="pi-jobs-stat-card pi-jobs-stat-active">
                <div class="pi-jobs-stat-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                </div>
                <div class="pi-jobs-stat-content">
                    <span class="pi-jobs-stat-value" data-count="<?php echo esc_attr($active_jobs); ?>">0</span>
                    <span class="pi-jobs-stat-label">Active</span>
                </div>
            </div>
            <div class="pi-jobs-stat-card pi-jobs-stat-planning">
                <div class="pi-jobs-stat-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                </div>
                <div class="pi-jobs-stat-content">
                    <span class="pi-jobs-stat-value" data-count="<?php echo esc_attr($planning_cnt); ?>">0</span>
                    <span class="pi-jobs-stat-label">Planning</span>
                </div>
            </div>
            <div class="pi-jobs-stat-card pi-jobs-stat-completed">
                <div class="pi-jobs-stat-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                </div>
                <div class="pi-jobs-stat-content">
                    <span class="pi-jobs-stat-value" data-count="<?php echo esc_attr($completed); ?>">0</span>
                    <span class="pi-jobs-stat-label">Completed</span>
                </div>
            </div>
        </div>

        <!-- Main Layout: table + insights + sidebar -->
        <div class="pi-jobs-layout">
            <div class="pi-jobs-main">
                <div class="pi-card pi-jobs-main-card">
                    <div class="pi-card-header">
                        <h2>Job Dashboard</h2>
                        <p>Every won lead is automatically converted into a job for delivery tracking.</p>
                    </div>
                    <div class="pi-card-body">
                        <?php if (empty($rows)) : ?>
                            <div class="pi-empty-state">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                                    <line x1="8" y1="12" x2="16" y2="12"/>
                                    <line x1="12" y1="8" x2="12" y2="16"/>
                                </svg>
                                <span>No jobs yet. Move a lead to <strong>Won</strong> to create a job.</span>
                            </div>
                        <?php else : ?>
                            <div class="pi-table-wrapper">
                                <table class="pi-table pi-table-striped">
                                    <thead>
                                        <tr>
                                            <th>Job</th>
                                            <th>Status</th>
                                            <th>Customer</th>
                                            <th>Site Address</th>
                                            <th>Start</th>
                                            <th>Finish</th>
                                            <th>Progress</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($rows as $row) : ?>
                                            <tr data-job-id="<?php echo esc_attr($row['id']); ?>">
                                                <td>
                                                    <strong><?php echo esc_html($row['code']); ?></strong>
                                                </td>
                                                <td>
                                                    <span class="pi-badge pi-badge-status-<?php echo esc_attr($row['status']); ?>">
                                                        <?php echo esc_html(ucfirst($row['status'])); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo esc_html($row['customer'] ?: '—'); ?></td>
                                                <td class="pi-jobs-address-cell"><?php echo esc_html($row['site_address'] ?: '—'); ?></td>
                                                <td><?php echo esc_html($row['start_date'] ?: '—'); ?></td>
                                                <td><?php echo esc_html($row['end_date'] ?: '—'); ?></td>
                                                <td>
                                                    <div class="pi-progress-inline">
                                                        <div class="pi-progress-bar">
                                                            <div class="pi-progress-bar-fill" style="width: <?php echo esc_attr($row['progress']); ?>%;"></div>
                                                        </div>
                                                        <span class="pi-progress-label"><?php echo esc_html($row['progress']); ?>%</span>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <aside class="pi-jobs-sidebar">
                <div class="pi-card pi-jobs-sidebar-card" id="pi-jobs-detail-card">
                    <div class="pi-card-header">
                        <h2>Job Details</h2>
                        <p class="pi-jobs-detail-sub">Select a job from the table to see more.</p>
                    </div>
                    <div class="pi-card-body">
                        <div class="pi-jobs-detail-empty">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><circle cx="12" cy="16" r="0.5"/>
                            </svg>
                            <p>Click any job row to view status, dates, and progress timeline.</p>
                        </div>
                        <div class="pi-jobs-detail-content" style="display:none;">
                            <!-- Filled dynamically via JS from REST API -->
                        </div>
                    </div>
                </div>

                <div class="pi-card pi-jobs-sidebar-card">
                    <div class="pi-card-header">
                        <h2>Activity Timeline</h2>
                        <p class="pi-jobs-detail-sub">Latest updates from the selected job.</p>
                    </div>
                    <div class="pi-card-body">
                        <div class="pi-jobs-timeline" id="pi-jobs-timeline">
                            <div class="pi-jobs-timeline-empty">
                                <span>No job selected.</span>
                            </div>
                        </div>
                    </div>
                </div>
            </aside>
        </div>
    </div>
    <?php

    return ob_get_clean();
}
add_shortcode('planningindex_jobs', 'pi_jobs_shortcode');

