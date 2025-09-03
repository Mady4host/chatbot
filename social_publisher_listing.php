<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>قائمة المنشورات - النشر الموحد</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;800&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
:root {
  --primary-color: #1e40af;
  --primary-light: #3b82f6;
  --primary-dark: #1e3a8a;
  --secondary-color: #64748b;
  --success-color: #059669;
  --danger-color: #dc2626;
  --warning-color: #d97706;
  --info-color: #0891b2;
  --light-bg: #f8fafc;
  --card-bg: #ffffff;
  --border-color: #e2e8f0;
  --text-primary: #1e293b;
  --text-secondary: #64748b;
  --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
  --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1);
  --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1);
  --border-radius: 12px;
  --transition: all 0.3s ease;
}

body {
  background: linear-gradient(135deg, var(--light-bg) 0%, #e2e8f0 100%);
  font-family: 'Cairo', Tahoma, Arial, sans-serif;
  min-height: 100vh;
  color: var(--text-primary);
}

.main-container {
  max-width: 1400px;
  margin: 0 auto;
  padding: 20px;
}

.header-card {
  background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%);
  color: white;
  border-radius: var(--border-radius);
  padding: 30px;
  margin-bottom: 30px;
  box-shadow: var(--shadow-lg);
}

.header-title {
  font-size: 2.2rem;
  font-weight: 800;
  margin-bottom: 10px;
  display: flex;
  align-items: center;
  gap: 15px;
}

.stats-row {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 20px;
  margin-bottom: 30px;
}

.stat-card {
  background: var(--card-bg);
  border: 1px solid var(--border-color);
  border-radius: var(--border-radius);
  padding: 20px;
  text-align: center;
  box-shadow: var(--shadow-sm);
  transition: var(--transition);
}

.stat-card:hover {
  transform: translateY(-2px);
  box-shadow: var(--shadow-md);
}

.stat-number {
  font-size: 2rem;
  font-weight: 800;
  color: var(--primary-color);
  margin-bottom: 5px;
}

.stat-label {
  color: var(--text-secondary);
  font-weight: 600;
}

.content-card {
  background: var(--card-bg);
  border: 1px solid var(--border-color);
  border-radius: var(--border-radius);
  padding: 25px;
  box-shadow: var(--shadow-sm);
}

.filters-bar {
  background: var(--light-bg);
  border: 1px solid var(--border-color);
  border-radius: 10px;
  padding: 20px;
  margin-bottom: 25px;
  display: flex;
  flex-wrap: wrap;
  gap: 15px;
  align-items: center;
}

.filter-group {
  display: flex;
  flex-direction: column;
  gap: 5px;
}

.filter-label {
  font-size: 0.9rem;
  font-weight: 600;
  color: var(--text-secondary);
}

.table-responsive {
  border-radius: 10px;
  overflow: hidden;
  border: 1px solid var(--border-color);
}

.table {
  margin-bottom: 0;
}

.table th {
  background: var(--light-bg);
  border-bottom: 2px solid var(--border-color);
  font-weight: 700;
  color: var(--text-primary);
  white-space: nowrap;
}

.table td {
  border-bottom: 1px solid #f1f5f9;
  vertical-align: middle;
}

.platform-badge {
  padding: 4px 10px;
  border-radius: 15px;
  font-size: 0.8rem;
  font-weight: 600;
}

.platform-facebook {
  background: #e3f2fd;
  color: #1565c0;
}

.platform-instagram {
  background: #fce4ec;
  color: #ad1457;
}

.content-type-badge {
  padding: 3px 8px;
  border-radius: 10px;
  font-size: 0.75rem;
  font-weight: 600;
}

