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
      <div class="stat-number"><?= number_format($stats['total']) ?></div>
      <div class="stat-label">إجمالي المنشورات</div>
    </div>
    <div class="stat-card">
      <div class="stat-number"><?= number_format($stats['published']) ?></div>
      <div class="stat-label">منشورة</div>
    </div>
    <div class="stat-card">
      <div class="stat-number"><?= number_format($stats['scheduled']) ?></div>
      <div class="stat-label">مجدولة</div>
    </div>
    <div class="stat-card">
      <div class="stat-number"><?= number_format($stats['failed']) ?></div>
      <div class="stat-label">فاشلة</div>
    </div>
  </div>

  <!-- Content -->
  <div class="content-card">
    <!-- Filters -->
    <form method="get" class="filters-bar">
      <div class="filter-group">
        <label class="filter-label">البحث</label>
        <input type="text" name="q" class="form-control form-control-sm" 
               value="<?= htmlspecialchars($filters['q'] ?? '') ?>" 
               placeholder="ابحث في العنوان أو الوصف...">
      </div>
      
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
              <tr>
                <td>
                  <input type="checkbox" class="form-check-input post-checkbox" value="<?= $post['id'] ?>">
                </td>
                
                <td>
                  <span class="platform-badge platform-<?= $post['platform'] ?>">
                    <?php if ($post['platform'] === 'facebook'): ?>
                      <i class="fab fa-facebook-f"></i> Facebook
                    <?php else: ?>
                      <i class="fab fa-instagram"></i> Instagram
                    <?php endif; ?>
                  </span>
                </td>
                
                <td>
                  <?php
                    $type_class = 'type-post';
                    $type_label = $post['content_type'];
                    
                    if (strpos($post['content_type'], 'reel') !== false) {
                      $type_class = 'type-reel';
                      $type_label = 'ريلز';
                    } elseif (strpos($post['content_type'], 'story') !== false) {
                      $type_class = 'type-story';
                      $type_label = 'قصة';
                    } elseif (strpos($post['content_type'], 'post') !== false) {
                      $type_class = 'type-post';
                      $type_label = 'منشور';
                    }
                  ?>
                  <span class="content-type-badge <?= $type_class ?>">
                    <?= $type_label ?>
                  </span>
                </td>
                
                <td>
                  <?php if (!empty($post['file_path'])): ?>
                    <?php if (strpos($post['content_type'], 'photo') !== false || strpos($post['content_type'], 'image') !== false): ?>
                      <img src="<?= base_url($post['file_path']) ?>" alt="معاينة" class="file-preview">
                    <?php elseif (strpos($post['content_type'], 'video') !== false): ?>
                      <video class="file-preview" muted>
                        <source src="<?= base_url($post['file_path']) ?>" type="video/mp4">
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
                    <?= htmlspecialchars($post['title'] ?: 'بدون عنوان') ?>
                  </div>
                  <div class="text-muted small text-truncate">
                    <?= htmlspecialchars(mb_substr($post['description'] ?? '', 0, 80)) ?>
                  </div>
                </td>
                
                <td>
                  <div class="account-info">
                    <?php
                      // جلب صورة الحساب
                      $avatar_url = 'https://via.placeholder.com/30/1e40af/ffffff?text=?';
                      if ($post['platform'] === 'facebook') {
                        $avatar_url = 'https://graph.facebook.com/' . $post['account_id'] . '/picture?type=small';
                      } elseif ($post['platform'] === 'instagram') {
                        // البحث عن صورة الحساب في قاعدة البيانات
                        foreach ($instagram_accounts as $acc) {
                          if ($acc['ig_user_id'] === $post['account_id']) {
                            $avatar_url = $acc['ig_profile_picture'] ?: $avatar_url;
                            break;
                          }
                        }
                      }
                    ?>
                    <img src="<?= $avatar_url ?>" alt="حساب" class="account-avatar"
                         onerror="this.src='https://via.placeholder.com/30/1e40af/ffffff?text=?';">
                    <div>
                      <div class="fw-bold small"><?= htmlspecialchars($post['account_name'] ?? 'حساب غير معروف') ?></div>
                      <div class="text-muted" style="font-size: 0.7rem;"><?= htmlspecialchars($post['account_id']) ?></div>
                    </div>
                  </div>
                </td>
                
                <td>
                  <span class="status-badge status-<?= $post['status'] ?>">
                    <?php
                      $status_labels = [
                        'published' => 'منشور',
                        'scheduled' => 'مجدول',
                        'failed' => 'فاشل',
                        'pending' => 'معلق',
                        'publishing' => 'جاري النشر'
                      ];
                      echo $status_labels[$post['status']] ?? $post['status'];
                    ?>
                  </span>
                </td>
                
                <td>
                  <div class="small">
                    <div><strong>إنشاء:</strong> <?= date('d/m/Y H:i', strtotime($post['created_at'])) ?></div>
                    <?php if (!empty($post['scheduled_time'])): ?>
                      <div class="text-warning"><strong>جدولة:</strong> <?= date('d/m/Y H:i', strtotime($post['scheduled_time'])) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($post['published_time'])): ?>
                      <div class="text-success"><strong>نشر:</strong> <?= date('d/m/Y H:i', strtotime($post['published_time'])) ?></div>
                    <?php endif; ?>
                  </div>
                </td>
                
                <td>
                  <div class="small text-center">
                    <div><i class="fas fa-heart text-danger"></i> <?= number_format($post['likes_count'] ?? 0) ?></div>
                    <div><i class="fas fa-comment text-primary"></i> <?= number_format($post['comments_count'] ?? 0) ?></div>
                    <div><i class="fas fa-share text-success"></i> <?= number_format($post['shares_count'] ?? 0) ?></div>
                  </div>
                </td>
                
                <td>
                  <div class="d-flex gap-1">
                    <?php if ($post['status'] === 'scheduled'): ?>
                      <a href="<?= site_url('social_publisher/edit/' . $post['id']) ?>" 
                         class="btn btn-outline-primary btn-sm" title="تعديل">
                        <i class="fas fa-edit"></i>
                      </a>
                    <?php endif; ?>
                    
                    <?php if ($post['status'] === 'failed'): ?>
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

    <!-- Pagination -->
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
    // تحديد الكل
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
            selectAllHeader.checked = selectAll.checked;
            selectAllHeader.indeterminate = selectAll.indeterminate;
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

    // الإجراءات الجماعية
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

    // إعادة النشر
    document.querySelectorAll('.republish-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const postId = this.dataset.id;
            
            if (!confirm('هل تريد إعادة نشر هذا المنشور؟')) {
                return;
            }

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

    // حذف المنشور
    document.querySelectorAll('.delete-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const postId = this.dataset.id;
            
            if (!confirm('هل أنت متأكد من حذف هذا المنشور؟')) {
                return;
            }

            window.location.href = '<?= site_url('social_publisher/delete/') ?>' + postId;
        });
    });

    // تحديث الإحصائيات كل دقيقة
    setInterval(function() {
        fetch('<?= site_url('social_publisher/ajax_get_stats') ?>')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // تحديث الأرقام في الصفحة
                    console.log('Stats updated:', data.stats);
                }
            })
            .catch(error => console.error('Error updating stats:', error));
    }, 60000);
});
</script>

</body>
</html>