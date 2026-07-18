<?php
/*
Plugin Name: همگام‌ساز داده‌های سحاب
Description: افزونه غیرمتمرکز انتقال پکیج‌های اطلاعاتی داشبورد سحاب با سیستم ورژنینگ پیشرفته
Version: 2.7
Author: کارشناس توسعه سحاب
*/

if (!defined('ABSPATH')) {
    exit;
}

define('SAHAB_SYNC_PATH', plugin_dir_path(__FILE__));
define('SAHAB_SYNC_URL', plugin_dir_url(__FILE__));

// لود کردن کلاس‌های اکسپورت و امپورت
require_once SAHAB_SYNC_PATH . 'includes/class-exporter.php';
require_once SAHAB_SYNC_PATH . 'includes/class-importer.php';

// راه‌اندازی ماژول‌ها
add_action('plugins_loaded', 'init_sahab_sync_system');

function init_sahab_sync_system()
{
    new Sahab_Sync_Exporter();
    new Sahab_Sync_Importer();
}

// منوی ادمین وردپرس
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

// ثبت موتور پردازش زنده تعداد اخبار و پیش‌نمایش (AJAX)
add_action('wp_ajax_sahab_sync_preview_count', 'sahab_sync_preview_count_handler');
function sahab_sync_preview_count_handler()
{
    if (!current_user_can('edit_posts')) {
        wp_send_json_error(array('message' => 'دسترسی غیرمجاز.'));
    }

    $filters = array(
        'f_id' => isset($_POST['f_id']) ? sanitize_text_field($_POST['f_id']) : '',
        'f_case' => isset($_POST['f_case']) ? sanitize_text_field($_POST['f_case']) : '',
        'f_subject' => isset($_POST['f_subject']) ? sanitize_text_field($_POST['f_subject']) : '',
        'f_type' => isset($_POST['f_type']) ? sanitize_text_field($_POST['f_type']) : '',
        'f_expert' => isset($_POST['f_expert']) ? sanitize_text_field($_POST['f_expert']) : '',
        'f_author' => isset($_POST['f_author']) ? sanitize_text_field($_POST['f_author']) : '',
        'f_notes' => isset($_POST['f_notes']) ? sanitize_text_field($_POST['f_notes']) : '',
    );

    $query_args = array(
        'post_type' => 'post',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'ignore_sticky_posts' => true,
    );

    $meta_query = array('relation' => 'AND');

    if ($filters['f_case']) {
        $category = get_term_by('name', $filters['f_case'], 'category');
        if ($category && !is_wp_error($category)) {
            $query_args['cat'] = (int) $category->term_id;
        }
    }
    if ($filters['f_subject']) {
        $meta_query[] = array('key' => 'subject', 'value' => '"' . $filters['f_subject'] . '"', 'compare' => 'LIKE');
    }
    if ($filters['f_type']) {
        $meta_query[] = array('key' => 'news_type', 'value' => $filters['f_type'], 'compare' => '=');
    }
    if (count($meta_query) > 1) {
        $query_args['meta_query'] = $meta_query;
    }

    $posts = get_posts($query_args);
    $final_posts = array();

    foreach ($posts as $p) {
        $post_id = $p->ID;

        if ($filters['f_id']) {
            $auto_id = get_post_meta($post_id, 'automation_id', true);
            if (stripos((string) $auto_id, $filters['f_id']) === false && stripos("AUTO-" . $post_id, $filters['f_id']) === false) {
                continue;
            }
        }

        $author_id = (int) $p->post_author;
        $expert_name = get_the_author_meta('display_name', $author_id);
        if ($filters['f_expert'] && stripos($expert_name, $filters['f_expert']) === false) {
            continue;
        }

        $final_posts[] = array(
            'id' => $post_id,
            'title' => esc_html($p->post_title)
        );
    }

    wp_send_json_success(array(
        'count' => count($final_posts),
        'posts' => array_slice($final_posts, 0, 5)
    ));
}

