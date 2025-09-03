<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>لوحة التحكم - النشر الموحد</title>
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
  font-size: 2.5rem;
  font-weight: 800;
  margin-bottom: 10px;
  display: flex;
  align-items: center;
  gap: 15px;
}

.stats-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
  gap: 20px;
  margin-bottom: 30px;
}

.stat-card {
  background: var(--card-bg);
  border: 1px solid var(--border-color);
  border-radius: var(--border-radius);
  padding: 25px;
  text-align: center;
  box-shadow: var(--shadow-sm);
  transition: var(--transition);
}

.stat-card:hover {
  transform: translateY(-2px);
  box-shadow: var(--shadow-md);
}

.stat-icon {
  font-size: 2.5rem;
  margin-bottom: 15px;
}

.stat-card.total .stat-icon { color: var(--info-color); }
.stat-card.today .stat-icon { color: var(--success-color); }
.stat-card.scheduled .stat-icon { color: var(--warning-color); }
.stat-card.failed .stat-icon { color: var(--danger-color); }

.stat-number {
  font-size: 2.5rem;
  font-weight: 800;
  margin-bottom: 5px;
}

.stat-card.total .stat-number { color: var(--info-color); }
.stat-card.today .stat-number { color: var(--success-color); }
.stat-card.scheduled .stat-number { color: var(--warning-color); }
.stat-card.failed .stat-number { color: var(--danger-color); }

.stat-label {
  color: var(--text-secondary);
  font-weight: 600;
}

.content-grid {
  display: grid;
  grid-template-columns: 2fr 1fr;
  gap: 30px;
}

.content-card {
  background: var(--card-bg);
  border: 1px solid var(--border-color);
  border-radius: var(--border-radius);
  padding: 25px;
  box-shadow: var(--shadow-sm);
}

.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 20px;
  padding-bottom: 15px;
  border-bottom: 2px solid var(--border-color);
}

.card-title {
  font-size: 1.3rem;
  font-weight: 700;
  color: var(--text-primary);
  display: flex;
  align-items: center;
  gap: 10px;
}

.post-item {
  display: flex;
  align-items: center;
  gap: 15px;
  padding: 15px;
  border: 1px solid var(--border-color);
  border-radius: 10px;
  margin-bottom: 15px;
  transition: var(--transition);
}

.post-item:hover {
  background: var(--light-bg);
  border-color: var(--primary-light);
}

.post-icon {
  width: 50px;
  height: 50px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.5rem;
  color: white;
}

