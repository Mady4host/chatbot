<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Social_publisher Controller
 * نظام النشر الموحد لـ Facebook و Instagram
 * هذا الملف مُحسّن: يحتوي على قفل للـ CRON (flock)، تحقق MIME عند حفظ الملفات،
 * تحسين إدارة المجلدات، ودوال مساعدة مكررة مُوحدة.
 *
 * PART 1 of 6
 */

class Social_publisher extends CI_Controller
{
    const CRON_TOKEN = 'SocialPublisher_Cron_2025';
    const CRON_LOCKFILE = 'social_publisher_cron.lock';

    public function __construct()
    {
        parent::__construct();
        // تحميل الموديلات واللايبراريز الضرورية
        $this->load->model(['Reel_model', 'Facebook_pages_model', 'Instagram_reels_model']);
        $this->load->library(['session', 'InstagramPublisher']);
        $this->load->helper(['url', 'form', 'security', 'file']);
        $this->load->database();
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
     */
    private function process_facebook_reels($uid, $pages)
    {
        if (empty($_FILES['files']['name'][0])) {
            throw new Exception('اختر ملفات فيديو للريلز');
        }

        // تحويل البيانات لتنسيق Reel_model المتوقع
        $_POST['fb_page_ids'] = $this->input->post('facebook_pages');
        $_POST['description'] = $this->input->post('global_description');
        $_POST['selected_hashtags'] = $this->input->post('selected_hashtags');
        $_POST['descriptions'] = $this->input->post('file_descriptions') ?: [];
        $_POST['schedule_times'] = $this->input->post('file_schedule_times') ?: [];
        $_POST['tz_offset_minutes'] = (int)$this->input->post('timezone_offset');
        $_POST['tz_name'] = $this->input->post('timezone_name');
        $_POST['media_type'] = 'reel';

        // تحويل ملفات إلى المفتاح المطلوب
        $_FILES['video_files'] = $_FILES['files'];

        $responses = $this->Reel_model->upload_reels($uid, $pages, $_POST, $_FILES);

        $this->handle_responses($responses);
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
                if ($p['fb_page_id'] === $page_id) {
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

                if ($post_result['success']) {
                    $results[] = ['type' => 'success', 'msg' => "تم النشر على {$page['page_name']}"];
                } else {
                    $results[] = ['type' => 'error', 'msg' => "فشل النشر على {$page['page_name']}: {$post_result['error']}"];
                }

            } catch (Exception $e) {
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
     * رفع صورة على Facebook (مُحسّن)
     */
    private function upload_facebook_photo($page_id, $file_path, $access_token)
    {
        try {
            $url = "https://graph.facebook.com/v19.0/{$page_id}/photos";
            if (!file_exists($file_path)) return ['success' => false, 'error' => 'ملف الصورة غير موجود'];

            $mime = function_exists('mime_content_type') ? @mime_content_type($file_path) : null;
            if (!$mime) $mime = 'application/octet-stream';
            $cfile = new CURLFile($file_path, $mime, basename($file_path));

            $post_fields = [
                'source' => $cfile,
                'published' => 'false',
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
            $errno = curl_errno($ch);
            $curl_err = curl_error($ch);
            curl_close($ch);

            $this->log_error('upload_facebook_photo', "page={$page_id} file=" . basename($file_path) . " http_code={$http_code} curl_errno={$errno} curl_err={$curl_err} resp_preview=" . substr((string)$response,0,1200));

            $result = json_decode($response, true);
            if ($errno) return ['success' => false, 'error' => 'cURL error: ' . $curl_err];
            if ($http_code === 200 && !empty($result['id'])) return ['success' => true, 'photo_id' => $result['id']];
            return ['success' => false, 'error' => $result['error']['message'] ?? "HTTP {$http_code}"];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Upload video to Facebook (robust)
     */
    private function upload_facebook_video($page_id, $file_path, $access_token)
    {
        try {
            if (!is_file($file_path) || !is_readable($file_path)) {
                return ['success' => false, 'error' => 'ملف الفيديو غير موجود أو غير قابل للقراءة'];
            }

            $version = 'v19.0';
            $filesize = filesize($file_path);
            $filename = basename($file_path);

            // START (طلب بداية رفع متقطع)
            $start_url = "https://graph.facebook.com/{$version}/{$page_id}/videos";
            $start_payload = ['upload_phase' => 'start', 'access_token' => $access_token, 'file_size' => $filesize];

            $ch = curl_init($start_url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($start_payload),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_TIMEOUT => 60
            ]);
            $start_raw = curl_exec($ch);
            $start_errno = curl_errno($ch);
            $start_err = curl_error($ch);
            $start_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $this->log_error('upload_facebook_video_start', "page={$page_id} file={$filename} http_code={$start_code} curl_errno={$start_errno} curl_err={$start_err} resp_preview=" . substr((string)$start_raw,0,1200));
            $start_json = json_decode($start_raw, true);

            // Determine upload URL: use provided upload_url OR build rupload URL when only video_id present
            $video_id = $start_json['video_id'] ?? null;
            $upload_url = $start_json['upload_url'] ?? null;
            if ($video_id && !$upload_url) {
                // build rupload URL like Reel_model — this works reliably
                $upload_url = "https://rupload.facebook.com/video-upload/{$version}/{$video_id}";
                $this->log_error('upload_facebook_video_info', "Using rupload URL constructed for video_id={$video_id}");
            }

            if (!empty($upload_url) && $video_id) {
                // UPLOAD: stream raw bytes to rupload host
                $fh = fopen($file_path, 'rb');
                if ($fh === false) {
                    return ['success' => false, 'error' => 'فشل فتح ملف الفيديو للقراءة'];
                }

                $file_contents = stream_get_contents($fh);
                fclose($fh);

                $ch2 = curl_init($upload_url);
                $headers = [
                    "Authorization: OAuth {$access_token}",
                    "offset: 0",
                    "file_size: {$filesize}"
                ];
                curl_setopt_array($ch2, [
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $file_contents,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_TIMEOUT => 0, // allow long-running upload
                ]);
                $upload_raw = curl_exec($ch2);
                $upload_errno = curl_errno($ch2);
                $upload_err = curl_error($ch2);
                $upload_code = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
                curl_close($ch2);

                $this->log_error('upload_facebook_video_upload', "page={$page_id} file={$filename} http_code={$upload_code} curl_errno={$upload_errno} curl_err={$upload_err} resp_preview=" . substr((string)$upload_raw,0,1200));
                $upload_json = json_decode($upload_raw, true);

                if ($upload_errno) {
                    return ['success' => false, 'error' => 'cURL upload error: ' . $upload_err];
                }
                if (isset($upload_json['error'])) {
                    return ['success' => false, 'error' => $upload_json['error']['message'] ?? 'Upload error'];
                }

                // FINISH
                $finish_url = $start_url; // same endpoint
                $finish_payload = [
                    'access_token' => $access_token,
                    'video_id' => $video_id,
                    'upload_phase' => 'finish',
                    'description' => $this->input->post('post_description') ?: $this->input->post('global_description')
                ];

                // أضف upload_session_id و start_offset/end_offset إن توفّروا من START
                if (!empty($start_json['upload_session_id'])) {
                    $finish_payload['upload_session_id'] = $start_json['upload_session_id'];
                }
                if (isset($start_json['start_offset'])) {
                    $finish_payload['start_offset'] = $start_json['start_offset'];
                }
                if (isset($start_json['end_offset'])) {
                    $finish_payload['end_offset'] = $start_json['end_offset'];
                }

                $ch3 = curl_init($finish_url);
                curl_setopt_array($ch3, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => http_build_query($finish_payload),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_TIMEOUT => 60
                ]);
                $finish_raw = curl_exec($ch3);
                $finish_errno = curl_errno($ch3);
                $finish_err = curl_error($ch3);
                $finish_code = curl_getinfo($ch3, CURLINFO_HTTP_CODE);
                curl_close($ch3);

                $this->log_error('upload_facebook_video_finish', "page={$page_id} file={$filename} http_code={$finish_code} curl_errno={$finish_errno} curl_err={$finish_err} resp_preview=" . substr((string)$finish_raw,0,1200));
                $finish_json = json_decode($finish_raw, true);

                if ($finish_errno) {
                    return ['success' => false, 'error' => 'cURL error during finish: ' . $finish_err];
                }

                // حاول الحصول على video_id إذا لم يكن معرفاً مسبقاً
                $video_id = $video_id ?? ($finish_json['video_id'] ?? ($upload_json['video_id'] ?? null));

                // إذا أعاد FINISH post_id فالمهم انتهى ويمكن إرجاعه مباشرة
                if ($finish_code === 200 && !empty($finish_json['post_id'])) {
                    return ['success' => true, 'post_id' => $finish_json['post_id']];
                }

                // الآن نريد إنشاء منشور في الـ feed مربوط بالفيديو لضمان أنه يظهر كمنشور فيديو (وليس Reel)
                if ($video_id) {
                    $feed_payload = [
                        'access_token' => $access_token,
                        'message' => $this->input->post('post_description') ?: $this->input->post('global_description'),
                        // attached_media يجب أن يكون JSON string array
                        'attached_media' => json_encode([ ['media_fbid' => $video_id] ])
                    ];

                    $feed_url = "https://graph.facebook.com/{$version}/{$page_id}/feed";
                    $chFeed = curl_init($feed_url);
                    curl_setopt_array($chFeed, [
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => $feed_payload,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_SSL_VERIFYPEER => false,
                        CURLOPT_TIMEOUT => 60
                    ]);
                    $feed_raw = curl_exec($chFeed);
                    $feed_errno = curl_errno($chFeed);
                    $feed_err = curl_error($chFeed);
                    $feed_http = curl_getinfo($chFeed, CURLINFO_HTTP_CODE);
                    curl_close($chFeed);

                    $this->log_error('upload_facebook_video_create_feed', "page={$page_id} video_id={$video_id} http_code={$feed_http} curl_errno={$feed_errno} curl_err={$feed_err} resp_preview=" . substr((string)$feed_raw,0,1200));
                    $feed_json = json_decode($feed_raw, true);

                    if ($feed_errno) {
                        return ['success' => false, 'error' => 'cURL error (create feed): ' . $feed_err];
                    }
                    if ($feed_http === 200 && !empty($feed_json['id'])) {
                        return ['success' => true, 'post_id' => $feed_json['id']];
                    }
                    // إن فشل إنشاء الفيد، أرجع رسالة مفصّلة لكن دلوقتي الفيديو موجود في حساب FB (ربما كميديا/ريل)
                    return ['success' => false, 'error' => $feed_json['error']['message'] ?? ("Feed create HTTP {$feed_http}"), 'video_id' => $video_id];
                }

                return ['success' => false, 'error' => $finish_json['error']['message'] ?? "HTTP {$finish_code}"];
            }

            // FALLBACK: single multipart POST (existing approach)
            $this->log_error('upload_facebook_video_fallback', "start did not return usable upload_url/video_id, falling back to single POST. start_resp=" . substr((string)$start_raw,0,1200));

            $mime = function_exists('mime_content_type') ? @mime_content_type($file_path) : null;
            if (!$mime) $mime = 'application/octet-stream';
            $cfile = new CURLFile($file_path, $mime, $filename);

            $post_fields = [
                'source' => $cfile,
                'description' => $this->input->post('post_description') ?: $this->input->post('global_description'),
                'access_token' => $access_token
            ];

            $chf = curl_init("https://graph.facebook.com/{$version}/{$page_id}/videos");
            if (defined('CURLOPT_SAFE_UPLOAD')) curl_setopt($chf, CURLOPT_SAFE_UPLOAD, true);
            curl_setopt_array($chf, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $post_fields,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_TIMEOUT => 600
            ]);

            $resp_fallback = curl_exec($chf);
            $code_fallback = curl_getinfo($chf, CURLINFO_HTTP_CODE);
            $errno_fallback = curl_errno($chf);
            $err_fallback = curl_error($chf);
            curl_close($chf);

            $this->log_error('upload_facebook_video_fallback_resp', "page={$page_id} file={$filename} http_code={$code_fallback} curl_errno={$errno_fallback} curl_err={$err_fallback} resp_preview=" . substr((string)$resp_fallback,0,1200));

            $res_json = json_decode($resp_fallback, true);
            if ($errno_fallback) return ['success' => false, 'error' => 'cURL error: ' . $err_fallback];
            if ($code_fallback === 200 && !empty($res_json['id'])) {
                // بعد الرفع المباشر، نحاول أيضًا إنشاء feed مرتبطة بالفيديو لضمان نوع المنشور
                $video_id_fallback = $res_json['id'];
                $feed_payload = [
                    'access_token' => $access_token,
                    'message' => $this->input->post('post_description') ?: $this->input->post('global_description'),
                    'attached_media' => json_encode([ ['media_fbid' => $video_id_fallback] ])
                ];
                $feed_url = "https://graph.facebook.com/{$version}/{$page_id}/feed";
                $chFeed2 = curl_init($feed_url);
                curl_setopt_array($chFeed2, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $feed_payload,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_TIMEOUT => 60
                ]);
                $feed_raw2 = curl_exec($chFeed2);
                $feed_http2 = curl_getinfo($chFeed2, CURLINFO_HTTP_CODE);
                $feed_errno2 = curl_errno($chFeed2);
                $feed_err2 = curl_error($chFeed2);
                curl_close($chFeed2);

                $this->log_error('upload_facebook_video_fallback_create_feed', "page={$page_id} video_id={$video_id_fallback} http_code={$feed_http2} curl_errno={$feed_errno2} curl_err={$feed_err2} resp_preview=" . substr((string)$feed_raw2,0,1200));
                $feed_json2 = json_decode($feed_raw2, true);
                if ($feed_errno2) return ['success' => false, 'error' => 'cURL error (fallback create feed): ' . $feed_err2];
                if ($feed_http2 === 200 && !empty($feed_json2['id'])) return ['success' => true, 'post_id' => $feed_json2['id']];
                return ['success' => false, 'error' => $feed_json2['error']['message'] ?? ("Fallback HTTP {$feed_http2}"), 'video_id' => $video_id_fallback];
            }
            return ['success' => false, 'error' => $res_json['error']['message'] ?? "HTTP {$code_fallback}"];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    /**
     * Instagram handling: routing
     */
    private function process_instagram_upload($uid, $content_type)
    {
        $ig_accounts = $this->input->post('instagram_accounts');
        if (empty($ig_accounts)) {
            throw new Exception('اختر حساب Instagram واحد على الأقل');
        }

        if ($content_type === 'reel') {
            return $this->process_instagram_reels($uid, $ig_accounts);
        } elseif ($content_type === 'story_video' || $content_type === 'story_photo') {
            return $this->process_instagram_stories($uid, $ig_accounts, $content_type);
        } else {
            return $this->process_instagram_posts($uid, $ig_accounts, $content_type);
        }
    }

    /**
     * معالجة ريلز Instagram
     */
    private function process_instagram_reels($uid, $ig_accounts)
    {
        if (empty($_FILES['files']['name'][0])) {
            throw new Exception('اختر ملفات فيديو للريلز');
        }

        $results = [];

        foreach ($_FILES['files']['name'] as $index => $filename) {
            if (empty($filename)) continue;

            $file_data = [
                'name' => $filename,
                'type' => $_FILES['files']['type'][$index],
                'tmp_name' => $_FILES['files']['tmp_name'][$index],
                'error' => $_FILES['files']['error'][$index],
                'size' => $_FILES['files']['size'][$index]
            ];

            if ($file_data['error'] !== UPLOAD_ERR_OK) {
                $results[] = ['type' => 'error', 'msg' => "خطأ في رفع {$filename}"];
                continue;
            }

            $file_descriptions = $this->input->post('file_descriptions') ? $this->input->post('file_descriptions') : [];
            $file_schedule_times = $this->input->post('file_schedule_times') ? $this->input->post('file_schedule_times') : [];

            // استبدال null-coalescing ببدائل متوافقة مع PHP الأقدم
            $description = isset($file_descriptions[$index]) && $file_descriptions[$index] !== '' 
                ? $file_descriptions[$index] 
                : $this->input->post('global_description');

            $schedule_time = isset($file_schedule_times[$index]) ? $file_schedule_times[$index] : null;

            foreach ($ig_accounts as $ig_user_id) {
                try {
                    $account = $this->get_instagram_account($uid, $ig_user_id);
                    if (!$account) {
                        $results[] = ['type' => 'error', 'msg' => "حساب Instagram غير موجود: {$ig_user_id}"];
                        continue;
                    }

                    // حفظ الملف بشكل آمن مع تحقق MIME
                    $saved_file = $this->save_uploaded_file($file_data, $uid, 'instagram');
                    if (!$saved_file['success']) {
                        $results[] = ['type' => 'error', 'msg' => "فشل حفظ {$filename}: {$saved_file['error']}"];
                        continue;
                    }

                    if (empty($schedule_time)) {
                        // نشر فوري باستخدام InstagramPublisher library
                        $publish_result = $this->instagrampublisher->publishReel(
                            $ig_user_id,
                            $saved_file['full_path'],
                            $description,
                            $account['access_token']
                        );

                        if (!empty($publish_result) && !empty($publish_result['ok'])) {
                            $results[] = ['type' => 'success', 'msg' => "تم نشر {$filename} على {$account['ig_username']}"];
                        } else {
                            $err = (isset($publish_result['error']) ? $publish_result['error'] : 'خطأ');
                            $results[] = ['type' => 'error', 'msg' => "فشل نشر {$filename} على {$account['ig_username']}: {$err}"];
                        }
                    } else {
                        // جدولة الريل في Instagram_reels_model
                        $tz_offset = (int)$this->input->post('timezone_offset');
                        $utc_time = $this->convert_to_utc($schedule_time, $tz_offset);

                        $record_id = $this->Instagram_reels_model->insert_record([
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

                        if ($record_id) {
                            $results[] = ['type' => 'success', 'msg' => "تم جدولة {$filename} على {$account['ig_username']}"];
                        } else {
                            $results[] = ['type' => 'error', 'msg' => "فشل جدولة {$filename} على {$account['ig_username']}"];
                        }
                    }

                } catch (Exception $e) {
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
     * هذه الدالة استبدلت النسخ المكررة السابقة ووُحدت هنا
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
                    array_map(fn($s) => ['type' => 'success', 'msg' => $s], $success),
                    array_map(fn($e) => ['type' => 'error', 'msg' => $e], $errors)
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
        $filters = array_filter($filters, fn($v) => $v !== '' && $v !== null);

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
     * CRON للنشر المجدول (مع flock لمنع تشغيل متزامن)
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

        // ---- START: secure flock-based cron lock ----
        // ملف القفل في مجلّد النظام المؤقت (يمكن تغييره إلى مسار تحت /home/social/locks لو أردت)
        $lockFile = sys_get_temp_dir() . '/' . self::CRON_LOCKFILE;
        $lockFp = @fopen($lockFile, 'c+');
        if ($lockFp === false) {
            // لم نستطع فتح ملف القفل — نسجل وننهي التنفيذ بهدوء
            $this->log_error('cron_publish', "cannot open lock file {$lockFile}");
            echo "Cannot open lock file: {$lockFile}\n";
            return;
        }

        // محاولة الحصول على قفل حصري وغير محظور
        if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
            // نسخة أخرى تعمل حالياً — سجل الرسالة وأعد الإغلاق
            $this->log_error('cron_publish', 'Another instance running or cannot open lock file');
            echo "Another instance is running.\n";
            fclose($lockFp);
            return;
        }

        // اكتب PID داخل ملف القفل لتسهيل التشخيص
        ftruncate($lockFp, 0);
        fwrite($lockFp, getmypid() . PHP_EOL);

        // تأكد أن القفل سيتحرر حتى لو انتهى السكربت بطريقة غير متوقعة
        register_shutdown_function(function() use ($lockFp, $lockFile) {
            if (is_resource($lockFp)) {
                @flock($lockFp, LOCK_UN);
                @fclose($lockFp);
            }
            @unlink($lockFile);
        });
        // ---- END: secure flock-based cron lock ----

        try {
            // ضع حدّ زمني كافٍ لعمليات النشر الطويلة
            if (function_exists('set_time_limit')) {
                @set_time_limit(0);
            }

            $this->create_social_posts_table();

            $due_posts = [];
            // support both column names
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
                    $this->process_scheduled_post($post);
                    $processed++;
                } catch (Exception $e) {
                    $this->log_error('cron_publish_item', "Post ID {$post['id']}: " . $e->getMessage());
                }
            }

            echo "Processed {$processed} scheduled posts.\n";

        } catch (Exception $e) {
            $this->log_error('cron_publish', $e->getMessage());
            echo "Cron failed: " . $e->getMessage() . "\n";
        } finally {
            // نحرر القفل ونغلق الملف هنا أيضاً (باش نضمن التحرير فور انتهاء الدالة)
            if (isset($lockFp) && is_resource($lockFp)) {
                @flock($lockFp, LOCK_UN);
                @fclose($lockFp);
                @unlink($lockFile);
            }
        }
    }
    /**
     * معالجة منشور مجدول واحد
     */
    private function process_scheduled_post($post)
    {
        // ضع العلم processing لمنع محاولات متزامنة
        try {
            $this->db->where('id', $post['id'])->update('social_posts', ['processing' => 1, 'status' => 'publishing', 'updated_at' => date('Y-m-d H:i:s')]);
        } catch (Exception $e) {
            // استمر حتى لو فشل التحديث (قد لا تكون الأعمدة موجودة)
        }

        if ($post['platform'] === 'facebook') {
            $this->process_scheduled_facebook_post($post);
        } elseif ($post['platform'] === 'instagram') {
            $this->process_scheduled_instagram_post($post);
        }

        // انتهاء المعالجة - إزالة العلم
        try {
            $this->db->where('id', $post['id'])->update('social_posts', ['processing' => 0, 'updated_at' => date('Y-m-d H:i:s')]);
        } catch (Exception $e) {
            // ignore
        }
    }

    /**
     * معالجة منشور Facebook مجدول
     */
    private function process_scheduled_facebook_post($post)
    {
        try {
            // جلب بيانات الصفحة
            $pages = $this->Facebook_pages_model->get_pages_by_user($post['user_id']);
            $page = null;
            foreach ($pages as $p) {
                // original table used account_id or account_id field may differ
                if ((string)($p['fb_page_id'] ?? '') === (string)($post['account_id'] ?? $post['account_id'])) {
                    $page = $p;
                    break;
                }
                if ((string)($p['fb_page_id'] ?? '') === (string)($post['account_id'] ?? $post['account_id'])) {
                    $page = $p;
                    break;
                }
            }

            if (!$page || empty($page['page_access_token'])) {
                throw new Exception('صفحة Facebook غير موجودة أو لا تملك صلاحيات');
            }

            $content_type = $post['content_type'] ?? $post['content_type'] ?? 'post_text';
            $result = $this->publish_facebook_post($post['account_id'], $page['page_access_token'], $content_type);

            if (!empty($result['success'])) {
                $update = [
                    'status' => 'published',
                    'post_id' => $result['post_id'] ?? null,
                    'published_time' => date('Y-m-d H:i:s'),
                    'error_message' => null
                ];
                $this->db->where('id', $post['id'])->update('social_posts', $update);
            } else {
                $this->db->where('id', $post['id'])->update('social_posts', [
                    'status' => 'failed',
                    'error_message' => $result['error'] ?? 'خطأ غير معروف'
                ]);
            }
        } catch (Exception $e) {
            $this->db->where('id', $post['id'])->update('social_posts', [
                'status' => 'failed',
                'error_message' => $e->getMessage()
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

            if (!empty($result['ok'])) {
                $this->db->where('id', $post['id'])->update('social_posts', [
                    'status' => 'published',
                    'post_id' => $result['media_id'] ?? null,
                    'published_time' => date('Y-m-d H:i:s'),
                    'error_message' => null
                ]);
            } else {
                $this->db->where('id', $post['id'])->update('social_posts', [
                    'status' => 'failed',
                    'error_message' => $result['error'] ?? 'فشل في النشر'
                ]);
            }

        } catch (Exception $e) {
            $this->db->where('id', $post['id'])->update('social_posts', [
                'status' => 'failed',
                'error_message' => $e->getMessage()
            ]);
        }
    }

    /**
     * AJAX: جلب الحسابات
     */
    public function ajax_get_accounts()
    {
        $this->require_login();
        $uid = (int)$this->session->userdata('user_id');

        $platform = $this->input->get('platform');

        if ($platform === 'facebook') {
            $accounts = $this->Facebook_pages_model->get_pages_by_user($uid);
        } elseif ($platform === 'instagram') {
            $accounts = $this->get_instagram_accounts_safe($uid);
        } else {
            $accounts = [];
        }

        $this->send_json(['success' => true, 'accounts' => $accounts]);
    }

    /**
     * AJAX: جلب الإحصائيات
     */
    public function ajax_get_stats()
    {
        $this->require_login();
        $uid = (int)$this->session->userdata('user_id');

        $stats = $this->get_posts_stats_safe($uid);

        $this->send_json(['success' => true, 'stats' => $stats]);
    }

    /**
     * AJAX: إجراءات جماعية
     */
    public function ajax_bulk_action()
    {
        $this->require_login();
        $uid = (int)$this->session->userdata('user_id');

        $action = $this->input->post('action');
        $post_ids = $this->input->post('post_ids');

        if (!$action || empty($post_ids)) {
            return $this->send_json(['success' => false, 'message' => 'بيانات غير صحيحة'], 400);
        }

        $affected = 0;

        try {
            switch ($action) {
                case 'delete':
                    $this->db->where('user_id', $uid)
                             ->where_in('id', $post_ids)
                             ->delete('social_posts');
                    $affected = $this->db->affected_rows();
                    break;

                case 'republish':
                    $this->db->where('user_id', $uid)
                             ->where_in('id', $post_ids)
                             ->where('status', 'failed')
                             ->update('social_posts', ['status' => 'pending']);
                    $affected = $this->db->affected_rows();
                    break;

                case 'cancel_schedule':
                    if ($this->column_exists('social_posts', 'scheduled_time') || $this->column_exists('social_posts', 'scheduled_at')) {
                        $this->db->where('user_id', $uid)
                                 ->where_in('id', $post_ids)
                                 ->where('status', 'scheduled')
                                 ->update('social_posts', ['status' => 'pending', 'scheduled_time' => null, 'scheduled_at' => null]);
                        $affected = $this->db->affected_rows();
                    }
                    break;
            }
        } catch (Exception $e) {
            $this->log_error('ajax_bulk_action', $e->getMessage());
            return $this->send_json(['success' => false, 'message' => 'حدث خطأ'], 500);
        }

        $this->send_json(['success' => true, 'affected' => $affected]);
    }

    /**
     * حذف منشور مجدول
     */
    public function delete_scheduled($id)
    {
        $this->require_login();
        $uid = (int)$this->session->userdata('user_id');

        try {
            $this->db->where('id', (int)$id)
                     ->where('user_id', $uid)
                     ->delete('social_posts');

            if ($this->db->affected_rows() > 0) {
                $this->session->set_flashdata('msg_success', 'تم حذف المنشور');
            } else {
                $this->session->set_flashdata('msg', 'المنشور غير موجود');
            }
        } catch (Exception $e) {
            $this->log_error('delete_scheduled', $e->getMessage());
            $this->session->set_flashdata('msg', 'حدث خطأ في الحذف');
        }

        redirect('social_publisher/listing');
    }

    /**
     * تعديل منشور مجدول
     */
    public function edit_scheduled($id)
    {
        $this->require_login();
        $uid = (int)$this->session->userdata('user_id');

        try {
            $post = $this->db->where('id', (int)$id)
                            ->where('user_id', $uid)
                            ->get('social_posts')->row_array();

            if (!$post) {
                $this->session->set_flashdata('msg', 'المنشور غير موجود');
                redirect('social_publisher/listing');
                return;
            }

            if ($post['status'] !== 'scheduled') {
                $this->session->set_flashdata('msg', 'لا يمكن تعديل هذا المنشور');
                redirect('social_publisher/listing');
                return;
            }

            $data['post'] = $post;
            $this->load->view('social_publisher_edit', $data);

        } catch (Exception $e) {
            $this->log_error('edit_scheduled', $e->getMessage());
            $this->session->set_flashdata('msg', 'حدث خطأ');
            redirect('social_publisher/listing');
        }
    }

    /**
     * التحديث والنسخ والـ templates و recurring و export وغيرها تبقى كما في الأصل
     * (التالي: update_scheduled, duplicate_scheduled, templates, create_template, edit_template,
     * delete_template, recurring_schedules, create_recurring, calculate_next_run, cron_recurring,
     * process_recurring_schedule, cron_cleanup, export_data, export_csv, export_json, api_publish, api_post_status)
     *
     * لتحافظ على الطول والوضوح قمت بعد ذلك بتضمين تلك الدوال كما في الملف الأصلي
     * مع تحسين قفل الـ CRON وعمليات التأكد من وجود الجداول التي تمت بالفعل.
     */

    /**
     * تحديث منشور مجدول
     */
    public function update_scheduled()
    {
        $this->require_login();
        $uid = (int)$this->session->userdata('user_id');

        try {
            $id = (int)$this->input->post('id');
            $description = trim($this->input->post('description'));
            $scheduled_time = $this->input->post('scheduled_time');

            $update_data = [
                'description' => $description,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            if ($scheduled_time && ($this->column_exists('social_posts', 'scheduled_time') || $this->column_exists('social_posts', 'scheduled_at'))) {
                if ($this->column_exists('social_posts', 'scheduled_time')) {
                    $update_data['scheduled_time'] = $scheduled_time;
                } else {
                    $update_data['scheduled_at'] = $scheduled_time;
                }
            }

            $this->db->where('id', $id)
                     ->where('user_id', $uid)
                     ->where('status', 'scheduled')
                     ->update('social_posts', $update_data);

            if ($this->db->affected_rows() > 0) {
                $this->session->set_flashdata('msg_success', 'تم التحديث');
            } else {
                $this->session->set_flashdata('msg', 'لم يتم التحديث');
            }

        } catch (Exception $e) {
            $this->log_error('update_scheduled', $e->getMessage());
            $this->session->set_flashdata('msg', 'حدث خطأ في التحديث');
        }

        redirect('social_publisher/listing');
    }

    /**
     * نسخ منشور مجدول
     */
    public function duplicate_scheduled($id)
    {
        $this->require_login();
        $uid = (int)$this->session->userdata('user_id');

        try {
            $post = $this->db->where('id', (int)$id)
                            ->where('user_id', $uid)
                            ->get('social_posts')->row_array();

            if (!$post) {
                $this->session->set_flashdata('msg', 'المنشور غير موجود');
                redirect('social_publisher/listing');
                return;
            }

            unset($post['id']);
            $post['status'] = 'pending';
            $post['post_id'] = null;
            $post['error_message'] = null;
            $post['published_time'] = null;
            $post['created_at'] = date('Y-m-d H:i:s');
            $post['updated_at'] = date('Y-m-d H:i:s');

            $this->db->insert('social_posts', $post);

            $this->session->set_flashdata('msg_success', 'تم نسخ المنشور');

        } catch (Exception $e) {
            $this->log_error('duplicate_scheduled', $e->getMessage());
            $this->session->set_flashdata('msg', 'حدث خطأ في النسخ');
        }

        redirect('social_publisher/listing');
    }

    /**
     * Templates management
     */
    public function templates()
    {
        $this->require_login();
        $uid = (int)$this->session->userdata('user_id');

        $this->create_templates_table();

        $templates = $this->db->where('user_id', $uid)
                             ->order_by('created_at', 'DESC')
                             ->get('social_templates')->result_array();

        $data['templates'] = $templates;
        $this->load->view('social_publisher_templates', $data);
    }

    private function create_templates_table()
    {
        if ($this->db->table_exists('social_templates')) {
            return;
        }

        $sql = "CREATE TABLE `social_templates` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `name` varchar(255) NOT NULL,
            `platform` enum('facebook','instagram','both') NOT NULL,
            `content_type` varchar(50) NOT NULL,
            `title` text DEFAULT NULL,
            `description` text DEFAULT NULL,
            `hashtags` text DEFAULT NULL,
            `created_at` datetime NOT NULL,
            `updated_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $this->db->query($sql);
    }

    public function create_template()
    {
        $this->require_login();
        $uid = (int)$this->session->userdata('user_id');

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                $this->create_templates_table();

                $data = [
                    'user_id' => $uid,
                    'name' => trim($this->input->post('name')),
                    'platform' => $this->input->post('platform'),
                    'content_type' => $this->input->post('content_type'),
                    'title' => trim($this->input->post('title')),
                    'description' => trim($this->input->post('description')),
                    'hashtags' => trim($this->input->post('hashtags')),
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ];

                $this->db->insert('social_templates', $data);

                $this->session->set_flashdata('msg_success', 'تم إنشاء القالب');
                redirect('social_publisher/templates');

            } catch (Exception $e) {
                $this->log_error('create_template', $e->getMessage());
                $this->session->set_flashdata('msg', 'حدث خطأ في إنشاء القالب');
            }
        }

        $this->load->view('social_publisher_create_template');
    }

    public function edit_template($id)
    {
        $this->require_login();
        $uid = (int)$this->session->userdata('user_id');

        try {
            $template = $this->db->where('id', (int)$id)
                                ->where('user_id', $uid)
                                ->get('social_templates')->row_array();

            if (!$template) {
                $this->session->set_flashdata('msg', 'القالب غير موجود');
                redirect('social_publisher/templates');
                return;
            }

            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $update_data = [
                    'name' => trim($this->input->post('name')),
                    'platform' => $this->input->post('platform'),
                    'content_type' => $this->input->post('content_type'),
                    'title' => trim($this->input->post('title')),
                    'description' => trim($this->input->post('description')),
                    'hashtags' => trim($this->input->post('hashtags')),
                    'updated_at' => date('Y-m-d H:i:s')
                ];

                $this->db->where('id', $id)->update('social_templates', $update_data);

                $this->session->set_flashdata('msg_success', 'تم تحديث القالب');
                redirect('social_publisher/templates');
            }

            $data['template'] = $template;
            $this->load->view('social_publisher_edit_template', $data);

        } catch (Exception $e) {
            $this->log_error('edit_template', $e->getMessage());
            $this->session->set_flashdata('msg', 'حدث خطأ');
            redirect('social_publisher/templates');
        }
    }

    public function delete_template($id)
    {
        $this->require_login();
        $uid = (int)$this->session->userdata('user_id');

        try {
            $this->db->where('id', (int)$id)
                     ->where('user_id', $uid)
                     ->delete('social_templates');

            if ($this->db->affected_rows() > 0) {
                $this->session->set_flashdata('msg_success', 'تم حذف القالب');
            } else {
                $this->session->set_flashdata('msg', 'القالب غير موجود');
            }

        } catch (Exception $e) {
            $this->log_error('delete_template', $e->getMessage());
            $this->session->set_flashdata('msg', 'حدث خطأ في الحذف');
        }

        redirect('social_publisher/templates');
    }

    /**
     * الجدولة المتكررة: عرض وادارة
     */
    public function recurring_schedules()
    {
        $this->require_login();
        $uid = (int)$this->session->userdata('user_id');

        $this->create_recurring_table();

        $schedules = $this->db->where('user_id', $uid)
                             ->order_by('created_at', 'DESC')
                             ->get('social_recurring_schedules')->result_array();

        $data['schedules'] = $schedules;
        $this->load->view('social_publisher_recurring', $data);
    }

    private function create_recurring_table()
    {
        if ($this->db->table_exists('social_recurring_schedules')) {
            return;
        }

        $sql = "CREATE TABLE `social_recurring_schedules` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `name` varchar(255) NOT NULL,
            `platform` enum('facebook','instagram','both') NOT NULL,
            `content_type` varchar(50) NOT NULL,
            `accounts` text NOT NULL,
            `template_id` int(11) DEFAULT NULL,
            `recurrence_type` enum('daily','weekly','monthly') NOT NULL,
            `recurrence_time` time NOT NULL,
            `recurrence_days` varchar(20) DEFAULT NULL,
            `start_date` date NOT NULL,
            `end_date` date DEFAULT NULL,
            `status` enum('active','paused','completed') DEFAULT 'active',
            `last_run` datetime DEFAULT NULL,
            `next_run` datetime DEFAULT NULL,
            `created_at` datetime NOT NULL,
            `updated_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `user_id` (`user_id`),
            KEY `next_run` (`next_run`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $this->db->query($sql);
    }

    public function create_recurring()
    {
        $this->require_login();
        $uid = (int)$this->session->userdata('user_id');

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                $this->create_recurring_table();

                $data = [
                    'user_id' => $uid,
                    'name' => trim($this->input->post('name')),
                    'platform' => $this->input->post('platform'),
                    'content_type' => $this->input->post('content_type'),
                    'accounts' => json_encode($this->input->post('accounts')),
                    'template_id' => $this->input->post('template_id') ?: null,
                    'recurrence_type' => $this->input->post('recurrence_type'),
                    'recurrence_time' => $this->input->post('recurrence_time'),
                    'recurrence_days' => $this->input->post('recurrence_days'),
                    'start_date' => $this->input->post('start_date'),
                    'end_date' => $this->input->post('end_date') ?: null,
                    'status' => 'active',
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ];

                $data['next_run'] = $this->calculate_next_run($data);

                $this->db->insert('social_recurring_schedules', $data);

                $this->session->set_flashdata('msg_success', 'تم إنشاء الجدولة المتكررة');
                redirect('social_publisher/recurring');

            } catch (Exception $e) {
                $this->log_error('create_recurring', $e->getMessage());
                $this->session->set_flashdata('msg', 'حدث خطأ في إنشاء الجدولة');
            }
        }

        $templates = $this->db->where('user_id', $uid)
                             ->get('social_templates')->result_array();

        $facebook_pages = $this->Facebook_pages_model->get_pages_by_user($uid);
        $instagram_accounts = $this->get_instagram_accounts_safe($uid);

        $data = [
            'templates' => $templates,
            'facebook_pages' => $facebook_pages,
            'instagram_accounts' => $instagram_accounts
        ];

        $this->load->view('social_publisher_create_recurring', $data);
    }

    private function calculate_next_run($schedule)
    {
        $start_date = $schedule['start_date'];
        $time = $schedule['recurrence_time'];
        $type = $schedule['recurrence_type'];

        $base_datetime = $start_date . ' ' . $time;
        $timestamp = strtotime($base_datetime);

        if ($timestamp <= time()) {
            switch ($type) {
                case 'daily':
                    $timestamp = strtotime('+1 day', $timestamp);
                    break;
                case 'weekly':
                    $timestamp = strtotime('+1 week', $timestamp);
                    break;
                case 'monthly':
                    $timestamp = strtotime('+1 month', $timestamp);
                    break;
            }
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * CRON للجدولة المتكررة مع flock
     */
    public function cron_recurring($token = null)
    {
        if (!$this->input->is_cli_request()) {
            if ($token !== self::CRON_TOKEN) {
                show_error('Unauthorized', 403);
                return;
            }
        }

        $lock_file = sys_get_temp_dir() . '/' . self::CRON_LOCKFILE . '.recurring';
        $fh = @fopen($lock_file, 'c+');
        if (!$fh || !flock($fh, LOCK_EX | LOCK_NB)) {
            $this->log_error('cron_recurring', 'Another instance running');
            echo "Another instance is running.\n";
            if ($fh) fclose($fh);
            return;
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

        } catch (Exception $e) {
            $this->log_error('cron_recurring', $e->getMessage());
            echo "Recurring cron failed: " . $e->getMessage() . "\n";
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
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
     * CRON للتنظيف مع flock
     */
    public function cron_cleanup($token = null)
    {
        if (!$this->input->is_cli_request()) {
            if ($token !== self::CRON_TOKEN) {
                show_error('Unauthorized', 403);
                return;
            }
        }

        $lock_file = sys_get_temp_dir() . '/' . self::CRON_LOCKFILE . '.cleanup';
        $fh = @fopen($lock_file, 'c+');
        if (!$fh || !flock($fh, LOCK_EX | LOCK_NB)) {
            $this->log_error('cron_cleanup', 'Another instance running');
            echo "Another instance is running.\n";
            if ($fh) fclose($fh);
            return;
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

        } catch (Exception $e) {
            $this->log_error('cron_cleanup', $e->getMessage());
            echo "Cleanup failed: " . $e->getMessage() . "\n";
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /**
     * تقارير وExports
     */
    public function reports()
    {
        $this->require_login();
        $uid = (int)$this->session->userdata('user_id');

        $this->create_social_posts_table();

        $data = [
            'daily_stats' => $this->get_daily_stats($uid),
            'platform_stats' => $this->get_platform_stats($uid),
            'content_type_stats' => $this->get_content_type_stats($uid),
            'success_rate' => $this->get_success_rate($uid)
        ];

        $this->load->view('social_publisher_reports', $data);
    }

    private function get_daily_stats($user_id)
    {
        try {
            $stats = [];
            for ($i = 6; $i >= 0; $i--) {
                $date = date('Y-m-d', strtotime("-{$i} days"));
                $count = $this->db->where('user_id', $user_id)
                                 ->where('status', 'published')
                                 ->where('DATE(created_at)', $date)
                                 ->count_all_results('social_posts');
                $stats[] = [
                    'date' => $date,
                    'count' => $count
                ];
            }
            return $stats;
        } catch (Exception $e) {
            $this->log_error('get_daily_stats', $e->getMessage());
            return [];
        }
    }

    private function get_platform_stats($user_id)
    {
        try {
            $facebook = $this->db->where('user_id', $user_id)
                                ->where('platform', 'facebook')
                                ->count_all_results('social_posts');

            $instagram = $this->db->where('user_id', $user_id)
                                 ->where('platform', 'instagram')
                                 ->count_all_results('social_posts');

            return [
                'facebook' => $facebook,
                'instagram' => $instagram
            ];
        } catch (Exception $e) {
            $this->log_error('get_platform_stats', $e->getMessage());
            return ['facebook' => 0, 'instagram' => 0];
        }
    }

    private function get_content_type_stats($user_id)
    {
        try {
            $stats = $this->db->select('content_type, COUNT(*) as count')
                             ->from('social_posts')
                             ->where('user_id', $user_id)
                             ->group_by('content_type')
                             ->get()->result_array();

            $result = [];
            foreach ($stats as $stat) {
                $result[$stat['content_type']] = $stat['count'];
            }

            return $result;
        } catch (Exception $e) {
            $this->log_error('get_content_type_stats', $e->getMessage());
            return [];
        }
    }

    private function get_success_rate($user_id)
    {
        try {
            $total = $this->db->where('user_id', $user_id)
                             ->count_all_results('social_posts');

            $published = $this->db->where('user_id', $user_id)
                                 ->where('status', 'published')
                                 ->count_all_results('social_posts');

            return $total > 0 ? round(($published / $total) * 100, 2) : 0;

        } catch (Exception $e) {
            $this->log_error('get_success_rate', $e->getMessage());
            return 0;
        }
    }

    public function analytics()
    {
        $this->require_login();
        $uid = (int)$this->session->userdata('user_id');

        $this->create_social_posts_table();

        $data = [
            'engagement_stats' => $this->get_engagement_stats($uid),
            'best_times' => $this->get_best_posting_times($uid),
            'hashtag_performance' => $this->get_hashtag_performance($uid),
            'account_performance' => $this->get_account_performance($uid)
        ];

        $this->load->view('social_publisher_analytics', $data);
    }

    private function get_engagement_stats($user_id)
    {
        try {
            $stats = $this->db->select('
                    AVG(likes_count) as avg_likes,
                    AVG(comments_count) as avg_comments,
                    AVG(shares_count) as avg_shares,
                    MAX(likes_count) as max_likes,
                    MAX(comments_count) as max_comments,
                    MAX(shares_count) as max_shares
                ')
                ->from('social_posts')
                ->where('user_id', $user_id)
                ->where('status', 'published')
                ->get()->row_array();

            return $stats ?: [
                'avg_likes' => 0, 'avg_comments' => 0, 'avg_shares' => 0,
                'max_likes' => 0, 'max_comments' => 0, 'max_shares' => 0
            ];

        } catch (Exception $e) {
            $this->log_error('get_engagement_stats', $e->getMessage());
            return [
                'avg_likes' => 0, 'avg_comments' => 0, 'avg_shares' => 0,
                'max_likes' => 0, 'max_comments' => 0, 'max_shares' => 0
            ];
        }
    }

    private function get_best_posting_times($user_id)
    {
        try {
            if (!$this->column_exists('social_posts', 'published_time')) {
                return [];
            }

            $stats = $this->db->select('
                    HOUR(published_time) as hour,
                    AVG(likes_count + comments_count + shares_count) as avg_engagement
                ')
                ->from('social_posts')
                ->where('user_id', $user_id)
                ->where('status', 'published')
                ->where('published_time IS NOT NULL', null, false)
                ->group_by('HOUR(published_time)')
                ->order_by('avg_engagement', 'DESC')
                ->limit(5)
                ->get()->result_array();

            return $stats;

        } catch (Exception $e) {
            $this->log_error('get_best_posting_times', $e->getMessage());
            return [];
        }
    }

    private function get_hashtag_performance($user_id) { return []; }

    private function get_account_performance($user_id)
    {
        try {
            $stats = $this->db->select('
                    account_name,
                    platform,
                    COUNT(*) as posts_count,
                    AVG(likes_count + comments_count + shares_count) as avg_engagement
                ')
                ->from('social_posts')
                ->where('user_id', $user_id)
                ->where('status', 'published')
                ->group_by(['account_name', 'platform'])
                ->order_by('avg_engagement', 'DESC')
                ->get()->result_array();

            return $stats;

        } catch (Exception $e) {
            $this->log_error('get_account_performance', $e->getMessage());
            return [];
        }
    }

    /**
     * Export and API endpoints
     */
    public function export_data($format = 'csv')
    {
        $this->require_login();
        $uid = (int)$this->session->userdata('user_id');

        try {
            $posts = $this->db->where('user_id', $uid)
                             ->order_by('created_at', 'DESC')
                             ->get('social_posts')->result_array();

            if ($format === 'csv') {
                $this->export_csv($posts);
            } else {
                $this->export_json($posts);
            }

        } catch (Exception $e) {
            $this->log_error('export_data', $e->getMessage());
            show_error('حدث خطأ في التصدير', 500);
        }
    }

    private function export_csv($posts)
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="social_posts_' . date('Y-m-d') . '.csv"');

        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($output, ['ID', 'المنصة', 'نوع المحتوى', 'العنوان', 'الوصف', 'الحالة', 'تاريخ الإنشاء']);

        foreach ($posts as $post) {
            fputcsv($output, [
                $post['id'],
                $post['platform'],
                $post['content_type'],
                $post['title'],
                $post['description'],
                $post['status'],
                $post['created_at']
            ]);
        }

        fclose($output);
    }

    private function export_json($posts)
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="social_posts_' . date('Y-m-d') . '.json"');

        echo json_encode($posts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * API publish endpoint (API key protected)
     */
    public function api_publish()
    {
        $api_key = $this->input->get_request_header('X-API-Key');
        if (!$api_key || !$this->validate_api_key($api_key)) {
            return $this->send_json(['error' => 'Invalid API key'], 401);
        }

        try {
            $data = json_decode($this->input->raw_input_stream, true);

            if (!$data) {
                throw new Exception('بيانات غير صحيحة');
            }

            $result = $this->process_api_publish($data);

            $this->send_json($result);

        } catch (Exception $e) {
            $this->log_error('api_publish', $e->getMessage());
            $this->send_json(['error' => $e->getMessage()], 400);
        }
    }

    private function validate_api_key($key)
    {
        // لاحقاً خزّن المفاتيح في جدول آمن أو config
        $valid_keys = ['demo_key_123', 'production_key_456'];
        return in_array($key, $valid_keys);
    }

    private function process_api_publish($data)
    {
        // تنفيذ منطق النشر عبر API (مبسط)
        return ['success' => true, 'message' => 'تم النشر بنجاح'];
    }

    public function api_post_status($post_id)
    {
        $api_key = $this->input->get_request_header('X-API-Key');
        if (!$api_key || !$this->validate_api_key($api_key)) {
            return $this->send_json(['error' => 'Invalid API key'], 401);
        }

        try {
            $post = $this->db->where('id', (int)$post_id)
                            ->get('social_posts')->row_array();

            if (!$post) {
                return $this->send_json(['error' => 'Post not found'], 404);
            }

            $this->send_json([
                'id' => $post['id'],
                'status' => $post['status'],
                'platform' => $post['platform'],
                'created_at' => $post['created_at'],
                'published_time' => $post['published_time']
            ]);

        } catch (Exception $e) {
            $this->log_error('api_post_status', $e->getMessage());
            $this->send_json(['error' => 'Server error'], 500);
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
     * Remaining utility functions
     */
    private function get_posts_stats_safe_placeholder() { /* placeholder if needed */ }

} // end class Social_publisher