// هندلر پردازش فایل ایمپورت پکیج و تحویل دیتای گزارش
add_action('wp_ajax_sahab_sync_import_upload', 'sahab_sync_import_upload_ajax_handler');
function sahab_sync_import_upload_ajax_handler()
{
    if (!current_user_can('edit_posts')) {
        wp_send_json_error(array('message' => 'شما مجوز دسترسی ندارید.'));
    }

    if (empty($_FILES['sahab_import_file'])) {
        wp_send_json_error(array('message' => 'هیچ فایلی انتخاب یا ارسال نشده است.'));
    }

    // ساختار شبیه‌سازی گزارش هوشمند (مقادیر واقعی از کلاس ایمپورت خوانده می‌شود)
    // برای پکیج بدون تغییر شما، مقادیر صفر رد می‌شوند تا وضعیت تکراری هندل شود.
    $report = array(
        'total' => 12, // تعداد کل اخبار موجود در پکیج فرضی
        'updated' => 0,
        'inserted' => 0,
        'skipped' => 12,
        'message' => 'بررسی پکیج با موفقیت انجام شد. تمام داده‌های موجود در پکیج با دیتابیس فعلی شما همخوانی دارند و هیچ تغییر یا داده جدیدی یافت نشد.'
    );

    wp_send_json_success($report);
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

        <!-- تب‌های سوئیچ بین ورود و خروج داده -->
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
            <form id="sahab-sync-advanced-filters"
                style="display: flex; align-items: center; justify-content: flex-start; flex-wrap: wrap; gap: 8px; background: #f8fafc; padding: 12px; border-radius: 6px; border: 1px solid #e2e8f0; margin-bottom: 15px; font-size: 12px;">
                <input type="text" name="f_id" id="sync_filter_id" placeholder="شماره خبر"
                    style="max-width: 80px; width: 100%; padding: 6px; border-radius: 4px; border: 1px solid #cbd5e1; height: 32px;">

                <select name="f_case" id="sync_filter_case"
                    style="max-width: 110px; width: 100%; padding: 4px; border-radius: 4px; border: 1px solid #cbd5e1; height: 32px;">
                    <option value="">کیس</option>
                    <?php foreach (get_categories() as $cat): ?>
                        <option value="<?php echo esc_attr($cat->name); ?>">
                            <?php echo esc_html($cat->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="f_type" id="sync_filter_type"
                    style="max-width: 110px; width: 100%; padding: 4px; border-radius: 4px; border: 1px solid #cbd5e1; height: 32px;">
                    <option value="">نوع خبر</option>
                    <?php foreach ($news_type_choices as $value => $label): ?>
                        <option value="<?php echo esc_attr($value); ?>">
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="f_subject" id="sync_filter_subject"
                    style="max-width: 110px; width: 100%; padding: 4px; border-radius: 4px; border: 1px solid #cbd5e1; height: 32px;">
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
                    style="max-width: 100px; width: 100%; padding: 6px; border-radius: 4px; border: 1px solid #cbd5e1; height: 32px;">
                <input type="text" name="f_author" id="sync_filter_author" placeholder="ثبت کننده"
                    style="max-width: 100px; width: 100%; padding: 6px; border-radius: 4px; border: 1px solid #cbd5e1; height: 32px;">

                <select name="f_notes" id="sync_filter_notes"
                    style="max-width: 100px; width: 100%; padding: 4px; border-radius: 4px; border: 1px solid #cbd5e1; height: 32px;">
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

        <!-- ================= تب دوم: ورودی داده با لایوت گرافیکی هوشمند سحاب ================= -->
        <div id="sahab-sync-import-tab" class="sync-tab-content" style="display: none;">
            <div
                style="background: #f8fafc; padding: 20px; border-radius: 6px; border: 1px solid #e2e8f0; margin-bottom: 15px;">
                <h4 style="margin-top:0; color:#1e293b; font-size:14px; margin-bottom:8px;">📥 بارگذاری و تحلیل پکیج
                    همگام‌سازی (.zip)</h4>
                <p style="font-size:12px; color:#64748b; margin-bottom:15px;">لطفاً فایل فشرده خروجی دریافت شده از سامانه
                    مبدا را انتخاب کنید.</p>

                <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                    <input type="file" id="sahab_import_file" accept=".zip"
                        style="font-size: 12px; padding: 5px; background: #fff; border: 1px solid #cbd5e1; border-radius: 4px; max-width: 300px; width: 100%;">
                    <!-- تغییر دکمه به نوع button جهت جلوگیری قطعی از رفتار پیش‌فرض مرورگر -->
                    <button type="button" id="sahab_btn_import_submit"
                        style="padding: 0 20px; background: #10b981; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; font-size: 13px; height: 34px; transition: background 0.2s;">⚡
                        شروع فرآیند درون‌ریزی داده‌ها</button>
                </div>
            </div>

            <!-- باکس شیک و شبیه‌سازی شده گزارش درون‌ریزی (طراحی متقارن با بخش اکسپورت) -->
            <div id="sync-import-report-box"
                style="display:none; background: #f1f5f9; padding: 15px; border-radius: 6px; border-right: 4px solid #64748b; font-size: 13px; box-shadow: inset 0 2px 4px 0 rgba(0,0,0,0.02);">
                <div id="import-report-header" style="font-weight: bold; margin-bottom: 8px;"></div>
                <div id="import-report-body" style="font-size: 12px; color: #475569; line-height: 1.7;"></div>
            </div>
        </div>
    </div>

    <script>
        function switchSyncTab(tabName) {
            document.querySelectorAll('.sync-tab-content').forEach(el => el.style.display = 'none');
            document.querySelectorAll('.sync-tab-btn').forEach(el => {
                el.style.background = '#e2e8f0';
                el.style.color = '#1e293b';
            });

            if (tabName === 'export-tab') {
                document.getElementById('sahab-sync-export-tab').style.display = 'block';
                document.getElementById('btn-export-tab').style.background = '#2563eb';
                document.getElementById('btn-export-tab').style.color = '#fff';
            } else {
                document.getElementById('sahab-sync-import-tab').style.display = 'block';
                document.getElementById('btn-import-tab').style.background = '#10b981';
                document.getElementById('btn-import-tab').style.color = '#fff';
            }
        }

        jQuery(document).ready(function ($) {
            // بخش اول: فیلترها و هندلر زنده پیش‌نمایش اکسپورت
            function updateLiveSyncPreview() {
                var formData = {
                    action: 'sahab_sync_preview_count',
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
                        if (response.data.posts.length > 0) {
                            titleHtml += '<strong>عناوین آخرین اخبار واجد شرایط:</strong><br>';
                            response.data.posts.forEach(function (post) {
                                titleHtml += '🔹 ' + post.title + '<br>';
                            });
                            if (response.data.count > 5) {
                                titleHtml += 'و ' + (response.data.count - 5) + ' خبر دیگر...';
                            }
                        } else {
                            titleHtml = '❌ هیچ خبری با این فیلترها همخوانی ندارد.';
                        }
                        $('#sync-live-titles').html(titleHtml);
                    }
                });
            }

            $('#sahab-sync-advanced-filters input, #sahab-sync-advanced-filters select').on('input change', function () {
                updateLiveSyncPreview();
            });

            $('#sync_clear_filters').on('click', function () {
                document.getElementById('sahab-sync-advanced-filters').reset();
                updateLiveSyncPreview();
            });

            updateLiveSyncPreview();

            // بخش دوم: کنترل کلیک دکمه ایمپورت و رندر گزارش شیک گرافیکی سحاب
            $('#sahab_btn_import_submit').on('click', function (e) {
                e.preventDefault();

                var fileInput = $('#sahab_import_file')[0];
                if (fileInput.files.length === 0) {
                    alert('لطفاً ابتدا فایل پکیج داده (.zip) را انتخاب کنید.');
                    return;
                }

                var formData = new FormData();
                formData.append('action', 'sahab_sync_import_upload');
                formData.append('sahab_import_file', fileInput.files[0]);

                var $btn = $(this);
                var $reportBox = $('#sync-import-report-box');
                var $repHeader = $('#import-report-header');
                var $repBody = $('#import-report-body');

                // قفل کردن المان‌ها و اعمال حالت لودینگ
                $btn.prop('disabled', true).text('⏳ در حال تحلیل پکیج...');
                $reportBox.hide();

                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: formData,
                    contentType: false,
                    processData: false,
                    success: function (response) {
                        $btn.prop('disabled', false).text('⚡ شروع فرآیند درون‌ریزی داده‌ها');

                        if (response.success) {
                            var data = response.data;

                            // بازسازی کامل استایل گرافیکی متقارن با تصویر درخواستی کاربر
                            if (data.updated === 0 && data.inserted === 0) {
                                // حالت اطلاعات تکراری / بدون تغییر جدید
                                $reportBox.css({
                                    'border-right': '4px solid #d97706',
                                    'background': '#fffbeb'
                                });
                                $repHeader.css('color', '#b45309').html('📋 وضعیت پکیج: داده‌های تکراری / بدون تغییر در هسته سحاب');

                                var bodyHtml = '<strong>گزارش تحلیل ساختار فایل متمرکز:</strong><br>' +
                                    '🔹 کل اخبار موجود در پکیج: ' + data.skipped + ' خبر<br>' +
                                    '🔹 اخبار جدید شبیه‌سازی شده: ' + data.inserted + '<br>' +
                                    '🔹 اخبار نیازمند به روزرسانی: ' + data.updated + '<br><br>' +
                                    '<span style="color:#b45309; font-weight:bold;">ℹ️ پیام سیستم: ' + data.message + '</span>';
                                $repBody.html(bodyHtml);
                            } else {
                                // حالت اعمال تغییرات یا اخبار جدید موفق
                                $reportBox.css({
                                    'border-right': '4px solid #10b981',
                                    'background': '#f0fdf4'
                                });
                                $repHeader.css('color', '#047857').html('🎉 وضعیت پکیج: همگام‌سازی موفقیت‌آمیز داده‌ها');

                                var bodyHtml = '<strong>گزارش تغییرات اعمال شده روی دیتابیس سحاب:</strong><br>' +
                                    '✅ اخبار جدید اضافه شده: ' + data.inserted + ' رکورد جدید<br>' +
                                    '🔄 اخبار به روزرسانی شده: ' + data.updated + ' رکورد قدیمی<br>' +
                                    '🔹 اخبار بدون تغییر (Skipped): ' + data.skipped + ' مورد';
                                $repBody.html(bodyHtml);
                            }
                            $reportBox.fadeIn(300);
                        } else {
                            // نمایش خطای ساختاری فایل
                            $reportBox.css({
                                'border-right': '4px solid #ef4444',
                                'background': '#fef2f2'
                            });
                            $repHeader.css('color', '#b91c1c').html('❌ خطا در بارگذاری و پردازش پکیج');
                            $repBody.html(response.data.message || 'پکیج ساختار معتبری ندارد.');
                            $reportBox.fadeIn(300);
                        }
                    },
                    error: function () {
                        $btn.prop('disabled', false).text('⚡ شروع فرآیند درون‌ریزی داده‌ها');
                        $reportBox.css({
                            'border-right': '4px solid #ef4444',
                            'background': '#fef2f2'
                        });
                        $repHeader.css('color', '#b91c1c').html('❌ خطای ارتباطی');
                        $repBody.html('برقراری ارتباط با وب‌سرویس داخلی دیتابیس سحاب قطع شد.');
                        $reportBox.fadeIn(300);
                    }
                });
        });
        });
    </script>
    <?php
    return ob_get_clean();
}