.post-icon.facebook { background: #1877f2; }
.post-icon.instagram { background: linear-gradient(45deg, #f09433 0%, #e6683c 25%, #dc2743 50%, #cc2366 75%, #bc1888 100%); }

.post-info {
  flex: 1;
}

.post-title {
  font-weight: 600;
  color: var(--text-primary);
  margin-bottom: 5px;
}

.post-meta {
  font-size: 0.9rem;
  color: var(--text-secondary);
  display: flex;
  gap: 15px;
}

.post-status {
  padding: 4px 8px;
  border-radius: 6px;
  font-size: 0.8rem;
  font-weight: 600;
}

.status-published { background: #dcfce7; color: var(--success-color); }
.status-scheduled { background: #fef3c7; color: var(--warning-color); }
.status-failed { background: #fef2f2; color: var(--danger-color); }

.quick-actions {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 15px;
  margin-top: 30px;
}

.action-card {
  background: var(--card-bg);
  border: 1px solid var(--border-color);
  border-radius: var(--border-radius);
  padding: 20px;
  text-align: center;
  text-decoration: none;
  color: var(--text-primary);
  transition: var(--transition);
  box-shadow: var(--shadow-sm);
}

.action-card:hover {
  transform: translateY(-2px);
  box-shadow: var(--shadow-md);
  color: var(--text-primary);
  text-decoration: none;
}

.action-icon {
  font-size: 2rem;
  margin-bottom: 10px;
  color: var(--primary-color);
}

.action-title {
  font-weight: 600;
  margin-bottom: 5px;
}

.action-desc {
  font-size: 0.9rem;
  color: var(--text-secondary);
}

.empty-state {
  text-align: center;
  padding: 40px 20px;
  color: var(--text-secondary);
}

.empty-state .icon {
  font-size: 3rem;
  color: var(--border-color);
  margin-bottom: 15px;
}

@media (max-width: 768px) {
  .main-container {
    padding: 10px;
  }
  
  .header-card {
    padding: 20px;
  }
  
  .header-title {
    font-size: 2rem;
  }
  
  .content-grid {
    grid-template-columns: 1fr;
  }
  
  .stats-grid {
    grid-template-columns: repeat(2, 1fr);
  }
  
  .quick-actions {
    grid-template-columns: 1fr;
  }
}
</style>
</head>
<body>

<div class="main-container">
  <!-- Header -->
  <div class="header-card">
    <h1 class="header-title">
      <i class="fas fa-tachometer-alt"></i>
      لوحة التحكم
    </h1>
    <p class="mb-0 opacity-75">إدارة ومتابعة منشوراتك على Facebook و Instagram</p>
  </div>

  <!-- Statistics -->
  <div class="stats-grid">
    <div class="stat-card total">
      <div class="stat-icon">
        <i class="fas fa-chart-bar"></i>
      </div>
      <div class="stat-number"><?= number_format($stats['total_posts']) ?></div>
      <div class="stat-label">إجمالي المنشورات</div>
    </div>
    
    <div class="stat-card today">
      <div class="stat-icon">
        <i class="fas fa-calendar-day"></i>
      </div>
      <div class="stat-number"><?= number_format($stats['published_today']) ?></div>
      <div class="stat-label">منشور اليوم</div>
    </div>
    
    <div class="stat-card scheduled">
      <div class="stat-icon">
        <i class="fas fa-clock"></i>
      </div>
      <div class="stat-number"><?= number_format($stats['scheduled_posts']) ?></div>
      <div class="stat-label">مجدولة</div>
    </div>
    
    <div class="stat-card failed">
      <div class="stat-icon">
        <i class="fas fa-exclamation-triangle"></i>
      </div>
      <div class="stat-number"><?= number_format($stats['failed_posts']) ?></div>
      <div class="stat-label">فاشلة</div>
    </div>
  </div>

  <!-- Content Grid -->
  <div class="content-grid">
    <!-- Recent Posts -->
    <div class="content-card">
      <div class="card-header">
        <h3 class="card-title">
          <i class="fas fa-history"></i>
          آخر المنشورات
        </h3>
        <a href="<?= site_url('social_publisher/listing') ?>" class="btn btn-outline-primary btn-sm">
          عرض الكل
        </a>
      </div>
      
      <?php if (!empty($recent_posts)): ?>
        <?php foreach (array_slice($recent_posts, 0, 5) as $post): ?>
          <div class="post-item">
            <div class="post-icon <?= $post['platform'] ?>">
              <?php if ($post['platform'] === 'facebook'): ?>
                <i class="fab fa-facebook-f"></i>
              <?php else: ?>
                <i class="fab fa-instagram"></i>
              <?php endif; ?>
            </div>
            
            <div class="post-info">
              <div class="post-title">
                <?= htmlspecialchars(mb_substr($post['title'] ?: $post['description'] ?: 'منشور بدون عنوان', 0, 50)) ?>
              </div>
              <div class="post-meta">
                <span><i class="fas fa-user"></i> <?= htmlspecialchars($post['account_name'] ?? 'حساب غير معروف') ?></span>
                <span><i class="fas fa-calendar"></i> <?= date('d/m/Y H:i', strtotime($post['created_at'])) ?></span>
              </div>
            </div>
            
            <div class="post-status status-<?= $post['status'] ?>">
              <?php
                $status_labels = [
                  'published' => 'منشور',
                  'scheduled' => 'مجدول',
                  'failed' => 'فاشل',
                  'pending' => 'معلق'
                ];
                echo $status_labels[$post['status']] ?? $post['status'];
              ?>
            </div>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="empty-state">
          <div class="icon">
            <i class="fas fa-inbox"></i>
          </div>
          <p>لا توجد منشورات بعد</p>
        </div>
      <?php endif; ?>
    </div>

    <!-- Upcoming Posts -->
    <div class="content-card">
      <div class="card-header">
        <h3 class="card-title">
          <i class="fas fa-calendar-alt"></i>
          المنشورات القادمة
        </h3>
      </div>
      
      <?php if (!empty($upcoming_posts)): ?>
        <?php foreach ($upcoming_posts as $post): ?>
          <div class="post-item">
            <div class="post-icon <?= $post['platform'] ?>">
              <?php if ($post['platform'] === 'facebook'): ?>
                <i class="fab fa-facebook-f"></i>
              <?php else: ?>
                <i class="fab fa-instagram"></i>
              <?php endif; ?>
            </div>
            
            <div class="post-info">
              <div class="post-title">
                <?= htmlspecialchars(mb_substr($post['title'] ?: $post['description'] ?: 'منشور بدون عنوان', 0, 40)) ?>
              </div>
              <div class="post-meta">
                <span><i class="fas fa-clock"></i> <?= date('d/m/Y H:i', strtotime($post['scheduled_time'])) ?></span>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="empty-state">
          <div class="icon">
            <i class="fas fa-calendar-times"></i>
          </div>
          <p>لا توجد منشورات مجدولة</p>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Quick Actions -->
  <div class="quick-actions">
    <a href="<?= site_url('social_publisher') ?>" class="action-card">
      <div class="action-icon">
        <i class="fas fa-plus-circle"></i>
      </div>
      <div class="action-title">نشر جديد</div>
      <div class="action-desc">إنشاء منشور جديد</div>
    </a>
    
    <a href="<?= site_url('social_publisher/listing') ?>" class="action-card">
      <div class="action-icon">
        <i class="fas fa-list"></i>
      </div>
      <div class="action-title">قائمة المنشورات</div>
      <div class="action-desc">عرض جميع المنشورات</div>
    </a>
    
    <a href="<?= site_url('social_publisher/stats') ?>" class="action-card">
      <div class="action-icon">
        <i class="fas fa-chart-line"></i>
      </div>
      <div class="action-title">الإحصائيات</div>
      <div class="action-desc">تقارير مفصلة</div>
    </a>
    
    <a href="<?= site_url('social_publisher/templates') ?>" class="action-card">
      <div class="action-icon">
        <i class="fas fa-file-alt"></i>
      </div>
      <div class="action-title">القوالب</div>
      <div class="action-desc">إدارة القوالب</div>
    </a>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// تحديث الإحصائيات كل 30 ثانية
setInterval(function() {
    fetch('<?= site_url('social_publisher/ajax_get_stats') ?>')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.querySelector('.stat-card.total .stat-number').textContent = data.stats.total_posts.toLocaleString();
                document.querySelector('.stat-card.today .stat-number').textContent = data.stats.published_today.toLocaleString();
                document.querySelector('.stat-card.scheduled .stat-number').textContent = data.stats.scheduled_posts.toLocaleString();
                document.querySelector('.stat-card.failed .stat-number').textContent = data.stats.failed_posts.toLocaleString();
            }
        })
        .catch(error => console.error('Error updating stats:', error));
}, 30000);
</script>

</body>
</html>
