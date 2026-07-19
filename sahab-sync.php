<?php
/*
Plugin Name: همگام‌ساز داده‌های سحاب
Description: افزونه غیرمتمرکز انتقال پکیج‌های اطلاعاتی داشبورد سحاب با سیستم ورژنینگ پیشرفته
Version: 2.9
Author: کارشناس توسعه سحاب
*/

if (!defined('ABSPATH')) {
    exit;
}

define('SAHAB_SYNC_PATH', plugin_dir_path(__FILE__));
define('SAHAB_SYNC_URL', plugin_dir_url(__FILE__));

require_once SAHAB_SYNC_PATH . 'includes/class-exporter.php';
require_once SAHAB_SYNC_PATH . 'includes/class-importer.php';

/**
 * Returns an array of post IDs matching the given filters (Fully synced with Sahab Dashboard Core).
 *
 * @param array $filters Associative array of filter values.
 * @return int[]
 */
function sahab_sync_get_filtered_post_ids(array $filters): array
{
    $args = array(
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    );

    $meta_query = array('relation' => 'AND');

    // ۱. فیلتر تاریخ وقوع بر اساس فرمت اسلش دیتابیس
    $from = isset($filters['f_date_from']) ? str_replace('-', '/', sanitize_text_field($filters['f_date_from'])) : '';
    $to   = isset($filters['f_date_to']) ? str_replace('-', '/', sanitize_text_field($filters['f_date_to'])) : '';

    if ($from !== '' && $to !== '') {
        $meta_query[] = array(
            'key'     => 'event_date',
            'value'   => array($from, $to),
            'compare' => 'BETWEEN',
            'type'    => 'CHAR',
        );
    } elseif ($from !== '') {
        $meta_query[] = array(
            'key'     => 'event_date',
            'value'   => $from,
            'compare' => '>=',
            'type'    => 'CHAR',
        );
    } elseif ($to !== '') {
        $meta_query[] = array(
            'key'     => 'event_date',
            'value'   => $to,
            'compare' => '<=',
            'type'    => 'CHAR',
        );
    }

    // ۲. فیلتر شناسه / شماره خبر (مطابق منطق اتوماسیون داشبورد سحاب)
    if (!empty($filters['f_id'])) {
        $post_id = absint($filters['f_id']);
        if ($post_id > 0) {
            $args['p'] = $post_id;
        }
    }

    // ۳. اصلاح کلیدی فیلتر کیس: انطباق متنی نام دسته با دسته‌بندی‌های بومی وردپرس (مطابق داشبورد هسته)
    if (!empty($filters['f_case'])) {
        $category = get_term_by('name', sanitize_text_field($filters['f_case']), 'category');
        if ($category && !is_wp_error($category)) {
            $args['cat'] = (int) $category->term_id;
        }
    }

    // ۴. فیلتر نوع خبر (ACF Meta Query - تطابق دقیق)
    if (!empty($filters['f_type'])) {
        $meta_query[] = array(
            'key'     => 'news_type',
            'value'   => sanitize_text_field($filters['f_type']),
            'compare' => '=',
        );
    }

    // ۵. فیلتر موضوع خبر (ACF Meta Query - تطابق LIKE برای آرایه‌های سریالایز شده)
    if (!empty($filters['f_subject'])) {
        $meta_query[] = array(
            'key'     => 'subject',
            'value'   => '"' . sanitize_text_field($filters['f_subject']) . '"',
            'compare' => 'LIKE',
        );
    }

    // تزریق فیلترهای متاداده به کوئری اصلی
    if (count($meta_query) > 1) {
        $args['meta_query'] = $meta_query;
    }

    $query = new WP_Query($args);
    $filtered_ids = array_map('intval', (array) $query->posts);

    // ۶. لایه دوم فیلترینگ در حافظه (برای فیلترهای متنی کارشناس، ثبت‌کننده و پی‌نوشت‌ها)
    if (empty($filtered_ids)) {
        return array();
    }

    $final_ids = array();
    foreach ($filtered_ids as $p_id) {
        // فیلتر متنی کارشناس (Display Name)
        if (!empty($filters['f_expert'])) {
            $author_id = (int) get_post_field('post_author', $p_id);
            $expert_name = get_the_author_meta('display_name', $author_id);
            if (stripos($expert_name, sanitize_text_field($filters['f_expert'])) === false) {
                continue;
            }
        }

        // فیلتر متنی ثبت‌کننده (News Creator)
        if (!empty($filters['f_author'])) {
            $creator_id = get_post_meta($p_id, 'news_creator_id', true);
            $creator_user = $creator_id ? get_userdata((int) $creator_id) : false;
            $creator_name = ($creator_user && !empty($creator_user->display_name)) ? $creator_user->display_name : get_the_author_meta('display_name', (int) get_post_field('post_author', $p_id));
            if (stripos($creator_name, sanitize_text_field($filters['f_author'])) === false) {
                continue;
            }
        }

        // فیلتر پی‌نوشت‌ها بر اساس ساختار کامنت‌های سحاب
        if (!empty($filters['f_notes'])) {
            $comments = get_comments(array('post_id' => $p_id, 'status' => 'approve', 'fields' => 'ids'));
            $has_matching_note = false;
            foreach ($comments as $c_id) {
                $type = get_comment_meta($c_id, 'comment_type', true);
                if ($filters['f_notes'] === 'misc' && !in_array($type, array('note', 'theory', 'rewrite'), true)) {
                    $has_matching_note = true;
                    break;
                } elseif ($type === $filters['f_notes']) {
                    $has_matching_note = true;
                    break;
                }
            }
            if (!$has_matching_note) {
                continue;
            }
        }

        $final_ids[] = $p_id;
    }

    return $final_ids;
}

