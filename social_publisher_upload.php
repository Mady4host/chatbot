<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>النشر الموحد - Facebook & Instagram</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;800&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
body {
  background: linear-gradient(135deg, #f1f4f8 0%, #e8ecf5 80%);
  font-family: 'Cairo', Tahoma, Arial, sans-serif;
  min-height: 100vh;
}

.main-card {
  background: #fff;
  border-radius: 20px;
  box-shadow: 0 8px 32px rgba(13,78,150,0.13);
  max-width: 1200px;
  margin: 30px auto;
  overflow: hidden;
  border: 1.5px solid #dde8f5;
}

.header {
  background: linear-gradient(90deg, #0d4e96 80%, #4fc3f7 160%);
  color: #fff;
  padding: 30px;
  text-align: center;
}

.header h1 {
  margin: 0;
  font-size: 2.2rem;
  font-weight: 800;
}

.body {
  padding: 30px;
}

.platform-selector {
  background: #f8fbff;
  border: 2px solid #e0edfa;
  border-radius: 15px;
  padding: 20px;
  margin-bottom: 30px;
}

.platform-btn {
  background: #fff;
  border: 2px solid #d0e2f5;
  border-radius: 12px;
  padding: 20px;
  cursor: pointer;
  transition: all 0.3s;
  text-align: center;
  min-height: 100px;
  display: flex;
  flex-direction: column;
  justify-content: center;
  align-items: center;
  gap: 8px;
}

.platform-btn:hover {
  border-color: #0d6efd;
  background: #f0f7ff;
  transform: translateY(-2px);
}

.platform-btn.active {
  border-color: #0d6efd;
  background: #e7f3ff;
  box-shadow: 0 4px 15px rgba(13, 110, 253, 0.2);
}

.platform-icon {
  font-size: 2.5rem;
  margin-bottom: 5px;
}

.content-type-selector {
  background: #fff;
  border: 1px solid #e0e5ec;
  border-radius: 12px;
  padding: 20px;
  margin-bottom: 25px;
  display: none;
}

.content-type-selector.active {
  display: block;
}

.content-btn {
  background: #f8fbff;
  border: 1px solid #d5e4f2;
  border-radius: 10px;
  padding: 15px;
  cursor: pointer;
  transition: all 0.2s;
  text-align: center;
  font-size: 14px;
  font-weight: 600;
  min-height: 80px;
  display: flex;
  flex-direction: column;
  justify-content: center;
}

.content-btn:hover {
  background: #e7f3ff;
  border-color: #0d6efd;
}

.content-btn.active {
  background: #0d6efd;
  color: #fff;
  border-color: #0d6efd;
}

.accounts-section {
  background: #fff;
  border: 1px solid #e0e5ec;
  border-radius: 15px;
  padding: 25px;
  margin-bottom: 20px;
  display: none;
}

.accounts-section.active {
  display: block;
}

.accounts-grid {
  background: #f9fbfe;
  border: 1px solid #dbe6f7;
  border-radius: 12px;
  padding: 15px;
  max-height: 300px;
  overflow-y: auto;
}

.account-item {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px;
  border-bottom: 1px solid #e9eef7;
  cursor: pointer;
  transition: 0.2s;
}

.account-item:hover {
  background: #f4faff;
}

.account-item:last-child {
  border-bottom: none;
}

.account-item img {
  width: 45px;
  height: 45px;
  border-radius: 50%;
  object-fit: cover;
  border: 2px solid #e0edfa;
}

.account-info {
  flex: 1;
}

.account-name {
  font-weight: 600;
  color: #1e293b;
  margin-bottom: 2px;
}

.account-id {
  font-size: 0.8rem;
  color: #64748b;
  font-family: monospace;
}

.upload-section {
  background: #fff;
  border: 1px solid #e0e5ec;
  border-radius: 15px;
  padding: 25px;
  margin-bottom: 20px;
  display: none;
}

.upload-section.active {
  display: block;
}

.drop-zone {
  border: 2px dashed #0d6efd;
  border-radius: 12px;
  padding: 40px;
  text-align: center;
  cursor: pointer;
  transition: all 0.3s;
  background: linear-gradient(90deg, #fff 85%, #f0f7ff 100%);
}

.drop-zone:hover {
  background: #f5faff;
  border-color: #0056b3;
}

.drop-zone.dragover {
  background: #e7f3ff;
  border-color: #0056b3;
}

.file-card {
  background: #f8fbff;
  border: 1px solid #e0edfa;
  border-radius: 12px;
  padding: 20px;
  margin-top: 20px;
  position: relative;
  display: flex;
  gap: 20px;
  align-items: flex-start;
}

.file-preview {
  width: 120px;
  height: 120px;
  border-radius: 10px;
  object-fit: cover;
  background: #e2e8f0;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 2rem;
  color: #64748b;
  border: 1px solid #cbd5e1;
}

.file-info {
  flex: 1;
}

.file-name {
  font-weight: 600;
  color: #1e293b;
  margin-bottom: 8px;
  font-size: 1.1rem;
}

.file-size {
  font-size: 0.9rem;
  color: #64748b;
  margin-bottom: 15px;
}

.file-controls {
  display: grid;
  gap: 15px;
}

.remove-file {
  position: absolute;
  top: 10px;
  left: 10px;
  background: #dc2626;
  color: white;
  border: none;
  border-radius: 50%;
  width: 35px;
  height: 35px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.2s;
  font-size: 1.2rem;
}

.remove-file:hover {
  background: #b91c1c;
  transform: scale(1.1);
}

.schedule-box {
  background: #f0f9ff;
  border: 1px solid #bae6fd;
  border-radius: 10px;
  padding: 15px;
  margin-top: 10px;
}

.schedule-box h6 {
  color: #0369a1;
  margin-bottom: 10px;
  font-weight: 600;
}

.hashtags-section {
  background: #fff;
  border: 1px solid #e0e5ec;
  border-radius: 15px;
  padding: 25px;
  margin-bottom: 20px;
  display: none;
}

.hashtags-section.active {
  display: block;
}

.hashtags-cloud {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 15px;
}

.hashtag-btn {
  background: #f0f7ff;
  border: 1px solid #bfdbfe;
  border-radius: 15px;
  padding: 5px 12px;
  font-size: 0.9rem;
  cursor: pointer;
  color: #1e40af;
  font-weight: 600;
  transition: all 0.2s;
}

.hashtag-btn:hover {
  background: #1e40af;
  color: white;
}

.selected-hashtags {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 15px;
  min-height: 50px;
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
}

.selected-hashtag {
  background: #1e40af;
  color: white;
  padding: 5px 10px;
  border-radius: 12px;
  font-size: 0.9rem;
  display: flex;
  align-items: center;
  gap: 5px;
}

.remove-hashtag {
  cursor: pointer;
  font-weight: bold;
  opacity: 0.8;
}

.remove-hashtag:hover {
  opacity: 1;
}

.text-post-section {
  background: #fff;
  border: 1px solid #e0e5ec;
  border-radius: 15px;
  padding: 25px;
  margin-bottom: 20px;
  display: none;
}

.text-post-section.active {
  display: block;
}

.auto-hide {
  transition: opacity 0.7s;
}

.hidden {
  display: none !important;
}

@media (max-width: 768px) {
  .main-card {
    margin: 10px;
    border-radius: 15px;
  }
  
  .header {
    padding: 20px;
  }
  
  .header h1 {
    font-size: 1.8rem;
  }
  
  .body {
    padding: 20px;
  }
  
  .file-card {
    flex-direction: column;
    gap: 15px;
  }
  
  .file-preview {
    width: 100%;
    height: 200px;
  }
}
</style>
</head>
<body>

<div class="main-card">
  <div class="header">
    <h1><i class="fas fa-rocket"></i> النشر الموحد</h1>
    <p class="mb-0">Facebook & Instagram في مكان واحد</p>
  </div>
  
  <div class="body">
    
    <?php if($this->session->flashdata('msg_success')): ?>
        <div class="alert alert-success auto-hide"><?= $this->session->flashdata('msg_success') ?></div>
    <?php endif; ?>
    <?php if($this->session->flashdata('msg')): ?>
        <div class="alert alert-danger auto-hide"><?= $this->session->flashdata('msg') ?></div>
    <?php endif; ?>

    <form id="publishForm" method="post" action="<?= site_url('social_publisher/process_upload') ?>" enctype="multipart/form-data">
      
      <!-- اختيار المنصة -->
      <div class="platform-selector">
        <h3 class="text-center mb-4"><i class="fas fa-globe"></i> اختر المنصة</h3>
        <div class="row g-3">
          <div class="col-md-6">
            <div class="platform-btn" data-platform="facebook">
              <div class="platform-icon">📘</div>
              <strong>Facebook</strong>
              <small>ريلز، قصص، منشورات</small>
            </div>
          </div>
          <div class="col-md-6">
            <div class="platform-btn" data-platform="instagram">
              <div class="platform-icon">📸</div>
              <strong>Instagram</strong>
              <small>ريلز، قصص، منشورات</small>
            </div>
          </div>
        </div>
        <input type="hidden" name="platform" id="platformInput">
      </div>

      <!-- اختيار نوع المحتوى -->
      <div class="content-type-selector" id="contentTypeSelector">
        <h4 class="mb-3"><i class="fas fa-layer-group"></i> نوع المحتوى</h4>
        <div class="row g-2" id="contentTypesContainer"></div>
        <input type="hidden" name="content_type" id="contentTypeInput">
      </div>

      <!-- اختيار الحسابات/الصفحات -->
      <div class="accounts-section" id="accountsSection">
        
        <!-- Facebook Pages -->
        <div id="facebookAccountsSection" class="hidden">
          <h4 class="mb-3"><i class="fas fa-users"></i> اختر صفحات Facebook</h4>
          <div class="d-flex justify-content-between align-items-center mb-3">
            <input type="text" id="facebookSearch" class="form-control" style="max-width: 300px;" placeholder="البحث في الصفحات...">
            <button type="button" id="selectAllFacebook" class="btn btn-outline-primary btn-sm">تحديد الكل</button>
          </div>
          
          <div class="accounts-grid">
            <?php if(!empty($facebook_pages)): ?>
              <?php foreach($facebook_pages as $page): 
                $img_src = !empty($page['page_picture']) ? $page['page_picture'] : 'https://graph.facebook.com/'.$page['fb_page_id'].'/picture?type=normal';
              ?>
              <label class="account-item">
                <input type="checkbox" name="facebook_pages[]" value="<?= htmlspecialchars($page['fb_page_id']) ?>">
                <img src="<?= htmlspecialchars($img_src) ?>" alt="صفحة" 
                     onerror="this.src='https://graph.facebook.com/<?= htmlspecialchars($page['fb_page_id']) ?>/picture?type=normal';">
                <div class="account-info">
                  <div class="account-name"><?= htmlspecialchars($page['page_name']) ?></div>
                  <div class="account-id"><?= htmlspecialchars($page['fb_page_id']) ?></div>
                </div>
              </label>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="text-center text-muted">
                <p>لا توجد صفحات مربوطة</p>
                <a href="<?= site_url('reels/pages') ?>" class="btn btn-primary btn-sm">ربط صفحات</a>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Instagram Accounts -->
        <div id="instagramAccountsSection" class="hidden">
          <h4 class="mb-3"><i class="fas fa-camera"></i> اختر حسابات Instagram</h4>
          <div class="d-flex justify-content-between align-items-center mb-3">
            <input type="text" id="instagramSearch" class="form-control" style="max-width: 300px;" placeholder="البحث في الحسابات...">
            <button type="button" id="selectAllInstagram" class="btn btn-outline-primary btn-sm">تحديد الكل</button>
          </div>
          
          <div class="accounts-grid">
            <?php if(!empty($instagram_accounts)): ?>
              <?php foreach($instagram_accounts as $account): ?>
              <label class="account-item">
                <input type="checkbox" name="instagram_accounts[]" value="<?= htmlspecialchars($account['ig_user_id']) ?>">
                <img src="<?= htmlspecialchars($account['ig_profile_picture'] ?: 'https://via.placeholder.com/45') ?>" alt="حساب">
                <div class="account-info">
                  <div class="account-name"><?= htmlspecialchars($account['ig_username'] ?: $account['page_name']) ?></div>
                  <div class="account-id"><?= htmlspecialchars($account['ig_user_id']) ?></div>
                </div>
              </label>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="text-center text-muted">
                <p>لا توجد حسابات إنستجرام مربوطة</p>
                <a href="<?= site_url('instagram/upload') ?>" class="btn btn-primary btn-sm">ربط حسابات</a>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- الهاشتاجات -->
      <div class="hashtags-section" id="hashtagsSection">
        <h4 class="mb-3"><i class="fas fa-hashtag"></i> الهاشتاجات</h4>
        <div class="d-flex gap-2 mb-3">
          <button type="button" id="btnGenerateAllTags" class="btn btn-sm btn-outline-primary">
            <i class="fas fa-hashtag"></i> توليد هاشتاجات اليوم
          </button>
          <button type="button" id="btnClearTags" class="btn btn-sm btn-outline-danger">
            <i class="fas fa-trash"></i> حذف كل الهاشتاجات
          </button>
        </div>
        
        <div class="hashtags-cloud">
          <?php if(!empty($trending_hashtags)): ?>
            <?php foreach($trending_hashtags as $tag): ?>
              <span class="hashtag-btn" data-tag="#<?= htmlspecialchars($tag) ?>">#<?= htmlspecialchars($tag) ?></span>
            <?php endforeach; ?>
          <?php else: ?>
            <span class="text-muted">لا توجد هاشتاجات</span>
          <?php endif; ?>
        </div>
        
        <div class="selected-hashtags" id="selectedHashtags">
          <span style="color: #64748b;">لا يوجد هاشتاجات مختارة حالياً</span>
        </div>
        
        <input type="hidden" name="selected_hashtags" id="selectedHashtagsInput">
      </div>

      <!-- وصف عام -->
      <div class="upload-section" id="globalDescSection">
        <h4 class="mb-3"><i class="fas fa-pencil"></i> وصف عام</h4>
        <textarea name="global_description" class="form-control" rows="3" placeholder="وصف يُطبق على جميع الملفات إذا لم يكن لها وصف خاص..."></textarea>
      </div>
<div class="upload-section" id="autoCommentsGlobal">
  <h4 class="mb-3"><i class="fas fa-comments"></i> تعليقات تلقائية (عام)</h4>
  <div id="globalCommentsContainer">
    <div class="mb-2">
      <textarea name="auto_comments_global[]" class="form-control auto-comment" rows="2" placeholder="اكتب تعليق تلقائي..."></textarea>
    </div>
  </div>
  <button type="button" id="addGlobalComment" class="btn btn-sm btn-outline-primary mt-2">
    <i class="fas fa-plus"></i> إضافة تعليق آخر
  </button>
</div>

<div class="upload-section" id="globalRecurrence">
  <h4 class="mb-3"><i class="fas fa-calendar-alt"></i> جدولة عامة</h4>
  <div class="row g-2">
    <div class="col-md-4">
      <label class="form-label">نوع التكرار</label>
      <select name="global_recurrence_type" class="form-select">
        <option value="none">بدون</option>
        <option value="daily">يومي</option>
        <option value="weekly">أسبوعي</option>
        <option value="monthly">شهري</option>
        <option value="quarterly">كل 3 أشهر</option>
      </select>
    </div>
    <div class="col-md-4">
      <label class="form-label">وقت بداية التكرار</label>
      <input type="datetime-local" name="global_recurrence_time" class="form-control">
    </div>
  </div>
</div>
      <!-- منشور نصي -->
      <div class="text-post-section" id="textPostSection">
        <h4 class="mb-3"><i class="fas fa-font"></i> المنشور النصي</h4>
        <div class="mb-3">
          <label class="form-label fw-bold">عنوان المنشور:</label>
          <input type="text" name="post_title" class="form-control" placeholder="عنوان المنشور...">
        </div>
        <div class="mb-3">
          <label class="form-label fw-bold">محتوى المنشور:</label>
          <textarea name="post_description" class="form-control" rows="5" placeholder="اكتب محتوى المنشور..."></textarea>
        </div>
      </div>

      <!-- رفع الملفات -->
      <div class="upload-section" id="uploadSection">
        <h4 class="mb-3"><i class="fas fa-cloud-upload"></i> رفع الملفات</h4>
        
        <div class="drop-zone" id="dropZone">
          <div style="font-size: 1.2rem; font-weight: 600; margin-bottom: 10px;">
            <i class="fas fa-cloud-upload" style="font-size: 2rem; color: #0d6efd;"></i>
          </div>
          <div>اضغط أو اسحب الملفات هنا</div>
          <small class="text-muted">الحد الأقصى: 120 ميجا لكل ملف</small>
          <input type="file" id="fileInput" name="files[]" multiple style="display: none;">
        </div>

        <div id="filesPreview"></div>
      </div>

      <!-- أزرار الإجراءات -->
      <div class="d-flex gap-3 mt-4">
        <button type="submit" class="btn btn-primary flex-grow-1">
          <i class="fas fa-paper-plane"></i> نشر المحتوى
        </button>
        <a href="<?= site_url('social_publisher/listing') ?>" class="btn btn-secondary">
          <i class="fas fa-list"></i> قائمة المنشورات
        </a>
        <a href="<?= site_url('social_publisher/dashboard') ?>" class="btn btn-outline-primary">
          <i class="fas fa-tachometer-alt"></i> لوحة التحكم
        </a>
      </div>

      <!-- حقول مخفية -->
      <input type="hidden" name="timezone_offset" id="timezoneOffset">
      <input type="hidden" name="timezone_name" id="timezoneName">
    </form>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // تعيين المنطقة الزمنية
    document.getElementById('timezoneOffset').value = new Date().getTimezoneOffset();
    if (Intl && Intl.DateTimeFormat) {
        document.getElementById('timezoneName').value = Intl.DateTimeFormat().resolvedOptions().timeZone;
    }

    // متغيرات عامة
    let selectedPlatform = '';
    let selectedContentType = '';
    let uploadedFiles = [];
    let selectedHashtags = new Set();

    // أنواع المحتوى لكل منصة
    const contentTypes = {
        facebook: [
            { value: 'reel', label: 'ريلز', icon: 'fas fa-video', accepts: 'video/*' },
            { value: 'story_video', label: 'قصة فيديو', icon: 'fas fa-play-circle', accepts: 'video/*' },
            { value: 'story_photo', label: 'قصة صورة', icon: 'fas fa-image', accepts: 'image/*' },
            { value: 'post_text', label: 'منشور نصي', icon: 'fas fa-font', accepts: null },
            { value: 'post_photo', label: 'منشور صورة', icon: 'fas fa-camera', accepts: 'image/*' },
            { value: 'post_video', label: 'منشور فيديو', icon: 'fas fa-film', accepts: 'video/*' }
        ],
        instagram: [
            { value: 'reel', label: 'ريلز', icon: 'fas fa-video', accepts: 'video/*' },
            { value: 'story_video', label: 'قصة فيديو', icon: 'fas fa-play-circle', accepts: 'video/*' },
            { value: 'story_photo', label: 'قصة صورة', icon: 'fas fa-image', accepts: 'image/*' },
            { value: 'post_photo', label: 'منشور صورة', icon: 'fas fa-camera', accepts: 'image/*' },
            { value: 'post_video', label: 'منشور فيديو', icon: 'fas fa-film', accepts: 'video/*' }
        ]
    };

    // اختيار المنصة
    document.querySelectorAll('.platform-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            // إزالة التحديد السابق
            document.querySelectorAll('.platform-btn').forEach(b => b.classList.remove('active'));
            
            // تحديد المنصة الجديدة
            this.classList.add('active');
            selectedPlatform = this.dataset.platform;
            document.getElementById('platformInput').value = selectedPlatform;
            
            // إظهار اختيار نوع المحتوى
            showContentTypeSelector();
            
            // إخفاء الأقسام التالية
            hideAllSections();
            
            // مسح الملفات السابقة
            uploadedFiles = [];
            updateFilesPreview();
        });
    });
