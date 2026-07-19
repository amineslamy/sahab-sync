<?php
if (!defined('ABSPATH')) {
    exit;
}

class Sahab_Sync_Importer
{
    public function __construct()
    {
        // هوک‌های AJAX برای مدیریت آپلود و پردازش پکیج زیپ سحاب
        add_action('wp_ajax_sahab_sync_execute_import', array($this, 'handle_ajax_import'));
        add_action('wp_ajax_sahab_do_import', array($this, 'handle_ajax_import'));
    }

    public function handle_ajax_import()
    {
        if (!empty($_POST['security'])) {
            check_ajax_referer('sahab_sync_nonce', 'security');
        }

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('message' => 'شما دسترسی لازم برای ورود اطلاعات را ندارید.'));
        }

        $file = array();
        if (!empty($_FILES['import_file'])) {
            $file = $_FILES['import_file'];
        } elseif (!empty($_FILES['sahab_import_file'])) {
            $file = $_FILES['sahab_import_file'];
        } elseif (!empty($_FILES['sync_import_file'])) {
            $file = $_FILES['sync_import_file'];
        }

        if (empty($file)) {
            wp_send_json_error(array('message' => 'لطفاً ابتدا فایل زیپ پکیج سحاب را انتخاب کنید.'));
        }

        // بررسی پسوند فایل
        $file_ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        if (strtolower($file_ext) !== 'zip') {
            wp_send_json_error(array('message' => 'فرمت فایل نامعتبر است. فقط فایل زیپ (zip.) مجاز است.'));
        }

        $upload_dir = wp_upload_dir();
        $extract_dir = $upload_dir['basedir'] . '/sahab-sync-extracts/' . uniqid('import_');

        // ایجاد پوشه موقت برای اکسترکت
        if (!file_exists($extract_dir)) {
            wp_mkdir_p($extract_dir);
        }

        // ۱. باز کردن فایل زیپ
        $zip = new ZipArchive();
        if ($zip->open($file['tmp_name']) !== TRUE) {
            wp_send_json_error(array('message' => 'خطا در باز کردن فایل فشرده زیپ.'));
        }

        $zip->extractTo($extract_dir);
        $zip->close();

        $manifest_path = $extract_dir . '/manifest.json';
        if (!file_exists($manifest_path)) {
            $this->clean_temporary_dir($extract_dir);
            wp_send_json_error(array('message' => 'فایل ساختار داده (manifest.json) در پکیج یافت نشد.'));
        }

        // ۲. خواندن داده‌های جی‌سون مانیفست
        $manifest_content = file_get_contents($manifest_path);
        $posts_data = json_decode($manifest_content, true);

        if (empty($posts_data) || !is_array($posts_data)) {
            $this->clean_temporary_dir($extract_dir);
            wp_send_json_error(array('message' => 'محتوای مانیفست خالی یا نامعتبر است.'));
        }

        $imported_count = 0;
        $updated_count = 0;
        $skipped_count = 0;

        // ۳. چرخه بررسی تک‌تک اخبار برای اعمال منطق ورژنینگ غیرمتمرکز
        foreach ($posts_data as $post_data) {
            $uuid = sanitize_text_field($post_data['uuid']);
            $incoming_version = isset($post_data['version']) ? (int) $post_data['version'] : 1;
            $incoming_hash = sanitize_text_field($post_data['content_hash']);

            // جستجوی خبر در سیستم جاری بر اساس UUID
            $existing_post = $this->get_post_by_uuid($uuid);

            if ($existing_post) {
                $post_id = $existing_post->ID;
                $current_version = (int) get_post_meta($post_id, 'sahab_version_number', true);
                $current_hash = get_post_meta($post_id, 'sahab_content_hash', true);

                // سناریوی تعارض: اگر هش‌ها یکی بود، یعنی هیچ تغییر واقعی رخ نداده است
                if ($current_hash === $incoming_hash) {
                    $skipped_count++;
                    continue;
                }

                // اگر نسخه ورودی جدیدتر یا مساوی بود، Overwrite انجام می‌شود و وردپرس اتوماتیک Revision می‌سازد
                if ($incoming_version >= $current_version) {
                    // افزایش شماره نسخه یکی بیشتر از ماکزیمم دو نود برای هماهنگی زنجیره ورژن‌ها
                    $new_version = max($incoming_version, $current_version) + 1;

                    $this->update_existing_post($post_id, $post_data, $new_version, $incoming_hash, $extract_dir);
                    $updated_count++;
                } else {
                    // نسخه ورودی قدیمی‌تر از نسخه لوکال جاری است؛ پس نادیده گرفته می‌شود
                    $skipped_count++;
                }
            } else {
                // خبر در این سیستم وجود ندارد؛ به عنوان یک رکورد جدید ثبت می‌شود
                $this->create_new_post($post_data, $incoming_version, $incoming_hash, $extract_dir);
                $imported_count++;
            }
        }

        // پاکسازی فایل‌های اکسترکت شده موقت از روی هارد
        $this->clean_temporary_dir($extract_dir);

        // آماده‌سازی گزارش نهایی فرآیند
        if (($imported_count + $updated_count) > 0) {
            $message = "تعداد {$imported_count} خبر جدید وارد و {$updated_count} خبر قبلی به‌روزرسانی شد.";
            if ($skipped_count > 0) {
                $message .= "\n💡 تعداد {$skipped_count} خبر به دلیل تکراری بودن یا داشتن نسخه قدیمی‌تر نادیده گرفته شد.";
            }

            wp_send_json_success(array(
                'message' => $message,
                'imported' => $imported_count,
                'updated' => $updated_count,
                'skipped' => $skipped_count
            ));
        } else {
            // در صورتی که تمام پکیج تکراری باشد، به عنوان موفقیت بدون تغییر به فرانت‌اِند پاس داده می‌شود تا دکمه قفل نگردد
            wp_send_json_success(array(
                'message' => "اطلاعات این پکیج کاملاً با داده‌های فعلی سامانه یکسان است.\nهیچ داده جدید یا تغییریافته‌ای جهت درون‌ریزی یافت نشد (تعداد کل اخبار بررسی شده: {$skipped_count}).",
                'imported' => 0,
                'updated' => 0,
                'skipped' => $skipped_count
            ));
        }
    }

    private function get_post_by_uuid($uuid)
    {
        $args = array(
            'post_type' => 'post',
            'meta_key' => 'sahab_uuid',
            'meta_value' => $uuid,
            'posts_per_page' => 1,
            'post_status' => 'any',
            'no_found_rows' => true
        );
        $query = new WP_Query($args);
        return $query->have_posts() ? $query->posts[0] : null;
    }

    private function create_new_post($data, $version, $hash, $extract_dir)
    {
        $post_id = wp_insert_post(array(
            'post_title' => sanitize_text_field($data['title']),
            'post_content' => wp_kses_post($data['content']),
            'post_excerpt' => sanitize_text_field($data['excerpt']),
            'post_status' => sanitize_text_field($data['status']),
            'post_date' => sanitize_text_field($data['date']),
        ));

        if (!is_wp_error($post_id)) {
            $this->sync_meta_and_taxonomies($post_id, $data, $version, $hash, $extract_dir);
        }
    }

    private function update_existing_post($post_id, $data, $new_version, $hash, $extract_dir)
    {
        wp_update_post(array(
            'ID' => $post_id,
            'post_title' => sanitize_text_field($data['title']),
            'post_content' => wp_kses_post($data['content']),
            'post_excerpt' => sanitize_text_field($data['excerpt']),
            'post_status' => sanitize_text_field($data['status']),
        ));

        $this->sync_meta_and_taxonomies($post_id, $data, $new_version, $hash, $extract_dir);
    }

    private function sync_meta_and_taxonomies($post_id, $data, $version, $hash, $extract_dir)
    {
        // ثبت متادیتاهای پایه سحاب
        update_post_meta($post_id, 'sahab_uuid', $data['uuid']);
        update_post_meta($post_id, 'sahab_version_number', $version);
        update_post_meta($post_id, 'sahab_content_hash', $hash);

        // ۱. بررسی و استخراج فیلد موضوع (subject) چه در دیتای اصلی چه در متادیتا
        $raw_subject = null;
        if (isset($data['subject'])) {
            $raw_subject = $data['subject'];
        } elseif (isset($data['metadata']['subject'])) {
            $raw_subject = $data['metadata']['subject'];
        }

        // ۲. یکدست‌سازی و تبدیل فیلد موضوع به آرایه استاندارد برای ACF Checkbox
        if ($raw_subject !== null) {
            $final_subjects = array();

            if (is_array($raw_subject)) {
                // حالت آرایه جی‌سان
                $final_subjects = $raw_subject;
            } elseif (is_string($raw_subject)) {
                // بررسی آرایه سریالایز شده پی‌اچ‌پی (تک موضوعی‌ها)
                $unserialized = @unserialize($raw_subject);
                if ($unserialized !== false || $raw_subject === 'b:0;') {
                    $final_subjects = (array) $unserialized;
                } else {
                    // حالت رشته متنی ترکیب شده با جداکننده | 
                    $final_subjects = array_map('trim', explode('|', $raw_subject));
                }
            }

            // ذخیره اصولی فیلد متناسب با رفتار ACF تا باکس‌ها به درستی تیک بخورند
            if (function_exists('update_field')) {
                update_field('subject', $final_subjects, $post_id);
            } else {
                update_post_meta($post_id, 'subject', $final_subjects);
            }
        }

        // ثبت سایر متادیتاها و فیلدهای ACF
        if (!empty($data['metadata']) && is_array($data['metadata'])) {
            foreach ($data['metadata'] as $meta_key => $meta_value) {
                // از این فیلدها عبور می‌کنیم چون قبلاً به صورت دستی یا اختصاصی پردازش شده‌اند
                if (in_array($meta_key, array('sahab_uuid', 'sahab_version_number', 'sahab_content_hash', 'subject'))) {
                    continue;
                }
                update_post_meta($post_id, $meta_key, $meta_value);
            }
        }

        // همگام‌سازی دسته‌ب بندی‌ها و تگ‌ها
        if (!empty($data['taxonomies']) && is_array($data['taxonomies'])) {
            foreach ($data['taxonomies'] as $taxonomy => $terms) {
                if (taxonomy_exists($taxonomy)) {
                    wp_set_object_terms($post_id, $terms, $taxonomy, false);
                }
            }
        }

        // جابجایی و درون‌ریزی تصویر شاخص در صورت وجود در پوشه media پکیج
        if (isset($data['metadata']['_thumbnail_id'])) {
            $this->import_media_file($post_id, $data['metadata']['_thumbnail_id'], $extract_dir, true);
        }
    }

    private function import_media_file($post_id, $old_thumb_id, $extract_dir, $is_featured = false)
    {
        $media_folder = $extract_dir . '/media';
        if (!file_exists($media_folder) || !is_dir($media_folder)) {
            return;
        }

        $files = glob($media_folder . '/*');
        if (empty($files)) {
            return;
        }

        foreach ($files as $file_path) {
            $filename = basename($file_path);

            $wp_upload_dir = wp_upload_dir();
            $target_path = $wp_upload_dir['path'] . '/' . $filename;

            if (copy($file_path, $target_path)) {
                $filetype = wp_check_filetype($filename, null);
                $attachment = array(
                    'guid' => $wp_upload_dir['url'] . '/' . $filename,
                    'post_mime_type' => $filetype['type'],
                    'post_title' => preg_replace('/\.[^.]+$/', '', $filename),
                    'post_content' => '',
                    'post_status' => 'inherit'
                );

                $attach_id = wp_insert_attachment($attachment, $target_path, $post_id);

                require_once(ABSPATH . 'wp-admin/includes/image.php');
                if (!is_wp_error($attach_id)) {
                    $attach_data = wp_generate_attachment_metadata($attach_id, $target_path);
                    wp_update_attachment_metadata($attach_id, $attach_data);

                    if ($is_featured) {
                        set_post_thumbnail($post_id, $attach_id);
                        break;
                    }
                }
            }
        }
    }

    private function clean_temporary_dir($dir)
    {
        if (!file_exists($dir)) {
            return;
        }
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            @$todo($fileinfo->getRealPath());
        }
        @rmdir($dir);
    }
}