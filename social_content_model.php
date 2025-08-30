<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Social Content Model
 * - إدارة النشر المتعدد على Facebook و Instagram
 * - دعم المنشورات، الريلز، والقصص
 * - نظام الجدولة المتكررة المتقدم
 * - لا يتعارض مع Reel_model الحالي
 */
class Social_content_model extends CI_Model
{
    // أنواع المحتوى المدعومة
    const POST_TYPES = [
        'facebook' => ['text', 'image', 'video', 'carousel', 'reel', 'story_photo', 'story_video'],
        'instagram' => ['image', 'video', 'carousel', 'reel', 'story_photo', 'story_video']
    ];

    // أنواع التكرار
    const RECURRENCE_TYPES = ['daily', 'weekly', 'monthly', 'quarterly'];

    // حد أقصى للملفات
    const MAX_FILES_PER_POST = 10;
    const MAX_COMMENTS_PER_POST = 20;

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->load->helper('date');
    }

    /* ======================== إدارة الحسابات ======================== */

    /**
     * جلب حسابات Facebook للمستخدم (يستخدم النموذج الحالي)
     */
    public function get_facebook_accounts($user_id)
    {
        $this->load->model('Facebook_pages_model', 'fb_model');
        return $this->fb_model->get_pages_by_user($user_id);
    }

    /**
     * جلب حسابات Instagram للمستخدم
     */
    public function get_instagram_accounts($user_id)
    {
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

    /**
     * جلب جميع الحسابات (Facebook + Instagram) 
     */
    public function get_all_accounts($user_id)
    {
        $accounts = [
            'facebook' => [],
            'instagram' => []
        ];

        // Facebook accounts
        $fb_accounts = $this->get_facebook_accounts($user_id);
        foreach ($fb_accounts as $acc) {
            $accounts['facebook'][] = [
                'id' => $acc['fb_page_id'],
                'name' => $acc['page_name'],
                'picture' => $acc['page_picture'] ?: $acc['_img'],
                'access_token' => $acc['page_access_token'],
                'health' => 'ok', // يمكن تطويرها لاحقاً
                'type' => 'page'
            ];
        }

        // Instagram accounts  
        $ig_accounts = $this->get_instagram_accounts($user_id);
        foreach ($ig_accounts as $acc) {
            $accounts['instagram'][] = [
                'id' => $acc['ig_user_id'],
                'name' => $acc['full_name'] ?: ('@' . $acc['username']),
                'username' => $acc['username'],
                'picture' => $acc['profile_picture_url'],
                'access_token' => $acc['access_token'],
                'health' => $acc['health_status'],
                'type' => $acc['is_business_account'] ? 'business' : 'personal',
                'followers' => $acc['follower_count']
            ];
        }

        return $accounts;
    }

    /* ======================== إنشاء المنشورات ======================== */

    /**
     * إنشاء منشور أو جدولته
     */
    public function create_social_post($user_id, $data)
    {
        // التحقق من صحة البيانات
        $validation = $this->validate_post_data($data);
        if (!$validation['valid']) {
            return ['success' => false, 'error' => $validation['error']];
        }

        $responses = [];
        $accounts = $data['accounts'] ?? [];
        $platform = $data['platform'] ?? 'facebook';

        foreach ($accounts as $account_id) {
            if ($data['publish_mode'] === 'immediate') {
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
     * نشر فوري
     */
    private function publish_immediate($user_id, $platform, $account_id, $data)
    {
        try {
            // حفظ الملفات أولاً
            $media_data = $this->process_media_files($data['files'] ?? []);
            
            // إنشاء السجل
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

            // حفظ التعليقات إن وجدت
            $this->save_post_comments($post_id, $user_id, $platform, $account_id, $data['comments'] ?? []);

            // محاولة النشر الآن
            $publish_result = $this->execute_post_publish($post_id);

            return [
                'success' => true,
                'post_id' => $post_id,
                'account_id' => $account_id,
                'status' => $publish_result['success'] ? 'published' : 'failed',
                'message' => $publish_result['message'] ?? 'تم النشر'
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
     * جدولة منشور
     */
    private function schedule_post($user_id, $platform, $account_id, $data)
    {
        try {
            // حفظ الملفات
            $media_data = $this->process_media_files($data['files'] ?? []);

            // تحديد أوقات الجدولة
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

                // حفظ التعليقات
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
            if (!is_uploaded_file($file['tmp_name'])) continue;

            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'mp4', 'mov', 'avi'];
            
            if (!in_array($extension, $allowed)) continue;

            $filename = 'social_' . time() . '_' . mt_rand(1000, 9999) . '.' . $extension;
            $filepath = $upload_dir . $filename;

            if (move_uploaded_file($file['tmp_name'], $filepath)) {
                $files_info[] = [
                    'original_name' => $file['name'],
                    'size' => $file['size'],
                    'type' => $this->get_media_type($extension),
                    'extension' => $extension
                ];
                $saved_paths[] = 'uploads/social_content/' . $filename;
            }
        }

        return ['files_info' => $files_info, 'saved_paths' => $saved_paths];
    }

    private function get_media_type($extension)
    {
        $image_exts = ['jpg', 'jpeg', 'png', 'gif'];
        $video_exts = ['mp4', 'mov', 'avi'];

        if (in_array($extension, $image_exts)) return 'image';
        if (in_array($extension, $video_exts)) return 'video';
        return 'unknown';
    }

    /* ======================== معالجة الجدولة ======================== */

    /**
     * معالجة أوقات الجدولة (مرة واحدة أو متكررة)
     */
    private function process_schedule_times($data)
    {
        $schedules = [];
        
        if (isset($data['schedule_times']) && is_array($data['schedule_times'])) {
            foreach ($data['schedule_times'] as $time_data) {
                if (empty($time_data['time'])) continue;

                $base_schedule = [
                    'local' => $time_data['time'],
                    'utc' => $this->localToUtc($time_data['time'], $data['tz_offset_minutes'] ?? 0),
                    'offset' => $data['tz_offset_minutes'] ?? 0,
                    'timezone' => $data['tz_name'] ?? ''
                ];

                // إذا لم يكن هناك تكرار
                if (empty($time_data['recurrence_kind']) || $time_data['recurrence_kind'] === 'none') {
                    $schedules[] = $base_schedule;
                } else {
                    // إنشاء جدولة متكررة
                    $recurring_schedules = $this->generate_recurring_schedules(
                        $base_schedule,
                        $time_data['recurrence_kind'],
                        $time_data['recurrence_until'] ?? null
                    );
                    $schedules = array_merge($schedules, $recurring_schedules);
                }
            }
        }

        return $schedules;
    }

    /**
     * إنشاء جدولة متكررة
     */
    private function generate_recurring_schedules($base_schedule, $recurrence_type, $until_date = null)
    {
        $schedules = [];
        $current_time = strtotime($base_schedule['utc']);
        $until_timestamp = $until_date ? strtotime($this->localToUtc($until_date, $base_schedule['offset'])) : null;

        // حد أقصى للتكرار لتجنب الإفراط
        $max_iterations = 100;
        $iteration = 0;

        while ($iteration < $max_iterations) {
            if ($until_timestamp && $current_time > $until_timestamp) break;

            $schedules[] = [
                'local' => $this->utcToLocal(date('Y-m-d H:i:s', $current_time), $base_schedule['offset']),
                'utc' => date('Y-m-d H:i:s', $current_time),
                'offset' => $base_schedule['offset'],
                'timezone' => $base_schedule['timezone']
            ];

            // حساب التكرار التالي
            switch ($recurrence_type) {
                case 'daily':
                    $current_time = strtotime('+1 day', $current_time);
                    break;
                case 'weekly':
                    $current_time = strtotime('+1 week', $current_time);
                    break;
                case 'monthly':
                    $current_time = strtotime('+1 month', $current_time);
                    break;
                case 'quarterly':
                    $current_time = strtotime('+3 months', $current_time);
                    break;
                default:
                    break 2; // خروج من الحلقة
            }

            $iteration++;
        }

        return $schedules;
    }

    /* ======================== التعليقات ======================== */

    /**
     * حفظ تعليقات المنشور
     */
    private function save_post_comments($post_id, $user_id, $platform, $account_id, $comments, $schedule_time = null)
    {
        if (empty($comments)) return;

        foreach ($comments as $comment) {
            if (empty($comment['text'])) continue;

            // تحديد وقت نشر التعليق
            $comment_schedule = null;
            if ($schedule_time) {
                // للمنشورات المجدولة: التعليق بعد المنشور بـ 5 دقائق
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

        // تحديث محاولة النشر
        $this->db->where('id', $post_id)->update('social_posts', [
            'attempt_count' => $post['attempt_count'] + 1,
            'processing' => 1,
            'last_attempt_at' => date('Y-m-d H:i:s'),
            'status' => 'processing'
        ]);

        try {
            $result = [];

            if ($post['platform'] === 'facebook') {
                $result = $this->publish_to_facebook($post);
            } elseif ($post['platform'] === 'instagram') {
                $result = $this->publish_to_instagram($post);
            }

            // تحديث حالة المنشور
            $update_data = [
                'processing' => 0,
                'status' => $result['success'] ? 'published' : 'failed',
                'last_error' => $result['success'] ? null : ($result['error'] ?? 'خطأ غير معروف')
            ];

            if ($result['success']) {
                $update_data['platform_post_id'] = $result['post_id'] ?? null;
                $update_data['published_time'] = date('Y-m-d H:i:s');
            }

            $this->db->where('id', $post_id)->update('social_posts', $update_data);

            return $result;

        } catch (Exception $e) {
            // تحديث حالة الفشل
            $this->db->where('id', $post_id)->update('social_posts', [
                'processing' => 0,
                'status' => 'failed',
                'last_error' => $e->getMessage()
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * النشر على Facebook
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
     * النشر على Instagram
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

    /* ======================== Facebook Publishing Methods ======================== */

    private function publish_facebook_text($page_id, $access_token, $post)
    {
        $url = "https://graph.facebook.com/v23.0/{$page_id}/feed";
        $data = [
            'message' => $post['content_text'],
            'access_token' => $access_token
        ];

        $response = $this->make_api_request($url, $data);
        return $this->handle_facebook_response($response);
    }

    private function publish_facebook_image($page_id, $access_token, $post)
    {
        $media_paths = json_decode($post['media_paths'], true);
        if (empty($media_paths)) {
            return ['success' => false, 'error' => 'لا توجد صور للنشر'];
        }

        // نشر صورة واحدة أو متعددة
        if (count($media_paths) === 1) {
            return $this->publish_facebook_single_image($page_id, $access_token, $post, $media_paths[0]);
        } else {
            return $this->publish_facebook_multiple_images($page_id, $access_token, $post, $media_paths);
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
            'message' => $post['content_text'],
            'source' => new CURLFile($image_file, mime_content_type($image_file)),
            'access_token' => $access_token
        ];

        $response = $this->make_api_request($url, $data, true);
        return $this->handle_facebook_response($response);
    }

    private function publish_facebook_video($page_id, $access_token, $post)
    {
        $media_paths = json_decode($post['media_paths'], true);
        if (empty($media_paths)) {
            return ['success' => false, 'error' => 'لا توجد فيديوهات للنشر'];
        }

        $video_path = $media_paths[0]; // أول فيديو
        $video_file = FCPATH . ltrim($video_path, '/');
        
        if (!file_exists($video_file)) {
            return ['success' => false, 'error' => 'ملف الفيديو غير موجود'];
        }

        // استخدام نظام رفع الفيديو المتقدم (مشابه للريلز)
        return $this->upload_facebook_video_resumable($page_id, $access_token, $video_file, $post['content_text']);
    }

    private function publish_facebook_reel($page_id, $access_token, $post)
    {
        // استخدام النظام الحالي للريلز (Reel_model)
        $this->load->model('Reel_model');
        
        $media_paths = json_decode($post['media_paths'], true);
        if (empty($media_paths)) {
            return ['success' => false, 'error' => 'لا توجد فيديوهات للريل'];
        }

        $video_path = $media_paths[0];
        $video_file = FCPATH . ltrim($video_path, '/');
        
        if (!file_exists($video_file)) {
            return ['success' => false, 'error' => 'ملف الريل غير موجود'];
        }

        // محاكاة بيانات الريل للنظام الحالي
        $reel_data = [
            'fb_page_id' => $page_id,
            'page_access_token' => $access_token,
            'tmp_name' => $video_file,
            'file_size' => filesize($video_file),
            'filename' => basename($video_path),
            'final_caption' => $post['content_text'],
            'utc_schedule' => null, // فوري
            'tz_offset_minutes' => 0,
            'tz_name' => '',
            'index' => 0,
            'raw_comments' => []
        ];

        try {
            // استخدام منطق الريل الحالي
            return $this->upload_single_reel($reel_data);
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /* ======================== Instagram Publishing Methods ======================== */

    private function publish_instagram_image($ig_user_id, $access_token, $post)
    {
        $media_paths = json_decode($post['media_paths'], true);
        if (empty($media_paths)) {
            return ['success' => false, 'error' => 'لا توجد صور للنشر'];
        }

        if (count($media_paths) === 1) {
            return $this->publish_instagram_single_image($ig_user_id, $access_token, $post, $media_paths[0]);
        } else {
            return $this->publish_instagram_carousel($ig_user_id, $access_token, $post);
        }
    }

    private function publish_instagram_single_image($ig_user_id, $access_token, $post, $image_path)
    {
        $image_url = base_url($image_path);
        
        // إنشاء media container
        $create_url = "https://graph.facebook.com/v23.0/{$ig_user_id}/media";
        $create_data = [
            'image_url' => $image_url,
            'caption' => $post['content_text'],
            'access_token' => $access_token
        ];

        $create_response = $this->make_api_request($create_url, $create_data);
        $create_result = json_decode($create_response, true);

        if (isset($create_result['error'])) {
            return ['success' => false, 'error' => $create_result['error']['message']];
        }

        $media_id = $create_result['id'];

        // نشر المحتوى
        $publish_url = "https://graph.facebook.com/v23.0/{$ig_user_id}/media_publish";
        $publish_data = [
            'creation_id' => $media_id,
            'access_token' => $access_token
        ];

        $publish_response = $this->make_api_request($publish_url, $publish_data);
        return $this->handle_instagram_response($publish_response);
    }

    private function publish_instagram_reel($ig_user_id, $access_token, $post)
    {
        $media_paths = json_decode($post['media_paths'], true);
        if (empty($media_paths)) {
            return ['success' => false, 'error' => 'لا توجد فيديوهات للريل'];
        }

        $video_path = $media_paths[0];
        $video_url = base_url($video_path);

        // إنشاء reel container
        $create_url = "https://graph.facebook.com/v23.0/{$ig_user_id}/media";
        $create_data = [
            'media_type' => 'REELS',
            'video_url' => $video_url,
            'caption' => $post['content_text'],
            'access_token' => $access_token
        ];

        $create_response = $this->make_api_request($create_url, $create_data);
        $create_result = json_decode($create_response, true);

        if (isset($create_result['error'])) {
            return ['success' => false, 'error' => $create_result['error']['message']];
        }

        $media_id = $create_result['id'];

        // انتظار معالجة الفيديو
        $this->wait_for_media_ready($media_id, $access_token);

        // نشر الريل
        $publish_url = "https://graph.facebook.com/v23.0/{$ig_user_id}/media_publish";
        $publish_data = [
            'creation_id' => $media_id,
            'access_token' => $access_token
        ];

        $publish_response = $this->make_api_request($publish_url, $publish_data);
        return $this->handle_instagram_response($publish_response);
    }

    /* ======================== Helper Methods ======================== */

    private function make_api_request($url, $data, $multipart = false)
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $multipart ? $data : http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 60
        ]);

        if (!$multipart) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        }

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new Exception('فشل في الاتصال بـ API');
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
            $response = $this->make_api_request($status_url, [], false);
            $result = json_decode($response, true);
            
            if (isset($result['status_code']) && $result['status_code'] === 'FINISHED') {
                return true;
            }
            
            sleep(3);
            $attempts++;
        }
        
        return false;
    }

    /* ======================== Utility Methods ======================== */

    private function validate_post_data($data)
    {
        if (empty($data['platform'])) {
            return ['valid' => false, 'error' => 'المنصة مطلوبة'];
        }

        if (!in_array($data['platform'], ['facebook', 'instagram'])) {
            return ['valid' => false, 'error' => 'منصة غير مدعومة'];
        }

        if (empty($data['post_type'])) {
            return ['valid' => false, 'error' => 'نوع المنشور مطلوب'];
        }

        if (!in_array($data['post_type'], self::POST_TYPES[$data['platform']])) {
            return ['valid' => false, 'error' => 'نوع المنشور غير مدعوم على هذه المنصة'];
        }

        if (empty($data['accounts'])) {
            return ['valid' => false, 'error' => 'يجب اختيار حساب واحد على الأقل'];
        }

        if (isset($data['files']) && count($data['files']) > self::MAX_FILES_PER_POST) {
            return ['valid' => false, 'error' => 'عدد الملفات يتجاوز الحد المسموح'];
        }

        return ['valid' => true];
    }

    private function localToUtc($local, $offset_minutes)
    {
        if (!$local) return null;
        
        $timestamp = strtotime($local);
        if ($timestamp === false) return null;
        
        return gmdate('Y-m-d H:i:s', $timestamp + ($offset_minutes * 60));
    }

    private function utcToLocal($utc, $offset_minutes)
    {
        if (!$utc) return null;
        
        $timestamp = strtotime($utc . ' UTC');
        if ($timestamp === false) return null;
        
        return date('Y-m-d H:i:s', $timestamp - ($offset_minutes * 60));
    }

    private function get_facebook_account_by_id($page_id)
    {
        $this->load->model('Facebook_pages_model');
        $pages = $this->Facebook_pages_model->get_pages_by_user($this->session->userdata('user_id'));
        
        foreach ($pages as $page) {
            if ($page['fb_page_id'] === $page_id) {
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

    /* ======================== استرجاع البيانات ======================== */

    /**
     * جلب المنشورات المجدولة المستحقة
     */
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

    /**
     * جلب منشورات المستخدم
     */
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

    /**
     * جلب تعليقات منشور
     */
    public function get_post_comments($post_id)
    {
        return $this->db->where('social_post_id', $post_id)
                       ->order_by('created_at', 'ASC')
                       ->get('social_post_comments')
                       ->result_array();
    }

    /**
     * معالجة التعليقات المستحقة
     */
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
        // تحديث الحالة إلى processing
        $this->db->where('id', $comment['id'])
                ->update('social_post_comments', [
                    'status' => 'processing',
                    'attempt_count' => $comment['attempt_count'] + 1
                ]);

        try {
            // جلب المنشور الأصلي
            $post = $this->db->where('id', $comment['social_post_id'])
                           ->get('social_posts')
                           ->row_array();

            if (!$post || empty($post['platform_post_id'])) {
                throw new Exception('المنشور الأصلي غير موجود أو لم يتم نشره بعد');
            }

            $success = false;
            if ($comment['platform'] === 'facebook') {
                $success = $this->post_facebook_comment($post['platform_post_id'], $comment);
            } elseif ($comment['platform'] === 'instagram') {
                $success = $this->post_instagram_comment($post['platform_post_id'], $comment);
            }

            // تحديث حالة التعليق
            $update_data = [
                'status' => $success ? 'posted' : 'failed',
                'last_error' => $success ? null : 'فشل في نشر التعليق'
            ];

            if ($success) {
                $update_data['posted_time'] = date('Y-m-d H:i:s');
            }

            $this->db->where('id', $comment['id'])
                    ->update('social_post_comments', $update_data);

            return $success;

        } catch (Exception $e) {
            // تحديث حالة الفشل
            $this->db->where('id', $comment['id'])
                    ->update('social_post_comments', [
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

        $response = $this->make_api_request($url, $data);
        $result = json_decode($response, true);

        return !isset($result['error']);
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

        $response = $this->make_api_request($url, $data);
        $result = json_decode($response, true);

        return !isset($result['error']);
    }

    /* ======================== إضافات متقدمة للريلز ======================== */

    /**
     * رفع ريل واحد (متوافق مع النظام الحالي)
     */
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

        $start_response = $this->make_api_request($start_url, $start_data);
        $start_result = json_decode($start_response, true);

        if (isset($start_result['error']) || empty($start_result['video_id'])) {
            return ['success' => false, 'error' => 'فشل في بدء رفع الريل'];
        }

        $video_id = $start_result['video_id'];

        // UPLOAD Phase
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
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 300
        ]);

        $upload_response = curl_exec($ch);
        curl_close($ch);

        if ($upload_response === false) {
            return ['success' => false, 'error' => 'فشل في رفع ملف الريل'];
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

        $finish_response = $this->make_api_request($finish_url, $finish_data);
        $finish_result = json_decode($finish_response, true);

        if (isset($finish_result['error'])) {
            return ['success' => false, 'error' => 'فشل في إنهاء نشر الريل'];
        }

        return [
            'success' => true,
            'post_id' => $video_id,
            'message' => 'تم نشر الريل بنجاح'
        ];
    }
}