.type-reel { background: #e8f5e8; color: #2e7d32; }
.type-story { background: #fff3e0; color: #f57c00; }
.type-post { background: #e3f2fd; color: #1976d2; }

.status-badge {
  padding: 4px 10px;
  border-radius: 12px;
  font-size: 0.8rem;
  font-weight: 600;
}

.status-published { background: #dcfce7; color: var(--success-color); }
.status-scheduled { background: #fef3c7; color: var(--warning-color); }
.status-failed { background: #fef2f2; color: var(--danger-color); }
.status-pending { background: #f0f9ff; color: var(--info-color); }

.account-info {
  display: flex;
  align-items: center;
  gap: 8px;
}

.account-avatar {
  width: 30px;
  height: 30px;
  border-radius: 50%;
  object-fit: cover;
  border: 1px solid var(--border-color);
}

.file-preview {
  width: 60px;
  height: 60px;
  border-radius: 8px;
  object-fit: cover;
  border: 1px solid var(--border-color);
}

.pagination-wrapper {
  display: flex;
  justify-content: center;
  margin-top: 30px;
}

.bulk-actions {
  background: var(--light-bg);
  border: 1px solid var(--border-color);
  border-radius: 10px;
  padding: 15px;
  margin-bottom: 20px;
  display: flex;
  align-items: center;
  gap: 15px;
}

.empty-state {
  text-align: center;
  padding: 60px 20px;
  color: var(--text-secondary);
}

.empty-state .icon {
  font-size: 4rem;
  color: var(--border-color);
  margin-bottom: 20px;
}

@media (max-width: 768px) {
  .main-container {
    padding: 10px;
  }
  
  .header-card {
    padding: 20px;
  }
  
  .header-title {
    font-size: 1.8rem;
  }
  
  .filters-bar {
    flex-direction: column;
    align-items: stretch;
  }
  
  .stats-row {
    grid-template-columns: repeat(2, 1fr);
  }
  
  .bulk-actions {
    flex-direction: column;
    align-items: stretch;
  }
}
</style>
</head>
<body>

<div class="main-container">
  <!-- Header -->
  <div class="header-card">
    <div class="d-flex justify-content-between align-items-center">
      <h1 class="header-title mb-0">
        <i class="fas fa-list"></i>
        قائمة المنشورات
      </h1>
      <div class="d-flex gap-2">
        <a href="<?= site_url('social_publisher') ?>" class="btn btn-light">
          <i class="fas fa-plus"></i> نشر جديد
        </a>
        <a href="<?= site_url('social_publisher/dashboard') ?>" class="btn btn-outline-light">
          <i class="fas fa-tachometer-alt"></i> لوحة التحكم
        </a>
      </div>
    </div>
  </div>

  <!-- Flash Messages -->
  <?php if($this->session->flashdata('msg_success')): ?>
    <div class="alert alert-success">
      <i class="fas fa-check-circle"></i>
      <?= $this->session->flashdata('msg_success') ?>
    </div>
  <?php endif; ?>
  
  <?php if($this->session->flashdata('msg')): ?>
    <div class="alert alert-danger">
      <i class="fas fa-exclamation-triangle"></i>
      <?= $this->session->flashdata('msg') ?>
    </div>
  <?php endif; ?>

  <!-- Statistics -->
  <div class="stats-row">
    <div class="stat-card">
      <div class="stat-number"><?= number_format($stats['total'] ?? 0) ?></div>
      <div class="stat-label">إجمالي المنشورات</div>
    </div>
    <div class="stat-card">
      <div class="stat-number"><?= number_format($stats['published'] ?? 0) ?></div>
      <div class="stat-label">منشورة</div>
    </div>
    <div class="stat-card">
      <div class="stat-number"><?= number_format($stats['scheduled'] ?? 0) ?></div>
      <div class="stat-label">مجدولة</div>
    </div>
    <div class="stat-card">
      <div class="stat-number"><?= number_format($stats['failed'] ?? 0) ?></div>
      <div class="stat-label">فاشلة</div>
    </div>
  </div>

  <!-- Content -->
  <div class="content-card">
    <!-- Filters -->
    <form method="get" class="filters-bar">
      <!-- نفس الفلاتر كما كانت -->
      <div class="filter-group">
        <label class="filter-label">البحث</label>
        <input type="text" name="q" class="form-control form-control-sm" 
               value="<?= htmlspecialchars($filters['q'] ?? '') ?>" 
               placeholder="ابحث في العنوان أو الوصف...">
      </div>
      <!-- بقية الفلاتر محفوظة كما في الملف الأصلي -->
      <div class="filter-group">
        <label class="filter-label">المنصة</label>
        <select name="platform" class="form-select form-select-sm">
          <option value="">الكل</option>
          <option value="facebook" <?= ($filters['platform'] ?? '') === 'facebook' ? 'selected' : '' ?>>Facebook</option>
          <option value="instagram" <?= ($filters['platform'] ?? '') === 'instagram' ? 'selected' : '' ?>>Instagram</option>
        </select>
      </div>
      <div class="filter-group">
        <label class="filter-label">نوع المحتوى</label>
        <select name="content_type" class="form-select form-select-sm">
          <option value="">الكل</option>
          <option value="reel" <?= ($filters['content_type'] ?? '') === 'reel' ? 'selected' : '' ?>>ريلز</option>
          <option value="story_photo" <?= ($filters['content_type'] ?? '') === 'story_photo' ? 'selected' : '' ?>>قصة صورة</option>
          <option value="story_video" <?= ($filters['content_type'] ?? '') === 'story_video' ? 'selected' : '' ?>>قصة فيديو</option>
          <option value="post_text" <?= ($filters['content_type'] ?? '') === 'post_text' ? 'selected' : '' ?>>منشور نصي</option>
          <option value="post_photo" <?= ($filters['content_type'] ?? '') === 'post_photo' ? 'selected' : '' ?>>منشور صورة</option>
          <option value="post_video" <?= ($filters['content_type'] ?? '') === 'post_video' ? 'selected' : '' ?>>منشور فيديو</option>
        </select>
      </div>
      <div class="filter-group">
        <label class="filter-label">الحالة</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">الكل</option>
          <option value="published" <?= ($filters['status'] ?? '') === 'published' ? 'selected' : '' ?>>منشورة</option>
          <option value="scheduled" <?= ($filters['status'] ?? '') === 'scheduled' ? 'selected' : '' ?>>مجدولة</option>
          <option value="failed" <?= ($filters['status'] ?? '') === 'failed' ? 'selected' : '' ?>>فاشلة</option>
          <option value="pending" <?= ($filters['status'] ?? '') === 'pending' ? 'selected' : '' ?>>معلقة</option>
          <option value="processing" <?= ($filters['status'] ?? '') === 'processing' ? 'selected' : '' ?>>جارٍ النشر</option>
        </select>
      </div>
      <div class="filter-group">
        <label class="filter-label">من تاريخ</label>
        <input type="date" name="date_from" class="form-control form-control-sm" 
               value="<?= htmlspecialchars($filters['date_from'] ?? '') ?>">
      </div>
      <div class="filter-group">
        <label class="filter-label">إلى تاريخ</label>
        <input type="date" name="date_to" class="form-control form-control-sm" 
               value="<?= htmlspecialchars($filters['date_to'] ?? '') ?>">
      </div>
      <div class="filter-group">
        <label class="filter-label">&nbsp;</label>
        <button type="submit" class="btn btn-primary btn-sm">
          <i class="fas fa-search"></i> بحث
        </button>
      </div>
    </form>

    <!-- Bulk Actions -->
    <?php if (!empty($posts)): ?>
    <div class="bulk-actions">
      <div class="d-flex align-items-center gap-2">
        <input type="checkbox" id="selectAll" class="form-check-input">
        <label for="selectAll" class="form-check-label">تحديد الكل</label>
      </div>
      
      <select id="bulkAction" class="form-select" style="width: auto;">
        <option value="">إجراءات جماعية</option>
        <option value="delete">حذف المحدد</option>
        <option value="republish">إعادة نشر الفاشل</option>
        <option value="cancel_schedule">إلغاء الجدولة</option>
      </select>
      
      <button type="button" id="executeBulk" class="btn btn-primary btn-sm">تنفيذ</button>
    </div>
    <?php endif; ?>

    <!-- Posts Table -->
    <div class="table-responsive">
      <table class="table table-hover">
        <thead>
          <tr>
            <th width="40">
              <input type="checkbox" id="selectAllHeader" class="form-check-input">
            </th>
            <th>المنصة</th>
            <th>النوع</th>
            <th>المعاينة</th>
            <th>العنوان/الوصف</th>
            <th>الحساب</th>
            <th>الحالة</th>
            <th>التاريخ</th>
            <th>التفاعل</th>
            <th>الإجراءات</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($posts)): ?>
            <tr>
              <td colspan="10">
                <div class="empty-state">
                  <div class="icon">
                    <i class="fas fa-inbox"></i>
                  </div>
                  <h4>لا توجد منشورات</h4>
                  <p>ابدأ بإنشاء منشورك الأول</p>
                  <a href="<?= site_url('social_publisher') ?>" class="btn btn-primary">
                    <i class="fas fa-plus"></i> إنشاء منشور جديد
                  </a>
                </div>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($posts as $post): ?>
              <?php
                // Normalized variables (safe access)
                $content_type_raw = $post['content_type'] ?? $post['post_type'] ?? '';
                // determine badge/class
                $type_class = 'type-post';
                $type_label = $content_type_raw ?: ($post['post_type'] ?? 'منشور');

                if (stripos($content_type_raw, 'reel') !== false || stripos($post['post_type'] ?? '', 'reel') !== false) {
                  $type_class = 'type-reel';
                  $type_label = 'ريلز';
                } elseif (stripos($content_type_raw, 'story') !== false || stripos($post['post_type'] ?? '', 'story') !== false) {
                  $type_class = 'type-story';
                  $type_label = 'قصة';
                } elseif (stripos($content_type_raw, 'post') !== false || in_array($post['post_type'] ?? '', ['text','image','video','carousel'])) {
                  $type_class = 'type-post';
                  $type_label = 'منشور';
                }

                // title/description safe
                $title = $post['title'] ?? $post['content_text'] ?? '';
                $title_display = $title !== '' ? htmlspecialchars($title) : 'بدون عنوان';

                // file path (support old and new fields)
                $file_path = $post['file_path'] ?? $post['media_paths'] ?? null;
                $media_file = $post['media_files'] ?? null;

                // account id / name
                $account_id = $post['account_id'] ?? $post['platform_account_id'] ?? '';
                $account_name = $post['account_name'] ?? null;

                // try to resolve account_name for facebook if missing
                if (empty($account_name) && isset($facebook_pages) && !empty($facebook_pages) && $post['platform'] === 'facebook') {
                  foreach ($facebook_pages as $fp) {
                    if ((string)($fp['fb_page_id'] ?? $fp['page_id'] ?? '') === (string)$account_id) {
                      $account_name = $fp['page_name'] ?? $fp['page_title'] ?? $fp['page_id'];
                      break;
                    }
                  }
                }

                // try to resolve for instagram if missing
                if (empty($account_name) && isset($instagram_accounts) && !empty($instagram_accounts) && $post['platform'] === 'instagram') {
                  foreach ($instagram_accounts as $acc) {
                    if (($acc['ig_user_id'] ?? '') === $account_id) {
                      $account_name = $acc['ig_username'] ?? $acc['page_name'] ?? null;
                      break;
                    }
                  }
                }

                if (empty($account_name)) {
                  $account_name = 'حساب غير معروف';
                }

                // status label mapping (include processing)
                $status_map = [
                  'published' => 'منشور',
                  'scheduled' => 'مجدول',
                  'failed' => 'فاشل',
                  'pending' => 'معلق',
                  'publishing' => 'جاري النشر',
                  'processing' => 'جاري النشر'
                ];
                $status_key = $post['status'] ?? 'pending';
                $status_label = $status_map[$status_key] ?? $status_key;

                // counts (safe)
                $likes_count = number_format($post['likes_count'] ?? 0);
                $comments_count = number_format($post['comments_count'] ?? 0);
                $shares_count = number_format($post['shares_count'] ?? 0);

                // published/scheduled timestamps (safe)
                $created_at = !empty($post['created_at']) ? date('d/m/Y H:i', strtotime($post['created_at'])) : '-';
                $scheduled_time = !empty($post['scheduled_time'] ?? $post['scheduled_at'] ?? null) ? date('d/m/Y H:i', strtotime($post['scheduled_time'] ?? $post['scheduled_at'])) : '';
                $published_time = !empty($post['published_time']) ? date('d/m/Y H:i', strtotime($post['published_time'])) : '';
              ?>

              <tr>
                <td>
                  <input type="checkbox" class="form-check-input post-checkbox" value="<?= $post['id'] ?>">
                </td>
                
                <td>
                  <span class="platform-badge <?= ($post['platform'] === 'facebook') ? 'platform-facebook' : 'platform-instagram' ?>">
                    <?php if (($post['platform'] ?? '') === 'facebook'): ?>
                      <i class="fab fa-facebook-f"></i> Facebook
                    <?php else: ?>
                      <i class="fab fa-instagram"></i> Instagram
                    <?php endif; ?>
                  </span>
                </td>
                
                <td>
                  <span class="content-type-badge <?= $type_class ?>">
                    <?= $type_label ?>
                  </span>
                </td>
                
                <td>
                  <?php if ($file_path || $media_file): ?>
                    <?php $preview = $file_path ?? ($media_file ? 'uploads/' . $media_file : null); ?>
                    <?php if ($preview && (stripos($preview, '.jpg') !== false || stripos($preview, '.png') !== false || stripos($preview, '.jpeg') !== false || stripos($preview, '.webp') !== false)): ?>
                      <img src="<?= base_url($preview) ?>" alt="معاينة" class="file-preview">
                    <?php elseif ($preview && (stripos($preview, '.mp4') !== false || stripos($preview, '.mov') !== false || stripos($preview, '.webm') !== false)): ?>
                      <video class="file-preview" muted>
                        <source src="<?= base_url($preview) ?>" type="video/mp4">
                      </video>
                    <?php else: ?>
                      <i class="fas fa-file fa-2x text-muted"></i>
                    <?php endif; ?>
                  <?php else: ?>
                    <i class="fas fa-font fa-2x text-muted"></i>
                  <?php endif; ?>
                </td>
                
                <td style="max-width: 250px;">
                  <div class="fw-bold text-truncate">
                    <?= $title_display ?>
                  </div>
                  <div class="text-muted small text-truncate">
                    <?= htmlspecialchars(mb_substr($post['content_text'] ?? ($post['description'] ?? ''), 0, 80)) ?>
                  </div>
                </td>
                
                <td>
                  <div class="account-info">
                    <?php
                      $avatar_url = 'https://via.placeholder.com/30/1e40af/ffffff?text=?';
                      if (($post['platform'] ?? '') === 'facebook') {
                        if (!empty($account_id)) {
                          $avatar_url = 'https://graph.facebook.com/' . $account_id . '/picture?type=small';
                        }
                      } elseif (($post['platform'] ?? '') === 'instagram') {
                        foreach ($instagram_accounts as $acc) {
                          if (($acc['ig_user_id'] ?? '') === $account_id) {
                            $avatar_url = $acc['ig_profile_picture'] ?: $avatar_url;
                            break;
                          }
                        }
                      }
                    ?>
                    <img src="<?= $avatar_url ?>" alt="حساب" class="account-avatar"
                         onerror="this.src='https://via.placeholder.com/30/1e40af/ffffff?text=?';">
                    <div>
                      <div class="fw-bold small"><?= htmlspecialchars($account_name) ?></div>
                      <div class="text-muted" style="font-size: 0.7rem;"><?= htmlspecialchars($account_id) ?></div>
                    </div>
                  </div>
                </td>
                
                <td>
                  <span class="status-badge status-<?= $status_key ?>">
                    <?= $status_label ?>
                  </span>
                </td>
                
                <td>
                  <div class="small">
                    <div><strong>إنشاء:</strong> <?= $created_at ?></div>
                    <?php if (!empty($scheduled_time)): ?>
                      <div class="text-warning"><strong>جدولة:</strong> <?= $scheduled_time ?></div>
                    <?php endif; ?>
                    <?php if (!empty($published_time)): ?>
                      <div class="text-success"><strong>نشر:</strong> <?= $published_time ?></div>
                    <?php endif; ?>
                  </div>
                </td>
                
                <td>
                  <div class="small text-center">
                    <div><i class="fas fa-heart text-danger"></i> <?= $likes_count ?></div>
                    <div><i class="fas fa-comment text-primary"></i> <?= $comments_count ?></div>
                    <div><i class="fas fa-share text-success"></i> <?= $shares_count ?></div>
                  </div>
                </td>
                
                <td>
                  <div class="d-flex gap-1">
                    <?php if ($status_key === 'scheduled'): ?>
                      <a href="<?= site_url('social_publisher/edit/' . $post['id']) ?>" 
                         class="btn btn-outline-primary btn-sm" title="تعديل">
                        <i class="fas fa-edit"></i>
                      </a>
                    <?php endif; ?>
                    
                    <?php if ($status_key === 'failed'): ?>
                      <button type="button" class="btn btn-outline-warning btn-sm republish-btn" 
                              data-id="<?= $post['id'] ?>" title="إعادة نشر">
                        <i class="fas fa-redo"></i>
                      </button>
                    <?php endif; ?>
                    
                    <a href="<?= site_url('social_publisher/duplicate/' . $post['id']) ?>" 
                       class="btn btn-outline-info btn-sm" title="نسخ">
                      <i class="fas fa-copy"></i>
                    </a>
                    
                    <button type="button" class="btn btn-outline-danger btn-sm delete-btn" 
                            data-id="<?= $post['id'] ?>" title="حذف">
                      <i class="fas fa-trash"></i>
                    </button>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination (unchanged) -->
    <?php if ($pages > 1): ?>
      <div class="pagination-wrapper">
        <nav>
          <ul class="pagination">
            <?php if ($page > 1): ?>
              <li class="page-item">
                <a class="page-link" href="?<?= http_build_query(array_merge($filters, ['page' => $page - 1])) ?>">
                  <i class="fas fa-chevron-right"></i>
                </a>
              </li>
            <?php endif; ?>
            
            <?php for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++): ?>
              <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_merge($filters, ['page' => $i])) ?>">
                  <?= $i ?>
                </a>
              </li>
            <?php endfor; ?>
            
            <?php if ($page < $pages): ?>
              <li class="page-item">
                <a class="page-link" href="?<?= http_build_query(array_merge($filters, ['page' => $page + 1])) ?>">
                  <i class="fas fa-chevron-left"></i>
                </a>
              </li>
            <?php endif; ?>
          </ul>
        </nav>
      </div>
    <?php endif; ?>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Select elements safely
    const selectAll = document.getElementById('selectAll');
    const selectAllHeader = document.getElementById('selectAllHeader');
    const postCheckboxes = document.querySelectorAll('.post-checkbox');

    function updateSelectAll() {
        const checkedCount = document.querySelectorAll('.post-checkbox:checked').length;
        const totalCount = postCheckboxes.length;
        
        if (selectAll) {
            selectAll.checked = checkedCount === totalCount && totalCount > 0;
            selectAll.indeterminate = checkedCount > 0 && checkedCount < totalCount;
        }
        
        if (selectAllHeader) {
            selectAllHeader.checked = selectAll ? selectAll.checked : false;
            selectAllHeader.indeterminate = selectAll ? selectAll.indeterminate : false;
        }
    }

    if (selectAll) {
        selectAll.addEventListener('change', function() {
            postCheckboxes.forEach(cb => cb.checked = this.checked);
            updateSelectAll();
        });
    }

    if (selectAllHeader) {
        selectAllHeader.addEventListener('change', function() {
            postCheckboxes.forEach(cb => cb.checked = this.checked);
            if (selectAll) selectAll.checked = this.checked;
        });
    }

    postCheckboxes.forEach(cb => {
        cb.addEventListener('change', updateSelectAll);
    });

    // Bulk actions handler (unchanged)
    document.getElementById('executeBulk')?.addEventListener('click', function() {
        const action = document.getElementById('bulkAction').value;
        const selectedIds = Array.from(document.querySelectorAll('.post-checkbox:checked'))
                                 .map(cb => cb.value);

        if (!action) {
            alert('اختر إجراء أولاً');
            return;
        }

        if (selectedIds.length === 0) {
            alert('اختر منشورات أولاً');
            return;
        }

        if (!confirm(`هل أنت متأكد من تنفيذ هذا الإجراء على ${selectedIds.length} منشور؟`)) {
            return;
        }

        fetch('<?= site_url('social_publisher/ajax_bulk_action') ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: new URLSearchParams({
                action: action,
                'post_ids[]': selectedIds
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(`تم تنفيذ الإجراء على ${data.affected} منشور`);
                location.reload();
            } else {
                alert(data.message || 'حدث خطأ');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('حدث خطأ في الاتصال');
        });
    });

    // Republish and delete handlers (unchanged)
    document.querySelectorAll('.republish-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const postId = this.dataset.id;
            if (!confirm('هل تريد إعادة نشر هذا المنشور؟')) return;
            fetch('<?= site_url('social_publisher/ajax_bulk_action') ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: new URLSearchParams({
                    action: 'republish',
                    'post_ids[]': [postId]
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('تم تحديد المنشور لإعادة النشر');
                    location.reload();
                } else {
                    alert(data.message || 'حدث خطأ');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('حدث خطأ في الاتصال');
            });
        });
    });

    document.querySelectorAll('.delete-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const postId = this.dataset.id;
            if (!confirm('هل أنت متأكد من حذف هذا المنشور؟')) return;
            window.location.href = '<?= site_url('social_publisher/delete/') ?>' + postId;
        });
    });

    // Update stats periodically (keeps original behaviour)
    setInterval(function() {
        fetch('<?= site_url('social_publisher/ajax_get_stats') ?>')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // optional: update numbers on UI if needed
                    console.log('Stats updated:', data.stats);
                }
            })
            .catch(error => console.error('Error updating stats:', error));
    }, 60000);
});
</script>

</body>
</html>
