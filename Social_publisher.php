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
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'SpreadSpeed/1.0 (+https://spreadspeed.com)');
    $resp = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno || $http_code >= 400) {
        $this->log_error('curl_get_error', json_encode([
            'url' => $url,
            'http_code' => $http_code,
            'errno' => $errno,
            'error' => $err,
            'response' => $resp
        ], JSON_UNESCAPED_UNICODE));
        return false;
    }

    return $resp;
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

    // prepare payload for Reel_model
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

    $_FILES['video_files'] = $_FILES['files'];

    $this->log_error('UPLOAD_REELS_CALL', json_encode([
        'user' => $uid,
        'pages' => $_POST['fb_page_ids'],
        'files' => array_values(array_filter($_FILES['video_files']['name'] ?? []))
    ], JSON_UNESCAPED_UNICODE));

    $responses = $this->Reel_model->upload_reels($uid, $pages, $_POST, $_FILES);
    $this->log_error('upload_reels_model_response', json_encode($responses, JSON_UNESCAPED_UNICODE));

    // Insert records for each page/file and try to create feed post if we have a platform_post_id (video_id)
    $selected_pages = $_POST['fb_page_ids'] ?: [];
    $file_names = array_values(array_filter($_FILES['video_files']['name'] ?? []));
    $added = 0;

    foreach ($selected_pages as $page_id) {
        foreach ($file_names as $fname) {
            // Try to extract post_id (video id) from reels_api.log (best-effort)
            $video_id = $this->extract_postid_from_reels_log($page_id, $fname, 300);
            $status = $video_id ? 'processing' : 'processing'; // keep processing until feed created or poller updates
            $account_col = $this->get_account_column_name();

            $record = [
                'user_id' => $uid,
                'platform' => 'facebook',
                $account_col => $page_id,
                'post_type' => 'video',
                'content_text' => $_POST['description'] ?? null,
                'media_files' => $fname,
                'media_paths' => 'uploads/facebook_reels/' . $fname,
                'status' => $status,
                'platform_post_id' => $video_id ?? null, // this may be video_id initially
                'last_error' => null,
                'published_time' => $video_id ? null : null,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];

            try {
                $this->db->insert('social_posts', $record);
                $insert_id = $this->db->insert_id();
                $added++;

                // If we have a video id, try to create a feed entry linking it so it appears on the Page feed
                if (!empty($video_id)) {
                    // get page access token
                    $page_token = $this->get_page_access_token_by_page_id($page_id);

                    if (!empty($page_token)) {
                        // Build feed payload: attach the uploaded media (media_fbid) to a feed post
                        $feed_payload = [
                            'access_token' => $page_token,
                            'attached_media' => json_encode([ ['media_fbid' => $video_id] ]),
                            'message' => $_POST['description'] ?? ''
                        ];

                        $feed_url = "https://graph.facebook.com/v19.0/{$page_id}/feed";
                        $feed_resp_raw = $this->curl_post($feed_url, $feed_payload);
                        $feed_resp = json_decode($feed_resp_raw, true);

                        $this->log_error('process_facebook_reels_create_feed', json_encode([
                            'page' => $page_id,
                            'video_id' => $video_id,
                            'feed_resp' => $feed_resp
                        ], JSON_UNESCAPED_UNICODE));

                        if (!empty($feed_resp['id'])) {
                            // update record to published with the feed post id
                            $this->db->where('id', $insert_id)->update([
                                'status' => 'published',
                                'platform_post_id' => $feed_resp['id'],
                                'published_time' => date('Y-m-d H:i:s'),
                                'updated_at' => date('Y-m-d H:i:s')
                            ]);

                            // publish comments if any (use page token)
                            $saved = $this->db->where('id', $insert_id)->get('social_posts')->row_array();
                            $this->publish_comments_for_facebook_post($saved, $page_token);
                        } else {
                            // Feed creation failed: keep processing => poller will check later
                            $this->log_error('process_facebook_reels_create_feed_failed', json_encode(['resp' => $feed_resp], JSON_UNESCAPED_UNICODE));
                        }
                    } else {
                        $this->log_error('process_facebook_reels_no_page_token', "No page token for {$page_id}");
                    }
                }

            } catch (Exception $e) {
                $this->log_error('db_insert_error_process_facebook_reels', json_encode(['msg'=>$e->getMessage(),'record'=>$record], JSON_UNESCAPED_UNICODE));
            }
        }
    }

    $this->log_error('process_facebook_reels_hotfix', "Inserted {$added} social_posts records for reels");

    $this->handle_responses($responses);
}
// 3) مساعدة: استخراج post_id من ملف اللوج reels_api.log (حل مؤقت)
private function extract_postid_from_reels_log($page_id, $filename, $lookback_seconds = 300)
{
    try {
        $log_path = APPPATH . 'logs/reels_api.log';
        if (!file_exists($log_path)) return null;

        $content = file_get_contents($log_path);
        if (!$content) return null;

        // نحصر الوقت: ننظر في الأسطر الأخيرة فقط لتسريع البحث
        $lines = array_slice(explode("\n", $content), -1000);

        // نمط بسيط يبحث عن post_id و page و file
        $pattern = '/post_id\"\s*:\s*\"([0-9_]+)\"|\"post_id\"\s*:\s*"([0-9_]+)"/i';
        // لكن نريد السطر الذي يحتوي على اسم الملف و page
        foreach (array_reverse($lines) as $line) {
            if (stripos($line, $page_id) !== false && stripos($line, $filename) !== false) {
                // حاول استخراج post_id JSON style
                if (preg_match('/"post_id"\s*:\s*"([^"]+)"/i', $line, $m)) {
                    return $m[1];
                }
                if (preg_match('/post_id=([0-9_]+)/i', $line, $m2)) {
                    return $m2[1];
                }
            }
        }

        // فشل إيجاد تطابق محدد: حاول استخراج أي post_id قريب من اسم الملف
        foreach (array_reverse($lines) as $line) {
            if (stripos($line, $filename) !== false) {
                if (preg_match('/"post_id"\s*:\s*"([^"]+)"/i', $line, $m)) {
                    return $m[1];
                }
            }
        }

        return null;
    } catch (Exception $e) {
        $this->log_error('extract_postid_from_reels_log', $e->getMessage());
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
                $stored_id = $post['platform_post_id'] ?? null; // غالبًا video_id
                $page_id = $post['platform_account_id'] ?? ($post['platform_account'] ?? null);

                if (empty($stored_id) || empty($page_id)) {
                    $this->log_error('poll_publishing_posts_skip', "Missing stored_id or page_id for post_db_id={$post_db_id}");
                    continue;
                }

                $page_token = $this->get_page_access_token_by_page_id($page_id);
                if (empty($page_token)) {
                    $this->log_error('poll_publishing_posts', "No page token for account_id={$page_id}, post_db_id={$post_db_id}");
                    continue;
                }

                // 0) أولاً: حاول قراءة post_id مباشرة من فيديو الFB
                $video_lookup_url = "https://graph.facebook.com/{$stored_id}?fields=id,post_id,permalink_url&access_token=" . urlencode($page_token);
                $video_raw = $this->curl_get($video_lookup_url);
                $video_resp = $video_raw ? json_decode($video_raw, true) : null;
                $this->log_error('poll_publishing_posts_video_lookup', json_encode(['post_db_id'=>$post_db_id, 'video_id'=>$stored_id, 'resp'=>$video_resp], JSON_UNESCAPED_UNICODE));

                if (is_array($video_resp) && empty($video_resp['error']) && !empty($video_resp['post_id'])) {
                    // وجدت mapping مباشر من video -> feed post
                    $feed_post_id = $video_resp['post_id'];
                    $this->db->where('id', $post_db_id)->update('social_posts', [
                        'status' => 'published',
                        'platform_post_id' => $feed_post_id, // استبدل الvideo id بالfeed post id
                        'published_time' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
                    $saved = $this->db->where('id', $post_db_id)->get('social_posts')->row_array();
                    $this->publish_comments_for_facebook_post($saved, $page_token);
                    $this->log_error('poll_publishing_posts_updated', "Marked published by video.post_id for post_db_id={$post_db_id} found_feed_id={$feed_post_id}");
                    continue;
                }

                // 1) كما في السابق: محاولة استعلام مباشر عن stored_id كـ feed id
                $lookup_url = "https://graph.facebook.com/{$stored_id}?fields=id,permalink_url,is_published&access_token=" . urlencode($page_token);
                $resp_raw = $this->curl_get($lookup_url);
                $resp = $resp_raw ? json_decode($resp_raw, true) : null;
                $this->log_error('poll_publishing_posts_resp', json_encode(['post_db_id' => $post_db_id, 'stored_id' => $stored_id, 'lookup_url' => $lookup_url, 'resp' => $resp], JSON_UNESCAPED_UNICODE));

                if (is_array($resp) && empty($resp['error']) && (!empty($resp['permalink_url']) || (!empty($resp['is_published']) && $resp['is_published'] == true))) {
                    $this->db->where('id', $post_db_id)->update('social_posts', [
                        'status' => 'published',
                        'published_time' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
                    $saved = $this->db->where('id', $post_db_id)->get('social_posts')->row_array();
                    $this->publish_comments_for_facebook_post($saved, $page_token);
                    $this->log_error('poll_publishing_posts_updated', "Marked published by direct lookup for post_db_id={$post_db_id} stored_id={$stored_id}");
                    continue;
                }

                // 2) بحث في feed الصفحة عن منشور مرتبط بالـ video id (fallback)
                $feed_url = "https://graph.facebook.com/{$page_id}/posts?fields=id,created_time,attachments{media,target,type,subattachments}&limit=50&access_token=" . urlencode($page_token);
                $feed_raw = $this->curl_get($feed_url);
                $feed_resp = $feed_raw ? json_decode($feed_raw, true) : null;
                $this->log_error('poll_publishing_posts_feed_resp', json_encode([
                    'post_db_id' => $post_db_id,
                    'video_id' => $stored_id,
                    'feed_url' => $feed_url,
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
                    $this->log_error('poll_publishing_posts_updated', "Marked published by finding feed post for post_db_id={$post_db_id} found_feed_id={$found_feed_id}");
                    continue;
                }

                // 3) لم نجد => متوقع أن الفيديو لا يزال processing
                $this->log_error('poll_publishing_posts_pending', "Still processing or not found on feed for post_db_id={$post_db_id} stored_id={$stored_id}");

            } catch (Exception $e) {
                $this->log_error('poll_publishing_posts_item', "Post DB ID {$post['id']} exception: " . $e->getMessage());
            }
        }

    } catch (Exception $e) {
        $this->log_error('poll_publishing_posts', $e->getMessage());
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
        // نجرب إيجاد post id صالح لنشر التعليقات
        $post_db_id = $post['id'] ?? null;
        $feed_post_id = $post['platform_post_id'] ?? null; // قد يكون feed post id أو video id
        $video_id = null;
        // إذا platform_post_id يبدو رقمًا طويلاً لكنه video id سابقًا، حاول التفريق لاحقًا
        // إذا كانت القيمة تشير إلى feed post (شكل 123_456 أو رقم) يمكن استخدامها مباشرة

        // إذا لم نتلقّ توكن، حاول جلبه
        if (empty($page_access_token)) {
            $page_access_token = $this->get_page_access_token_by_page_id($post['platform_account_id'] ?? null);
            if (empty($page_access_token)) {
                $this->log_error('publish_comments_for_facebook_post', "No page token for account_id=" . ($post['platform_account_id'] ?? ''));
                return;
            }
        }

        // اجمع التعليقات من auto_comment و comments_json
        $comments = [];
        if (!empty($post['auto_comment'])) {
            $g = $this->safe_json_decode($post['auto_comment']);
            if (is_array($g)) $comments = array_merge($comments, $g);
        }
        if (!empty($post['comments_json'])) {
            $f = $this->safe_json_decode($post['comments_json']);
            if (is_array($f)) $comments = array_merge($comments, $f);
        }
        if (empty($comments)) return;

        // 1) حاول أن نحلل platform_post_id: هل هو feed post id أم video id؟
        // إذا كان post id يحتوي على "_" غالبًا feed post (pageid_postid). وإن لم يكن جرب استعلام video node لوجود post_id
        if (!empty($feed_post_id) && strpos($feed_post_id, '_') === false) {
            // يمكن أن يكون video id — افحص إذا للفيديو حقل post_id
            $video_lookup_url = "https://graph.facebook.com/{$feed_post_id}?fields=post_id&access_token=" . urlencode($page_access_token);
            $video_raw = $this->curl_get($video_lookup_url);
            $video_resp = $video_raw ? json_decode($video_raw, true) : null;
            $this->log_error('publish_comments_for_facebook_post_video_lookup', json_encode(['post_db_id'=>$post_db_id,'checked_id'=>$feed_post_id,'resp'=>$video_resp], JSON_UNESCAPED_UNICODE));
            if (is_array($video_resp) && empty($video_resp['error']) && !empty($video_resp['post_id'])) {
                $video_id = $feed_post_id;
                $feed_post_id = $video_resp['post_id']; // استبداله بfeed id
            } else {
                // إن لم يعطِ post_id فافترض أن feed_post_id ربما هو بالفعل feed id أو الفيديو لا يملك post_id حتى الآن
            }
        }

        // دالة داخلية للنشر على endpoint معين مع تسجيل الرد
        $post_comment = function($target_id, $message) use ($page_access_token, $post_db_id) {
            $url = "https://graph.facebook.com/{$target_id}/comments";
            $payload = ['message' => $message, 'access_token' => $page_access_token];
            $resp_raw = $this->curl_post($url, $payload);
            $resp = json_decode($resp_raw, true);
            $this->log_error('publish_comment_resp_full', json_encode([
                'post_db_id' => $post_db_id,
                'target' => $target_id,
                'message' => $message,
                'resp' => $resp
            ], JSON_UNESCAPED_UNICODE));
            return $resp;
        };

        // 2) حاول النشر إلى feed_post_id أولاً (أن وجد)
        $used_target = null;
        if (!empty($feed_post_id)) {
            foreach ($comments as $c) {
                $msg = trim((string)$c);
                if ($msg === '') continue;
                $resp = $post_comment($feed_post_id, $msg);
                // إذا نجح (عاد id) اعتبر ناجحًا، وإلا سجّل الخطأ واستمر لمحاولة الفيديو
                if (is_array($resp) && !empty($resp['id'])) {
                    $used_target = $feed_post_id;
                } else {
                    $this->log_error('publish_comment_failed_on_feed', json_encode(['post_db_id'=>$post_db_id,'target'=>$feed_post_id,'resp'=>$resp], JSON_UNESCAPED_UNICODE));
                }
            }
            if ($used_target) return;
        }

        // 3) إن لم ينجح في feed: حاول على video node (platform_post_id إذا لم نعدله أعلاه)
        $video_target = $video_id ?? ($post['platform_post_id'] ?? null);
        if (!empty($video_target)) {
            foreach ($comments as $c) {
                $msg = trim((string)$c);
                if ($msg === '') continue;
                $resp = $post_comment($video_target, $msg);
                if (is_array($resp) && !empty($resp['id'])) {
                    $this->log_error('publish_comment_success_on_video', json_encode(['post_db_id'=>$post_db_id,'video_target'=>$video_target,'resp'=>$resp], JSON_UNESCAPED_UNICODE));
                    $used_target = $video_target;
                } else {
                    $this->log_error('publish_comment_failed_on_video', json_encode(['post_db_id'=>$post_db_id,'video_target'=>$video_target,'resp'=>$resp], JSON_UNESCAPED_UNICODE));
                }
            }
        }

        // إن لم ينجح أي منهما سيسجّل اللوج الخطأ ونحن نترك لمهام لاحقة إعادة المحاولة
    } catch (Exception $e) {
        $this->log_error('publish_comments_for_facebook_post', $e->getMessage());
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
        // 1) لو موديل Facebook_pages_model لديه دالة مُساعدة استخدمها أولاً (بعض البيئات قد تحتويها)
        if (method_exists($this->Facebook_pages_model, 'get_page_by_fb_page_id')) {
            $p = $this->Facebook_pages_model->get_page_by_fb_page_id($page_id);
            if (!empty($p['page_access_token'])) return $p['page_access_token'];
        }

        // 2) اعمل فحص للأعمدة في facebook_rx_fb_page_info
        if ($this->db->table_exists('facebook_rx_fb_page_info')) {
            $cols = $this->db->query("SHOW COLUMNS FROM `facebook_rx_fb_page_info`")->result_array();
            $available = array_column($cols, 'Field');

            // نبحث عن أي عمود ممكن يحتوي معرف الصفحة
            $candidate_cols = [];
            if (in_array('page_id', $available)) $candidate_cols[] = 'page_id';
            if (in_array('fb_page_id', $available)) $candidate_cols[] = 'fb_page_id';
            if (in_array('id', $available)) $candidate_cols[] = 'id';

            // ونتحقق إن عمود التوكن موجود
            if (in_array('page_access_token', $available)) {
                if (!empty($candidate_cols)) {
                    // استخدم Query Builder لبناء شرط OR بأعمدة مرنة
                    $this->db->select('page_access_token')->from('facebook_rx_fb_page_info');
                    $this->db->group_start();
                    foreach ($candidate_cols as $i => $col) {
                        if ($i === 0) $this->db->where($col, $page_id);
                        else $this->db->or_where($col, $page_id);
                    }
                    $this->db->group_end();
                    $row = $this->db->limit(1)->get()->row_array();
                    if (!empty($row['page_access_token'])) return $row['page_access_token'];
                }
            } else {
                // إذا لم يوجد عمود page_access_token قد يكون العمود باسم آخر، حاول إيجاد الحقول المشابهة
                $possible_token_cols = array_filter($available, function($c){ 
                    return stripos($c, 'access_token') !== false || stripos($c, 'token') !== false;
                });
                if (!empty($possible_token_cols) && !empty($candidate_cols)) {
                    $this->db->select($possible_token_cols[0] . ' as page_access_token')->from('facebook_rx_fb_page_info');
                    $this->db->group_start();
                    foreach ($candidate_cols as $i => $col) {
                        if ($i === 0) $this->db->where($col, $page_id);
                        else $this->db->or_where($col, $page_id);
                    }
                    $this->db->group_end();
                    $row2 = $this->db->limit(1)->get()->row_array();
                    if (!empty($row2['page_access_token'])) return $row2['page_access_token'];
                }
            }
        }

        // 3) إن لم نجد شيء — رجّع null
        return null;
    } catch (Exception $e) {
        $this->log_error('get_page_access_token_by_page_id', $e->getMessage());
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
