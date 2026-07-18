<?php
/*
Plugin Name: همگام‌ساز داده‌های سحاب
Description: افزونه غیرمتمرکز انتقال پکیج‌های اطلاعاتی داشبورد سحاب با سیستم ورژنینگ پیشرفته
Version: 2.0
Author: کارشناس توسعه سحاب
*/

if (!defined('ABSPATH')) {
    exit;
}

// لود کردن کلاس‌های اکسپورت و امپورت
require_once plugin_dir_path(__FILE__) . 'includes/class-exporter.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-importer.php';

// راه‌اندازی ماژول‌ها
add_action('plugins_loaded', 'init_sahab_sync_system');

function init_sahab_sync_system()
{
    new Sahab_Sync_Exporter();
    new Sahab_Sync_Importer();
}

// ساخت منوی پیشخوان سحاب (در صورتی که قبلا نساخته‌اید)
add_action('admin_menu', 'sahab_sync_add_admin_menu');
function sahab_sync_add_admin_menu()
{
    add_menu_page(
        'همگام‌سازی سحاب',
        'پشتیبان سحاب',
        'edit_posts',
        'sahab-sync-page',
        'sahab_sync_render_admin_page',
        'dashicons-cloud-upload',
        30
    );
}

function sahab_sync_render_admin_page()
{
    include_once plugin_dir_path(__FILE__) . 'admin/views/sync-page.php';
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