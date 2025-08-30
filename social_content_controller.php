<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Social Content Controller
 * - إدارة النشر المتعدد على Facebook و Instagram
 * - واجهة موحدة للمنشورات، الريلز، والقصص
 * - لا يتعارض مع Reels Controller الحالي
 */
class Social_content extends CI_Controller
{
    const ALLOWED_EXTENSIONS = [
        'image' => ['jpg', 'jpeg', 'png', 'gif'],
        'video' => ['mp4', 'mov', 'avi', 'm4v']
    ];

    const MAX_FILE_SIZE = [
        'image' => 8 * 1024 * 1024,  // 8MB
        'video' => 100 * 1024 * 1024 // 100MB
    ];

    public function __construct()
    {
        parent::__construct();
        $this->load->model('Social_content_model', 'social_model');
        $this->load->model('Facebook_pages_model', 'fb_model');
        $this->load->library(['session', 'upload']);
        $this->load->helper(['url', 'form', 'security', 'file']);
    }

    /* ======================== صفحات العرض ======================== */

    /**
     * قائمة المنشورات
     */
    public function list()
    {
        $this->require_login();
        $user_id = (int)$this->session->userdata('user_id');

        // فلاتر
        $platform = $this->input->get('platform');
        $post_type = $this->input->get('post_type');
        $status = $this->input->get('status');
        $limit = (int)($this->input->get('limit') ?: 50);

        // جلب المنشورات
        $posts = $this->social_model->get_user_posts($user_id, $platform, $limit);
        
        // جلب المنشورات المجدولة
        $scheduled = $this->db->where('user_id', $user_id)
                             ->where('status', 'pending')
                             ->order_by('scheduled_at', 'ASC')
                             ->limit(100)
                             ->get('social_posts')
                             ->result_array();

        // جلب معلومات الحسابات للعرض
        $accounts = $this->social_model->get_all_accounts($user_id);
        $accounts_map = $this->build_accounts_map($accounts);

        $data = [
            'posts' => $posts,
            'scheduled_posts' => $scheduled,
            'accounts_map' => $accounts_map,
            'filters' => [
                'platform' => $platform,
                'post_type' => $post_type,
                'status' => $status
            ]
        ];

        $this->load->view('social_content/list', $data);
    }

    /* ======================== معالجة النشر ======================== */