document.getElementById('addGlobalComment')?.addEventListener('click', function() {
    const container = document.getElementById('globalCommentsContainer');
    const div = document.createElement('div');
    div.className = 'mb-2';
    div.innerHTML = `<textarea name="auto_comments_global[]" class="form-control auto-comment" rows="2" placeholder="اكتب تعليق تلقائي..."></textarea>
                     <button type="button" class="btn btn-sm btn-outline-danger mt-1 remove-comment">حذف</button>`;
    container.appendChild(div);
    div.querySelector('.remove-comment').addEventListener('click', () => div.remove());
});

// add extra comment for a specific file index
window.addExtraCommentForFile = function(index) {
    const container = document.querySelector(`#file-comments-container-${index}`);
    if (!container) return;
    const el = document.createElement('div');
    el.className = 'mb-2';
    el.innerHTML = `<textarea name="file_comments[${index}][]" class="form-control" rows="2" placeholder="تعليق للملف..."></textarea>
                    <button type="button" class="btn btn-sm btn-outline-danger mt-1 remove-comment">حذف</button>`;
    container.appendChild(el);
    el.querySelector('.remove-comment').addEventListener('click', () => el.remove());
};
    // عرض اختيار نوع المحتوى
    function showContentTypeSelector() {
        const selector = document.getElementById('contentTypeSelector');
        const container = document.getElementById('contentTypesContainer');
        
        selector.classList.add('active');
        container.innerHTML = '';
        
        const types = contentTypes[selectedPlatform] || [];
        types.forEach(type => {
            const col = document.createElement('div');
            col.className = 'col-md-4 col-sm-6 mb-2';
            
            const btn = document.createElement('div');
            btn.className = 'content-btn';
            btn.dataset.content = type.value;
            btn.dataset.accepts = type.accepts || '';
            btn.innerHTML = `
                <i class="${type.icon}"></i>
                <strong>${type.label}</strong>
            `;
            
            btn.addEventListener('click', function() {
                selectContentType(type.value, type.accepts);
            });
            
            col.appendChild(btn);
            container.appendChild(col);
        });
    }

    // اختيار نوع المحتوى
    function selectContentType(contentType, accepts) {
        // إزالة التحديد السابق
        document.querySelectorAll('.content-btn').forEach(btn => btn.classList.remove('active'));
        
        // تحديد النوع الجديد
        document.querySelector(`[data-content="${contentType}"]`).classList.add('active');
        selectedContentType = contentType;
        document.getElementById('contentTypeInput').value = contentType;
        
        // تحديث نوع الملفات المقبولة
        const fileInput = document.getElementById('fileInput');
        if (accepts) {
            fileInput.accept = accepts;
        } else {
            fileInput.removeAttribute('accept');
        }
        
        // إظهار الأقسام المناسبة
        showRelevantSections();
        
        // مسح الملفات السابقة
        uploadedFiles = [];
        updateFilesPreview();
    }

    // إظهار الأقسام المناسبة
    function showRelevantSections() {
        // إظهار اختيار الحسابات
        document.getElementById('accountsSection').classList.add('active');
        
        if (selectedPlatform === 'facebook') {
            document.getElementById('facebookAccountsSection').classList.remove('hidden');
            document.getElementById('instagramAccountsSection').classList.add('hidden');
        } else {
            document.getElementById('instagramAccountsSection').classList.remove('hidden');
            document.getElementById('facebookAccountsSection').classList.add('hidden');
        }
        
        // إظهار الهاشتاجات للريلز والمنشورات (ليس للقصص)
        if (['reel', 'post_text', 'post_photo', 'post_video'].includes(selectedContentType)) {
            document.getElementById('hashtagsSection').classList.add('active');
        } else {
            document.getElementById('hashtagsSection').classList.remove('active');
        }
        
        // إظهار الوصف العام
        document.getElementById('globalDescSection').classList.add('active');
        
        // إظهار منطقة النص للمنشورات النصية
        if (selectedContentType === 'post_text') {
            document.getElementById('textPostSection').classList.add('active');
            document.getElementById('uploadSection').classList.remove('active');
        } else {
            document.getElementById('textPostSection').classList.remove('active');
            document.getElementById('uploadSection').classList.add('active');
        }
    }

    // إخفاء جميع الأقسام
    function hideAllSections() {
        document.getElementById('accountsSection').classList.remove('active');
        document.getElementById('hashtagsSection').classList.remove('active');
        document.getElementById('globalDescSection').classList.remove('active');
        document.getElementById('uploadSection').classList.remove('active');
        document.getElementById('textPostSection').classList.remove('active');
    }

    // إدارة الهاشتاجات
    function updateSelectedHashtags() {
        const container = document.getElementById('selectedHashtags');
        container.innerHTML = '';
        
        if (selectedHashtags.size === 0) {
            const span = document.createElement('span');
            span.style.color = '#64748b';
            span.textContent = 'لا يوجد هاشتاجات مختارة حالياً';
            container.appendChild(span);
        } else {
            selectedHashtags.forEach(tag => {
                const span = document.createElement('span');
                span.className = 'selected-hashtag';
                span.innerHTML = `
                    ${tag}
                    <span class="remove-hashtag" onclick="removeHashtag('${tag}')">×</span>
                `;
                container.appendChild(span);
            });
        }
        
        document.getElementById('selectedHashtagsInput').value = Array.from(selectedHashtags).join(' ');
    }

    window.removeHashtag = function(tag) {
        selectedHashtags.delete(tag);
        updateSelectedHashtags();
    };

    // أحداث الهاشتاجات
    document.querySelectorAll('.hashtag-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const tag = this.dataset.tag;
            if (tag) {
                selectedHashtags.add(tag);
                updateSelectedHashtags();
            }
        });
    });

    document.getElementById('btnGenerateAllTags').addEventListener('click', function() {
        document.querySelectorAll('.hashtag-btn').forEach(btn => {
            const tag = btn.dataset.tag;
            if (tag) selectedHashtags.add(tag);
        });
        updateSelectedHashtags();
    });

    document.getElementById('btnClearTags').addEventListener('click', function() {
        selectedHashtags.clear();
        updateSelectedHashtags();
    });

    // رفع الملفات
    const dropZone = document.getElementById('dropZone');
    const fileInput = document.getElementById('fileInput');
    const filesPreview = document.getElementById('filesPreview');

    dropZone.addEventListener('click', () => fileInput.click());
    
    dropZone.addEventListener('dragover', (e) => {
        e.preventDefault();
        dropZone.classList.add('dragover');
    });
    
    dropZone.addEventListener('dragleave', () => {
        dropZone.classList.remove('dragover');
    });
    
    dropZone.addEventListener('drop', (e) => {
        e.preventDefault();
        dropZone.classList.remove('dragover');
        handleFiles(e.dataTransfer.files);
    });
    
    fileInput.addEventListener('change', (e) => {
        handleFiles(e.target.files);
    });

    function handleFiles(files) {
        Array.from(files).forEach(file => {
            if (file.size > 120 * 1024 * 1024) {
                alert(`الملف ${file.name} كبير جداً (أكثر من 120 ميجا)`);
                return;
            }
            
            // فحص نوع الملف
            const isValidType = validateFileType(file);
            if (!isValidType) {
                alert(`نوع الملف ${file.name} غير مدعوم لهذا النوع من المحتوى`);
                return;
            }
            
            const fileId = Date.now() + Math.random();
            uploadedFiles.push({ id: fileId, file: file });
            
            updateFilesPreview();
        });
    }

    function validateFileType(file) {
        const ext = file.name.split('.').pop().toLowerCase();
        
        switch (selectedContentType) {
            case 'reel':
            case 'story_video':
            case 'post_video':
                return ['mp4', 'mov', 'mkv'].includes(ext);
                
            case 'story_photo':
            case 'post_photo':
                return ['jpg', 'jpeg', 'png', 'gif'].includes(ext);
                
            default:
                return true;
        }
    }

    function updateFilesPreview() {
        filesPreview.innerHTML = '';
        
        uploadedFiles.forEach((fileObj, index) => {
            const fileCard = createFileCard(fileObj, index);
            filesPreview.appendChild(fileCard);
        });
    }

    function createFileCard(fileObj, index) {
        const card = document.createElement('div');
        card.className = 'file-card';
        card.dataset.fileId = fileObj.id;
        
        const file = fileObj.file;
        const isImage = file.type.startsWith('image/');
        const isVideo = file.type.startsWith('video/');
        
        let previewContent = '';
        if (isImage) {
            const url = URL.createObjectURL(file);
            previewContent = `<img src="${url}" alt="معاينة" class="file-preview">`;
        } else if (isVideo) {
            const url = URL.createObjectURL(file);
            previewContent = `<video class="file-preview" muted controls><source src="${url}" type="${file.type}"></video>`;
        } else {
            previewContent = `<div class="file-preview"><i class="fas fa-file"></i></div>`;
        }
        
        card.innerHTML = `
            <button type="button" class="remove-file" onclick="removeFile('${fileObj.id}')">
                <i class="fas fa-times"></i>
            </button>
            
            ${previewContent}
            
            <div class="file-info">
                <div class="file-name">${file.name}</div>
                <div class="file-size">${formatFileSize(file.size)}</div>
                
                <div class="file-controls">
                    ${getFileControls(index)}
                </div>
            </div>
        `;
        
        return card;
    }