add_action('plugins_loaded', 'init_sahab_sync_system');
function init_sahab_sync_system()
{
    new Sahab_Sync_Exporter();
    new Sahab_Sync_Importer();
}

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
    include_once SAHAB_SYNC_PATH . 'admin/views/sync-page.php';
}

// لود کردن دقیق اسکریپت‌های دیت‌پیکر موجود در کل فریم‌ورک سحاب
add_action('admin_enqueue_scripts', 'sahab_sync_enqueue_datepicker_assets');
add_action('wp_enqueue_scripts', 'sahab_sync_enqueue_datepicker_assets');
function sahab_sync_enqueue_datepicker_assets()
{
    // تعریف بیس آدرس بر اساس ریشه خود افزونه sahab-sync
    $plugin_assets_url = plugins_url('assets', __FILE__);
    $plugin_assets_dir = plugin_dir_path(__FILE__) . 'assets';

    // بارگذاری استایل تقویم جلالی از پوشه css داخل افزونه
    if (file_exists($plugin_assets_dir . '/css/jalali-datepicker.min.css')) {
        wp_enqueue_style('sahab-sync-datepicker-css', $plugin_assets_url . '/css/jalali-datepicker.min.css', array(), '1.0.0');
    }

    // بارگذاری اسکریپت تقویم جلالی از پوشه js داخل افزونه با وابستگی به جی‌کوئری
    if (file_exists($plugin_assets_dir . '/js/jalali-datepicker.min.js')) {
        wp_enqueue_script('sahab-sync-datepicker-js', $plugin_assets_url . '/js/jalali-datepicker.min.js', array('jquery'), '1.0.0', true);
    }
}

