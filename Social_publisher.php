<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Social_publisher Controller
 * نظام النشر الموحد لـ Facebook و Instagram
 * معدل: إزالة flock من الـ CRON، دعم processing flag، poller لمعالجة المنشورات قيد المعالجة،
 * حفظ تعليقات الملفات والتعليقات العامة، وتحسين اللوق.
 */

class Social_publisher extends CI_Controller
{
    const CRON_TOKEN = 'SocialPublisher_Cron_2025';

    public function __construct()
    {
        parent::__construct();
        // تحميل الموديلات واللايبراريز الضرورية
        $this->load->model(['Reel_model', 'Facebook_pages_model', 'Instagram_reels_model']);
        $this->load->library(['session', 'InstagramPublisher']);
        $this->load->helper(['url', 'form', 'security', 'file']);
        $this->load->database();
    }

private function curl_get($url, $timeout = 15)
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, (int)$timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_ENCODING, '');
    if (!ini_get('open_basedir')) {
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    }
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'SpreadSpeed/1.0 (+https://spreadspeed.com)');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);

    $resp = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno || ($http_code !== 0 && $http_code >= 400)) {
        // سجل نص منسق (JSON) بدلاً من تمرير مصفوفة
        $this->log_error('curl_get_error', json_encode([
            'url' => $url,
            'http_code' => $http_code,
            'errno' => $errno,
            'error' => $err,
            'response_preview' => is_string($resp) ? mb_substr($resp, 0, 1500) : null
        ], JSON_UNESCAPED_UNICODE));
        return false;
    }

    return $resp;
}
public function mark_scheduled_due()
{
    // امنع استدعاء الدالة من المتصفح — تسمح فقط للـ CLI أو للطلبات المصرح بها
    if (!$this->input->is_cli_request()) {
        // لو تريد السماح باستدعاء من ويب لأغراض الاختبار قم بتعديل هذا السلوك بعناية
        $this->log_error('mark_scheduled_due_blocked_web', 'Attempt to call mark_scheduled_due via web');
        show_error('This endpoint is CLI only', 403);
        return;
    }

    try {
        $now = date('Y-m-d H:i:s');
        // حوّل المنشورات التي انتهى موعدها من scheduled => processing
        $this->db->where('status', 'scheduled');
        $this->db->where('scheduled_time <=', $now);
        $this->db->update('social_posts', ['status' => 'processing', 'updated_at' => $now]);

        $affected = $this->db->affected_rows();
        $this->log_error('mark_scheduled_due_done', json_encode(['time' => $now, 'updated_rows' => $affected], JSON_UNESCAPED_UNICODE));
        echo "Marked due scheduled posts to processing (rows updated: {$affected})\n";
    } catch (Exception $e) {
        $this->log_error('mark_scheduled_due_error', $e->getMessage());
        echo "Error: " . $e->getMessage() . "\n";
    }
}
    /**
     * Require login helper
     */
    private function require_login()
    {
        if (!$this->session->userdata('user_id')) {
            $redirect = rawurlencode(current_url());
            redirect('home/login?redirect=' . $redirect);
            exit;
        }
    }
/** helper: return platform_account_id column name (fixed for your schema) */
private function get_account_column_name()
{
    return 'platform_account_id';
}