function getFileControls(index) {
    let controls = '';

    // وصف خاص للريلز والمنشورات
    if (['reel', 'post_photo', 'post_video'].includes(selectedContentType)) {
        controls += `
            <div class="mb-3">
                <label class="form-label fw-bold">وصف خاص لهذا الملف:</label>
                <textarea name="file_descriptions[${index}]" class="form-control" rows="2" placeholder="وصف هذا الملف (اختياري)..."></textarea>
            </div>
        `;
    }

    // جدولة فردية + تكرار فردي
    controls += `
        <div class="schedule-box">
            <h6><i class="fas fa-clock"></i> جدولة هذا الملف</h6>
            <div class="row g-2">
                <div class="col-md-6">
                    <label class="form-label">وقت النشر (اختياري):</label>
                    <input type="datetime-local" name="file_schedule_times[${index}]" class="form-control">
                    <small class="text-muted">إذا تُرك فارغاً، سيتم النشر فوراً</small>
                </div>
                <div class="col-md-6">
                    <label class="form-label">تكرار (اختياري):</label>
                    <select name="file_recurrence_types[${index}]" class="form-select">
                        <option value="none">بدون تكرار</option>
                        <option value="daily">يومي</option>
                        <option value="weekly">أسبوعي</option>
                        <option value="monthly">شهري</option>
                        <option value="quarterly">كل 3 أشهر</option>
                    </select>
                </div>
            </div>
        </div>
    `;

    // تعليقات خاصة بالملف + زر إضافة تعليق
    controls += `
        <div class="mb-3">
            <label class="form-label fw-bold">تعليقات للملف</label>
            <div id="file-comments-container-${index}">
                <div class="mb-2">
                    <textarea name="file_comments[${index}][]" class="form-control" rows="2" placeholder="تعليق للملف..."></textarea>
                </div>
            </div>
            <button type="button" class="btn btn-sm btn-outline-primary mt-2" onclick="addExtraCommentForFile(${index})">
                <i class="fas fa-plus"></i> إضافة تعليق للملف
            </button>
        </div>
    `;

    return controls;
}

    window.removeFile = function(fileId) {
        uploadedFiles = uploadedFiles.filter(f => f.id !== fileId);
        updateFilesPreview();
    };

    function formatFileSize(bytes) {
        if (bytes === 0) return '0 بايت';
        const k = 1024;
        const sizes = ['بايت', 'كيلو', 'ميجا', 'جيجا'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    // تحديد الكل للحسابات
    document.getElementById('selectAllFacebook')?.addEventListener('click', function() {
        const checkboxes = document.querySelectorAll('input[name="facebook_pages[]"]');
        const allChecked = Array.from(checkboxes).every(cb => cb.checked);
        
        checkboxes.forEach(cb => {
            cb.checked = !allChecked;
        });
        
        this.textContent = allChecked ? 'تحديد الكل' : 'إلغاء الكل';
    });

    document.getElementById('selectAllInstagram')?.addEventListener('click', function() {
        const checkboxes = document.querySelectorAll('input[name="instagram_accounts[]"]');
        const allChecked = Array.from(checkboxes).every(cb => cb.checked);
        
        checkboxes.forEach(cb => {
            cb.checked = !allChecked;
        });
        
        this.textContent = allChecked ? 'تحديد الكل' : 'إلغاء الكل';
    });

    // البحث في الحسابات
    document.getElementById('facebookSearch')?.addEventListener('input', function() {
        const query = this.value.toLowerCase();
        document.querySelectorAll('#facebookAccountsSection .account-item').forEach(item => {
            const text = item.textContent.toLowerCase();
            item.style.display = text.includes(query) ? '' : 'none';
        });
    });

    document.getElementById('instagramSearch')?.addEventListener('input', function() {
        const query = this.value.toLowerCase();
        document.querySelectorAll('#instagramAccountsSection .account-item').forEach(item => {
            const text = item.textContent.toLowerCase();
            item.style.display = text.includes(query) ? '' : 'none';
        });
    });

    // إرسال النموذج
    document.getElementById('publishForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        if (!selectedPlatform) {
            alert('اختر المنصة أولاً');
            return;
        }
        
        if (!selectedContentType) {
            alert('اختر نوع المحتوى');
            return;
        }
        
        // التحقق من اختيار الحسابات
        const platformAccounts = selectedPlatform === 'facebook' 
            ? document.querySelectorAll('input[name="facebook_pages[]"]:checked')
            : document.querySelectorAll('input[name="instagram_accounts[]"]:checked');
            
        if (platformAccounts.length === 0) {
            alert('اختر حساب واحد على الأقل');
            return;
        }
        
        // التحقق من المحتوى
        if (selectedContentType === 'post_text') {
            const title = document.querySelector('input[name="post_title"]').value.trim();
            const description = document.querySelector('textarea[name="post_description"]').value.trim();
            if (!title && !description) {
                alert('أدخل عنوان أو محتوى للمنشور النصي');
                return;
            }
        } else {
            if (uploadedFiles.length === 0) {
                alert('أضف ملفات للنشر');
                return;
            }
        }
        
        // إرسال البيانات
        submitForm();
    });

    function submitForm() {
        const formData = new FormData(document.getElementById('publishForm'));
        
        // إضافة الملفات
        uploadedFiles.forEach((fileObj, index) => {
            formData.append(`files[${index}]`, fileObj.file);
        });
        
        const submitBtn = document.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري النشر...';
        
        fetch(document.getElementById('publishForm').action, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('تم النشر بنجاح!');
                window.location.href = data.redirect_url || '<?= site_url('social_publisher/listing') ?>';
            } else {
                alert(data.message || 'حدث خطأ أثناء النشر');
                
                // عرض الرسائل التفصيلية
                if (data.messages && data.messages.length > 0) {
                    let details = '';
                    data.messages.forEach(msg => {
                        details += `${msg.type === 'success' ? '✅' : '❌'} ${msg.msg}\n`;
                    });
                    alert(details);
                }
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('حدث خطأ في الاتصال');
        })
        .finally(() => {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        });
    }

    // تهيئة
    updateSelectedHashtags();
    
    // إخفاء الرسائل تلقائياً
    setTimeout(() => {
        document.querySelectorAll('.auto-hide').forEach(el => {
            el.style.opacity = '0';
            setTimeout(() => el.remove(), 700);
        });
    }, 4000);
});
</script>

</body>
</html>