    /**
     * معالجة النشر المتعدد
     */
    public function publish()
    {
        $this->require_login();
        
        if (!$this->input->is_ajax_request()) {
            show_404();
            return;
        }

        $user_id = (int)$this->session->userdata('user_id');

        try {
            // جمع البيانات من النموذج
            $post_data = $this->collect_post_data();
            
            // التحقق من صحة البيانات
            $validation = $this->validate_publish_data($post_data);
            if (!$validation['valid']) {
                return $this->json_response(['success' => false, 'error' => $validation['error']], 400);
            }

            // معالجة الملفات المرفوعة
            $files_result = $this->process_uploaded_files();
            if (!$files_result['success']) {
                return $this->json_response(['success' => false, 'error' => $files_result['error']], 400);
            }

            $post_data['files'] = $files_result['files'];

            // إنشاء/جدولة المنشور
            $results = $this->social_model->create_social_post($user_id, $post_data);

            // تحضير الرد
            $success_count = 0;
            $error_count = 0;
            $messages = [];

            foreach ($results as $result) {
                if ($result['success']) {
                    $success_count++;
                    $messages[] = [
                        'type' => 'success',
                        'account' => $result['account_id'],
                        'message' => $result['message']
                    ];
                } else {
                    $error_count++;
                    $messages[] = [
                        'type' => 'error',
                        'account' => $result['account_id'],
                        'message' => $result['error']
                    ];
                }
            }

            return $this->json_response([
                'success' => $success_count > 0,
                'results' => $messages,
                'summary' => [
                    'success' => $success_count,
                    'errors' => $error_count,
                    'total' => count($results)
                ]
            ]);

        } catch (Exception $e) {
            return $this->json_response([
                'success' => false,
                'error' => 'حدث خطأ في الخادم: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * رفع ملفات متعددة بنظام AJAX
     */
    public function upload_files()
    {
        $this->require_login();
        
        if (!$this->input->is_ajax_request()) {
            show_404();
            return;
        }

        try {
            $files_result = $this->process_uploaded_files();
            return $this->json_response($files_result);

        } catch (Exception $e) {
            return $this->json_response([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /* ======================== إدارة الجدولة ======================== */

    /**
     * تحرير منشور مجدول
     */
    public function edit_scheduled($post_id)
    {
        $this->require_login();
        $user_id = (int)$this->session->userdata('user_id');

        $post = $this->db->where('id', (int)$post_id)
                        ->where('user_id', $user_id)
                        ->where('status', 'pending')
                        ->get('social_posts')
                        ->row_array();

        if (!$post) {
            $this->session->set_flashdata('error', 'منشور غير موجود أو لا يمكن تعديله');
            redirect('social_content/list');
            return;
        }

        // جلب التعليقات المرتبطة
        $comments = $this->social_model->get_post_comments($post_id);

        $data = [
            'post' => $post,
            'comments' => $comments
        ];

        $this->load->view('social_content/edit_scheduled', $data);
    }

    /**
     * تحديث منشور مجدول
     */
    public function update_scheduled()
    {
        $this->require_login();
        $user_id = (int)$this->session->userdata('user_id');
        $post_id = (int)$this->input->post('post_id');

        $post = $this->db->where('id', $post_id)
                        ->where('user_id', $user_id)
                        ->where('status', 'pending')
                        ->get('social_posts')
                        ->row_array();

        if (!$post) {
            $this->session->set_flashdata('error', 'منشور غير موجود');
            redirect('social_content/list');
            return;
        }

        // جمع بيانات التحديث
        $update_data = [
            'content_text' => trim($this->input->post('content_text')),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        // تحديث وقت الجدولة إذا تم تغييره
        $new_schedule = trim($this->input->post('scheduled_local'));
        $tz_offset = (int)($this->input->post('tz_offset_minutes') ?: 0);
        $tz_name = trim($this->input->post('tz_name') ?: '');

        if ($new_schedule) {
            $new_utc = $this->localToUtc($new_schedule, $tz_offset);
            if ($new_utc && strtotime($new_utc) > time() + 300) {
                $update_data['scheduled_at'] = $new_utc;
                $update_data['original_local_time'] = str_replace('T', ' ', $new_schedule) . ':00';
                $update_data['original_offset_minutes'] = $tz_offset;
                $update_data['original_timezone'] = $tz_name;
            }
        }

        // معالجة الملفات الجديدة إن وجدت
        if (!empty($_FILES['new_files']['name'][0])) {
            $files_result = $this->process_uploaded_files('new_files');
            if ($files_result['success']) {
                $existing_files = json_decode($post['media_files'], true) ?: [];
                $existing_paths = json_decode($post['media_paths'], true) ?: [];
                
                $update_data['media_files'] = json_encode(array_merge($existing_files, $files_result['files_info']));
                $update_data['media_paths'] = json_encode(array_merge($existing_paths, $files_result['saved_paths']));
            }
        }

        // تحديث المنشور
        $this->db->where('id', $post_id)->update('social_posts', $update_data);

        $this->session->set_flashdata('success', 'تم تحديث المنشور بنجاح');
        redirect('social_content/list');
    }

    /**
     * حذف منشور مجدول
     */
    public function delete_scheduled($post_id)
    {
        $this->require_login();
        $user_id = (int)$this->session->userdata('user_id');

        $post = $this->db->where('id', (int)$post_id)
                        ->where('user_id', $user_id)
                        ->where('status', 'pending')
                        ->get('social_posts')
                        ->row_array();

        if (!$post) {
            $this->session->set_flashdata('error', 'منشور غير موجود');
        } else {
            // حذف الملفات المرتبطة
            $this->delete_post_files($post);
            
            // حذف المنشور والتعليقات المرتبطة
            $this->db->where('id', $post_id)->delete('social_posts');
            
            $this->session->set_flashdata('success', 'تم حذف المنشور');
        }

        redirect('social_content/list');
    }

    /* ======================== CRON Jobs ======================== */

    /**
     * معالجة المنشورات المجدولة (CRON)
     */
    public function cron_publish($token = null)
    {
        if (!$this->input->is_cli_request() && $token !== 'SocialContentCron_2025_SecureToken') {
            show_error('Unauthorized', 403);
            return;
        }

        $lock_file = sys_get_temp_dir() . '/social_content_cron.lock';
        $fh = fopen($lock_file, 'c+');
        
        if (!$fh || !flock($fh, LOCK_EX | LOCK_NB)) {
            echo "Another instance running\n";
            return;
        }

        try {
            $due_posts = $this->social_model->get_due_scheduled_posts(50);
            $processed = 0;
            $successful = 0;
            $failed = 0;

            foreach ($due_posts as $post) {
                echo "Processing post #{$post['id']}...\n";
                
                $result = $this->social_model->execute_post_publish($post['id']);
                
                if ($result['success']) {
                    $successful++;
                    echo "✓ Success: {$result['message']}\n";
                } else {
                    $failed++;
                    echo "✗ Failed: {$result['error']}\n";
                }
                
                $processed++;
                
                // استراحة قصيرة بين المنشورات
                usleep(500000); // 0.5 ثانية
            }

            // معالجة التعليقات المستحقة
            $comments_processed = $this->social_model->process_due_comments(100);

            echo "Summary:\n";
            echo "Posts processed: {$processed}\n";
            echo "Successful: {$successful}\n";
            echo "Failed: {$failed}\n";
            echo "Comments processed: {$comments_processed}\n";

        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /* ======================== API Endpoints ======================== */

    /**
     * جلب الحسابات حسب المنصة
     */
    public function ajax_get_accounts()
    {
        $this->require_login();
        
        if (!$this->input->is_ajax_request()) {
            show_404();
            return;
        }

        $platform = $this->input->post('platform');
        $user_id = (int)$this->session->userdata('user_id');

        if ($platform === 'facebook') {
            $accounts = $this->social_model->get_facebook_accounts($user_id);
        } elseif ($platform === 'instagram') {
            $accounts = $this->social_model->get_instagram_accounts($user_id);
        } else {
            $accounts = $this->social_model->get_all_accounts($user_id);
        }

        return $this->json_response([
            'success' => true,
            'accounts' => $accounts
        ]);
    }

    /**
     * معاينة منشور قبل النشر
     */
    public function ajax_preview_post()
    {
        $this->require_login();
        
        if (!$this->input->is_ajax_request()) {
            show_404();
            return;
        }

        try {
            $data = $this->collect_post_data();
            
            // إنشاء معاينة بدون نشر فعلي
            $preview = [
                'platform' => $data['platform'],
                'post_type' => $data['post_type'],
                'content' => $data['content_text'],
                'accounts_count' => count($data['accounts']),
                'files_count' => count($data['files'] ?? []),
                'comments_count' => count($data['comments'] ?? []),
                'publish_mode' => $data['publish_mode']
            ];

            if ($data['publish_mode'] === 'scheduled') {
                $preview['schedule_times'] = count($data['schedule_times'] ?? []);
            }

            return $this->json_response([
                'success' => true,
                'preview' => $preview
            ]);

        } catch (Exception $e) {
            return $this->json_response([
                'success' => false,
                'error' => $e->getMessage()
            ], 400);
        }
    }

    /* ======================== Helper Methods ======================== */

    private function require_login()
    {
        if (!$this->session->userdata('user_id')) {
            redirect('auth/login');
            exit;
        }
    }

    private function json_response($data, $status_code = 200)
    {
        $this->output->set_status_header($status_code);
        $this->output->set_content_type('application/json', 'utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    private function collect_post_data()
    {
        return [
            'platform' => trim($this->input->post('platform')),
            'post_type' => trim($this->input->post('post_type')),
            'content_text' => trim($this->input->post('content_text')),
            'accounts' => $this->input->post('accounts') ?: [],
            'publish_mode' => trim($this->input->post('publish_mode') ?: 'immediate'),
            'schedule_times' => $this->input->post('schedule_times') ?: [],
            'comments' => $this->input->post('comments') ?: [],
            'tz_offset_minutes' => (int)($this->input->post('tz_offset_minutes') ?: 0),
            'tz_name' => trim($this->input->post('tz_name') ?: '')
        ];
    }

    private function validate_publish_data($data)
    {
        if (empty($data['platform'])) {
            return ['valid' => false, 'error' => 'يجب اختيار المنصة'];
        }

        if (!in_array($data['platform'], ['facebook', 'instagram'])) {
            return ['valid' => false, 'error' => 'منصة غير مدعومة'];
        }

        if (empty($data['post_type'])) {
            return ['valid' => false, 'error' => 'يجب اختيار نوع المنشور'];
        }

        if (empty($data['accounts'])) {
            return ['valid' => false, 'error' => 'يجب اختيار حساب واحد على الأقل'];
        }

        if ($data['post_type'] === 'text' && empty(trim($data['content_text']))) {
            return ['valid' => false, 'error' => 'النص مطلوب للمنشورات النصية'];
        }

        if (in_array($data['post_type'], ['image', 'video', 'carousel', 'reel', 'story_photo', 'story_video'])) {
            if (empty($_FILES) || empty(array_filter($_FILES, function($file) {
                return !empty($file['name'][0]);
            }))) {
                return ['valid' => false, 'error' => 'يجب رفع ملفات للمحتوى المرئي'];
            }
        }

        return ['valid' => true];
    }

    private function process_uploaded_files($field_name = 'media_files')
    {
        if (empty($_FILES[$field_name]['name'][0])) {
            return ['success' => true, 'files' => [], 'files_info' => [], 'saved_paths' => []];
        }

        $upload_dir = FCPATH . 'uploads/social_content/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0775, true);
        }

        $files = [];
        $files_info = [];
        $saved_paths = [];
        $names = $_FILES[$field_name]['name'];
        $tmp_names = $_FILES[$field_name]['tmp_name'];
        $sizes = $_FILES[$field_name]['size'];
        $errors = $_FILES[$field_name]['error'];

        for ($i = 0; $i < count($names); $i++) {
            if ($errors[$i] !== UPLOAD_ERR_OK || empty($names[$i])) {
                continue;
            }

            $original_name = $names[$i];
            $tmp_name = $tmp_names[$i];
            $size = $sizes[$i];

            // التحقق من الامتداد
            $extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
            $file_type = $this->get_file_type($extension);

            if (!$file_type) {
                return ['success' => false, 'error' => "امتداد غير مدعوم: {$extension}"];
            }

            // التحقق من الحجم
            if ($size > self::MAX_FILE_SIZE[$file_type]) {
                $max_mb = round(self::MAX_FILE_SIZE[$file_type] / (1024 * 1024));
                return ['success' => false, 'error' => "حجم الملف أكبر من {$max_mb}MB: {$original_name}"];
            }

            // إنشاء اسم فريد
            $filename = 'social_' . time() . '_' . $i . '_' . mt_rand(1000, 9999) . '.' . $extension;
            $filepath = $upload_dir . $filename;

            // نقل الملف
            if (move_uploaded_file($tmp_name, $filepath)) {
                $files[] = [
                    'name' => $original_name,
                    'tmp_name' => $filepath,
                    'size' => $size,
                    'error' => UPLOAD_ERR_OK
                ];

                $files_info[] = [
                    'original_name' => $original_name,
                    'size' => $size,
                    'type' => $file_type,
                    'extension' => $extension
                ];

                $saved_paths[] = 'uploads/social_content/' . $filename;
            } else {
                return ['success' => false, 'error' => "فشل في رفع الملف: {$original_name}"];
            }
        }

        return [
            'success' => true,
            'files' => $files,
            'files_info' => $files_info,
            'saved_paths' => $saved_paths
        ];
    }

    private function get_file_type($extension)
    {
        if (in_array($extension, self::ALLOWED_EXTENSIONS['image'])) {
            return 'image';
        }
        
        if (in_array($extension, self::ALLOWED_EXTENSIONS['video'])) {
            return 'video';
        }
        
        return false;
    }

    private function build_accounts_map($accounts)
    {
        $map = [];
        
        foreach ($accounts['facebook'] as $acc) {
            $map['facebook'][$acc['id']] = $acc;
        }
        
        foreach ($accounts['instagram'] as $acc) {
            $map['instagram'][$acc['id']] = $acc;
        }
        
        return $map;
    }

    private function delete_post_files($post)
    {
        $media_paths = json_decode($post['media_paths'], true);
        if (!$media_paths) return;

        foreach ($media_paths as $path) {
            $full_path = FCPATH . ltrim($path, '/');
            if (file_exists($full_path)) {
                @unlink($full_path);
            }
        }
    }

    private function localToUtc($local, $offset_minutes)
    {
        if (!$local) return null;
        
        $timestamp = strtotime($local);
        if ($timestamp === false) return null;
        
        return gmdate('Y-m-d H:i:s', $timestamp + ($offset_minutes * 60));
    }
} الواجهة الرئيسية للنشر المتعدد
     */
    public function index()
    {
        $this->require_login();
        $user_id = (int)$this->session->userdata('user_id');

        // جلب جميع الحسابات
        $accounts = $this->social_model->get_all_accounts($user_id);

        // إعدادات الصفحة
        $data = [
            'accounts' => $accounts,
            'platform_counts' => [
                'facebook' => count($accounts['facebook']),
                'instagram' => count($accounts['instagram'])
            ],
            'supported_types' => [
                'facebook' => Social_content_model::POST_TYPES['facebook'],
                'instagram' => Social_content_model::POST_TYPES['instagram']
            ],
            'max_file_sizes' => self::MAX_FILE_SIZE
        ];

        // جلب الهاشتاجات الشائعة
        if ($this->db->table_exists('trending_hashtags')) {
            $this->load->model('Reel_model');
            $data['trending_hashtags'] = $this->Reel_model->get_trending_hashtags();
        } else {
            $data['trending_hashtags'] = [];
        }

        $this->load->view('social_content/upload', $data);
    }

    /**
     *