// موتور پردازش زنده تعداد اخبار و پیش‌نمایش (AJAX)
add_action('wp_ajax_sahab_sync_preview_count', 'sahab_sync_preview_count_handler');
function sahab_sync_preview_count_handler()
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error('دسترسی غیرمجاز.', 403);
    }

    // یکپارچه‌سازی نام کلیدها بر اساس معماری تابع فیلتر اصلی پروژه شما
    $filters = array(
        'f_date_from' => isset($_POST['f_date_from']) ? sanitize_text_field($_POST['f_date_from']) : '',
        'f_date_to' => isset($_POST['f_date_to']) ? sanitize_text_field($_POST['f_date_to']) : '',
        'f_id' => isset($_POST['f_id']) ? sanitize_text_field($_POST['f_id']) : '',
        'f_case' => isset($_POST['f_case']) ? sanitize_text_field($_POST['f_case']) : '',
        'f_subject' => isset($_POST['f_subject']) ? sanitize_text_field($_POST['f_subject']) : '',
        'f_type' => isset($_POST['f_type']) ? sanitize_text_field($_POST['f_type']) : '',
        'f_expert' => isset($_POST['f_expert']) ? sanitize_text_field($_POST['f_expert']) : '',
        'f_author' => isset($_POST['f_author']) ? sanitize_text_field($_POST['f_author']) : '',
        'f_notes' => isset($_POST['f_notes']) ? sanitize_text_field($_POST['f_notes']) : '',
    );

    if (function_exists('sahab_sync_get_filtered_post_ids')) {
        $filtered_ids = sahab_sync_get_filtered_post_ids($filters);

        // اگر تابع به هر دلیلی آرایه برنگرداند یا کل دیتابیس مدنظر بود (فیلتر خالی)
        if (!is_array($filtered_ids)) {
            $filtered_ids = array();
        }

        $count = count($filtered_ids);
        $preview_posts = array();

        if ($count > 0) {
            $sliced_ids = array_slice($filtered_ids, 0, 5);
            foreach ($sliced_ids as $p_id) {
                $preview_posts[] = array(
                    'id' => $p_id,
                    'title' => esc_html(get_the_title($p_id))
                );
            }
        }

        wp_send_json_success(array(
            'count' => $count,
            'posts' => $preview_posts
        ));
    } else {
        // لایه فال‌بک در صورتی که تابع اصلی در دسترس نباشد تا مقدار صفر فیک ندهد
        $args = array(
            'post_type' => 'post',
            'posts_per_page' => -1,
            'fields' => 'ids'
        );
        $query = new WP_Query($args);
        wp_send_json_success(array(
            'count' => count($query->posts),
            'posts' => array()
        ));
    }
}

