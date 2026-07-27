<?php
if (!defined('ABSPATH')) {
    exit;
}

banka_tms_app_require_login();

global $post;
$page_slug = $post ? $post->post_name : 'tms-dashboard';

?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<div class="banka-tms-shell">
    <aside class="banka-tms-sidebar">
        <h2>Banka TMS</h2>
        <nav>
            <a href="<?php echo esc_url(home_url('/tms-dashboard/')); ?>">Dashboard</a>
            <a href="<?php echo esc_url(home_url('/tms-tasks/')); ?>">Tasks</a>
            <a href="<?php echo esc_url(home_url('/tms-users/')); ?>">Users</a>
            <a href="<?php echo esc_url(home_url('/tms-vendors/')); ?>">Vendors</a>
            <a href="<?php echo esc_url(home_url('/tms-sites/')); ?>">Sites</a>
            <a href="<?php echo esc_url(home_url('/tms-categories/')); ?>">Categories</a>
            <a href="<?php echo esc_url(home_url('/tms-designations/')); ?>">Designations</a>
            <a href="<?php echo esc_url(home_url('/tms-recurring/')); ?>">Recurring</a>
            <a href="<?php echo esc_url(home_url('/tms-action-log/')); ?>">Action Log</a>
            <a href="<?php echo esc_url(home_url('/tms-settings/')); ?>">Settings</a>
        </nav>
    </aside>
    <main class="banka-tms-content">
        <div class="banka-tms-topbar">
            <h1><?php echo esc_html(get_the_title()); ?></h1>
            <a href="<?php echo esc_url(wp_logout_url(home_url())); ?>">Logout</a>
        </div>

        <?php if ($page_slug === 'tms-dashboard') : ?>
            <div class="banka-tms-dashboard">
                <div class="banka-tms-dashboard-header">
                    <div>
                        <p class="banka-tms-eyebrow">TaskPro Dashboard</p>
                        <h2>Dashboard</h2>
                        <p>Monitor tasks, manage users, and review pending work from a single workspace.</p>
                    </div>
                </div>

                <div class="banka-tms-card">
                    <h3>Quick Actions</h3>
                    <div class="banka-tms-quick-actions">
                        <a class="banka-tms-btn blue" href="<?php echo esc_url(home_url('/tms-tasks/')); ?>">➕ New Task</a>
                        <a class="banka-tms-btn green" href="<?php echo esc_url(home_url('/tms-users/')); ?>">👤 Add User</a>
                        <a class="banka-tms-btn orange" href="#">⬇ PDF</a>
                        <a class="banka-tms-btn yellow" href="#">⬇ Excel</a>
                        <a class="banka-tms-btn pink" href="#">📨 Daily Report</a>
                    </div>
                </div>

                <div class="banka-tms-stats-grid">
                    <div class="banka-tms-stat-card blue">
                        <span class="banka-tms-stat-label">Total Tasks</span>
                        <strong class="banka-tms-stat-value">0</strong>
                    </div>
                    <div class="banka-tms-stat-card orange">
                        <span class="banka-tms-stat-label">Pending Tasks</span>
                        <strong class="banka-tms-stat-value">0</strong>
                    </div>
                    <div class="banka-tms-stat-card red">
                        <span class="banka-tms-stat-label">Overdue Tasks</span>
                        <strong class="banka-tms-stat-value">0</strong>
                    </div>
                    <div class="banka-tms-stat-card green">
                        <span class="banka-tms-stat-label">Completed Tasks</span>
                        <strong class="banka-tms-stat-value">0</strong>
                    </div>
                    <div class="banka-tms-stat-card purple">
                        <span class="banka-tms-stat-label">Total Users</span>
                        <strong class="banka-tms-stat-value">0</strong>
                    </div>
                </div>

                <div class="banka-tms-card">
                    <h3>Assigneewise Pending Tasks</h3>
                    <div class="banka-tms-table-wrap">
                        <table class="banka-tms-table">
                            <thead>
                                <tr><th>Employee</th><th>Total Pending</th><th>Not Yet Started</th><th>In Progress</th></tr>
                            </thead>
                            <tbody>
                                <tr><td colspan="4">No pending tasks to analyze.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="banka-tms-card">
                    <h3>Projectwise Pending Tasks</h3>
                    <div class="banka-tms-table-wrap">
                        <table class="banka-tms-table">
                            <thead>
                                <tr><th>Project</th><th>Total Pending</th><th>Not Yet Started</th><th>In Progress</th></tr>
                            </thead>
                            <tbody>
                                <tr><td colspan="4">No pending tasks to analyze.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php elseif ($page_slug === 'tms-tasks') : ?>
            <div class="banka-tms-card">
                <h3>Task management</h3>
                <form id="banka-tms-task-form" class="banka-tms-form">
                    <input type="hidden" id="task-id" value="0" />
                    <input type="text" id="task-title" placeholder="Task title" required />
                    <textarea id="task-description" placeholder="Description"></textarea>
                    <select id="task-status">
                        <option>Pending</option>
                        <option>In Progress</option>
                        <option>Completed</option>
                    </select>
                    <button type="submit">Save task</button>
                </form>
                <div id="banka-tms-task-status" class="banka-tms-status"></div>
                <h4>Recent tasks</h4>
                <ul id="banka-tms-task-list"></ul>
            </div>
        <?php elseif ($page_slug === 'tms-users') : ?>
            <div class="banka-tms-card">
                <h3>User management</h3>
                <form id="banka-tms-user-form" class="banka-tms-form">
                    <input type="text" id="user-name" placeholder="Full name" required />
                    <input type="text" id="user-login" placeholder="Username" required />
                    <input type="email" id="user-email" placeholder="Email" required />
                    <input type="password" id="user-password" placeholder="Password" required />
                    <select id="user-role">
                        <option value="subscriber">Subscriber</option>
                        <option value="editor">Editor</option>
                        <option value="administrator">Administrator</option>
                    </select>
                    <button type="submit">Create user</button>
                </form>
                <div id="banka-tms-user-status" class="banka-tms-status"></div>
                <div id="banka-tms-user-list"></div>
            </div>
        <?php elseif ($page_slug === 'tms-vendors') : ?>
            <div class="banka-tms-card">
                <h3>Vendors</h3>
                <form id="banka-tms-vendor-form" class="banka-tms-form">
                    <input type="text" id="vendor-name" placeholder="Vendor name" required />
                    <input type="email" id="vendor-email" placeholder="Email" />
                    <input type="text" id="vendor-mobile" placeholder="Mobile" />
                    <button type="submit">Save vendor</button>
                </form>
                <div id="banka-tms-vendor-status" class="banka-tms-status"></div>
                <div id="banka-tms-vendor-list"></div>
            </div>
        <?php elseif ($page_slug === 'tms-sites') : ?>
            <div class="banka-tms-card">
                <h3>Sites</h3>
                <p>Site master data can be managed from this module once the workflow is expanded.</p>
            </div>
        <?php elseif ($page_slug === 'tms-categories') : ?>
            <div class="banka-tms-card">
                <h3>Categories</h3>
                <p>Task and user categories can be configured here in the next iteration.</p>
            </div>
        <?php elseif ($page_slug === 'tms-designations') : ?>
            <div class="banka-tms-card">
                <h3>Designations</h3>
                <p>Designation master data is ready for expansion in this plugin version.</p>
            </div>
        <?php elseif ($page_slug === 'tms-recurring') : ?>
            <div class="banka-tms-card">
                <h3>Recurring tasks</h3>
                <p>Recurring task management can be added as a next-step module.</p>
            </div>
        <?php elseif ($page_slug === 'tms-action-log') : ?>
            <div class="banka-tms-card">
                <h3>Action log</h3>
                <p>Audit and action history can be displayed here in the next iteration.</p>
            </div>
        <?php else : ?>
            <div class="banka-tms-card">
                <h3>Settings</h3>
                <p>Settings and developer controls can be wired into this page next.</p>
                <form class="banka-tms-form">
                    <input type="text" placeholder="Organization name" />
                    <input type="text" placeholder="Logo URL" />
                    <button type="button">Save</button>
                </form>
            </div>
        <?php endif; ?>
    </main>
</div>
<?php wp_footer(); ?>
</body>
</html>
<?php
exit;
