<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Social Content Model
 * - إدارة النشر المتعدد على Facebook و Instagram
 */
class Social_content_model extends CI_Model
{
    // أنواع المحتوى المدعومة
    const POST_TYPES = [
        'facebook' => ['text', 'image', 'video', 'carousel', 'reel', 'story_photo', 'story_video'],
        'instagram' => ['text', 'image', 'video', 'carousel', 'reel', 'story_photo', 'story_video']
    ];

    // أنواع التكرار
    const RECURRENCE_TYPES = ['daily', 'weekly', 'monthly', 'quarterly'];

    // حد أقصى للملفات والتعليقات
    const MAX_FILES_PER_POST = 10;
    const MAX_COMMENTS_PER_POST = 20;

    // امتدادات مسموحة عامة (تستخدم للتحقق السطحي)
    private $allowed_extensions = ['jpg','jpeg','png','gif','mp4','mov','avi','m4v'];

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->load->helper('date');
    }

    /* ======================== إدارة الحسابات ======================== */

    /**
     * جلب حسابات Facebook للمستخدم (يستخدم Facebook_pages_model الحالي)
     */
    public function get_facebook_accounts($user_id)
    {
        $this->load->model('Facebook_pages_model', 'fb_model');
        return $this->fb_model->get_pages_by_user($user_id);
    }

    /**
     * جلب حسابات Instagram للمستخدم (آمنة مع فحص جدوى الجدول)
     */
    public function get_instagram_accounts($user_id)
    {
        // جدول instagram_rx_accounts مستخدم هنا
        if (!$this->db->table_exists('instagram_rx_accounts')) {
            return [];
        }

        $this->db->select('
            ig_user_id,
            username,
            full_name,
            profile_picture_url,
            access_token,
            health_status,
            is_business_account,
            follower_count,
            last_sync_at
        ');
        $this->db->where('user_id', $user_id);
        $this->db->where('health_status !=', 'revoked');
        $this->db->order_by('username', 'ASC');

        return $this->db->get('instagram_rx_accounts')->result_array();
    }

    /* ======================== إنشاء المنشورات ======================== */

    /**
     * إنشاء منشور أو جدولته
     * يُرجع مصفوفة نتائج لكل حساب تم معالجته
     */
    public function create_social_post($user_id, $data)
    {
        // Validate basic shape first
        $validation = $this->validate_post_data($data);
        if (!$validation['valid']) {
            return [['success' => false, 'error' => $validation['error'], 'account_id' => null]];
        }

        $responses = [];
        $accounts = $data['accounts'] ?? [];
        $platform = $data['platform'] ?? 'facebook';

        foreach ($accounts as $account_id) {
            if (($data['publish_mode'] ?? 'immediate') === 'immediate') {
                // نشر فوري
                $result = $this->publish_immediate($user_id, $platform, $account_id, $data);
                $responses[] = $result;
            } else {
                // جدولة
                $result = $this->schedule_post($user_id, $platform, $account_id, $data);
                $responses[] = $result;
            }
        }

        return $responses;
    }

    /**
     * نشر فوري: حفظ سجل ثم محاولة النشر
     */
    private function publish_immediate($user_id, $platform, $account_id, $data)
    {
        try {
            // حفظ الملفات (إن وُجدت)
            $media_data = $this->process_media_files($data['files'] ?? []);

            // إعداد بيانات السجل
            $post_data = [
                'user_id' => $user_id,
                'platform' => $platform,
                'platform_account_id' => $account_id,
                'post_type' => $data['post_type'],
                'content_text' => $data['content_text'] ?? '',
                'media_files' => json_encode($media_data['files_info']),
                'media_paths' => json_encode($media_data['saved_paths']),
                'status' => 'pending',
                'created_at' => date('Y-m-d H:i:s')
            ];

            $this->db->insert('social_posts', $post_data);
            $post_id = $this->db->insert_id();

            // حفظ التعليقات المرتبطة إذا وُجدت
            $this->save_post_comments($post_id, $user_id, $platform, $account_id, $data['comments'] ?? []);

            // تنفيذ النشر الآن
            $publish_result = $this->execute_post_publish($post_id);

            return [
                'success' => !empty($publish_result['success']),
                'post_id' => $post_id,
                'account_id' => $account_id,
                'message' => $publish_result['message'] ?? ($publish_result['error'] ?? '')
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'account_id' => $account_id,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * جدولة منشور: إدراج سجل لكل توقيت مجدول
     */
    private function schedule_post($user_id, $platform, $account_id, $data)
    {
        try {
            $media_data = $this->process_media_files($data['files'] ?? []);

            $schedules = $this->process_schedule_times($data);

            $created_posts = [];

            foreach ($schedules as $schedule_time) {
                $post_data = [
                    'user_id' => $user_id,
                    'platform' => $platform,
                    'platform_account_id' => $account_id,
                    'post_type' => $data['post_type'],
                    'content_text' => $data['content_text'] ?? '',
                    'media_files' => json_encode($media_data['files_info']),
                    'media_paths' => json_encode($media_data['saved_paths']),
                    'scheduled_at' => $schedule_time['utc'],
                    'original_local_time' => $schedule_time['local'],
                    'original_offset_minutes' => $schedule_time['offset'],
                    'original_timezone' => $schedule_time['timezone'],
                    'status' => 'pending',
                    'created_at' => date('Y-m-d H:i:s')
                ];

                $this->db->insert('social_posts', $post_data);
                $post_id = $this->db->insert_id();
                $created_posts[] = $post_id;

                // حفظ تعليقات الجدولة إن وُجدت
                $this->save_post_comments($post_id, $user_id, $platform, $account_id, $data['comments'] ?? [], $schedule_time);
            }

            return [
                'success' => true,
                'account_id' => $account_id,
                'scheduled_posts' => count($created_posts),
                'message' => 'تمت الجدولة بنجاح'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'account_id' => $account_id,
                'error' => $e->getMessage()
            ];
        }
    }

    /* ======================== معالجة الملفات ======================== */

    /**
     * معالجة وحفظ ملفات الوسائط
     * - يتوقع مصفوفة $files مشابهة لهيكل $_FILES بعد تنسيقها (name,tmp_name,size,error)
     */
    private function process_media_files($files)
    {
        if (empty($files)) {
            return ['files_info' => [], 'saved_paths' => []];
        }

        $upload_dir = FCPATH . 'uploads/social_content/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0775, true);
        }

        $files_info = [];
        $saved_paths = [];

        foreach ($files as $key => $file) {
            // تأكد من وجود الملف (قد يكون مُعد مسبقاً في publish_immediate أو upload)
            if (!is_uploaded_file($file['tmp_name']) && !file_exists($file['tmp_name'])) {
                continue;
            }

            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($extension, $this->allowed_extensions)) {
                continue;
            }

            // تحقق MIME عبر finfo
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            $image_exts = ['jpg', 'jpeg', 'png', 'gif'];
            $video_exts = ['mp4', 'mov', 'avi', 'm4v'];

            if (in_array($extension, $image_exts) && strpos($mime, 'image/') !== 0) {
                continue;
            }
            if (in_array($extension, $video_exts) && strpos($mime, 'video/') !== 0) {
                continue;
            }

            $filename = 'social_' . time() . '_' . mt_rand(1000, 9999) . '.' . $extension;
            $filepath = $upload_dir . $filename;

            // محاولة نقل الملف بأمان؛ إذا لم يكن مرفوعاً بواسطة PHP (موجود كمسار) جرب rename
            if (is_uploaded_file($file['tmp_name'])) {
                $moved = move_uploaded_file($file['tmp_name'], $filepath);
            } else {
                $moved = rename($file['tmp_name'], $filepath);
            }

            if ($moved) {
                $files_info[] = [
                    'original_name' => $file['name'],
                    'size' => $file['size'] ?? filesize($filepath),
                    'type' => $this->get_media_type($extension),
                    'extension' => $extension,
                    'mime' => $mime
                ];
                $saved_paths[] = 'uploads/social_content/' . $filename;
            }
        }

        return ['files_info' => $files_info, 'saved_paths' => $saved_paths];
    }
    /* ======================== النشر والتنفيذ ======================== */

    /**
     * تنفيذ نشر منشور
     */
    public function execute_post_publish($post_id)
    {
        $post = $this->db->where('id', $post_id)->get('social_posts')->row_array();
        if (!$post) {
            return ['success' => false, 'error' => 'منشور غير موجود'];
        }

        // محاولة تحديث attempt_count بأمان (تحقق من وجود العمود)
        try {
            // رفع مؤقت لقيمة attempt_count الافتراضية
            $attempts = isset($post['attempt_count']) ? (int)$post['attempt_count'] : 0;
            $this->db->where('id', $post_id)->update('social_posts', [
                'attempt_count' => $attempts + 1,
                'processing' => 1,
                'last_attempt_at' => date('Y-m-d H:i:s'),
                'status' => 'processing'
            ]);
        } catch (Exception $e) {
            // تجاهل إذا لم تكن الأعمدة موجودة
        }

        try {
            $result = ['success' => false, 'error' => 'غير معروف'];

            if ($post['platform'] === 'facebook') {
                $result = $this->publish_to_facebook($post);
            } elseif ($post['platform'] === 'instagram') {
                $result = $this->publish_to_instagram($post);
            } else {
                $result = ['success' => false, 'error' => 'منصة غير مدعومة'];
            }

            // تحديث حالة المنشور بناءً على النتيجة
            $update_data = [
                'processing' => 0,
                'status' => $result['success'] ? 'published' : 'failed',
                'last_error' => $result['success'] ? null : ($result['error'] ?? 'خطأ غير معروف')
            ];

            if ($result['success']) {
                if (!empty($result['post_id'])) {
                    $update_data['platform_post_id'] = $result['post_id'];
                }
                $update_data['published_time'] = date('Y-m-d H:i:s');
            }

            $this->db->where('id', $post_id)->update('social_posts', $update_data);

            return $result;

        } catch (Exception $e) {
            $this->db->where('id', $post_id)->update('social_posts', [
                'processing' => 0,
                'status' => 'failed',
                'last_error' => $e->getMessage()
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * النشر على Facebook - واجهة عليا تختار الطريقة
     */
    private function publish_to_facebook($post)
    {
        $account = $this->get_facebook_account_by_id($post['platform_account_id']);
        if (!$account || empty($account['page_access_token'])) {
            return ['success' => false, 'error' => 'حساب Facebook غير صالح'];
        }

        $access_token = $account['page_access_token'];
        $page_id = $post['platform_account_id'];

        try {
            switch ($post['post_type']) {
                case 'text':
                    return $this->publish_facebook_text($page_id, $access_token, $post);
                case 'image':
                    return $this->publish_facebook_image($page_id, $access_token, $post);
                case 'video':
                    return $this->publish_facebook_video($page_id, $access_token, $post);
                case 'carousel':
                    return $this->publish_facebook_carousel($page_id, $access_token, $post);
                case 'reel':
                    return $this->publish_facebook_reel($page_id, $access_token, $post);
                case 'story_photo':
                    return $this->publish_facebook_story_photo($page_id, $access_token, $post);
                case 'story_video':
                    return $this->publish_facebook_story_video($page_id, $access_token, $post);
                default:
                    return ['success' => false, 'error' => 'نوع منشور غير مدعوم'];
            }
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * النشر على Instagram - واجهة عليا تختار الطريقة
     */
    private function publish_to_instagram($post)
    {
        $account = $this->get_instagram_account_by_id($post['platform_account_id']);
        if (!$account || empty($account['access_token'])) {
            return ['success' => false, 'error' => 'حساب Instagram غير صالح'];
        }

        $access_token = $account['access_token'];
        $ig_user_id = $post['platform_account_id'];

        try {
            switch ($post['post_type']) {
                case 'text':
                    return $this->publish_instagram_text($ig_user_id, $access_token, $post);
                case 'image':
                    return $this->publish_instagram_image($ig_user_id, $access_token, $post);
                case 'video':
                    return $this->publish_instagram_video($ig_user_id, $access_token, $post);
                case 'carousel':
                    return $this->publish_instagram_carousel($ig_user_id, $access_token, $post);
                case 'reel':
                    return $this->publish_instagram_reel($ig_user_id, $access_token, $post);
                case 'story_photo':
                    return $this->publish_instagram_story_photo($ig_user_id, $access_token, $post);
                case 'story_video':
                    return $this->publish_instagram_story_video($ig_user_id, $access_token, $post);
                default:
                    return ['success' => false, 'error' => 'نوع منشور غير مدعوم على Instagram'];
            }
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /* ======================== Facebook Publishing Methods (مبسطة) ======================== */

    private function publish_facebook_text($page_id, $access_token, $post)
    {
        $url = "https://graph.facebook.com/v23.0/{$page_id}/feed";
        $data = [
            'message' => $post['content_text'],
            'access_token' => $access_token
        ];

        try {
            $response = $this->make_api_request($url, $data);
            return $this->handle_facebook_response($response);
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function publish_facebook_single_image($page_id, $access_token, $post, $image_path)
    {
        $url = "https://graph.facebook.com/v23.0/{$page_id}/photos";
        $image_file = FCPATH . ltrim($image_path, '/');

        if (!file_exists($image_file)) {
            return ['success' => false, 'error' => 'ملف الصورة غير موجود'];
        }

        $data = [
            'message' => $post['content_text'] ?? '',
            'source' => new CURLFile($image_file, mime_content_type($image_file)),
            'access_token' => $access_token
        ];

        try {
            $response = $this->make_api_request($url, $data, true);
            return $this->handle_facebook_response($response);
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function publish_facebook_image($page_id, $access_token, $post)
    {
        $media_paths = json_decode($post['media_paths'], true);
        if (empty($media_paths)) {
            return ['success' => false, 'error' => 'لا توجد صور للنشر'];
        }

        if (count($media_paths) === 1) {
            return $this->publish_facebook_single_image($page_id, $access_token, $post, $media_paths[0]);
        }

        // عدة صور: استخدم object_attachment أو منشور مع attachments (مبسط هنا: رفع أول صورة ونشرها مع رسالة)
        return $this->publish_facebook_single_image($page_id, $access_token, $post, $media_paths[0]);
    }

    private function publish_facebook_video($page_id, $access_token, $post)
    {
        $media_paths = json_decode($post['media_paths'], true);
        if (empty($media_paths)) {
            return ['success' => false, 'error' => 'لا توجد فيديوهات للنشر'];
        }

        $video_file = FCPATH . ltrim($media_paths[0], '/');
        if (!file_exists($video_file)) {
            return ['success' => false, 'error' => 'ملف الفيديو غير موجود'];
        }

        // رفع فيديو عبر endpoint /videos
        $url = "https://graph.facebook.com/v23.0/{$page_id}/videos";
        $data = [
            'file' => new CURLFile($video_file, mime_content_type($video_file)),
            'description' => $post['content_text'] ?? '',
            'access_token' => $access_token
        ];

        try {
            $response = $this->make_api_request($url, $data, true);
            return $this->handle_facebook_response($response);
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function publish_facebook_reel($page_id, $access_token, $post)
    {
        // استخدم تجربة upload_single_reel إذا كانت متاحة
        $media_paths = json_decode($post['media_paths'], true);
        if (empty($media_paths)) {
            return ['success' => false, 'error' => 'لا توجد فيديوهات للريل'];
        }

        $video_path = FCPATH . ltrim($media_paths[0], '/');
        if (!file_exists($video_path)) {
            return ['success' => false, 'error' => 'ملف الريل غير موجود'];
        }

        $reel_data = [
            'fb_page_id' => $page_id,
            'page_access_token' => $access_token,
            'tmp_name' => $video_path,
            'file_size' => filesize($video_path),
            'filename' => basename($video_path),
            'final_caption' => $post['content_text'] ?? '',
        ];

        return $this->upload_single_reel($reel_data);
    }

    // قصص ونماذج أخرى يمكن توسيعها لاحقًا
    private function publish_facebook_story_photo($page_id, $access_token, $post) { return ['success'=>false,'error'=>'غير مدعوم مؤقتاً']; }
    private function publish_facebook_story_video($page_id, $access_token, $post) { return ['success'=>false,'error'=>'غير مدعوم مؤقتاً']; }
    private function publish_facebook_carousel($page_id, $access_token, $post) { return ['success'=>false,'error'=>'غير مدعوم مؤقتاً']; }

    /* ======================== Instagram Publishing Methods (مبسطة) ======================== */

    private function publish_instagram_text($ig_user_id, $access_token, $post)
    {
        // Instagram doesn't support pure text posts through API
        // We'll create a simple text image or return an error
        if (empty($post['content_text'])) {
            return ['success' => false, 'error' => 'يجب إدخال نص للمنشور'];
        }

        // For now, return error as Instagram API doesn't support text-only posts
        // In future versions, this could be enhanced to create a text image
        return ['success' => false, 'error' => 'Instagram لا يدعم المنشورات النصية المجردة. يرجى إضافة صورة أو فيديو'];
    }

    private function publish_instagram_image($ig_user_id, $access_token, $post)
    {
        $media_paths = json_decode($post['media_paths'], true);
        if (empty($media_paths)) {
            return ['success' => false, 'error' => 'لا توجد صور للنشر'];
        }

        $image_url = base_url($media_paths[0]);

        $create_url = "https://graph.facebook.com/v23.0/{$ig_user_id}/media";
        $create_data = [
            'image_url' => $image_url,
            'caption' => $post['content_text'] ?? '',
            'access_token' => $access_token
        ];

        try {
            $create_response = $this->make_api_request($create_url, $create_data);
            $create_result = json_decode($create_response, true);
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        if (isset($create_result['error'])) {
            return ['success' => false, 'error' => $create_result['error']['message'] ?? 'خطأ'];
        }

        $media_id = $create_result['id'];
        $publish_url = "https://graph.facebook.com/v23.0/{$ig_user_id}/media_publish";
        $publish_data = ['creation_id' => $media_id, 'access_token' => $access_token];

        try {
            $publish_response = $this->make_api_request($publish_url, $publish_data);
            return $this->handle_instagram_response($publish_response);
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function publish_instagram_reel($ig_user_id, $access_token, $post)
    {
        $media_paths = json_decode($post['media_paths'], true);
        if (empty($media_paths)) {
            return ['success' => false, 'error' => 'لا توجد فيديوهات للريل'];
        }

        $video_url = base_url($media_paths[0]);

        $create_url = "https://graph.facebook.com/v23.0/{$ig_user_id}/media";
        $create_data = [
            'media_type' => 'REELS',
            'video_url' => $video_url,
            'caption' => $post['content_text'] ?? '',
            'access_token' => $access_token
        ];

        try {
            $create_response = $this->make_api_request($create_url, $create_data);
            $create_result = json_decode($create_response, true);
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        if (isset($create_result['error'])) {
            return ['success' => false, 'error' => $create_result['error']['message'] ?? 'خطأ'];
        }

        $media_id = $create_result['id'];
        $this->wait_for_media_ready($media_id, $access_token);

        $publish_url = "https://graph.facebook.com/v23.0/{$ig_user_id}/media_publish";
        $publish_data = ['creation_id' => $media_id, 'access_token' => $access_token];

        try {
            $publish_response = $this->make_api_request($publish_url, $publish_data);
            return $this->handle_instagram_response($publish_response);
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /* ======================== Helper Methods for API and Uploads ======================== */

    /**
     * make_api_request - يستخدم التحقق من الشهادات SSL تلقائياً
     */
    private function make_api_request($url, $data, $multipart = false)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart ? $data : http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // enforce SSL verification
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 90);

        if (!$multipart) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        }

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new Exception('فشل في الاتصال بـ API: ' . $curl_error);
        }

        $decoded = json_decode($response, true);
        if ($http_code >= 400) {
            $err_msg = 'HTTP ' . $http_code;
            if (is_array($decoded) && isset($decoded['error'])) {
                $err_msg .= ' - ' . ($decoded['error']['message'] ?? json_encode($decoded['error']));
            }
            throw new Exception($err_msg);
        }

        return $response;
    }

    private function handle_facebook_response($response)
    {
        $result = json_decode($response, true);

        if (isset($result['error'])) {
            return [
                'success' => false,
                'error' => $result['error']['message'] ?? 'خطأ في Facebook API'
            ];
        }

        return [
            'success' => true,
            'post_id' => $result['id'] ?? null,
            'message' => 'تم النشر على Facebook بنجاح'
        ];
    }

    private function handle_instagram_response($response)
    {
        $result = json_decode($response, true);

        if (isset($result['error'])) {
            return [
                'success' => false,
                'error' => $result['error']['message'] ?? 'خطأ في Instagram API'
            ];
        }

        return [
            'success' => true,
            'post_id' => $result['id'] ?? null,
            'message' => 'تم النشر على Instagram بنجاح'
        ];
    }

    private function wait_for_media_ready($media_id, $access_token, $max_attempts = 10)
    {
        $attempts = 0;
        while ($attempts < $max_attempts) {
            $status_url = "https://graph.facebook.com/v23.0/{$media_id}?fields=status_code&access_token={$access_token}";
            try {
                $response = $this->make_api_request($status_url, [], false);
            } catch (Exception $e) {
                sleep(3);
                $attempts++;
                continue;
            }

            $result = json_decode($response, true);
            if (isset($result['status_code']) && $result['status_code'] === 'FINISHED') {
                return true;
            }

            sleep(3);
            $attempts++;
        }

        return false;
    }

    /* ======================== التعليقات ======================== */

    private function save_post_comments($post_id, $user_id, $platform, $account_id, $comments, $schedule_time = null)
    {
        if (empty($comments)) return;

        foreach ($comments as $comment) {
            if (empty($comment['text'])) continue;

            $comment_schedule = null;
            if ($schedule_time) {
                $comment_schedule = date('Y-m-d H:i:s', strtotime($schedule_time['utc']) + 300);
            }

            $comment_data = [
                'social_post_id' => $post_id,
                'user_id' => $user_id,
                'platform' => $platform,
                'platform_account_id' => $account_id,
                'comment_text' => trim($comment['text']),
                'scheduled_at' => $comment_schedule,
                'status' => 'pending',
                'created_at' => date('Y-m-d H:i:s')
            ];

            $this->db->insert('social_post_comments', $comment_data);
        }
    }

    public function get_due_scheduled_posts($limit = 50)
    {
        return $this->db->select('*')
                       ->where('status', 'pending')
                       ->where('processing', 0)
                       ->where('scheduled_at <=', date('Y-m-d H:i:s'))
                       ->order_by('scheduled_at', 'ASC')
                       ->limit($limit)
                       ->get('social_posts')
                       ->result_array();
    }

    public function get_user_posts($user_id, $platform = null, $limit = 100)
    {
        $this->db->where('user_id', $user_id);
        if ($platform) {
            $this->db->where('platform', $platform);
        }

        return $this->db->order_by('created_at', 'DESC')
                       ->limit($limit)
                       ->get('social_posts')
                       ->result_array();
    }

    public function get_post_comments($post_id)
    {
        return $this->db->where('social_post_id', $post_id)
                       ->order_by('created_at', 'ASC')
                       ->get('social_post_comments')
                       ->result_array();
    }

    public function process_due_comments($limit = 100)
    {
        $comments = $this->db->select('*')
                           ->where('status', 'pending')
                           ->where('scheduled_at <=', date('Y-m-d H:i:s'))
                           ->order_by('scheduled_at', 'ASC')
                           ->limit($limit)
                           ->get('social_post_comments')
                           ->result_array();

        $processed = 0;
        foreach ($comments as $comment) {
            if ($this->post_comment($comment)) {
                $processed++;
            }
        }

        return $processed;
    }

    private function post_comment($comment)
    {
        // محاولة تحديث status -> processing
        $this->db->where('id', $comment['id'])
                ->update('social_post_comments', [
                    'status' => 'processing',
                    'attempt_count' => (isset($comment['attempt_count']) ? $comment['attempt_count'] : 0) + 1
                ]);

        try {
            $post = $this->db->where('id', $comment['social_post_id'])->get('social_posts')->row_array();
            if (!$post || empty($post['platform_post_id'])) {
                throw new Exception('المنشور الأصلي غير موجود أو لم يتم نشره بعد');
            }

            $success = false;
            if ($comment['platform'] === 'facebook') {
                $success = $this->post_facebook_comment($post['platform_post_id'], $comment);
            } elseif ($comment['platform'] === 'instagram') {
                $success = $this->post_instagram_comment($post['platform_post_id'], $comment);
            }

            $update_data = [
                'status' => $success ? 'posted' : 'failed',
                'last_error' => $success ? null : 'فشل في نشر التعليق'
            ];

            if ($success) {
                $update_data['posted_time'] = date('Y-m-d H:i:s');
            }

            $this->db->where('id', $comment['id'])->update('social_post_comments', $update_data);

            return $success;

        } catch (Exception $e) {
            $this->db->where('id', $comment['id'])->update('social_post_comments', [
                'status' => 'failed',
                'last_error' => $e->getMessage()
            ]);
            return false;
        }
    }

    private function post_facebook_comment($post_id, $comment)
    {
        $account = $this->get_facebook_account_by_id($comment['platform_account_id']);
        if (!$account) return false;

        $url = "https://graph.facebook.com/v23.0/{$post_id}/comments";
        $data = [
            'message' => $comment['comment_text'],
            'access_token' => $account['page_access_token']
        ];

        try {
            $response = $this->make_api_request($url, $data);
            $res = json_decode($response, true);
            return !isset($res['error']);
        } catch (Exception $e) {
            return false;
        }
    }

    private function post_instagram_comment($post_id, $comment)
    {
        $account = $this->get_instagram_account_by_id($comment['platform_account_id']);
        if (!$account) return false;

        $url = "https://graph.facebook.com/v23.0/{$post_id}/comments";
        $data = [
            'message' => $comment['comment_text'],
            'access_token' => $account['access_token']
        ];

        try {
            $response = $this->make_api_request($url, $data);
            $res = json_decode($response, true);
            return !isset($res['error']);
        } catch (Exception $e) {
            return false;
        }
    }

    /* ======================== Helpers: Accounts lookup ======================== */

    private function get_facebook_account_by_id($page_id)
    {
        $this->load->model('Facebook_pages_model');
        $pages = $this->Facebook_pages_model->get_pages_by_user($this->session->userdata('user_id'));

        foreach ($pages as $page) {
            if ($page['fb_page_id'] == $page_id) {
                return $page;
            }
        }

        return null;
    }

    private function get_instagram_account_by_id($ig_user_id)
    {
        return $this->db->where('ig_user_id', $ig_user_id)
                       ->where('user_id', $this->session->userdata('user_id'))
                       ->get('instagram_rx_accounts')
                       ->row_array();
    }

    /* ======================== رفع ريل منفرد (كررناها هنا للتماسك) ======================== */

    private function upload_single_reel($reel_data)
    {
        $version = 'v23.0';
        $page_id = $reel_data['fb_page_id'];
        $access_token = $reel_data['page_access_token'];
        $video_file = $reel_data['tmp_name'];
        $caption = $reel_data['final_caption'];

        // START Phase
        $start_url = "https://graph.facebook.com/{$version}/{$page_id}/video_reels";
        $start_data = [
            'upload_phase' => 'start',
            'access_token' => $access_token
        ];

        try {
            $start_response = $this->make_api_request($start_url, $start_data);
            $start_result = json_decode($start_response, true);
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'فشل في بدء رفع الريل: ' . $e->getMessage()];
        }

        if (isset($start_result['error']) || empty($start_result['video_id'])) {
            return ['success' => false, 'error' => 'فشل في بدء رفع الريل'];
        }

        $video_id = $start_result['video_id'];

        // UPLOAD Phase (مبسط)
        $upload_url = "https://rupload.facebook.com/video-upload/{$version}/{$video_id}";
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $upload_url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => file_get_contents($video_file),
            CURLOPT_HTTPHEADER => [
                "Authorization: OAuth {$access_token}",
                "offset: 0",
                "file_size: " . filesize($video_file)
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 300
        ]);

        $upload_response = curl_exec($ch);
        $curl_err = curl_error($ch);
        curl_close($ch);

        if ($upload_response === false) {
            return ['success' => false, 'error' => 'فشل في رفع ملف الريل: ' . $curl_err];
        }

        // FINISH Phase
        $finish_url = "https://graph.facebook.com/{$version}/{$page_id}/video_reels";
        $finish_data = [
            'access_token' => $access_token,
            'video_id' => $video_id,
            'upload_phase' => 'finish',
            'description' => $caption,
            'video_state' => 'PUBLISHED'
        ];

        try {
            $finish_response = $this->make_api_request($finish_url, $finish_data);
            $finish_result = json_decode($finish_response, true);
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'فشل في إنهاء نشر الريل: ' . $e->getMessage()];
        }

        if (isset($finish_result['error'])) {
            return ['success' => false, 'error' => 'فشل في إنهاء نشر الريل'];
        }

        return [
            'success' => true,
            'post_id' => $video_id,
            'message' => 'تم نشر الريل بنجاح'
        ];
    }

} // نهاية الكلاس