// رندر شورت‌کد فرانت‌اِند همگام‌سازی
add_shortcode('sahab_sync_form', 'sahab_sync_render_frontend_shortcode');
function sahab_sync_render_frontend_shortcode()
{
    if (!current_user_can('edit_posts')) {
        return '<div style="color: #ef4444; font-weight: bold; text-align: right; padding: 15px; background: #fee2e2; border-radius: 6px;">شما مجوز دسترسی به این بخش را ندارید.</div>';
    }

    $news_type_field = function_exists('acf_get_field') ? acf_get_field('news_type') : false;
    $news_type_choices = ($news_type_field && !empty($news_type_field['choices'])) ? $news_type_field['choices'] : array(
        'open' => 'آشکار',
        'official' => 'رسمی',
        'technical' => 'فنی',
        'cyber' => 'سایبری',
        'hidden' => 'پنهان',
        'ravi' => 'راوی',
    );

    ob_start();
    ?>
    <div class="sahab-sync-wrapper" dir="rtl"
        style="background: #fff; padding: 25px; border-radius: 8px; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); border: 1px solid #e2e8f0;">

        <div class="sahab-sync-tabs"
            style="display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 2px solid #e2e8f0; padding-bottom: 10px;">
            <button class="sync-tab-btn active" onclick="switchSyncTab('export-tab')" id="btn-export-tab"
                style="padding: 8px 16px; background: #2563eb; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; transition: all 0.3s;">📦
                صدور داده پکیج (Export)</button>
            <button class="sync-tab-btn" onclick="switchSyncTab('import-tab')" id="btn-import-tab"
                style="padding: 8px 16px; background: #e2e8f0; color: #1e293b; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; transition: all 0.3s;">📥
                ورود داده پکیج (Import)</button>
        </div>

        <!-- ================= تب اول: خروجی داده ================= -->
        <div id="sahab-sync-export-tab" class="sync-tab-content">
            <!-- اصلاح چیدمان: انتقال فیلدهای تاریخ به ابتدای فرم فیلترها -->
            <form id="sahab-sync-advanced-filters"
                style="display: flex; align-items: center; justify-content: flex-start; flex-wrap: wrap; gap: 8px; background: #f8fafc; padding: 12px; border-radius: 6px; border: 1px solid #e2e8f0; margin-bottom: 15px; font-size: 12px;">

                <!-- فیلدهای تاریخ در ابتدای فیلترها با استایل و کلاس دیت‌پیکر -->
                <input type="text" name="f_date_from" id="sync_filter_date_from" class="sahab-pwt-datepicker"
                    placeholder="از تاریخ ثبت"
                    style="max-width: 110px; width: 100%; padding: 6px; border-radius: 4px; border: 1px solid #cbd5e1; height: 32px; font-size: 11px; background: #fff; text-align: center;"
                    autocomplete="off">
                <input type="text" name="f_date_to" id="sync_filter_date_to" class="sahab-pwt-datepicker"
                    placeholder="تا تاریخ ثبت"
                    style="max-width: 110px; width: 100%; padding: 6px; border-radius: 4px; border: 1px solid #cbd5e1; height: 32px; font-size: 11px; background: #fff; text-align: center;"
                    autocomplete="off">

                <input type="text" name="f_id" id="sync_filter_id" placeholder="شماره خبر"
                    style="max-width: 75px; width: 100%; padding: 6px; border-radius: 4px; border: 1px solid #cbd5e1; height: 32px; text-align:center;">

                <select name="f_case" id="sync_filter_case"
                    style="max-width: 95px; width: 100%; padding: 4px; border-radius: 4px; border: 1px solid #cbd5e1; height: 32px;">
                    <option value="">کیس</option>
                    <?php foreach (get_categories() as $cat): ?>
                        <option value="<?php echo esc_attr($cat->name); ?>">
                            <?php echo esc_html($cat->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="f_type" id="sync_filter_type"
                    style="max-width: 95px; width: 100%; padding: 4px; border-radius: 4px; border: 1px solid #cbd5e1; height: 32px;">
                    <option value="">نوع خبر</option>
                    <?php foreach ($news_type_choices as $value => $label): ?>
                        <option value="<?php echo esc_attr($value); ?>">
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="f_subject" id="sync_filter_subject"
                    style="max-width: 95px; width: 100%; padding: 4px; border-radius: 4px; border: 1px solid #cbd5e1; height: 32px;">
                    <option value="">موضوع</option>
                    <?php
                    $field = function_exists('acf_get_field') ? acf_get_field('subject') : false;
                    if ($field && !empty($field['choices'])):
                        foreach ($field['choices'] as $value => $label): ?>
                            <option value="<?php echo esc_attr($value); ?>">
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; endif; ?>
                </select>

                <input type="text" name="f_expert" id="sync_filter_expert" placeholder="کارشناس"
                    style="max-width: 85px; width: 100%; padding: 6px; border-radius: 4px; border: 1px solid #cbd5e1; height: 32px;">
                <input type="text" name="f_author" id="sync_filter_author" placeholder="ثبت کننده"
                    style="max-width: 85px; width: 100%; padding: 6px; border-radius: 4px; border: 1px solid #cbd5e1; height: 32px;">

                <select name="f_notes" id="sync_filter_notes"
                    style="max-width: 85px; width: 100%; padding: 4px; border-radius: 4px; border: 1px solid #cbd5e1; height: 32px;">
                    <option value="">پی‌نوشت</option>
                    <option value="theory">نظریه</option>
                    <option value="rewrite">بازنویسی</option>
                    <option value="note">ملاحظه</option>
                    <option value="misc">متفرقه</option>
                </select>

                <button type="button" id="sync_clear_filters"
                    style="padding: 0 12px; background: #64748b; color: #fff; border-radius: 4px; border: none; cursor: pointer; height: 32px; font-weight: bold;">حذف
                    فیلترها</button>
            </form>

            <div id="sahab-sync-status-preview"
                style="background: #f1f5f9; padding: 12px; border-radius: 6px; margin-bottom: 15px; border-right: 4px solid #0284c7; font-size: 13px;">
                📊 وضعیت پکیج: <strong style="color: #0284c7;" id="sync-live-count">در حال محاسبه...</strong> خبر منطبق بر
                فیلترها یافت شد.
                <div id="sync-live-titles" style="font-size: 11px; color: #475569; margin-top: 6px; line-height: 1.6;">
                </div>
            </div>

            <div class="sahab-sync-actions-box"
                style="padding: 15px; background: #eff6ff; border: 1px dashed #3b82f6; border-radius: 6px; display: flex; align-items: center; justify-content: space-between;">
                <div>
                    <strong style="color: #1e3a8a; display: block; margin-bottom: 4px;">🚀 تولید و دانلود پکیج داده</strong>
                    <span style="color: #3b82f6; font-size: 11px;">پس از اطمینان از صحت پیش‌نمایش بالا، روی دکمه کلیک
                        کنید.</span>
                </div>
                <button type="button" id="sahab_btn_generate_sync"
                    style="padding: 0 20px; background: #2563eb; color: #fff; border-radius: 6px; border: none; cursor: pointer; height: 40px; font-weight: bold; font-size: 13px;">تولید
                    پکیج خروجی</button>
            </div>
        </div>

        <!-- ================= تب دوم: ورودی داده ================= -->
        <div id="sahab-sync-import-tab" class="sync-tab-content" style="display: none;">
            <!-- بخش ایمپورت مثل قبل باقی می‌ماند -->
            <div
                style="background: #f8fafc; padding: 20px; border-radius: 6px; border: 1px solid #e2e8f0; margin-bottom: 15px;">
                <h4 style="margin-top:0; color:#1e293b; font-size:14px; margin-bottom:8px;">📥 بارگذاری و تحلیل پکیج
                    همگام‌سازی (.zip)</h4>
                <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                    <input type="file" id="sahab_import_file" accept=".zip"
                        style="font-size: 12px; padding: 5px; background: #fff; border: 1px solid #cbd5e1; border-radius: 4px; max-width: 300px; width: 100%;">
                    <button type="button" id="sahab_btn_import_submit"
                        style="padding: 0 20px; background: #10b981; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; font-size: 13px; height: 34px;">⚡
                        درون‌ریزی داده‌ها</button>
                </div>
            </div>
            <div id="sync-import-report-box" style="display:none; background: #f1f5f9; padding: 15px; border-radius: 6px;">
                <div id="import-report-header" style="font-weight: bold; margin-bottom: 8px;"></div>
                <div id="import-report-body" style="font-size: 12px; color: #475569;"></div>
            </div>
        </div>
    </div>

    <script>
        function switchSyncTab(tabName) {
            document.querySelectorAll('.sync-tab-content').forEach(el => el.style.display = 'none');
            document.querySelectorAll('.sync-tab-btn').forEach(el => {
                el.style.background = '#e2e8f0'; el.style.color = '#1e293b';
            });
            if (tabName === 'export-tab') {
                document.getElementById('sahab-sync-export-tab').style.display = 'block';
                document.getElementById('btn-export-tab').style.background = '#2563eb'; document.getElementById('btn-export-tab').style.color = '#fff';
            } else {
                document.getElementById('sahab-sync-import-tab').style.display = 'block';
                document.getElementById('btn-import-tab').style.background = '#10b981'; document.getElementById('btn-import-tab').style.color = '#fff';
            }
        }

        jQuery(document).ready(function ($) {
            // فعال‌سازی و مقداردهی اولیه دیت‌پیکر شمسی
            if ($.fn.persianDatepicker) {
                $(".sahab-pwt-datepicker").persianDatepicker({
                    initialValue: false,
                    format: 'YYYY/MM/DD',
                    autoClose: true,
                    onSelect: function (unix) {
                        // به محض انتخاب تاریخ، شمارش زنده مجدداً اجرا شود
                        updateLiveSyncPreview();
                    }
                });
            }

            function updateLiveSyncPreview() {
                var formData = {
                    action: 'sahab_sync_preview_count',
                    f_date_from: $('#sync_filter_date_from').val(),
                    f_date_to: $('#sync_filter_date_to').val(),
                    f_id: $('#sync_filter_id').val(),
                    f_case: $('#sync_filter_case').val(),
                    f_type: $('#sync_filter_type').val(),
                    f_subject: $('#sync_filter_subject').val(),
                    f_expert: $('#sync_filter_expert').val(),
                    f_author: $('#sync_filter_author').val(),
                    f_notes: $('#sync_filter_notes').val()
                };

                $('#sync-live-count').text('در حال محاسبه...');

                $.post('<?php echo admin_url('admin-ajax.php'); ?>', formData, function (response) {
                    if (response.success) {
                        $('#sync-live-count').text(response.data.count);
                        var titleHtml = '';
                        
                        // بررسی هوشمند اینکه آیا کاربر فیلتری پر کرده است یا خیر
                        var isAnyFilterApplied = $('#sync_filter_date_from').val() || $('#sync_filter_date_to').val() || 
                                                 $('#sync_filter_id').val() || $('#sync_filter_case').val() || 
                                                 $('#sync_filter_type').val() || $('#sync_filter_subject').val() || 
                                                 $('#sync_filter_expert').val() || $('#sync_filter_author').val() || 
                                                 $('#sync_filter_notes').val();

                        if (response.data.count > 0) {
                            if (response.data.posts && response.data.posts.length > 0) {
                                titleHtml += '<strong>عناوین آخرین اخبار واجد شرایط:</strong><br>';
                                response.data.posts.forEach(function (post) {
                                    titleHtml += '🔹 ' + post.title + '<br>';
                                });
                                if (response.data.count > 5) {
                                    titleHtml += 'و ' + (response.data.count - 5) + ' خبر دیگر...';
                                }
                            }
                            
                            // نمایش متن صحیح برای حالت بدون فیلتر (کل سیستم)
                            if (!isAnyFilterApplied) {
                                titleHtml = '🔹 پکیج شامل اخبار کل سیستم است (بدون اعمال فیلتر خاص).';
                            }
                        } else {
                            titleHtml = '❌ هیچ خبری با فیلترهای انتخابی همخوانی ندارد.';
                        }
                        $('#sync-live-titles').html(titleHtml);
                    } else {
                        $('#sync-live-count').text('۰');
                        $('#sync-live-titles').html('<span style="color:#ef4444;">موردی یافت نشد یا فیلتر نامعتبر است.</span>');
                    }
                }).fail(function () {
                    $('#sync-live-count').text('۰');
                    $('#sync-live-titles').html('<span style="color:#ef4444;">خطای ارتباطی با سرور لوکال.</span>');
                });
            }

            // تریگر زنده تغییرات اینپوت‌ها و فیلترها
            $('#sahab-sync-advanced-filters input, #sahab-sync-advanced-filters select').on('input change', function () {
                updateLiveSyncPreview();
            });

            $('#sahab_btn_generate_sync').on('click', function (e) {
                e.preventDefault();
                var filters = {
                    action: 'sahab_sync_export_download',
                    f_date_from: $('#sync_filter_date_from').val(),
                    f_date_to: $('#sync_filter_date_to').val(),
                    f_id: $('#sync_filter_id').val(),
                    f_case: $('#sync_filter_case').val(),
                    f_type: $('#sync_filter_type').val(),
                    f_subject: $('#sync_filter_subject').val(),
                    f_expert: $('#sync_filter_expert').val(),
                    f_author: $('#sync_filter_author').val(),
                    f_notes: $('#sync_filter_notes').val()
                };
                var $form = $('<form>', { action: '<?php echo admin_url('admin-ajax.php'); ?>', method: 'POST' });
                $.each(filters, function (key, value) {
                    $form.append($('<input>', { type: 'hidden', name: key, value: value }));
                });
                $('body').append($form); $form.submit(); $form.remove();
            });

            $('#sync_clear_filters').on('click', function () {
                document.getElementById('sahab-sync-advanced-filters').reset();
                updateLiveSyncPreview();
            });

            updateLiveSyncPreview();
        });
    </script>
    <?php
    return ob_get_clean();
}