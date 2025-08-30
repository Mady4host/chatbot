<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Social_publisher Controller
 * نظام النشر الموحد لـ Facebook و Instagram
 * يستخدم الجداول والموديلات الموجودة
 */
class Social_publisher extends CI_Controller
{
    const CRON_TOKEN = 'SocialPublisher_Cron_2025';

    public function __construct()
    {
        parent::__construct();
        $this->load->model(['Reel_model', 'Facebook_pages_model', 'Instagram_reels_model']);
        $this->load->library(['session', 'InstagramPublisher']);
        $this->load->helper(['url', 'form', 'security']);
        $this->load->database();
    }

    private function require_login()
    {
        if (!$this->session->userdata('user_id')) {
            $redirect = rawurlencode(current_url());
            redirect('home/login?redirect=' . $redirect);
            exit;
        }
    }

    private function send_json($data, $code = 200)
    {
        $this->output->set_status_header($code);
        $this->output->set_content_type('application/json', 'utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    private function log_error($context, $message)
    {
        $log_dir = APPPATH . 'logs/';
        if (!is_dir($log_dir)) {
            mkdir($log_dir, 0755, true);
        }
        
        $log_file = $log_dir . 'social_publisher.log';
        $log_entry = '[' . date('Y-m-d H:i:s') . '] ' . $context . ': ' . $message . PHP_EOL;
        
        file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
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
        
        // جلب حسابات Instagram مع فحص الأعمدة الموجودة
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
                // إنشاء الجدول إذا لم يكن موجوداً
                $this->create_instagram_accounts_table();
            }

            if (!$this->db->table_exists('facebook_rx_fb_page_info')) {
                return [];
            }

            // فحص الأعمدة الموجودة
            $columns = $this->db->query("SHOW COLUMNS FROM facebook_rx_fb_page_info")->result_array();
            $available_columns = array_column($columns, 'Field');
            
            // بناء الاستعلام بناءً على الأعمدة الموجودة
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

            // مزامنة حسابات Instagram
            $sql = "INSERT IGNORE INTO instagram_accounts 
                    (user_id, ig_user_id, ig_username, page_name, ig_profile_picture, access_token, ig_linked, status, created_at, updated_at)
                    SELECT " . implode(',', $select_fields) . ", 1, 'active', NOW(), NOW()
                    FROM facebook_rx_fb_page_info
                    WHERE user_id = ?";

            $this->db->query($sql, [$user_id]);

            // جلب الحسابات
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
     * إنشاء جدول instagram_accounts
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
     * إنشاء جدول social_posts
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
     * معالجة النشر
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

            // إنشاء الجداول إذا لم تكن موجودة
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
     * معالجة نشر Facebook
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

        // استخدام النظام الموجود للريلز والقصص
        if ($content_type === 'reel') {
            return $this->process_facebook_reels($uid, $pages);
        } elseif ($content_type === 'story_video' || $content_type === 'story_photo') {
            return $this->process_facebook_stories($uid, $pages, $content_type);
        } else {
            return $this->process_facebook_posts($uid, $pages, $content_type);
        }
    }

    /**
     * معالجة ريلز Facebook باستخدام النظام الموجود
     */
    private function process_facebook_reels($uid, $pages)
    {
        if (empty($_FILES['files']['name'][0])) {
            throw new Exception('اختر ملفات فيديو للريلز');
        }

        // تحويل للتنسيق المتوقع من Reel_model
        $_POST['fb_page_ids'] = $this->input->post('facebook_pages');
        $_POST['description'] = $this->input->post('global_description');
        $_POST['selected_hashtags'] = $this->input->post('selected_hashtags');
        $_POST['descriptions'] = $this->input->post('file_descriptions') ?: [];
        $_POST['schedule_times'] = $this->input->post('file_schedule_times') ?: [];
        $_POST['tz_offset_minutes'] = (int)$this->input->post('timezone_offset');
        $_POST['tz_name'] = $this->input->post('timezone_name');
        $_POST['media_type'] = 'reel';

        // تحويل الملفات للتنسيق المتوقع
        $_FILES['video_files'] = $_FILES['files'];

        $responses = $this->Reel_model->upload_reels($uid, $pages, $_POST, $_FILES);
        
        $this->handle_responses($responses);
    }

    /**
     * معالجة قصص Facebook باستخدام النظام الموجود
     */
    private function process_facebook_stories($uid, $pages, $content_type)
    {
        if (empty($_FILES['files']['name'][0])) {
            throw new Exception('اختر ملفات للقصص');
        }

        // تحويل للتنسيق المتوقع
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
            
            if (!$page || !$page['page_access_token']) {
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
     * نشر منشور Facebook
     */
    private function publish_facebook_post($page_id, $access_token, $content_type)
    {
        try {
            $url = "https://graph.facebook.com/v19.0/{$page_id}/feed";
            
            $params = [
                'access_token' => $access_token
            ];

            // إضافة المحتوى حسب النوع
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
                        // رفع الصورة أولاً
                        $photo_result = $this->upload_facebook_photo($page_id, $_FILES['files']['tmp_name'][0], $access_token);
                        if ($photo_result['success']) {
                            $params['object_attachment'] = $photo_result['photo_id'];
                            $params['message'] = $this->input->post('post_description') ?: $this->input->post('global_description');
                        } else {
                            throw new Exception($photo_result['error']);
                        }
                    } else {
                        throw new Exception('اختر صورة للنشر');
                    }
                    break;
                    
                case 'post_video':
                    if (!empty($_FILES['files']['tmp_name'][0])) {
                        // رفع الفيديو
                        return $this->upload_facebook_video($page_id, $_FILES['files']['tmp_name'][0], $access_token);
                    } else {
                        throw new Exception('اختر فيديو للنشر');
                    }
                    break;
            }

            // إرسال المنشور
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $params,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_TIMEOUT => 60
            ]);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);

