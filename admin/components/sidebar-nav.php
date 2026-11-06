<aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <a href="/admin/dashboard.php" class="brand">
                <div class="brand-icon">
                    <i class="fas fa-cube"></i>
                </div>
                <span><?= htmlspecialchars($site_name) ?></span>
            </a>
            <button class="close-sidebar" onclick="toggleSidebar()">
                <i class="fas fa-times"></i>
            </button>
        </div>

   <nav class="sidebar-nav">

    <div class="nav-section">
        <div class="nav-title">Main</div>

        <a href="/admin/dashboard.php" class="nav-link active">
            <i class="fas fa-home"></i>
            Dashboard
        </a>

        <a href="/admin/blogs-manager.php" class="nav-link">
            <i class="fas fa-newspaper"></i>
            Blog Posts
        </a>

        <?php if (!empty($permissions['files']['access_file_manager'])): ?>
        <a href="/admin/file-manager.php" class="nav-link">
            <i class="fas fa-images"></i>
            File Manager
        </a>
        <?php endif; ?>

        <?php if (!empty($permissions['blogs']['manage_comments'])): ?>
        <a href="/admin/comments-manager.php" class="nav-link">
            <i class="fas fa-comments"></i>
            Comments
        </a>
        <?php endif; ?>

    </div>

    <div class="nav-section">
        <div class="nav-title">Marketing</div>

        <?php if (!empty($permissions['push_notifications']['send'])): ?>
        <a href="/push_notifications/push-manager.php" class="nav-link">
            <i class="fas fa-bell"></i>
            Push Notifications
        </a>
        <?php endif; ?>

    </div>

    <div class="nav-section">
        <div class="nav-title">Content</div>

        <?php if (!empty($permissions['blogs']['manage_tags'])): ?>
        <a href="/admin/tag-manager.php" class="nav-link">
            <i class="fas fa-tags"></i>
            Tag Manager
        </a>
        <?php endif; ?>
        	
        <?php if (!empty($permissions['blogs']['manage_categories'])): ?>
                <a href="/admin/categories-manager.php" class="nav-link">
                    <i class="fas fa-list"></i>
                    Category Manager
                </a>
                <?php endif; ?>

        <?php if (!empty($permissions['analytics']['view_basic'])): ?>
        <a href="/admin/analytics.php" class="nav-link">
            <i class="fas fa-chart-line"></i>
            Analytics
        </a>
        <?php endif; ?>

    </div>

    <div class="nav-section">
        <div class="nav-title">System</div>

        <?php if (!empty($permissions['settings']['maintenance_mode'])): ?>
        <a href="/admin/cache-manager.php" class="nav-link">
            <i class="fas fa-bolt"></i>
            Cache Manager
        </a>
        <?php endif; ?>

        <?php if (!empty($permissions['security']['view_logs'])): ?>
        <a href="/admin/activity-logs.php" class="nav-link">
            <i class="fas fa-history"></i>
            Activity Logs
        </a>
        <?php endif; ?>

        <?php if (!empty($permissions['users']['create'])): ?>
        <a href="/admin/user-manager.php" class="nav-link">
            <i class="fas fa-user"></i>
            Users Manager
        </a>
        <?php endif; ?>
        	
        <a href="/admin/api/logout.php" class="nav-link">
            <i class="fas fa-sign-out-alt"></i>
            Logout 
        </a>

    </div>

</nav>    

        <div class="sidebar-footer">
            <div class="user-card">
                <div class="user-avatar">
                    <?= strtoupper(substr($username, 0, 1)) ?>
                </div>
                <div class="user-info">
                    <div class="user-name"><?= htmlspecialchars($username) ?></div>
                    <div class="user-role"><?= ucfirst(htmlspecialchars($_SESSION['role'])) ?></div>
                </div>
            </div>
        </div>
    </aside>