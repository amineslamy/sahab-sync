<?php
/**
 * Plugin Name: موتور همگام‌سازی آفلاین سحاب (Sahab Sync Engine)
 * Description: زیرسیستم اختصاصی انتقال داده میان سیستم‌های آفلاین کارشناسان و سیستم مرکزی مادر بدون تداخل شناسه.
 * Version: 1.0.0
 * Author: تیم توسعه سحاب
 * License: GPL2
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SAHAB_SYNC_PATH', plugin_dir_path(__FILE__));
define('SAHAB_SYNC_URL', plugin_dir_url(__FILE__));

require_once SAHAB_SYNC_PATH . 'includes/class-db-setup.php';
require_once SAHAB_SYNC_PATH . 'includes/class-exporter.php';

if (class_exists('Sahab_DB_Setup')) {
    $sahab_db_setup = new Sahab_DB_Setup();
}

if (class_exists('Sahab_Sync_Exporter')) {
    $sahab_sync_exporter = new Sahab_Sync_Exporter();
}

// ثبت منوی پیشخوان برای مدیریت و تست‌های توسعه
add_action('admin_menu', 'sahab_sync_add_admin_menu');
function sahab_sync_add_admin_menu()
{
    add_menu_page(
        'همگام‌سازی سحاب',
        'همگام‌سازی سحاب',
        'edit_posts',
        'sahab-sync-panel',
        'sahab_sync_render_admin_page',
        'dashicons-cloud-upload',
        30
    );
}

function sahab_sync_render_admin_page()
{
    require_once SAHAB_SYNC_PATH . 'admin/views/sync-page.php';
}

// ثبت شورت‌کد برای نمایش هم‌زمان در فرانت‌اِند (میز کار سحاب)
add_shortcode('sahab_sync_form', 'sahab_sync_render_frontend_shortcode');
function sahab_sync_render_frontend_shortcode()
{
    if (!current_user_can('edit_posts')) {
        return '<div style="color: #ef4444; font-weight: bold; text-align: right;">شما مجوز دسترسی به این بخش را ندارید.</div>';
    }

    ob_start();
    require SAHAB_SYNC_PATH . 'admin/views/sync-page.php';
    return ob_get_clean();
}