/** helper: return scheduled column (scheduled_time or scheduled_at) */
private function get_scheduled_column()
{
    if ($this->column_exists('social_posts', 'scheduled_time')) return 'scheduled_time';
    if ($this->column_exists('social_posts', 'scheduled_at')) return 'scheduled_at';
    return null;
}
    /**
     * Send JSON response helper
     */
    private function send_json($data, $code = 200)
    {
        $this->output->set_status_header($code);
        $this->output->set_content_type('application/json', 'utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Simple logger to application logs directory
     */
    private function log_error($context, $message)
    {
        $log_dir = APPPATH . 'logs/';
        if (!is_dir($log_dir)) {
            @mkdir($log_dir, 0755, true);
        }

        $log_file = $log_dir . 'social_publisher.log';
        $log_entry = '[' . date('Y-m-d H:i:s') . '] ' . $context . ': ' . $message . PHP_EOL;

        // use LOCK_EX to avoid concurrent writes
        @file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * الصفحة الرئيسية للنشر الموحد
     */
    public function index()
    {
        $this->require_login();
        $uid = (int)$this->session->userdata('user_id');

        // جلب صفحات Facebook
        $facebook_pages = $this->Facebook_pages_model->get_pages_by_user($uid);

        // جلب حسابات Instagram بأمان
        $instagram_accounts = $this->get_instagram_accounts_safe($uid);

        // هاشتاجات شائعة
        $trending_hashtags = $this->Reel_model->get_trending_hashtags();

        $data = [
            'facebook_pages' => $facebook_pages,
            'instagram_accounts' => $instagram_accounts,
            'trending_hashtags' => $trending_hashtags
        ];

        $this->load->view('social_publisher_upload', $data);
    }

    /**
     * جلب حسابات Instagram بأمان مع فحص الأعمدة
     */
    private function get_instagram_accounts_safe($user_id)
    {
        try {
            // فحص وجود الجداول والأعمدة
            if (!$this->db->table_exists('instagram_accounts')) {
                $this->create_instagram_accounts_table();
            }

            if (!$this->db->table_exists('facebook_rx_fb_page_info')) {
                return [];
            }

            $columns = $this->db->query("SHOW COLUMNS FROM facebook_rx_fb_page_info")->result_array();
            $available_columns = array_column($columns, 'Field');

            // بناء الحقول للاستخراج من جدول facebook_rx_fb_page_info
            $select_fields = ['user_id', 'page_id as ig_user_id'];

            if (in_array('page_name', $available_columns)) {
                $select_fields[] = 'page_name';
            } else {
                $select_fields[] = 'page_id as page_name';
            }

            if (in_array('username', $available_columns)) {
                $select_fields[] = 'username as ig_username';
            } else {
                $select_fields[] = 'page_id as ig_username';
            }

            if (in_array('page_profile', $available_columns)) {
                $select_fields[] = 'page_profile as ig_profile_picture';
            } else {
                $select_fields[] = 'NULL as ig_profile_picture';
            }

            if (in_array('page_access_token', $available_columns)) {
                $select_fields[] = 'page_access_token as access_token';
            } else {
                $select_fields[] = 'NULL as access_token';
            }

            // إدخال بيانات إلى instagram_accounts (INSERT IGNORE)
            $sql = "INSERT IGNORE INTO instagram_accounts 
                    (user_id, ig_user_id, ig_username, page_name, ig_profile_picture, access_token, ig_linked, status, created_at, updated_at)
                    SELECT " . implode(',', $select_fields) . ", 1, 'active', NOW(), NOW()
                    FROM facebook_rx_fb_page_info
                    WHERE user_id = ?";

            $this->db->query($sql, [$user_id]);

            // جلب الحسابات المُزامنة
            return $this->db->select('ig_user_id, ig_username, page_name, ig_profile_picture, access_token')
                           ->from('instagram_accounts')
                           ->where('user_id', $user_id)
                           ->where('ig_linked', 1)
                           ->where('status', 'active')
                           ->get()->result_array();

        } catch (Exception $e) {
            $this->log_error('get_instagram_accounts_safe', $e->getMessage());
            return [];
        }
    }

    /**
     * إنشاء جدول instagram_accounts (احتياطي)
     */
    private function create_instagram_accounts_table()
    {
        $sql = "CREATE TABLE IF NOT EXISTS `instagram_accounts` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `ig_user_id` varchar(100) NOT NULL,
            `ig_username` varchar(255) DEFAULT NULL,
            `page_name` varchar(255) DEFAULT NULL,
            `ig_profile_picture` text DEFAULT NULL,
            `access_token` text DEFAULT NULL,
            `ig_linked` tinyint(1) DEFAULT 1,
            `status` enum('active','inactive') DEFAULT 'active',
            `created_at` datetime NOT NULL,
            `updated_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `user_ig_unique` (`user_id`, `ig_user_id`),
            KEY `user_id` (`user_id`),
            KEY `ig_user_id` (`ig_user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $this->db->query($sql);
    }

    /**
     * إنشاء جدول social_posts (احتياطي)
     * ملاحظة: إذا لديك أعمدة إضافية (processing, auto_comment, comments_json) فأنت اضفتها خارجًا.
     */
    private function create_social_posts_table()
    {
        if ($this->db->table_exists('social_posts')) {
            return;
        }

        $sql = "CREATE TABLE `social_posts` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `platform` enum('facebook','instagram') NOT NULL,
            `account_id` varchar(100) NOT NULL,
            `account_name` varchar(255) DEFAULT NULL,
            `content_type` varchar(50) NOT NULL,
            `title` text DEFAULT NULL,
            `description` text DEFAULT NULL,
            `file_path` varchar(500) DEFAULT NULL,
            `status` enum('pending','published','failed','scheduled','publishing') DEFAULT 'pending',
            `post_id` varchar(100) DEFAULT NULL,
            `error_message` text DEFAULT NULL,
            `scheduled_time` datetime DEFAULT NULL,
            `published_time` datetime DEFAULT NULL,
            `likes_count` int(11) DEFAULT 0,
            `comments_count` int(11) DEFAULT 0,
            `shares_count` int(11) DEFAULT 0,
            `created_at` datetime NOT NULL,
            `updated_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `user_id` (`user_id`),
            KEY `platform` (`platform`),
            KEY `status` (`status`),
            KEY `scheduled_time` (`scheduled_time`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $this->db->query($sql);
    }

    /**
     * معالجة رفع/نشر محتوى من الواجهة
     */
    public function process_upload()
    {
        $this->require_login();
        $uid = (int)$this->session->userdata('user_id');

        try {
            $platform = $this->input->post('platform');
            $content_type = $this->input->post('content_type');

            if (!$platform || !$content_type) {
                throw new Exception('المنصة ونوع المحتوى مطلوبان');
            }

            // تأكد من وجود جدول المنشورات
            $this->create_social_posts_table();

            // توجيه للمعالج المناسب
            if ($platform === 'facebook') {
                return $this->process_facebook_upload($uid, $content_type);
            } elseif ($platform === 'instagram') {
                return $this->process_instagram_upload($uid, $content_type);
            } else {
                throw new Exception('منصة غير مدعومة');
            }

        } catch (Exception $e) {
            $this->log_error('process_upload', $e->getMessage());

            if ($this->input->is_ajax_request()) {
                return $this->send_json(['success' => false, 'message' => $e->getMessage()], 400);
            }

            $this->session->set_flashdata('msg', $e->getMessage());
            redirect('social_publisher');
        }
    }

    /**
     * معالجة نشر Facebook (توجيه حسب النوع)
     */
    private function process_facebook_upload($uid, $content_type)
    {
        $fb_page_ids = $this->input->post('facebook_pages');
        if (empty($fb_page_ids)) {
            throw new Exception('اختر صفحة Facebook واحدة على الأقل');
        }

        $pages = $this->Facebook_pages_model->get_pages_by_user($uid);
        if (!$pages) {
            throw new Exception('لا توجد صفحات Facebook مربوطة');
        }

        // ربط بأنظمة الريلز والقصص المتوفرة
        if ($content_type === 'reel') {
            return $this->process_facebook_reels($uid, $pages);
        } elseif ($content_type === 'story_video' || $content_type === 'story_photo') {
            return $this->process_facebook_stories($uid, $pages, $content_type);
        } else {
            return $this->process_facebook_posts($uid, $pages, $content_type);
        }
    }

    /**
     * معالجة ريلز Facebook باستخدام Reel_model
     * نمرّر الآن تعليقات الملفات والتعليقات العامة إلى الموديل عبر $_POST ليتم حفظها إن كان الموديل يدعم ذلك.
     */
private function process_facebook_reels($uid, $pages)
{
    if (empty($_FILES['files']['name'][0])) {
        throw new Exception('اختر ملفات فيديو للريلز');
    }

    // تحضير البيانات لنداء Reel_model (متوافق مع القديم)
    $_POST['fb_page_ids'] = $this->input->post('facebook_pages');
    $_POST['description'] = $this->input->post('global_description');
    $_POST['selected_hashtags'] = $this->input->post('selected_hashtags');
    $_POST['descriptions'] = $this->input->post('file_descriptions') ?: [];
    $_POST['schedule_times'] = $this->input->post('file_schedule_times') ?: [];
    $_POST['tz_offset_minutes'] = (int)$this->input->post('timezone_offset');
    $_POST['tz_name'] = $this->input->post('timezone_name');
    $_POST['media_type'] = 'reel';
    $_POST['file_comments'] = $this->input->post('file_comments') ?: [];
    $_POST['auto_comments_global'] = $this->input->post('auto_comments_global') ?: [];

    // map files key for Reel_model
    $_FILES['video_files'] = $_FILES['files'];

    $this->log_error('UPLOAD_REELS_CALL', json_encode([
        'user' => $uid,
        'pages' => $_POST['fb_page_ids'],
        'files' => array_values(array_filter($_FILES['video_files']['name'] ?? []))
    ], JSON_UNESCAPED_UNICODE));

    // استدعاء الموديل للرفع
    $responses = $this->Reel_model->upload_reels($uid, $pages, $_POST, $_FILES);
    $this->log_error('upload_reels_model_response', json_encode($responses, JSON_UNESCAPED_UNICODE));

    // جلب قائمة أعمدة الجدول social_posts مرة واحدة (لتجنب أخطاء Unknown column)
    $available_columns = [];
    try {
        $cols = $this->db->query("SHOW COLUMNS FROM `social_posts`")->result_array();
        if (!empty($cols)) {
            $available_columns = array_column($cols, 'Field');
        }
    } catch (Exception $e) {
        $this->log_error('process_facebook_reels_show_columns_failed', $e->getMessage());
    }

    // Helper: map candidate keys إلى أعمدة موجودة إن كانت مختلفة أسماء
    $alias_map = [
        'platform_account_id' => ['platform_account_id', 'account_id', 'platform_account'],
        'platform_post_id'    => ['platform_post_id', 'post_id', 'platform_post'],
        'content_text'        => ['content_text', 'description', 'content'],
        'media_paths'         => ['media_paths', 'file_path', 'file_path_name'],
        'media_files'         => ['media_files', 'file_name', 'media_files'],
        'comments_json'       => ['comments_json', 'comments'],
        'auto_comment'        => ['auto_comment', 'auto_comments'],
        'post_type'           => ['post_type', 'type'],
        'last_error'          => ['last_error', 'error_message'],
        'published_time'      => ['published_time', 'published_at'],
        'scheduled_time'      => ['scheduled_time', 'scheduled_at']
    ];

    // ضبط قائمة الصفحات والملفات
    $selected_pages = $_POST['fb_page_ids'] ?: [];
    $video_files_names = $_FILES['video_files']['name'] ?? [];

    $added = 0;
    // نحافظ على الفهرس الأصلي للملفات حتى نربط التعليقات الصحيحة بكل ملف
    for ($fidx = 0; $fidx < count($video_files_names); $fidx++) {
        $fname = $video_files_names[$fidx];
        if (empty($fname)) continue;

        // حاول استخراج video_id و post_id من استجابة الموديل أو من اللوج (best-effort)
        $file_level_video_id = null;
        $file_level_post_id = null;
        if (is_array($responses)) {
            foreach ($responses as $r) {
                if ((!empty($r['file']) && $r['file'] === $fname) ||
                    (!empty($r['filename']) && $r['filename'] === $fname) ||
                    (!empty($r['original_name']) && $r['original_name'] === $fname)
                ) {
                    $file_level_video_id = $file_level_video_id ?: ($r['video_id'] ?? $r['media_fbid'] ?? null);
                    $file_level_post_id  = $file_level_post_id  ?: ($r['post_id'] ?? $r['postid'] ?? null);
                }
                if (!empty($r['video_id']) && !empty($r['page']) && in_array($r['page'], $selected_pages, true) && empty($file_level_video_id)) {
                    $file_level_video_id = $r['video_id'];
                }
            }
        }

        foreach ($selected_pages as $page_id) {
            // إذا لم نجد video_id من الاستجابات حاول البحث في اللوج
            $video_id = $file_level_video_id ?: $this->extract_postid_from_reels_log($page_id, $fname, 600);
            $post_id_from_model = $file_level_post_id ?: null;

            // Validate extracted ids: ignore values that are equal to page id or obviously invalid
            if (!empty($video_id)) {
                $video_id = trim((string)$video_id);
                // if equals page id, ignore (common error)
                if ((string)$video_id === (string)$page_id) {
                    $this->log_error('process_facebook_reels_ignored_videoid_equals_page', json_encode([
                        'page' => $page_id,
                        'file' => $fname,
                        'found' => $video_id
                    ], JSON_UNESCAPED_UNICODE));
                    $video_id = null;
                } elseif (!preg_match('/^[0-9_]{6,}$/', $video_id)) {
                    // not a plausible video/post id (reject)
                    $this->log_error('process_facebook_reels_ignored_videoid_bad_pattern', json_encode([
                        'file' => $fname,
                        'found' => $video_id
                    ], JSON_UNESCAPED_UNICODE));
                    $video_id = null;
                }
            }
            if (!empty($post_id_from_model)) {
                $post_id_from_model = trim((string)$post_id_from_model);
                if (!preg_match('/^[0-9_]{6,}$/', $post_id_from_model)) {
                    $this->log_error('process_facebook_reels_ignored_postid_bad_pattern', json_encode([
                        'file' => $fname,
                        'found' => $post_id_from_model
                    ], JSON_UNESCAPED_UNICODE));
                    $post_id_from_model = null;
                }
            }

            // جمع التعليقات الخاصة بهذا الملف (إن وُجدت) والتعليقات العامة
            $per_file_comments = [];
            if (!empty($_POST['file_comments']) && isset($_POST['file_comments'][$fidx]) && is_array($_POST['file_comments'][$fidx])) {
                $per_file_comments = array_values(array_filter($_POST['file_comments'][$fidx]));
            }
            $global_auto_comments = is_array($_POST['auto_comments_global']) ? $_POST['auto_comments_global'] : [];

            // احسب إن الملف مجدول لهذا الفهرس
            $per_file_schedule = null;
            if (!empty($_POST['schedule_times']) && isset($_POST['schedule_times'][$fidx]) && $_POST['schedule_times'][$fidx]) {
                $raw_sched = $_POST['schedule_times'][$fidx];
                $ts = is_numeric($raw_sched) ? (int)$raw_sched : strtotime($raw_sched);
                if ($ts !== false && $ts > 0) {
                    $per_file_schedule = date('Y-m-d H:i:s', $ts);
                }
            }

            // حدد الحالة الأولية: إذا الموديل أعطى post_id بالفعل نعتبره منشورًا الآن،
            // وإلا إذا وجدنا موعد مجدول اجعل الحالة scheduled، وإلا processing.
            $initial_status = 'processing';
            if (!empty($post_id_from_model)) {
                $initial_status = 'published';
            } elseif (!empty($per_file_schedule)) {
                $initial_status = 'scheduled';
            } else {
                $initial_status = 'processing';
            }

            // بناء سجل مرجعي (candidate) باسماء مفهومة
            $candidate_record = [
                'user_id' => $uid,
                'platform' => 'facebook',
                'platform_account_id' => $page_id,
                'post_type' => 'video',
                'content_text' => $_POST['description'] ?? null,
                'media_files' => $fname,
                'media_paths' => 'uploads/facebook_reels/' . $fname,
                'status' => $initial_status,
                'platform_post_id' => $post_id_from_model ?: ($video_id ?: null),
                'scheduled_time' => $per_file_schedule,
                'auto_comment' => !empty($global_auto_comments) ? json_encode(array_values($global_auto_comments), JSON_UNESCAPED_UNICODE) : null,
                'comments_json' => !empty($per_file_comments) ? json_encode($per_file_comments, JSON_UNESCAPED_UNICODE) : null,
                'last_error' => null,
                'published_time' => (!empty($post_id_from_model) ? date('Y-m-d H:i:s') : null),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];

            // فلترة وترجمة الحقول إلى الأعمدة الفعلية الموجودة في DB
            $filtered = [];
            if (!empty($available_columns)) {
                foreach ($candidate_record as $key => $val) {
                    if (in_array($key, $available_columns, true)) {
                        $filtered[$key] = $val;
                        continue;
                    }
                    if (isset($alias_map[$key]) && is_array($alias_map[$key])) {
                        foreach ($alias_map[$key] as $alt) {
                            if (in_array($alt, $available_columns, true)) {
                                $filtered[$alt] = $val;
                                break;
                            }
                        }
                    }
                }
            } else {
                $filtered = $candidate_record;
            }

            try {
                if (empty($filtered)) {
                    $this->log_error('process_facebook_reels_insert_skipped', json_encode([
                        'reason' => 'no matching columns in social_posts',
                        'candidate' => array_keys($candidate_record),
                        'available_columns_sample' => array_slice($available_columns, 0, 20)
                    ], JSON_UNESCAPED_UNICODE));
                } else {
                    $this->db->insert('social_posts', $filtered);
                    $insert_id = $this->db->insert_id();
                    $added++;

                    // لو الموديل أعطانا post_id (feed id) فاعتبر المنشور منشورًا الآن وننشر التعليقات فورًا
                    if (!empty($post_id_from_model)) {
                        try {
                            $update = [];
                            if (in_array('status', $available_columns, true)) $update['status'] = 'published';
                            $col = in_array('platform_post_id', $available_columns, true) ? 'platform_post_id' : (in_array('post_id', $available_columns, true) ? 'post_id' : null);
                            if ($col) $update[$col] = $post_id_from_model;
                            if (in_array('published_time', $available_columns, true)) $update['published_time'] = date('Y-m-d H:i:s');
                            if (!empty($update)) {
                                $this->db->where('id', $insert_id)->update('social_posts', $update);
                            }
                        } catch (Exception $e) {
                            $this->log_error('process_facebook_reels_update_published_failed', json_encode(['msg' => $e->getMessage(), 'insert_id' => $insert_id], JSON_UNESCAPED_UNICODE));
                        }

                        // حاول نشر التعليقات الآن باستخدام توكن الصفحة إن أمكن
                        $page_token = $this->get_page_access_token_by_page_id($page_id);
                        if (!empty($page_token)) {
                            $saved = $this->db->where('id', $insert_id)->get('social_posts')->row_array();
                            $this->publish_comments_for_facebook_post($saved, $page_token);
                        } else {
                            $this->log_error('process_facebook_reels_no_token_for_comment', json_encode([
                                'page' => $page_id,
                                'insert_id' => $insert_id
                            ], JSON_UNESCAPED_UNICODE));
                        }

                        $this->log_error('process_facebook_reels_published_from_model', json_encode([
                            'page' => $page_id,
                            'file' => $fname,
                            'post_id' => $post_id_from_model,
                            'social_posts_id' => $insert_id
                        ], JSON_UNESCAPED_UNICODE));
                    } else {
                        // حالة عادية: سجل processing أو scheduled
                        $this->log_error('process_facebook_reels_saved', json_encode([
                            'page' => $page_id,
                            'file' => $fname,
                            'video_id' => $video_id,
                            'status' => $initial_status,
                            'scheduled_time' => $per_file_schedule,
                            'social_posts_id' => $insert_id
                        ], JSON_UNESCAPED_UNICODE));

                        // إذا عندنا video_id صالح ونضعه كـ processing — حاول فورًا حل mapping video -> post_id لثوانٍ معدودة
                        if ($initial_status === 'processing' && !empty($video_id)) {
                            $page_token = $this->get_page_access_token_by_page_id($page_id);
                            if (!empty($page_token)) {
                                $this->try_resolve_and_publish($insert_id, $video_id, $page_id, $page_token);
                            } else {
                                $this->log_error('process_facebook_reels_no_token_for_resolve', json_encode([
                                    'page' => $page_id,
                                    'insert_id' => $insert_id
                                ], JSON_UNESCAPED_UNICODE));
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                $this->log_error('db_insert_error_process_facebook_reels', json_encode([
                    'msg' => $e->getMessage(),
                    'candidate_record' => $candidate_record,
                    'filtered_record' => $filtered
                ], JSON_UNESCAPED_UNICODE));
            }
        } // end pages loop
    } // end files loop

    $this->log_error('process_facebook_reels_done', json_encode([
        'inserted_count' => $added,
        'note' => 'deferred feed creation to poller when applicable'
    ], JSON_UNESCAPED_UNICODE));

    // اعرض ردود الموديل للواجهة كما كان معتادا
    $this->handle_responses($responses);
}

/**
 * Try resolving video_id -> feed post_id for a short interval (best-effort)
 * If found, updates social_posts record to published and publishes comments.
 */
private function try_resolve_and_publish($social_post_db_id, $candidate_id, $page_id, $page_token)
{
    try {
        // candidate_id قد يكون video_id أو قد يكون feed post id (post_id).
        // نجرّب مباشرة Query على /v17.0/{candidate_id}?fields=post_id,permalink_url
        $attempts = 3;
        $wait = 4; // ثانية بين المحاولات

        for ($i = 0; $i < $attempts; $i++) {
            $url = "https://graph.facebook.com/v17.0/{$candidate_id}?fields=id,post_id,permalink_url&access_token=" . urlencode($page_token);
            $raw = $this->curl_get($url, 10);
            if ($raw === false) {
                $this->log_error('try_resolve_and_publish_curl_failed', json_encode([
                    'social_post_db_id' => $social_post_db_id,
                    'candidate_id' => $candidate_id,
                    'attempt' => $i+1
                ], JSON_UNESCAPED_UNICODE));
                sleep($wait);
                continue;
            }

            $resp = json_decode($raw, true);
            $this->log_error('try_resolve_and_publish_resp', json_encode([
                'social_post_db_id' => $social_post_db_id,
                'candidate_id' => $candidate_id,
                'attempt' => $i+1,
                'resp' => $resp
            ], JSON_UNESCAPED_UNICODE));

            if (is_array($resp) && empty($resp['error'])) {
                // إذا نوجد post_id في الاستجابة - فهو feed id
                if (!empty($resp['post_id'])) {
                    $feed_post_id = $resp['post_id'];
                } else {
                    // وإلا إن كان id نفسه يبدو كـ feed id فاعتبره feed id
                    if (preg_match('/^[0-9]{6,}_[0-9]{4,}$/', (string)$candidate_id) || preg_match('/^[0-9]{8,}$/', (string)$candidate_id)) {
                        $feed_post_id = $candidate_id;
                    } else {
                        $feed_post_id = null;
                    }
                }

                if (!empty($feed_post_id)) {
                    // حدّث قاعدة البيانات: status=published و platform_post_id=feed_post_id
                    $update = [];
                    if ($this->db->field_exists('status', 'social_posts')) $update['status'] = 'published';
                    if ($this->db->field_exists('platform_post_id', 'social_posts')) $update['platform_post_id'] = $feed_post_id;
                    if ($this->db->field_exists('published_time', 'social_posts')) $update['published_time'] = date('Y-m-d H:i:s');
                    if (!empty($update)) $this->db->where('id', $social_post_db_id)->update('social_posts', $update);

                    $saved = $this->db->where('id', $social_post_db_id)->get('social_posts')->row_array();
                    // حاول نشر التعليقات الآن
                    $this->publish_comments_for_facebook_post($saved, $page_token);

                    $this->log_error('try_resolve_and_publish_success', json_encode([
                        'social_post_db_id' => $social_post_db_id,
                        'found_feed_post_id' => $feed_post_id
                    ], JSON_UNESCAPED_UNICODE));
                    return true;
                }
            }

            sleep($wait);
        }

        $this->log_error('try_resolve_and_publish_not_found', json_encode([
            'social_post_db_id' => $social_post_db_id,
            'candidate_id' => $candidate_id
        ], JSON_UNESCAPED_UNICODE));
        return false;
    } catch (Exception $e) {
        $this->log_error('try_resolve_and_publish_exception', json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE));
        return false;
    }
}
// 3) مساعدة: استخراج post_id من ملف اللوج reels_api.log (حل مؤقت)
private function extract_postid_from_reels_log($page_id, $filename, $lookback_seconds = 300)
{
    try {
        $log_path = APPPATH . 'logs/reels_api.log';
        if (!is_file($log_path) || !is_readable($log_path)) return null;

        // اقرأ آخر الأسطر (بحد أقصى 4000 سطر لتخفيف الذاكرة)
        $lines = @file($log_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) return null;
        $lines = array_slice($lines, -4000);

        $now = time();

        // أنماط بحث مرتبة بالأولوية
        $patterns = [
            '/"post_id"\s*:\s*"([^"]+)"/i',
            '/post_id=([0-9_]+)/i',
            '/"video_id"\s*:\s*"([^"]+)"/i',
            '/video_id=([0-9_]+)/i',
            '/"media_fbid"\s*:\s*"([^"]+)"/i',
            '/media_fbid=([0-9_]+)/i',
            // feed id like 123_456
            '/([0-9]{6,}_[0-9]{4,})/',
            // long numeric id
            '/([0-9]{8,})/'
        ];

        $is_plausible_id = function($id) use ($page_id) {
            if (empty($id)) return false;
            $id = trim((string)$id);
            // لا تقبل id يساوي page id (خطأ شائع)
            if ($id === (string)$page_id) return false;
            // قبول feed id بصيغة 123_456
            if (preg_match('/^[0-9]{6,}_[0-9]{4,}$/', $id)) return true;
            // قبول long numeric (8+ digits)
            if (preg_match('/^[0-9]{8,}$/', $id)) return true;
            return false;
        };

        // Helper: استخراج أول id مطابق من سطر
        $extract = function($line) use ($patterns) {
            foreach ($patterns as $pat) {
                if (preg_match($pat, $line, $m)) {
                    if (!empty($m[1])) return $m[1];
                }
            }
            return null;
        };

        // نبحث خطوط أحدث أولاً - شرط الوقت إذا تحوي طابع زمني قابل للقراءة
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = $lines[$i];

            // احترم lookback_seconds إن وُجد طابع زمني في بداية السطر
            $ts_ok = true;
            if (preg_match('/^\[?(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})/', $line, $t)) {
                $ts = strtotime($t[1] . ' UTC');
                if ($ts !== false && ($now - $ts) > $lookback_seconds) $ts_ok = false;
            }
            if (!$ts_ok) continue;

            $has_file = $filename !== '' && stripos($line, basename($filename)) !== false;
            $has_page = stripos($line, (string)$page_id) !== false;

            // أفضل تطابق: سطر يحتوي الملف والصفحة مع id صالح
            if ($has_file && $has_page) {
                $id = $extract($line);
                if ($id && $is_plausible_id($id)) return $id;
            }
        }

        // ثم: أي سطر يحتوي الملف وحده
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = $lines[$i];
            $ts_ok = true;
            if (preg_match('/^\[?(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})/', $line, $t)) {
                $ts = strtotime($t[1] . ' UTC');
                if ($ts !== false && ($now - $ts) > $lookback_seconds) $ts_ok = false;
            }
            if (!$ts_ok) continue;

            if (stripos($line, basename($filename)) !== false) {
                $id = $extract($line);
                if ($id && $is_plausible_id($id)) return $id;
            }
        }

        // ثم: أي سطر يحتوي الصفحة مع id صالح
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = $lines[$i];
            $ts_ok = true;
            if (preg_match('/^\[?(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})/', $line, $t)) {
                $ts = strtotime($t[1] . ' UTC');
                if ($ts !== false && ($now - $ts) > $lookback_seconds) $ts_ok = false;
            }
            if (!$ts_ok) continue;

            if (stripos($line, (string)$page_id) !== false) {
                $id = $extract($line);
                if ($id && $is_plausible_id($id)) return $id;
            }
        }

        return null;
    } catch (Exception $e) {
        $this->log_error('extract_postid_from_reels_log_exception', $e->getMessage());
        return null;
    }
}
    /**
     * معالجة قصص Facebook
     */
    private function process_facebook_stories($uid, $pages, $content_type)
    {
        if (empty($_FILES['files']['name'][0])) {
            throw new Exception('اختر ملفات للقصص');
        }

        $_POST['fb_page_ids'] = $this->input->post('facebook_pages');
        $_POST['media_type'] = $content_type;

        if ($content_type === 'story_photo') {
            $_FILES['story_photo_files'] = $_FILES['files'];
            $responses = $this->Reel_model->upload_story_photo($uid, $pages, $_POST, $_FILES);
        } else {
            $_FILES['video_files'] = $_FILES['files'];
            $responses = $this->Reel_model->upload_story_video($uid, $pages, $_POST, $_FILES);
        }

        $this->handle_responses($responses);
    }

    /**
     * معالجة منشورات Facebook العادية
     */
private function process_facebook_posts($uid, $pages, $content_type)
{
    $fb_page_ids = $this->input->post('facebook_pages');
    $results = [];

    foreach ($fb_page_ids as $page_id) {
        $page = null;
        foreach ($pages as $p) {
            if (($p['fb_page_id'] ?? '') === (string)$page_id || ($p['page_id'] ?? '') === (string)$page_id) {
                $page = $p;
                break;
            }
        }

        if (!$page || empty($page['page_access_token'])) {
            $results[] = ['type' => 'error', 'msg' => "لا يوجد access token للصفحة {$page_id}"];
            continue;
        }

        try {
            $post_result = $this->publish_facebook_post($page_id, $page['page_access_token'], $content_type);

            if (!empty($post_result['success'])) {
                // map content_type to post_type column values
                $map = [
                    'post_text' => 'text',
                    'post_photo' => 'image',
                    'post_video' => 'video'
                ];
                $post_type = isset($map[$content_type]) ? $map[$content_type] : 'text';

                $account_col = $this->get_account_column_name();
                $record = [
                    'user_id' => $uid,
                    'platform' => 'facebook',
                    $account_col => $page_id,
                    // account_name column not present in your table => don't include it
                    'post_type' => $post_type,
                    'content_text' => $this->input->post('post_description') ?: $this->input->post('global_description'),
                    'media_files' => !empty($_FILES['files']['name'][0]) ? $_FILES['files']['name'][0] : null,
                    'media_paths' => !empty($_FILES['files']['name'][0]) ? ('uploads/facebook_temp/' . $_FILES['files']['name'][0]) : null,
                    'status' => 'published',
                    'platform_post_id' => $post_result['post_id'] ?? null,
                    'last_error' => null,
                    'published_time' => date('Y-m-d H:i:s'),
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ];

                try {
                    $this->db->insert('social_posts', $record);
                    $inserted_id = $this->db->insert_id();

                    // نشر التعليقات إذا وُجدت
                    $saved_post = $this->db->where('id', $inserted_id)->get('social_posts')->row_array();
                    $this->publish_comments_for_facebook_post($saved_post, $page['page_access_token']);

                    $results[] = ['type' => 'success', 'msg' => "تم النشر على {$page['page_name']}"];
                } catch (Exception $e) {
                    $dberr = $this->db->error();
                    $this->log_error('db_insert_error_process_facebook_posts', json_encode(['msg' => $e->getMessage(), 'dberr' => $dberr, 'record' => $record], JSON_UNESCAPED_UNICODE));
                    $results[] = ['type' => 'error', 'msg' => "فشل حفظ السجل في DB: " . ($dberr['message'] ?? $e->getMessage())];
                }
            } else {
                // سجّل الفشل في الجدول باستخدام الأعمدة الموجودة
                $account_col = $this->get_account_column_name();
                $fail_rec = [
                    'user_id' => $uid,
                    'platform' => 'facebook',
                    $account_col => $page_id,
                    'post_type' => ($content_type === 'post_photo' ? 'image' : ($content_type === 'post_video' ? 'video' : 'text')),
                    'content_text' => $this->input->post('post_description') ?: $this->input->post('global_description'),
                    'status' => 'failed',
                    'last_error' => $post_result['error'] ?? 'خطأ في النشر',
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ];
                try { $this->db->insert('social_posts', $fail_rec); } catch(Exception $e){ $this->log_error('db_insert_error_process_facebook_posts_failed', json_encode(['msg'=>$e->getMessage(),'record'=>$fail_rec], JSON_UNESCAPED_UNICODE)); }

                $results[] = ['type' => 'error', 'msg' => "فشل النشر على {$page['page_name']}: " . ($post_result['error'] ?? 'خطأ')];
            }

        } catch (Exception $e) {
            $this->log_error('process_facebook_posts_exception', $e->getMessage());
            $results[] = ['type' => 'error', 'msg' => "خطأ في النشر على {$page['page_name']}: " . $e->getMessage()];
        }
    }

    $this->handle_responses($results);
}

    /**
     * نشر منشور Facebook (مُحسّن من ناحية التحقق)
     */
    private function publish_facebook_post($page_id, $access_token, $content_type)
    {
        try {
            $url = "https://graph.facebook.com/v19.0/{$page_id}/feed";

            $params = [
                'access_token' => $access_token
            ];

            // تجهيز المحتوى بحسب النوع
            switch ($content_type) {
                case 'post_text':
                    $message = $this->input->post('post_description') ?: $this->input->post('global_description');
                    if (!$message) {
                        throw new Exception('أدخل نص المنشور');
                    }
                    $params['message'] = $message;
                    break;

                case 'post_photo':
                    if (!empty($_FILES['files']['tmp_name'][0])) {
                        $message = $this->input->post('post_description') ?: $this->input->post('global_description');
                        $fileTmp = $_FILES['files']['tmp_name'][0];
                        $fileName = $_FILES['files']['name'][0];

                        if (!is_file($fileTmp)) {
                            throw new Exception('ملف الصورة غير صالح');
                        }

                        // رفع الصورة مباشرة كـ منشور (avoid unpublished+object_attachment workflow)
                        $url = "https://graph.facebook.com/v19.0/{$page_id}/photos";

                        $mime = function_exists('mime_content_type') ? @mime_content_type($fileTmp) : null;
                        if (!$mime) $mime = 'image/jpeg';
                        $cfile = new CURLFile($fileTmp, $mime, $fileName);

                        $post_fields = [
                            'source' => $cfile,
                            'message' => $message,
                            'published' => 'true',
                            'access_token' => $access_token
                        ];

                        $ch = curl_init($url);
                        if (defined('CURLOPT_SAFE_UPLOAD')) curl_setopt($ch, CURLOPT_SAFE_UPLOAD, true);
                        curl_setopt_array($ch, [
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_POST => true,
                            CURLOPT_POSTFIELDS => $post_fields,
                            CURLOPT_SSL_VERIFYPEER => false,
                            CURLOPT_TIMEOUT => 120
                        ]);

                        $response = curl_exec($ch);
                        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        $curl_err = curl_error($ch);
                        curl_close($ch);

                        // سجل الرد للتحقق
                        $this->log_error('publish_facebook_post_photo_direct', "page={$page_id} file={$fileName} http_code={$http_code} curl_err={$curl_err} resp_preview=" . substr((string)$response,0,1200));

                        $j = json_decode($response, true);
                        if ($http_code === 200 && !empty($j['id'])) {
                            return ['success' => true, 'post_id' => $j['id']];
                        }
                        $error = isset($j['error']) ? $j['error']['message'] : "HTTP {$http_code}";
                        return ['success' => false, 'error' => $error];
                    } else {
                        throw new Exception('اختر صورة للنشر');
                    }
                    break;

                case 'post_video':
                    if (!empty($_FILES['files']['tmp_name'][0])) {
                        $fileTmp = $_FILES['files']['tmp_name'][0];
                        $fileName = $_FILES['files']['name'][0];

                        if (!is_file($fileTmp) || !is_readable($fileTmp)) {
                            throw new Exception('ملف الفيديو غير صالح أو غير قابل للقراءة');
                        }

                        // حاول الرفع المباشر كـ منشور فيديو (published = true)
                        $url = "https://graph.facebook.com/v19.0/{$page_id}/videos";

                        // حدد mime type إن أمكن
                        $mime = function_exists('mime_content_type') ? @mime_content_type($fileTmp) : null;
                        if (!$mime) {
                            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                            $mime = ($ext === 'mp4') ? 'video/mp4' : 'application/octet-stream';
                        }

                        $cfile = new CURLFile($fileTmp, $mime, $fileName);

                        $post_fields = [
                            'source' => $cfile,
                            'description' => $this->input->post('post_description') ?: $this->input->post('global_description'),
                            'published' => 'true',
                            'access_token' => $access_token
                        ];

                        $ch = curl_init($url);
                        if (defined('CURLOPT_SAFE_UPLOAD')) curl_setopt($ch, CURLOPT_SAFE_UPLOAD, true);
                        curl_setopt_array($ch, [
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_POST => true,
                            CURLOPT_POSTFIELDS => $post_fields,
                            CURLOPT_SSL_VERIFYPEER => false,
                            CURLOPT_CONNECTTIMEOUT => 30,
                            CURLOPT_TIMEOUT => 600 // زمن أطول للفيديو
                        ]);

                        $response = curl_exec($ch);
                        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        $errno = curl_errno($ch);
                        $curl_err = curl_error($ch);
                        curl_close($ch);

                        // سجل النتيجة في لوج الكنترولر للمراجعة
                        $this->log_error('publish_facebook_post_video_direct', "page={$page_id} file={$fileName} http_code={$http_code} curl_errno={$errno} curl_err={$curl_err} resp_preview=" . substr((string)$response,0,1200));

                        $j = json_decode($response, true);
                        if ($errno) {
                            return ['success' => false, 'error' => 'cURL error: ' . $curl_err];
                        }
                        if ($http_code === 200 && !empty($j['id'])) {
                            return ['success' => true, 'post_id' => $j['id']];
                        }

                        $error = $j['error']['message'] ?? ("HTTP {$http_code}");
                        return ['success' => false, 'error' => $error];
                    } else {
                        throw new Exception('اختر فيديو للنشر');
                    }
                    break;

                default:
                    throw new Exception('نوع محتوى غير مدعوم للنشر المباشر');
            }

            // تنفيذ طلب النشر باستخدام curl wrapper
            $response = $this->curl_post($url, $params);
            $result = json_decode($response, true);

            if (!empty($result['id'])) {
                return ['success' => true, 'post_id' => $result['id']];
            }

            $error = isset($result['error']) ? $result['error']['message'] : 'Unknown error';
            throw new Exception($error);

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * معالجة Instagram: رفع/نشر ريلز
     * أضفت دعم قراءة التعليقات (file_comments و auto_comments_global) وحفظ سجلات social_posts إذا أمكن.
     */
private function process_instagram_reels($uid, $ig_accounts)
{
    if (empty($_FILES['files']['name'][0])) {
        throw new Exception('اختر ملفات فيديو للريلز');
    }

    $results = [];
    $file_comments_all = $this->input->post('file_comments') ?: [];
    $global_comments = $this->input->post('auto_comments_global') ?: [];
    $file_descriptions = $this->input->post('file_descriptions') ?: [];
    $file_schedule_times = $this->input->post('file_schedule_times') ?: [];

    foreach ($_FILES['files']['name'] as $index => $filename) {
        if (empty($filename)) continue;

        $file_data = [
            'name' => $filename,
            'type' => $_FILES['files']['type'][$index] ?? '',
            'tmp_name' => $_FILES['files']['tmp_name'][$index] ?? '',
            'error' => $_FILES['files']['error'][$index] ?? UPLOAD_ERR_NO_FILE,
            'size' => $_FILES['files']['size'][$index] ?? 0
        ];

        if ($file_data['error'] !== UPLOAD_ERR_OK) {
            $results[] = ['type' => 'error', 'msg' => "خطأ في رفع {$filename}"];
            continue;
        }

        $description = isset($file_descriptions[$index]) && $file_descriptions[$index] !== ''
            ? $file_descriptions[$index]
            : $this->input->post('global_description');

        $schedule_time_raw = isset($file_schedule_times[$index]) ? $file_schedule_times[$index] : null;

        $comments_for_this_file = [];
        if (!empty($file_comments_all) && isset($file_comments_all[$index]) && is_array($file_comments_all[$index])) {
            foreach ($file_comments_all[$index] as $c) {
                $c = trim((string)$c);
                if ($c !== '') $comments_for_this_file[] = $c;
            }
        }
        $global_comments_clean = [];
        if (!empty($global_comments) && is_array($global_comments)) {
            foreach ($global_comments as $c) {
                $c = trim((string)$c);
                if ($c !== '') $global_comments_clean[] = $c;
            }
        }

        foreach ($ig_accounts as $ig_user_id) {
            try {
                $account = $this->get_instagram_account($uid, $ig_user_id);
                if (!$account) {
                    $results[] = ['type' => 'error', 'msg' => "حساب Instagram غير موجود: {$ig_user_id}"];
                    continue;
                }

                $saved_file = $this->save_uploaded_file($file_data, $uid, 'instagram');
                if (!$saved_file['success']) {
                    $results[] = ['type' => 'error', 'msg' => "فشل حفظ {$filename}: {$saved_file['error']}"];
                    continue;
                }

                if (empty($schedule_time_raw)) {
                    $publish_result = $this->instagrampublisher->publishReel(
                        $ig_user_id,
                        $saved_file['full_path'],
                        $description,
                        $account['access_token']
                    );

                    $this->log_error('publishReel_response_full', json_encode([
                        'user' => $ig_user_id, 'file' => $saved_file['filename'], 'resp' => $publish_result
                    ], JSON_UNESCAPED_UNICODE));

                    $platform_post_id = null;
                    if (is_array($publish_result)) {
                        $platform_post_id = $publish_result['media_id'] ?? $publish_result['post_id'] ?? $publish_result['id'] ?? null;
                    }

                    $is_ok = is_array($publish_result) && (!empty($publish_result['ok']) || !empty($publish_result['success']));
                    $status_to_store = ($is_ok && $platform_post_id) ? 'published' : 'processing';
                    $error_msg = $is_ok ? null : (is_array($publish_result) ? json_encode($publish_result, JSON_UNESCAPED_UNICODE) : (string)$publish_result);

                    $account_col = $this->get_account_column_name();
                    $record = [
                        'user_id' => $uid,
                        'platform' => 'instagram',
                        $account_col => $ig_user_id,
                        'post_type' => 'video',
                        'content_text' => $description,
                        'media_files' => $saved_file['filename'],
                        'media_paths' => $saved_file['path'],
                        'status' => $status_to_store,
                        'platform_post_id' => $platform_post_id ?? null,
                        'last_error' => $error_msg,
                        'published_time' => ($status_to_store === 'published') ? date('Y-m-d H:i:s') : null,
                        'attempt_count' => 1,
                        'processing' => 0,
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s')
                    ];

                    if ($this->column_exists('social_posts', 'auto_comment')) {
                        $record['auto_comment'] = !empty($global_comments_clean) ? json_encode($global_comments_clean, JSON_UNESCAPED_UNICODE) : null;
                    }
                    if ($this->column_exists('social_posts', 'comments_json')) {
                        $record['comments_json'] = !empty($comments_for_this_file) ? json_encode($comments_for_this_file, JSON_UNESCAPED_UNICODE) : null;
                    }

                    try {
                        $this->db->insert('social_posts', $record);
                        $this->log_error('process_instagram_reels_insert', "Inserted social_posts for {$saved_file['filename']} status={$status_to_store}");
                    } catch (Exception $e) {
                        $this->log_error('db_insert_error_process_instagram_reels', json_encode(['msg'=>$e->getMessage(),'record'=>$record], JSON_UNESCAPED_UNICODE));
                    }

                    $results[] = ['type' => 'success', 'msg' => ($status_to_store === 'published' ? "تم نشر {$filename} على {$account['ig_username']}" : "تم رفع {$filename} ويجري معالجته على {$account['ig_username']}")];
                } else {
                    // schedule path
                    $tz_offset = (int)$this->input->post('timezone_offset');
                    $utc_time = $this->convert_to_utc($schedule_time_raw, $tz_offset);

                    $this->Instagram_reels_model->insert_record([
                        'user_id' => $uid,
                        'ig_user_id' => $ig_user_id,
                        'media_kind' => 'ig_reel',
                        'file_type' => 'video',
                        'file_name' => $saved_file['filename'],
                        'file_path' => $saved_file['path'],
                        'description' => $description,
                        'status' => 'scheduled',
                        'publish_mode' => 'scheduled',
                        'scheduled_time' => $utc_time
                    ]);

                    $account_col = $this->get_account_column_name();
                    $record = [
                        'user_id' => $uid,
                        'platform' => 'instagram',
                        $account_col => $ig_user_id,
                        'post_type' => 'video',
                        'content_text' => $description,
                        'media_files' => $saved_file['filename'],
                        'media_paths' => $saved_file['path'],
                        'status' => 'processing',
                        'scheduled_time' => $utc_time,
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s')
                    ];
                    if ($this->column_exists('social_posts','auto_comment')) $record['auto_comment'] = !empty($global_comments_clean) ? json_encode($global_comments_clean, JSON_UNESCAPED_UNICODE) : null;
                    if ($this->column_exists('social_posts','comments_json')) $record['comments_json'] = !empty($comments_for_this_file) ? json_encode($comments_for_this_file, JSON_UNESCAPED_UNICODE) : null;
                    try { $this->db->insert('social_posts', $record); } catch(Exception $e){ $this->log_error('db_insert_error_process_instagram_reels_scheduled', json_encode(['msg'=>$e->getMessage(),'record'=>$record], JSON_UNESCAPED_UNICODE)); }

                    $results[] = ['type' => 'success', 'msg' => "تم جدولة {$filename} على {$account['ig_username']}"];
                }

            } catch (Exception $e) {
                $this->log_error('process_instagram_reels_exception', $e->getMessage());
                $results[] = ['type' => 'error', 'msg' => "خطأ في معالجة {$filename}: " . $e->getMessage()];
            }
        }
    }

    $this->handle_responses($results);
}

    /**
     * معالجة قصص Instagram
     */
    private function process_instagram_stories($uid, $ig_accounts, $content_type)
    {
        if (empty($_FILES['files']['name'][0])) {
            throw new Exception('اختر ملفات للقصص');
        }

        $results = [];

        foreach ($_FILES['files']['name'] as $index => $filename) {
            if (empty($filename)) continue;

            $file_data = [
                'name' => $filename,
                'type' => isset($_FILES['files']['type'][$index]) ? $_FILES['files']['type'][$index] : '',
                'tmp_name' => isset($_FILES['files']['tmp_name'][$index]) ? $_FILES['files']['tmp_name'][$index] : '',
                'error' => isset($_FILES['files']['error'][$index]) ? $_FILES['files']['error'][$index] : UPLOAD_ERR_NO_FILE,
                'size' => isset($_FILES['files']['size'][$index]) ? $_FILES['files']['size'][$index] : 0
            ];

            if ($file_data['error'] !== UPLOAD_ERR_OK) {
                $results[] = ['type' => 'error', 'msg' => "خطأ في رفع {$filename}"];
                continue;
            }

            foreach ($ig_accounts as $ig_user_id) {
                try {
                    $account = $this->get_instagram_account($uid, $ig_user_id);
                    if (!$account) {
                        $results[] = ['type' => 'error', 'msg' => "حساب Instagram غير موجود: {$ig_user_id}"];
                        continue;
                    }

                    // حفظ الملف بأمان
                    $saved_file = $this->save_uploaded_file($file_data, $uid, 'instagram');
                    if (!$saved_file['success']) {
                        $results[] = ['type' => 'error', 'msg' => "فشل حفظ {$filename}: {$saved_file['error']}"];
                        continue;
                    }

                    // تحديد نوع الملف للقصة
                    $file_type = ($content_type === 'story_photo') ? 'image' : 'video';

                    // نشر القصة عبر library (تحقق من أن النتيجة ليست null)
                    $publish_result = $this->instagrampublisher->publishStory(
                        $ig_user_id,
                        $saved_file['full_path'],
                        $file_type,
                        isset($account['access_token']) ? $account['access_token'] : ''
                    );

                    $ok = (is_array($publish_result) && isset($publish_result['ok']) && $publish_result['ok']) ? true : false;
                    if ($ok) {
                        $results[] = ['type' => 'success', 'msg' => "تم نشر قصة {$filename} على " . (isset($account['ig_username']) ? $account['ig_username'] : $ig_user_id)];
                    } else {
                        $err = (is_array($publish_result) && isset($publish_result['error'])) ? $publish_result['error'] : 'خطأ';
                        $results[] = ['type' => 'error', 'msg' => "فشل نشر قصة {$filename} على " . (isset($account['ig_username']) ? $account['ig_username'] : $ig_user_id) . ": {$err}"];
                    }

                } catch (Exception $e) {
                    $results[] = ['type' => 'error', 'msg' => "خطأ في معالجة {$filename}: " . $e->getMessage()];
                    $this->log_error('process_instagram_stories', $e->getMessage());
                }
            }
        }

        $this->handle_responses($results);
    }

    /**
     * معالجة منشورات Instagram (محدودة لأن API لا يدعم ذلك دائماً)
     */
    private function process_instagram_posts($uid, $ig_accounts, $content_type)
    {
        $results = [
            ['type' => 'error', 'msg' => 'منشورات Instagram العادية غير مدعومة حالياً عبر API']
        ];

        $this->handle_responses($results);
    }

    /**
     * الحصول على حساب Instagram من جدول instagram_accounts
     */
    private function get_instagram_account($user_id, $ig_user_id)
    {
        return $this->db->select('*')
                       ->from('instagram_accounts')
                       ->where('user_id', $user_id)
                       ->where('ig_user_id', $ig_user_id)
                       ->where('ig_linked', 1)
                       ->where('status', 'active')
                       ->get()->row_array();
    }

    /**
     * حفظ ملف مرفوع مع تحقق MIME وتهيئة المجلد
     */
    private function save_uploaded_file($file_data, $user_id, $platform = 'social')
    {
        try {
            if (!isset($file_data['error']) || $file_data['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('خطأ في رفع الملف');
            }

            $upload_dir = FCPATH . "uploads/{$platform}/";
            $this->ensure_upload_dir($upload_dir);

            $ext = strtolower(pathinfo($file_data['name'], PATHINFO_EXTENSION));

            // تحقق MIME عبر finfo
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file_data['tmp_name']);
            finfo_close($finfo);

            if (strpos($mime, 'image/') === 0) {
                // allowed image extensions
                $allowed_image_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                if (!in_array($ext, $allowed_image_ext)) {
                    throw new Exception('امتداد الصورة غير مدعوم');
                }
            } elseif (strpos($mime, 'video/') === 0) {
                // allowed video extensions
                $allowed_video_ext = ['mp4', 'mov', 'm4v', 'avi', 'webm'];
                if (!in_array($ext, $allowed_video_ext)) {
                    throw new Exception('امتداد الفيديو غير مدعوم');
                }
            } else {
                throw new Exception('نوع الملف غير مدعوم');
            }

            $filename = date('Ymd_His') . '_' . $user_id . '_' . uniqid() . '.' . $ext;
            $full_path = $upload_dir . $filename;

            if (!move_uploaded_file($file_data['tmp_name'], $full_path)) {
                // حاول rename كحل احتياطي إن كان الملف قد تم تخزينه بالفعل (مثلاً بواسطة process_uploaded_files)
                if (!@rename($file_data['tmp_name'], $full_path)) {
                    throw new Exception('فشل في نقل الملف');
                }
            }

            // إعادة القيم مع المسارات النسبية والمطلقة
            return [
                'success' => true,
                'filename' => $filename,
                'path' => "uploads/{$platform}/" . $filename,
                'full_path' => $full_path,
                'mime' => $mime,
                'size' => filesize($full_path)
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Ensure upload directory exists and is writable
     */
    private function ensure_upload_dir($dir)
    {
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true)) {
                throw new Exception('فشل في إنشاء مجلد الرفع: ' . $dir);
            }
        }
        if (!is_writable($dir)) {
            @chmod($dir, 0755);
            if (!is_writable($dir)) {
                throw new Exception('مجلد الرفع غير قابل للكتابة: ' . $dir);
            }
        }
    }

    /**
     * تحويل الوقت المحلي إلى UTC
     */
    private function convert_to_utc($local_time, $offset_minutes)
    {
        if (!$local_time) return null;

        $timestamp = strtotime($local_time);
        if ($timestamp === false) return null;

        return gmdate('Y-m-d H:i:s', $timestamp + ($offset_minutes * 60));
    }

    /**
     * معالجة الردود (AJAX أو إعادة توجيه)
     */
    private function handle_responses($responses)
    {
        $success = [];
        $errors = [];

        foreach ($responses as $r) {
            if (!empty($r['type']) && $r['type'] === 'success') {
                $success[] = $r['msg'];
            } else {
                $errors[] = $r['msg'] ?? ($r['error'] ?? 'خطأ');
            }
        }

        if ($this->input->is_ajax_request()) {
            return $this->send_json([
                'success' => !empty($success),
                'messages' => array_merge(
                    array_map(function($s){ return ['type' => 'success', 'msg' => $s]; }, $success),
                    array_map(function($e){ return ['type' => 'error', 'msg' => $e]; }, $errors)
                ),
                'redirect_url' => site_url('social_publisher/listing')
            ]);
        }

        if ($success) $this->session->set_flashdata('msg_success', implode('<br>', $success));
        if ($errors) $this->session->set_flashdata('msg', implode('<br>', $errors));

        redirect('social_publisher/listing');
    }

    /**
     * Listing of posts with filters and pagination
     */
    public function listing()
    {
        $this->require_login();
        $uid = (int)$this->session->userdata('user_id');

        $this->create_social_posts_table();

        $filters = [
            'platform' => $this->input->get('platform'),
            'content_type' => $this->input->get('content_type'),
            'status' => $this->input->get('status'),
            'q' => $this->input->get('q'),
            'date_from' => $this->input->get('date_from'),
            'date_to' => $this->input->get('date_to')
        ];
        $filters = array_filter($filters, function($v){ return $v !== '' && $v !== null; });

        $this->db->from('social_posts');
        $this->db->where('user_id', $uid);

        foreach ($filters as $key => $value) {
            if ($key === 'q') {
                $this->db->group_start();
                $this->db->like('title', $value);
                $this->db->or_like('description', $value);
                $this->db->group_end();
            } elseif ($key === 'date_from') {
                $this->db->where('created_at >=', $value . ' 00:00:00');
            } elseif ($key === 'date_to') {
                $this->db->where('created_at <=', $value . ' 23:59:59');
            } else {
                $this->db->where($key, $value);
            }
        }

        $page = max(1, (int)$this->input->get('page'));
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $total = $this->db->count_all_results('', false);
        $posts = $this->db->order_by('created_at', 'DESC')
                         ->limit($limit, $offset)
                         ->get()->result_array();

        $stats = $this->get_posts_stats_safe($uid);
        $facebook_pages = $this->Facebook_pages_model->get_pages_by_user($uid);
        $instagram_accounts = $this->get_instagram_accounts_safe($uid);

        $data = [
            'posts' => $posts,
            'total' => $total,
            'page' => $page,
            'pages' => ceil($total / $limit),
            'limit' => $limit,
            'filters' => $filters,
            'stats' => $stats,
            'facebook_pages' => $facebook_pages,
            'instagram_accounts' => $instagram_accounts
        ];

        $this->load->view('social_publisher_listing', $data);
    }

    /**
     * Dashboard view
     */
    public function dashboard()
    {
        $this->require_login();
        $uid = (int)$this->session->userdata('user_id');

        $this->create_social_posts_table();
        $stats = $this->get_posts_stats_safe($uid);

        $recent_posts = [];
        try {
            $recent_posts = $this->db->select('*')
                                   ->from('social_posts')
                                   ->where('user_id', $uid)
                                   ->order_by('created_at', 'DESC')
                                   ->limit(10)
                                   ->get()->result_array();
        } catch (Exception $e) {
            $this->log_error('dashboard_recent', $e->getMessage());
        }

        $upcoming_posts = [];
        try {
            if ($this->column_exists('social_posts', 'scheduled_time') || $this->column_exists('social_posts', 'scheduled_at')) {
                $col = $this->column_exists('social_posts', 'scheduled_time') ? 'scheduled_time' : 'scheduled_at';
                $upcoming_posts = $this->db->select('*')
                                         ->from('social_posts')
                                         ->where('user_id', $uid)
                                         ->where('status', 'scheduled')
                                         ->where($col . ' >', date('Y-m-d H:i:s'))
                                         ->order_by($col, 'ASC')
                                         ->limit(5)
                                         ->get()->result_array();
            }
        } catch (Exception $e) {
            $this->log_error('dashboard_upcoming', $e->getMessage());
        }

        $data = [
            'stats' => $stats,
            'recent_posts' => $recent_posts,
            'upcoming_posts' => $upcoming_posts
        ];

        $this->load->view('social_publisher_dashboard', $data);
    }

    /**
     * فحص وجود عمود في جدول (آمن)
     */
    private function column_exists($table, $column)
    {
        try {
            $columns = $this->db->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'")->result_array();
            return !empty($columns);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * إحصائيات آمنة
     */
    private function get_posts_stats_safe($user_id)
    {
        $stats = [
            'total_posts' => 0,
            'published_today' => 0,
            'scheduled_posts' => 0,
            'failed_posts' => 0,
            'published' => 0,
            'pending' => 0,
            'total' => 0,
            'scheduled' => 0,
            'failed' => 0
        ];

        try {
            if (!$this->db->table_exists('social_posts')) {
                return $stats;
            }

            $stats['total_posts'] = $this->db->where('user_id', $user_id)
                                           ->count_all_results('social_posts');
            $stats['total'] = $stats['total_posts'];

            $stats['published'] = $this->db->where('user_id', $user_id)
                                          ->where('status', 'published')
                                          ->count_all_results('social_posts');

            $stats['pending'] = $this->db->where('user_id', $user_id)
                                        ->where('status', 'pending')
                                        ->count_all_results('social_posts');

            $stats['failed_posts'] = $this->db->where('user_id', $user_id)
                                             ->where('status', 'failed')
                                             ->count_all_results('social_posts');
            $stats['failed'] = $stats['failed_posts'];

            if ($this->column_exists('social_posts', 'scheduled_time') || $this->column_exists('social_posts', 'scheduled_at')) {
                $col = $this->column_exists('social_posts', 'scheduled_time') ? 'scheduled_time' : 'scheduled_at';
                $stats['scheduled_posts'] = $this->db->where('user_id', $user_id)
                                                    ->where('status', 'scheduled')
                                                    ->count_all_results('social_posts');
                $stats['scheduled'] = $stats['scheduled_posts'];

                if ($this->column_exists('social_posts', 'published_time')) {
                    $stats['published_today'] = $this->db->where('user_id', $user_id)
                                                        ->where('status', 'published')
                                                        ->where('DATE(published_time)', date('Y-m-d'))
                                                        ->count_all_results('social_posts');
                }
            }

        } catch (Exception $e) {
            $this->log_error('get_posts_stats_safe', $e->getMessage());
        }

        return $stats;
    }

    /**
     * CRON للنشر المجدول (بدون flock) - يعتمد على processing flag لمنع التزامن
     */
    public function cron_publish($token = null)
    {
        // التحقق من الصلاحية
        if (!$this->input->is_cli_request()) {
            if ($token !== self::CRON_TOKEN) {
                show_error('Unauthorized', 403);
                return;
            }
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        try {
            $this->create_social_posts_table();

            // أولاً: حاول معالجة أي منشورات في وضع publishing (poller)
            $this->poll_publishing_posts();

            // جلب المنشورات المستحقة (status = scheduled) والـ processing = 0
            $due_posts = [];
            if ($this->column_exists('social_posts', 'scheduled_time') || $this->column_exists('social_posts', 'scheduled_at')) {
                $col = $this->column_exists('social_posts', 'scheduled_time') ? 'scheduled_time' : 'scheduled_at';
                $due_posts = $this->db->select('*')
                                     ->from('social_posts')
                                     ->where('status', 'scheduled')
                                     ->where('processing', 0)
                                     ->where($col . ' <=', date('Y-m-d H:i:s'))
                                     ->limit(50)
                                     ->get()->result_array();
            }

            $processed = 0;
            foreach ($due_posts as $post) {
                try {
                    // محاولة الاستحواذ على الصف بشكل ذي atomic
                    $this->db->where('id', $post['id'])->where('processing', 0)->update('social_posts', ['processing' => 1, 'status' => 'publishing', 'updated_at' => date('Y-m-d H:i:s')]);
                    if ($this->db->affected_rows() == 0) {
                        // لم نتمكّن من الاستحواذ (شخص آخر قد استحوذ)
                        continue;
                    }

                    // أعِد قراءة السجل للتأكد
                    $post_locked = $this->db->where('id', $post['id'])->get('social_posts')->row_array();
                    if (!$post_locked) continue;

                    $this->process_scheduled_post($post_locked);

                    // تحرير العلامة processing
                    $this->db->where('id', $post['id'])->update('social_posts', ['processing' => 0, 'updated_at' => date('Y-m-d H:i:s')]);

                    $processed++;
                } catch (Exception $e) {
                    $this->log_error('cron_publish_item', "Post ID {$post['id']}: " . $e->getMessage());
                    // حاول تحرير العلامة لو لازالت مرفوعة
                    try { $this->db->where('id', $post['id'])->update('social_posts', ['processing' => 0, 'updated_at' => date('Y-m-d H:i:s')]); } catch(Exception $ex){}
                }
            }

            echo "Processed {$processed} scheduled posts.\n";
            $this->log_error('cron_publish', "Processed {$processed} scheduled posts.");

        } catch (Exception $e) {
            $this->log_error('cron_publish', $e->getMessage());
            echo "Cron failed: " . $e->getMessage() . "\n";
        }
    }

    /**
     * poller لمعالجة المنشورات التي في وضع publishing/publishing_processing: 
     * يتحقق من جاهزية الفيديو/المنشور عبر Graph API ثم يحدث السجل وينشر التعليقات.
     */
  private function poll_publishing_posts()
{
    try {
        $rows = $this->db->select('*')
                         ->from('social_posts')
                         ->where('status', 'processing')
                         ->where('platform_post_id IS NOT NULL', null, false)
                         ->get()->result_array();

        foreach ($rows as $post) {
            try {
                $post_db_id = $post['id'] ?? null;
                $stored_id = $post['platform_post_id'] ?? null;
                $page_id = $post['platform_account_id'] ?? ($post['platform_account'] ?? ($post['account_id'] ?? null));

                if (empty($stored_id) || empty($page_id)) {
                    $this->log_error('poll_publishing_posts_skip', json_encode([
                        'reason' => 'missing_stored_or_page',
                        'post_db_id' => $post_db_id,
                        'stored_id' => $stored_id,
                        'page_id' => $page_id
                    ], JSON_UNESCAPED_UNICODE));
                    continue;
                }

                // Get page token safely
                $page_token = null;
                if (method_exists($this, 'get_page_access_token_by_page_id')) {
                    $page_token = $this->get_page_access_token_by_page_id($page_id);
                }
                if (empty($page_token) && isset($this->Facebook_pages_model) && method_exists($this->Facebook_pages_model, 'get_page_by_fb_page_id')) {
                    $p = $this->Facebook_pages_model->get_page_by_fb_page_id($page_id);
                    if (!empty($p['page_access_token'])) $page_token = $p['page_access_token'];
                }
                if (empty($page_token)) {
                    $this->log_error('poll_publishing_posts_no_token', json_encode([
                        'post_db_id' => $post_db_id,
                        'page_id' => $page_id
                    ], JSON_UNESCAPED_UNICODE));
                    continue;
                }

                // 0) query the video node for post_id mapping
                $video_lookup_url = "https://graph.facebook.com/{$stored_id}?fields=id,post_id,permalink_url&access_token=" . urlencode($page_token);
                $video_raw = $this->curl_get($video_lookup_url);
                if ($video_raw === false) {
                    $this->log_error('poll_publishing_posts_video_lookup_error', json_encode([
                        'post_db_id' => $post_db_id,
                        'video_id' => $stored_id,
                        'lookup_url' => $video_lookup_url
                    ], JSON_UNESCAPED_UNICODE));
                    // تخطى هذه الدورة — قد يكون خطأ مؤقت أو مشكلة توكن
                    continue;
                }
                $video_resp = json_decode($video_raw, true);
                $this->log_error('poll_publishing_posts_video_lookup', json_encode([
                    'post_db_id' => $post_db_id,
                    'video_id' => $stored_id,
                    'resp' => $video_resp
                ], JSON_UNESCAPED_UNICODE));

                if (is_array($video_resp) && empty($video_resp['error']) && !empty($video_resp['post_id'])) {
                    $feed_post_id = $video_resp['post_id'];
                    $this->db->where('id', $post_db_id)->update('social_posts', [
                        'status' => 'published',
                        'platform_post_id' => $feed_post_id,
                        'published_time' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
                    $saved = $this->db->where('id', $post_db_id)->get('social_posts')->row_array();
                    $this->publish_comments_for_facebook_post($saved, $page_token);
                    $this->log_error('poll_publishing_posts_updated', json_encode([
                        'post_db_id' => $post_db_id,
                        'found_feed_id' => $feed_post_id,
                        'method' => 'video.post_id'
                    ], JSON_UNESCAPED_UNICODE));
                    continue;
                }

                // 1) try stored_id as feed id directly
                $lookup_url = "https://graph.facebook.com/{$stored_id}?fields=id,permalink_url,is_published&access_token=" . urlencode($page_token);
                $resp_raw = $this->curl_get($lookup_url);
                if ($resp_raw === false) {
                    $this->log_error('poll_publishing_posts_lookup_error', json_encode([
                        'post_db_id' => $post_db_id,
                        'stored_id' => $stored_id,
                        'lookup_url' => $lookup_url
                    ], JSON_UNESCAPED_UNICODE));
                    continue;
                }
                $resp = json_decode($resp_raw, true);
                $this->log_error('poll_publishing_posts_resp', json_encode([
                    'post_db_id' => $post_db_id,
                    'stored_id' => $stored_id,
                    'resp' => $resp
                ], JSON_UNESCAPED_UNICODE));

                if (is_array($resp) && empty($resp['error']) && (!empty($resp['permalink_url']) || (!empty($resp['is_published']) && $resp['is_published'] == true) || !empty($resp['id']))) {
                    $this->db->where('id', $post_db_id)->update('social_posts', [
                        'status' => 'published',
                        'published_time' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
                    $saved = $this->db->where('id', $post_db_id)->get('social_posts')->row_array();
                    $this->publish_comments_for_facebook_post($saved, $page_token);
                    $this->log_error('poll_publishing_posts_updated', json_encode([
                        'post_db_id' => $post_db_id,
                        'method' => 'direct_lookup',
                        'stored_id' => $stored_id
                    ], JSON_UNESCAPED_UNICODE));
                    continue;
                }

                // 2) fallback: search recent page feed for attachments pointing to our video id
                $feed_url = "https://graph.facebook.com/{$page_id}/posts?fields=id,created_time,attachments{media,target,type,subattachments}&limit=50&access_token=" . urlencode($page_token);
                $feed_raw = $this->curl_get($feed_url);
                if ($feed_raw === false) {
                    $this->log_error('poll_publishing_posts_feed_error', json_encode([
                        'post_db_id' => $post_db_id,
                        'video_id' => $stored_id,
                        'feed_url' => $feed_url
                    ], JSON_UNESCAPED_UNICODE));
                    continue;
                }
                $feed_resp = json_decode($feed_raw, true);
                $this->log_error('poll_publishing_posts_feed_resp', json_encode([
                    'post_db_id' => $post_db_id,
                    'video_id' => $stored_id,
                    'feed_resp' => $feed_resp
                ], JSON_UNESCAPED_UNICODE));

                $found_feed_id = null;
                if (is_array($feed_resp) && !empty($feed_resp['data'])) {
                    foreach ($feed_resp['data'] as $fp) {
                        if (empty($fp['attachments'])) continue;
                        $attachments = $fp['attachments']['data'] ?? [];
                        foreach ($attachments as $att) {
                            if (!empty($att['target']['id']) && (string)$att['target']['id'] === (string)$stored_id) {
                                $found_feed_id = $fp['id']; break 3;
                            }
                            if (!empty($att['media']['target']['id']) && (string)$att['media']['target']['id'] === (string)$stored_id) {
                                $found_feed_id = $fp['id']; break 3;
                            }
                            if (!empty($att['subattachments']['data']) && is_array($att['subattachments']['data'])) {
                                foreach ($att['subattachments']['data'] as $sub) {
                                    if (!empty($sub['target']['id']) && (string)$sub['target']['id'] === (string)$stored_id) {
                                        $found_feed_id = $fp['id']; break 4;
                                    }
                                }
                            }
                            if (!empty($att['target']) && is_array($att['target'])) {
                                $flat = json_encode($att['target']);
                                if (strpos($flat, (string)$stored_id) !== false) {
                                    $found_feed_id = $fp['id']; break 3;
                                }
                            }
                        }
                    }
                }

                if ($found_feed_id) {
                    $this->db->where('id', $post_db_id)->update('social_posts', [
                        'status' => 'published',
                        'platform_post_id' => $found_feed_id,
                        'published_time' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
                    $saved = $this->db->where('id', $post_db_id)->get('social_posts')->row_array();
                    $this->publish_comments_for_facebook_post($saved, $page_token);
                    $this->log_error('poll_publishing_posts_updated', json_encode([
                        'post_db_id' => $post_db_id,
                        'found_feed_id' => $found_feed_id,
                        'method' => 'search_feed'
                    ], JSON_UNESCAPED_UNICODE));
                    continue;
                }

                // still processing
                $this->log_error('poll_publishing_posts_pending', json_encode([
                    'post_db_id' => $post_db_id,
                    'stored_id' => $stored_id,
                    'reason' => 'not_found_or_processing'
                ], JSON_UNESCAPED_UNICODE));

            } catch (Exception $e_inner) {
                $this->log_error('poll_publishing_posts_item', json_encode([
                    'post_db_id' => $post['id'] ?? null,
                    'error' => $e_inner->getMessage()
                ], JSON_UNESCAPED_UNICODE));
            }
        }

    } catch (Exception $e) {
        $this->log_error('poll_publishing_posts', json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE));
    }
}
    /**
     * ننشر التعليقات المحفوظة على منشور فيسبوك (post_id)
     * يجمع التعليقات من auto_comment و comments_json ويؤدي POST /{post_id}/comments
     */
   private function publish_comments_for_facebook_post($post, $page_access_token = null)
{
    try {
        if (empty($post)) return;

        $post_db_id = $post['id'] ?? null;
        $platform_post_id = $post['platform_post_id'] ?? ($post['post_id'] ?? null);
        $page_id = $post['platform_account_id'] ?? ($post['platform_account'] ?? ($post['account_id'] ?? null));

        // جلب توكن الصفحة إن لم يُمرر
        if (empty($page_access_token)) {
            if (method_exists($this, 'get_page_access_token_by_page_id')) {
                $page_access_token = $this->get_page_access_token_by_page_id($page_id);
            }
            if (empty($page_access_token) && isset($this->Facebook_pages_model) && method_exists($this->Facebook_pages_model, 'get_page_by_fb_page_id')) {
                $p = $this->Facebook_pages_model->get_page_by_fb_page_id($page_id);
                if (!empty($p['page_access_token'])) $page_access_token = $p['page_access_token'];
            }
            if (empty($page_access_token)) {
                $this->log_error('publish_comments_for_facebook_post_no_token', json_encode([
                    'post_db_id' => $post_db_id,
                    'page_id' => $page_id
                ], JSON_UNESCAPED_UNICODE));
                return;
            }
        }

        // جمع التعليقات من الحقول الممكنة
        $comments = [];
        if (!empty($post['auto_comment'])) {
            $c = $this->safe_json_decode($post['auto_comment']);
            if (is_array($c)) $comments = array_merge($comments, $c);
        }
        if (!empty($post['comments_json'])) {
            $c2 = $this->safe_json_decode($post['comments_json']);
            if (is_array($c2)) $comments = array_merge($comments, $c2);
        }
        if (!empty($post['comments'])) {
            $c3 = $this->safe_json_decode($post['comments']);
            if (is_array($c3)) $comments = array_merge($comments, $c3);
        }
        if (!empty($post['auto_comments'])) {
            $c4 = $this->safe_json_decode($post['auto_comments']);
            if (is_array($c4)) $comments = array_merge($comments, $c4);
        }

        $clean_comments = [];
        foreach ($comments as $c) {
            if (is_array($c) && isset($c['text'])) $txt = trim((string)$c['text']);
            else $txt = trim((string)$c);
            if ($txt !== '') $clean_comments[] = $txt;
        }
        if (empty($clean_comments)) return;

        // helper لنشر تعليق عبر Graph API (سيرجع مصفوفة الاستجابة أو null)
        $post_comment = function($target_id, $message) use ($page_access_token, $post_db_id) {
            $url = "https://graph.facebook.com/v17.0/{$target_id}/comments";
            $payload = ['message' => $message, 'access_token' => $page_access_token];
            $resp_raw = $this->curl_post($url, $payload);
            $resp = $resp_raw ? json_decode($resp_raw, true) : null;
            $this->log_error('publish_comment_resp_full', json_encode([
                'post_db_id' => $post_db_id,
                'target' => $target_id,
                'message_preview' => mb_substr($message, 0, 200),
                'resp' => $resp
            ], JSON_UNESCAPED_UNICODE));
            return $resp;
        };

        $published_to = null;

        // 1) إذا platform_post_id يبدو كـ feed id: جرّب مباشرة
        if (!empty($platform_post_id) && (strpos((string)$platform_post_id, '_') !== false || preg_match('/^[0-9]{8,}$/', (string)$platform_post_id))) {
            foreach ($clean_comments as $msg) {
                $resp = $post_comment($platform_post_id, $msg);
                if (is_array($resp) && !empty($resp['id'])) {
                    // سجّل التعليق في social_post_comments
                    try {
                        $comment_insert = [
                            'post_id' => $post_db_id,
                            'platform_post_id' => $platform_post_id,
                            'comment_id' => $resp['id'],
                            'comment_text' => $msg,
                            'created_at' => date('Y-m-d H:i:s'),
                            'updated_at' => date('Y-m-d H:i:s')
                        ];
                        $this->db->insert('social_post_comments', $comment_insert);
                    } catch (Exception $e) {
                        $this->log_error('publish_comments_insert_comment_failed', json_encode([
                            'post_db_id' => $post_db_id, 'error' => $e->getMessage()
                        ], JSON_UNESCAPED_UNICODE));
                    }

                    $published_to = $platform_post_id;
                } else {
                    $this->log_error('publish_comment_failed_on_feed', json_encode([
                        'post_db_id' => $post_db_id,
                        'target' => $platform_post_id,
                        'resp' => $resp
                    ], JSON_UNESCAPED_UNICODE));
                }
            }
            if ($published_to) {
                // إذا تم النشر فعلياً حدّث حالة السجل إلى published
                try {
                    $up = [];
                    if ($this->db->field_exists('status', 'social_posts')) $up['status'] = 'published';
                    if ($this->db->field_exists('published_time', 'social_posts')) $up['published_time'] = date('Y-m-d H:i:s');
                    if (!empty($up)) $this->db->where('id', $post_db_id)->update('social_posts', $up);
                } catch (Exception $e) {
                    $this->log_error('publish_comments_mark_post_published_failed', $e->getMessage());
                }
                return;
            }
        }

        // 2) لو لم ينجح، حاول استعلام video node -> post_id ثم انشر
        if (!empty($platform_post_id)) {
            $video_lookup_url = "https://graph.facebook.com/v17.0/{$platform_post_id}?fields=id,post_id,permalink_url&access_token=" . urlencode($page_access_token);
            $video_raw = $this->curl_get($video_lookup_url);
            if ($video_raw !== false) {
                $video_resp = json_decode($video_raw, true);
                $this->log_error('publish_comments_video_lookup', json_encode([
                    'post_db_id' => $post_db_id,
                    'checked_id' => $platform_post_id,
                    'resp' => $video_resp
                ], JSON_UNESCAPED_UNICODE));

                if (is_array($video_resp) && empty($video_resp['error']) && !empty($video_resp['post_id'])) {
                    $resolved_feed_id = $video_resp['post_id'];
                    foreach ($clean_comments as $msg) {
                        $resp = $post_comment($resolved_feed_id, $msg);
                        if (is_array($resp) && !empty($resp['id'])) {
                            try {
                                $this->db->insert('social_post_comments', [
                                    'post_id' => $post_db_id,
                                    'platform_post_id' => $resolved_feed_id,
                                    'comment_id' => $resp['id'],
                                    'comment_text' => $msg,
                                    'created_at' => date('Y-m-d H:i:s'),
                                    'updated_at' => date('Y-m-d H:i:s')
                                ]);
                            } catch (Exception $e) {
                                $this->log_error('publish_comments_insert_comment_failed2', $e->getMessage());
                            }
                            $published_to = $resolved_feed_id;
                        } else {
                            $this->log_error('publish_comment_failed_on_resolved_feed', json_encode([
                                'post_db_id' => $post_db_id,
                                'target' => $resolved_feed_id,
                                'resp' => $resp
                            ], JSON_UNESCAPED_UNICODE));
                        }
                    }
                    if ($published_to) {
                        try {
                            $up = [];
                            if ($this->db->field_exists('status', 'social_posts')) $up['status'] = 'published';
                            if ($this->db->field_exists('platform_post_id', 'social_posts')) $up['platform_post_id'] = $resolved_feed_id;
                            if ($this->db->field_exists('published_time', 'social_posts')) $up['published_time'] = date('Y-m-d H:i:s');
                            if (!empty($up)) $this->db->where('id', $post_db_id)->update('social_posts', $up);
                        } catch (Exception $e) {
                            $this->log_error('publish_comments_update_postid_failed', $e->getMessage());
                        }
                        return;
                    }
                }
            } else {
                $this->log_error('publish_comments_video_lookup_failed_curl', json_encode([
                    'post_db_id' => $post_db_id,
                    'checked_id' => $platform_post_id
                ], JSON_UNESCAPED_UNICODE));
            }
        }

        // 3) أخيراً: حاول النشر على node نفسه (video node)
        foreach ($clean_comments as $msg) {
            $resp = $post_comment($platform_post_id, $msg);
            if (is_array($resp) && !empty($resp['id'])) {
                try {
                    $this->db->insert('social_post_comments', [
                        'post_id' => $post_db_id,
                        'platform_post_id' => $platform_post_id,
                        'comment_id' => $resp['id'],
                        'comment_text' => $msg,
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
                } catch (Exception $e) {
                    $this->log_error('publish_comments_insert_comment_failed3', $e->getMessage());
                }
                $published_to = $platform_post_id;
            } else {
                $this->log_error('publish_comment_failed_final', json_encode([
                    'post_db_id' => $post_db_id,
                    'target' => $platform_post_id,
                    'resp' => $resp
                ], JSON_UNESCAPED_UNICODE));
            }
        }
        if ($published_to) {
            try {
                $up = [];
                if ($this->db->field_exists('status', 'social_posts')) $up['status'] = 'published';
                if ($this->db->field_exists('published_time', 'social_posts')) $up['published_time'] = date('Y-m-d H:i:s');
                if (!empty($up)) $this->db->where('id', $post_db_id)->update('social_posts', $up);
            } catch (Exception $e) {
                $this->log_error('publish_comments_mark_post_published_failed2', $e->getMessage());
            }
        }

    } catch (Exception $e) {
        $this->log_error('publish_comments_for_facebook_post_exception', json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE));
    }
}
private function publish_comments_for_instagram_post($post)
{
    try {
        if (empty($post['platform_post_id'])) return;
        $media_id = $post['platform_post_id'];

        // get IG access token
        $account_id = $post['platform_account_id'] ?? null;
        if (!$account_id) return;
        $ig = $this->get_instagram_account($post['user_id'], $account_id);
        $access_token = $ig['access_token'] ?? null;
        if (!$access_token) {
            $this->log_error('publish_comments_for_instagram_post', "No IG access token for account {$account_id}");
            return;
        }

        $comments = [];
        if (!empty($post['auto_comment'])) {
            $g = $this->safe_json_decode($post['auto_comment']);
            if (is_array($g)) $comments = array_merge($comments, $g);
        }
        if (!empty($post['comments_json'])) {
            $f = $this->safe_json_decode($post['comments_json']);
            if (is_array($f)) $comments = array_merge($comments, $f);
        }

        foreach ($comments as $c) {
            $message = trim((string)$c);
            if ($message === '') continue;
            $url = "https://graph.facebook.com/{$media_id}/comments";
            $post_data = ['message' => $message, 'access_token' => $access_token];
            $resp = $this->curl_post($url, $post_data);
            $this->log_error('publish_instagram_comment_resp', json_encode(['media' => $media_id, 'comment' => $message, 'resp' => $resp], JSON_UNESCAPED_UNICODE));
        }
    } catch (Exception $e) {
        $this->log_error('publish_comments_for_instagram_post', $e->getMessage());
    }
}
    /**
     * جلب page access token بسلام؛ يحاول طرق متعددة (موديل أو جدول facebook_rx_fb_page_info)
     */
private function get_page_access_token_by_page_id($page_id)
{
    try {
        if (empty($page_id)) return null;

        // 1) Try model helper if available (many projects expose this)
        if (isset($this->Facebook_pages_model) && method_exists($this->Facebook_pages_model, 'get_page_by_fb_page_id')) {
            try {
                $p = $this->Facebook_pages_model->get_page_by_fb_page_id($page_id);
                if (!empty($p) && !empty($p['page_access_token'])) {
                    return $p['page_access_token'];
                }
            } catch (Exception $e) {
                // model helper failed — log and continue to DB checks
                $this->log_error('get_page_access_token_model_err', $e->getMessage());
            }
        }

        // Helper to search a table for token given a set of candidate id-columns and token-column
        $search_table_for_token = function($table, $candidate_id_cols, $token_col_candidate = null) use ($page_id) {
            try {
                $cols = $this->db->query("SHOW COLUMNS FROM `{$table}`")->result_array();
                if (empty($cols)) return null;
                $available = array_column($cols, 'Field');

                // find token column if not supplied
                $token_col = $token_col_candidate && in_array($token_col_candidate, $available) ? $token_col_candidate : null;
                if (!$token_col) {
                    $possible = array_filter($available, function($c){
                        return stripos($c, 'access_token') !== false || stripos($c, 'page_access_token') !== false || stripos($c, 'token') !== false;
                    });
                    if (!empty($possible)) $token_col = array_values($possible)[0];
                }
                if (!$token_col) return null;

                // Filter candidate id columns to ones actually present
                $id_cols = array_values(array_filter($candidate_id_cols, function($c) use ($available) {
                    return in_array($c, $available);
                }));
                if (empty($id_cols)) return null;

                $this->db->select($token_col . ' as page_access_token')->from($table);
                $this->db->group_start();
                foreach ($id_cols as $i => $col) {
                    if ($i === 0) $this->db->where($col, $page_id);
                    else $this->db->or_where($col, $page_id);
                }
                $this->db->group_end();
                $row = $this->db->limit(1)->get()->row_array();
                if (!empty($row['page_access_token'])) return $row['page_access_token'];
            } catch (Exception $ex) {
                // ignore per-table error; caller will log if needed
            }
            return null;
        };

        // 2) Primary: facebook_rx_fb_page_info (common in many FB-integrations)
        if ($this->db->table_exists('facebook_rx_fb_page_info')) {
            $candidate_cols = ['page_id', 'fb_page_id', 'id'];
            $token = $search_table_for_token('facebook_rx_fb_page_info', $candidate_cols, 'page_access_token');
            if (!empty($token)) return $token;
        }

        // 3) Fallback: legacy facebook_pages table
        if ($this->db->table_exists('facebook_pages')) {
            $candidate_cols2 = ['fb_page_id', 'page_id', 'id'];
            $token2 = $search_table_for_token('facebook_pages', $candidate_cols2, 'page_access_token');
            if (!empty($token2)) return $token2;
        }

        // 4) Additional fallback: sometimes tokens are stored in facebook_rx_fb_user_info or other tables
        $misc_tables = ['facebook_rx_fb_user_info', 'fb_page_info', 'pages']; // try a few common names
        foreach ($misc_tables as $t) {
            if ($this->db->table_exists($t)) {
                $token_misc = $search_table_for_token($t, ['page_id', 'fb_page_id', 'id']);
                if (!empty($token_misc)) return $token_misc;
            }
        }

        return null;
    } catch (Exception $e) {
        $this->log_error('get_page_access_token_by_page_id_exception', $e->getMessage());
        return null;
    }
}
    /**
     * معالجة منشور مجدول واحد
     */
    private function process_scheduled_post($post)
    {
        // برمجياً نضع العلم processing في دالة cron_publish قبل الاستدعاء
        if ($post['platform'] === 'facebook') {
            $this->process_scheduled_facebook_post($post);
        } elseif ($post['platform'] === 'instagram') {
            $this->process_scheduled_instagram_post($post);
        }
    }

    /**
     * معالجة منشور Facebook مجدول
     */
    private function process_scheduled_facebook_post($post)
{
    try {
        $pages = $this->Facebook_pages_model->get_pages_by_user($post['user_id']);
        $page = null;
        foreach ($pages as $p) {
            if ((string)($p['fb_page_id'] ?? '') === (string)($post['platform_account_id'] ?? '')) {
                $page = $p;
                break;
            }
        }

        if (!$page || empty($page['page_access_token'])) {
            throw new Exception('صفحة Facebook غير موجودة أو لا تملك صلاحيات');
        }

        // map DB post_type to publish_facebook_post content_type
        $post_type_db = $post['post_type'] ?? ($post['content_type'] ?? 'text');
        $map_rev = ['text' => 'post_text', 'image' => 'post_photo', 'video' => 'post_video', 'carousel' => 'post_photo'];
        $ctype = isset($map_rev[$post_type_db]) ? $map_rev[$post_type_db] : 'post_text';

        $result = $this->publish_facebook_post($post['platform_account_id'], $page['page_access_token'], $ctype);

        if (!empty($result['success'])) {
            $update = [
                'status' => 'published',
                'platform_post_id' => $result['post_id'] ?? null,
                'published_time' => date('Y-m-d H:i:s'),
                'last_error' => null,
                'updated_at' => date('Y-m-d H:i:s')
            ];
            $this->db->where('id', $post['id'])->update('social_posts', $update);

            // publish comments
            $this->publish_comments_for_facebook_post(array_merge($post, $update), $page['page_access_token']);
        } else {
            $this->db->where('id', $post['id'])->update('social_posts', [
                'status' => 'failed',
                'last_error' => $result['error'] ?? 'خطأ غير معروف',
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        }
    } catch (Exception $e) {
        $this->db->where('id', $post['id'])->update('social_posts', [
            'status' => 'failed',
            'last_error' => $e->getMessage(),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    }
}

    /**
     * معالجة منشور Instagram مجدول
     */
    private function process_scheduled_instagram_post($post)
    {
        try {
            $account = $this->get_instagram_account($post['user_id'], $post['account_id']);
            if (!$account || empty($account['access_token'])) {
                throw new Exception('حساب Instagram غير موجود أو لا يملك صلاحيات');
            }

            if (($post['content_type'] ?? '') === 'reel') {
                $result = $this->instagrampublisher->publishReel(
                    $post['account_id'],
                    FCPATH . ltrim($post['file_path'], '/'),
                    $post['description'] ?? '',
                    $account['access_token']
                );
            } else {
                $result = ['ok' => false, 'error' => 'نوع غير مدعوم حالياً'];
            }

            // سجل الرد
            $this->log_error('scheduled_instagram_publish_resp', json_encode(['post' => $post['id'], 'resp' => $result], JSON_UNESCAPED_UNICODE));

            if (!empty($result['ok'])) {
                $this->db->where('id', $post['id'])->update('social_posts', [
                    'status' => 'published',
                    'post_id' => $result['media_id'] ?? ($result['post_id'] ?? null),
                    'published_time' => date('Y-m-d H:i:s'),
                    'error_message' => null,
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
                // نشر التعليقات على Instagram لو مدعوم (يمكن تنفيذ لاحقًا)
            } else {
                $this->db->where('id', $post['id'])->update('social_posts', [
                    'status' => 'failed',
                    'error_message' => $result['error'] ?? 'فشل في النشر',
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            }

        } catch (Exception $e) {
            $this->db->where('id', $post['id'])->update('social_posts', [
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        }
    }

    /**
     * CRON للجدولة المتكررة (بدون flock)
     */
    public function cron_recurring($token = null)
    {
        if (!$this->input->is_cli_request()) {
            if ($token !== self::CRON_TOKEN) {
                show_error('Unauthorized', 403);
                return;
            }
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        try {
            if (!$this->db->table_exists('social_recurring_schedules')) {
                echo "Recurring schedules table not found.\n";
                return;
            }

            $due_schedules = $this->db->where('status', 'active')
                                     ->where('next_run <=', date('Y-m-d H:i:s'))
                                     ->get('social_recurring_schedules')->result_array();

            $processed = 0;
            foreach ($due_schedules as $schedule) {
                try {
                    $this->process_recurring_schedule($schedule);
                    $processed++;
                } catch (Exception $e) {
                    $this->log_error('cron_recurring_item', "Schedule ID {$schedule['id']}: " . $e->getMessage());
                }
            }

            echo "Processed {$processed} recurring schedules.\n";
            $this->log_error('cron_recurring', "Processed {$processed} recurring schedules.");

        } catch (Exception $e) {
            $this->log_error('cron_recurring', $e->getMessage());
            echo "Recurring cron failed: " . $e->getMessage() . "\n";
        }
    }

    private function process_recurring_schedule($schedule)
    {
        // تحديث next_run و last_run أو إكمال الجدولة
        $next_run = $this->calculate_next_run($schedule);

        if ($schedule['end_date'] && $next_run > $schedule['end_date'] . ' 23:59:59') {
            $this->db->where('id', $schedule['id'])
                     ->update('social_recurring_schedules', [
                         'status' => 'completed',
                         'updated_at' => date('Y-m-d H:i:s')
                     ]);
        } else {
            $this->db->where('id', $schedule['id'])
                     ->update('social_recurring_schedules', [
                         'last_run' => date('Y-m-d H:i:s'),
                         'next_run' => $next_run,
                         'updated_at' => date('Y-m-d H:i:s')
                     ]);
        }
    }

    /**
     * CRON للتنظيف (بدون flock)
     */
    public function cron_cleanup($token = null)
    {
        if (!$this->input->is_cli_request()) {
            if ($token !== self::CRON_TOKEN) {
                show_error('Unauthorized', 403);
                return;
            }
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        try {
            $cleaned = 0;

            $old_files = $this->db->select('file_path')
                                 ->from('social_posts')
                                 ->where('created_at <', date('Y-m-d H:i:s', strtotime('-30 days')))
                                 ->where('file_path IS NOT NULL', null, false)
                                 ->get()->result_array();

            foreach ($old_files as $file) {
                $full_path = FCPATH . ltrim($file['file_path'], '/');
                if (file_exists($full_path)) {
                    @unlink($full_path);
                    $cleaned++;
                }
            }

            $this->db->where('created_at <', date('Y-m-d H:i:s', strtotime('-90 days')))
                     ->delete('social_posts');

            $deleted_records = $this->db->affected_rows();

            echo "Cleaned {$cleaned} files and {$deleted_records} old records.\n";
            $this->log_error('cron_cleanup', "Cleaned {$cleaned} files and {$deleted_records} old records.");

        } catch (Exception $e) {
            $this->log_error('cron_cleanup', $e->getMessage());
            echo "Cleanup failed: " . $e->getMessage() . "\n";
        }
    }

    /**
     * Utility: perform POST requests with cURL and handle errors.
     * - $multipart = true when sending CURLFile
     */
    private function curl_post($url, $data, $is_multipart = false, $extra_headers = [])
    {
        $ch = curl_init($url);
        $headers = [];

        if ($is_multipart) {
            if (defined('CURLOPT_SAFE_UPLOAD')) {
                curl_setopt($ch, CURLOPT_SAFE_UPLOAD, true);
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        } else {
            $body = is_array($data) ? http_build_query($data) : $data;
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        if (!empty($extra_headers) && is_array($extra_headers)) {
            foreach ($extra_headers as $h) {
                if ($is_multipart && stripos($h, 'content-type:') === 0) continue;
                $headers[] = $h;
            }
        }

        if (!empty($headers)) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        curl_setopt_array($ch, [
            CURLOPT_POST => 1,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => 300
        ]);

        $res = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->log_error('curl_post', "url={$url} http_code={$http_code} curl_errno={$errno} curl_err={$err} resp_preview=" . substr((string)$res,0,1200));

        return $res === false ? '' : $res;
    } 

    /**
     * Remaining utility functions (placeholders)
     */
    private function get_posts_stats_safe_placeholder() { /* placeholder if needed */ }

} // end class Social_publisher