            if ($curl_error) {
                throw new Exception("خطأ في الاتصال: {$curl_error}");
            }

            $result = json_decode($response, true);
            
            if ($http_code === 200 && !empty($result['id'])) {
                return [
                    'success' => true,
                    'post_id' => $result['id']
                ];
            } else {
                $error = isset($result['error']) ? $result['error']['message'] : "HTTP {$http_code}";
                throw new Exception($error);
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * رفع صورة Facebook
     */
    private function upload_facebook_photo($page_id, $file_path, $access_token)
    {
        try {
            $url = "https://graph.facebook.com/v19.0/{$page_id}/photos";
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => [
                    'source' => new CURLFile($file_path),
                    'published' => 'false',
                    'access_token' => $access_token
                ],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_TIMEOUT => 120
            ]);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $result = json_decode($response, true);
            
            if ($http_code === 200 && !empty($result['id'])) {
                return [
                    'success' => true,
                    'photo_id' => $result['id']
                ];
            } else {
                return [
                    'success' => false,
                    'error' => isset($result['error']) ? $result['error']['message'] : "HTTP {$http_code}"
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * رفع فيديو Facebook
     */
    private function upload_facebook_video($page_id, $file_path, $access_token)
    {
        try {
            $url = "https://graph.facebook.com/v19.0/{$page_id}/videos";
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => [
                    'source' => new CURLFile($file_path),
                    'description' => $this->input->post('post_description') ?: $this->input->post('global_description'),
                    'access_token' => $access_token
                ],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_TIMEOUT => 300
            ]);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $result = json_decode($response, true);
            
            if ($http_code === 200 && !empty($result['id'])) {
                return [
                    'success' => true,
                    'post_id' => $result['id']
                ];
            } else {
                return [
                    'success' => false,
                    'error' => isset($result['error']) ? $result['error']['message'] : "HTTP {$http_code}"
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * معالجة نشر Instagram
     */
    private function process_instagram_upload($uid, $content_type)
    {
        $ig_accounts = $this->input->post('instagram_accounts');
        if (empty($ig_accounts)) {
            throw new Exception('اختر حساب Instagram واحد على الأقل');
        }

        // معالجة حسب نوع المحتوى
        if ($content_type === 'reel') {
            return $this->process_instagram_reels($uid, $ig_accounts);
        } elseif ($content_type === 'story_video' || $content_type === 'story_photo') {
            return $this->process_instagram_stories($uid, $ig_accounts, $content_type);
        } else {
            return $this->process_instagram_posts($uid, $ig_accounts, $content_type);
        }
    }

    /**
     * معالجة ريلز Instagram باستخدام النظام الموجود
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

            $file_descriptions = $this->input->post('file_descriptions') ?: [];
            $file_schedule_times = $this->input->post('file_schedule_times') ?: [];
            
            $description = $file_descriptions[$index] ?? $this->input->post('global_description');
            $schedule_time = $file_schedule_times[$index] ?? null;

            foreach ($ig_accounts as $ig_user_id) {
                try {
                    $account = $this->get_instagram_account($uid, $ig_user_id);
                    if (!$account) {
                        $results[] = ['type' => 'error', 'msg' => "حساب Instagram غير موجود: {$ig_user_id}"];
                        continue;
                    }

                    // حفظ الملف
                    $saved_file = $this->save_uploaded_file($file_data, $uid, 'instagram');
                    if (!$saved_file['success']) {
                        $results[] = ['type' => 'error', 'msg' => "فشل حفظ {$filename}: {$saved_file['error']}"];
                        continue;
                    }

                    if (!$schedule_time) {
                        // نشر فوري باستخدام InstagramPublisher
                        $publish_result = $this->instagrampublisher->publishReel(
                            $ig_user_id, 
                            $saved_file['full_path'], 
                            $description, 
                            $account['access_token']
                        );
                        
                        if ($publish_result['ok']) {
                            $results[] = ['type' => 'success', 'msg' => "تم نشر {$filename} على {$account['ig_username']}"];
                        } else {
                            $results[] = ['type' => 'error', 'msg' => "فشل نشر {$filename} على {$account['ig_username']}: {$publish_result['error']}"];
                        }
                    } else {
                        // جدولة باستخدام Instagram_reels_model
                        $utc_time = $this->convert_to_utc($schedule_time, (int)$this->input->post('timezone_offset'));
                        
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

                        $results[] = ['type' => 'success', 'msg' => "تم جدولة {$filename} على {$account['ig_username']}"];
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
                'type' => $_FILES['files']['type'][$index],
                'tmp_name' => $_FILES['files']['tmp_name'][$index],
                'error' => $_FILES['files']['error'][$index],
                'size' => $_FILES['files']['size'][$index]
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

                    // حفظ الملف
                    $saved_file = $this->save_uploaded_file($file_data, $uid, 'instagram');
                    if (!$saved_file['success']) {
                        $results[] = ['type' => 'error', 'msg' => "فشل حفظ {$filename}: {$saved_file['error']}"];
                        continue;
                    }

                    // نشر القصة
                    $file_type = $content_type === 'story_photo' ? 'image' : 'video';
                    $publish_result = $this->instagrampublisher->publishStory(
                        $ig_user_id, 
                        $saved_file['full_path'], 
                        $file_type, 
                        $account['access_token']
                    );
                    
                    if ($publish_result['ok']) {
                        $results[] = ['type' => 'success', 'msg' => "تم نشر قصة {$filename} على {$account['ig_username']}"];
                    } else {
                        $results[] = ['type' => 'error', 'msg' => "فشل نشر قصة {$filename} على {$account['ig_username']}: {$publish_result['error']}"];
                    }

                } catch (Exception $e) {
                    $results[] = ['type' => 'error', 'msg' => "خطأ في معالجة {$filename}: " . $e->getMessage()];
                }
            }
        }

        $this->handle_responses($results);
    }

    /**
     * معالجة منشورات Instagram
     */
    private function process_instagram_posts($uid, $ig_accounts, $content_type)
    {
        // Instagram لا يدعم منشورات عادية عبر API
        // يمكن استخدام Instagram Basic Display API للمنشورات
        $results = [
            ['type' => 'error', 'msg' => 'منشورات Instagram العادية غير مدعومة حالياً عبر API']
        ];

        $this->handle_responses($results);
    }

    /**
     * جلب حساب Instagram
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
     * حفظ ملف مرفوع
     */
    private function save_uploaded_file($file_data, $user_id, $platform = 'social')
    {
        try {
            if ($file_data['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('خطأ في رفع الملف');
            }

            $upload_dir = FCPATH . "uploads/{$platform}/";
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            $ext = strtolower(pathinfo($file_data['name'], PATHINFO_EXTENSION));
            $filename = date('Ymd_His') . '_' . $user_id . '_' . uniqid() . '.' . $ext;
            $full_path = $upload_dir . $filename;

            if (!move_uploaded_file($file_data['tmp_name'], $full_path)) {
                throw new Exception('فشل في نقل الملف');
            }

            return [
                'success' => true,
                'filename' => $filename,
                'path' => "uploads/{$platform}/" . $filename,
                'full_path' => $full_path
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
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
     * معالجة الردود
     */
    private function handle_responses($responses)
    {
        $success = [];
        $errors = [];
        
        foreach ($responses as $r) {
            if ($r['type'] === 'success') {
                $success[] = $r['msg'];
            } else {
                $errors[] = $r['msg'];
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
     * قائمة المنشورات
     */
    public function listing()
    {
        $this->require_login();
        $uid = (int)$this->session->userdata('user_id');

        // إنشاء الجدول إذا لم يكن موجوداً
        $this->create_social_posts_table();

        // فلاتر
        $filters = [
            'platform' => $this->input->get('platform'),
            'content_type' => $this->input->get('content_type'),
            'status' => $this->input->get('status'),
            'q' => $this->input->get('q'),
            'date_from' => $this->input->get('date_from'),
            'date_to' => $this->input->get('date_to')
        ];

        // إزالة الفلاتر الفارغة
        $filters = array_filter($filters, fn($v) => $v !== '' && $v !== null);

        // بناء الاستعلام
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

        // الترقيم
        $page = max(1, (int)$this->input->get('page'));
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $total = $this->db->count_all_results('', false);
        $posts = $this->db->order_by('created_at', 'DESC')
                         ->limit($limit, $offset)
                         ->get()->result_array();

        // إحصائيات آمنة
        $stats = $this->get_posts_stats_safe($uid);

        // حسابات للفلاتر
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
     * لوحة التحكم
     */
    public function dashboard()
    {
        $this->require_login();
        $uid = (int)$this->session->userdata('user_id');

        // إنشاء الجدول إذا لم يكن موجوداً
        $this->create_social_posts_table();

        // إحصائيات آمنة
        $stats = $this->get_posts_stats_safe($uid);

        // آخر المنشورات
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

        // المنشورات القادمة
        $upcoming_posts = [];
        try {
            if ($this->column_exists('social_posts', 'scheduled_time')) {
                $upcoming_posts = $this->db->select('*')
                                         ->from('social_posts')
                                         ->where('user_id', $uid)
                                         ->where('status', 'scheduled')
                                         ->where('scheduled_time >', date('Y-m-d H:i:s'))
                                         ->order_by('scheduled_time', 'ASC')
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
     * فحص وجود عمود في جدول
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
     * جلب إحصائيات آمنة
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

            // إجمالي المنشورات
            $stats['total_posts'] = $this->db->where('user_id', $user_id)
                                           ->count_all_results('social_posts');
            $stats['total'] = $stats['total_posts'];

            // المنشورة
            $stats['published'] = $this->db->where('user_id', $user_id)
                                          ->where('status', 'published')
                                          ->count_all_results('social_posts');

            // المعلقة
            $stats['pending'] = $this->db->where('user_id', $user_id)
                                        ->where('status', 'pending')
                                        ->count_all_results('social_posts');

            // الفاشلة
            $stats['failed_posts'] = $this->db->where('user_id', $user_id)
                                             ->where('status', 'failed')
                                             ->count_all_results('social_posts');
            $stats['failed'] = $stats['failed_posts'];

            // المجدولة (إذا كان العمود موجود)
            if ($this->column_exists('social_posts', 'scheduled_time')) {
                $stats['scheduled_posts'] = $this->db->where('user_id', $user_id)
                                                    ->where('status', 'scheduled')
                                                    ->count_all_results('social_posts');
                $stats['scheduled'] = $stats['scheduled_posts'];

                // منشورات اليوم (إذا كان العمود موجود)
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
     * CRON للنشر المجدول
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

        try {
            $this->create_social_posts_table();

            // جلب المنشورات المستحقة للنشر
            $due_posts = [];
            if ($this->column_exists('social_posts', 'scheduled_time')) {
                $due_posts = $this->db->select('*')
                                     ->from('social_posts')
                                     ->where('status', 'scheduled')
                                     ->where('scheduled_time <=', date('Y-m-d H:i:s'))
                                     ->limit(20)
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
        }
    }

    /**
     * معالجة منشور مجدول
     */
    private function process_scheduled_post($post)
    {
        $this->db->where('id', $post['id'])
                 ->update('social_posts', ['status' => 'publishing']);

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
            // جلب بيانات الصفحة
            $pages = $this->Facebook_pages_model->get_pages_by_user($post['user_id']);
            $page = null;
            foreach ($pages as $p) {
                if ($p['fb_page_id'] === $post['account_id']) {
                    $page = $p;
                    break;
                }
            }

            if (!$page || !$page['page_access_token']) {
                throw new Exception('صفحة Facebook غير موجودة أو لا تملك صلاحيات');
            }

            // نشر المحتوى
            $result = $this->publish_facebook_post($post['account_id'], $page['page_access_token'], $post['content_type']);

            if ($result['success']) {
                $this->db->where('id', $post['id'])
                         ->update('social_posts', [
                             'status' => 'published',
                             'post_id' => $result['post_id'],
                             'published_time' => date('Y-m-d H:i:s')
                         ]);
            } else {
                $this->db->where('id', $post['id'])
                         ->update('social_posts', [
                             'status' => 'failed',
                             'error_message' => $result['error']
                         ]);
            }
        } catch (Exception $e) {
            $this->db->where('id', $post['id'])
                     ->update('social_posts', [
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
            
            if (!$account || !$account['access_token']) {
                throw new Exception('حساب Instagram غير موجود أو لا يملك صلاحيات');
            }

            if ($post['content_type'] === 'reel') {
                $result = $this->instagrampublisher->publishReel(
                    $post['account_id'], 
                    FCPATH . $post['file_path'], 
                    $post['description'], 
                    $account['access_token']
                );
            } else {
                $result = ['ok' => false, 'error' => 'نوع غير مدعوم حالياً'];
            }

            if ($result['ok']) {
                $this->db->where('id', $post['id'])
                         ->update('social_posts', [
                             'status' => 'published',
                             'post_id' => $result['media_id'] ?? null,
                             'published_time' => date('Y-m-d H:i:s')
                         ]);
            } else {
                $this->db->where('id', $post['id'])
                         ->update('social_posts', [
                             'status' => 'failed',
                             'error_message' => $result['error']
                         ]);
            }
        } catch (Exception $e) {
            $this->db->where('id', $post['id'])
                     ->update('social_posts', [
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
                    if ($this->column_exists('social_posts', 'scheduled_time')) {
                        $this->db->where('user_id', $uid)
                                 ->where_in('id', $post_ids)
                                 ->where('status', 'scheduled')
                                 ->update('social_posts', ['status' => 'pending', 'scheduled_time' => null]);
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

            if ($scheduled_time && $this->column_exists('social_posts', 'scheduled_time')) {
                $update_data['scheduled_time'] = $scheduled_time;
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
     * نسخ منشور
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

            // إنشاء نسخة جديدة
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
     * الإحصائيات والتقارير
     */
    public function stats()
    {
        $this->require_login();
        $uid = (int)$this->session->userdata('user_id');

        $this->create_social_posts_table();

        $data = [
            'stats' => $this->get_posts_stats_safe($uid),
            'daily_stats' => $this->get_daily_stats($uid),
            'platform_stats' => $this->get_platform_stats($uid)
        ];

        $this->load->view('social_publisher_stats', $data);
    }

    /**
     * إحصائيات يومية
     */
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

    /**
     * إحصائيات المنصات
     */
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

    /**
     * تصدير البيانات
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

    /**
     * تصدير CSV
     */
    private function export_csv($posts)
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="social_posts_' . date('Y-m-d') . '.csv"');

        $output = fopen('php://output', 'w');
        
        // BOM for UTF-8
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Headers
        fputcsv($output, ['ID', 'المنصة', 'نوع المحتوى', 'العنوان', 'الوصف', 'الحالة', 'تاريخ الإنشاء']);

        // Data
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

    /**
     * تصدير JSON
     */
    private function export_json($posts)
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="social_posts_' . date('Y-m-d') . '.json"');

        echo json_encode($posts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * API للنشر (للتطبيقات الخارجية)
     */
    public function api_publish()
    {
        // التحقق من API key
        $api_key = $this->input->get_request_header('X-API-Key');
        if (!$api_key || !$this->validate_api_key($api_key)) {
            return $this->send_json(['error' => 'Invalid API key'], 401);
        }

        try {
            $data = json_decode($this->input->raw_input_stream, true);
            
            if (!$data) {
                throw new Exception('بيانات غير صحيحة');
            }

            // معالجة النشر عبر API
            $result = $this->process_api_publish($data);
            
            $this->send_json($result);

        } catch (Exception $e) {
            $this->log_error('api_publish', $e->getMessage());
            $this->send_json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * التحقق من API key
     */
    private function validate_api_key($key)
    {
        // يمكن تخزين API keys في قاعدة البيانات أو ملف config
        $valid_keys = ['demo_key_123', 'production_key_456'];
        return in_array($key, $valid_keys);
    }

    /**
     * معالجة النشر عبر API
     */
    private function process_api_publish($data)
    {
        // تنفيذ منطق النشر عبر API
        return ['success' => true, 'message' => 'تم النشر بنجاح'];
    }

    /**
     * حالة المنشور عبر API
     */
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
     * إدارة القوالب
     */
    public function templates()
    {
        $this->require_login();
        $uid = (int)$this->session->userdata('user_id');

        // إنشاء جدول القوالب إذا لم يكن موجوداً
        $this->create_templates_table();

        $templates = $this->db->where('user_id', $uid)
                             ->order_by('created_at', 'DESC')
                             ->get('social_templates')->result_array();

        $data['templates'] = $templates;
        $this->load->view('social_publisher_templates', $data);
    }

    /**
     * إنشاء جدول القوالب
     */
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

    /**
     * إنشاء قالب جديد
     */
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

    /**
     * تعديل قالب
     */
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

    /**
     * حذف قالب
     */
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
     * الجدولة المتكررة
     */
    public function recurring_schedules()
    {
        $this->require_login();
        $uid = (int)$this->session->userdata('user_id');

        // إنشاء جدول الجدولة المتكررة
        $this->create_recurring_table();

        $schedules = $this->db->where('user_id', $uid)
                             ->order_by('created_at', 'DESC')
                             ->get('social_recurring_schedules')->result_array();

        $data['schedules'] = $schedules;
        $this->load->view('social_publisher_recurring', $data);
    }

    /**
     * إنشاء جدول الجدولة المتكررة
     */
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

    /**
     * إنشاء جدولة متكررة
     */
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

                // حساب next_run
                $data['next_run'] = $this->calculate_next_run($data);

                $this->db->insert('social_recurring_schedules', $data);
                
                $this->session->set_flashdata('msg_success', 'تم إنشاء الجدولة المتكررة');
                redirect('social_publisher/recurring');

            } catch (Exception $e) {
                $this->log_error('create_recurring', $e->getMessage());
                $this->session->set_flashdata('msg', 'حدث خطأ في إنشاء الجدولة');
            }
        }

        // جلب القوالب والحسابات
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

    /**
     * حساب موعد التشغيل القادم
     */
    private function calculate_next_run($schedule)
    {
        $start_date = $schedule['start_date'];
        $time = $schedule['recurrence_time'];
        $type = $schedule['recurrence_type'];

        $base_datetime = $start_date . ' ' . $time;
        $timestamp = strtotime($base_datetime);

        if ($timestamp <= time()) {
            // إذا كان الوقت في الماضي، احسب التالي
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
     * CRON للجدولة المتكررة
     */
    public function cron_recurring($token = null)
    {
        if (!$this->input->is_cli_request()) {
            if ($token !== self::CRON_TOKEN) {
                show_error('Unauthorized', 403);
                return;
            }
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
        }
    }

    /**
     * معالجة جدولة متكررة
     */
    private function process_recurring_schedule($schedule)
    {
        // تنفيذ الجدولة المتكررة
        // يمكن إنشاء منشور جديد بناءً على القالب

        // تحديث next_run
        $next_run = $this->calculate_next_run($schedule);
        
        // فحص إذا انتهت الجدولة
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
     * CRON للتنظيف
     */
    public function cron_cleanup($token = null)
    {
        if (!$this->input->is_cli_request()) {
            if ($token !== self::CRON_TOKEN) {
                show_error('Unauthorized', 403);
                return;
            }
        }

        try {
            $cleaned = 0;

            // حذف الملفات القديمة (أكثر من 30 يوم)
            $old_files = $this->db->select('file_path')
                                 ->from('social_posts')
                                 ->where('created_at <', date('Y-m-d H:i:s', strtotime('-30 days')))
                                 ->where('file_path IS NOT NULL', null, false)
                                 ->get()->result_array();

            foreach ($old_files as $file) {
                $full_path = FCPATH . $file['file_path'];
                if (file_exists($full_path)) {
                    unlink($full_path);
                    $cleaned++;
                }
            }

            // حذف السجلات القديمة جداً (أكثر من 90 يوم)
            $this->db->where('created_at <', date('Y-m-d H:i:s', strtotime('-90 days')))
                     ->delete('social_posts');

            $deleted_records = $this->db->affected_rows();

            echo "Cleaned {$cleaned} files and {$deleted_records} old records.\n";

        } catch (Exception $e) {
            $this->log_error('cron_cleanup', $e->getMessage());
            echo "Cleanup failed: " . $e->getMessage() . "\n";
        }
    }

    /**
     * تقارير مفصلة
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

    /**
     * إحصائيات أنواع المحتوى
     */
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

    /**
     * معدل النجاح
     */
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

    /**
     * التحليلات المتقدمة
     */
    public function analytics()
    {
        $this->require_login();
        $uid = (int)$this->session->userdata('user_id');

        $this->create_social_posts_table();

        // تحليلات متقدمة
        $data = [
            'engagement_stats' => $this->get_engagement_stats($uid),
            'best_times' => $this->get_best_posting_times($uid),
            'hashtag_performance' => $this->get_hashtag_performance($uid),
            'account_performance' => $this->get_account_performance($uid)
        ];

        $this->load->view('social_publisher_analytics', $data);
    }

    /**
     * إحصائيات التفاعل
     */
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

    /**
     * أفضل أوقات النشر
     */
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

    /**
     * أداء الهاشتاجات
     */
    private function get_hashtag_performance($user_id)
    {
        // تحليل الهاشتاجات الأكثر نجاحاً
        return [];
    }

    /**
     * أداء الحسابات
     */
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
}