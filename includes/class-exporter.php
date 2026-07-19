<?php
if (!defined('ABSPATH')) {
    exit;
}

class Sahab_Sync_Exporter {

    public function __construct() {
        // تغییر نام اکشن جهت یکپارچگی کامل با درخواست فرم جاوااسکریپت فرانت‌اند
        add_action('wp_ajax_sahab_sync_export_download', array($this, 'handle_direct_post_export'));
    }

    /**
     * هندلر مستقیم پردازش فیلترها، ساخت زیپ و دانلود آن آنی مرورگر
     */
    public function handle_direct_post_export() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('دسترسی غیرمجاز.', 403);
        }

        // دریافت و پاکسازی فیلترهای ارسالی از فرم فرانت‌اِند
        $filters = array(
            'f_date_from' => isset($_POST['f_date_from']) ? sanitize_text_field($_POST['f_date_from']) : '',
            'f_date_to'   => isset($_POST['f_date_to']) ? sanitize_text_field($_POST['f_date_to']) : '',
            'f_id'        => isset($_POST['f_id']) ? sanitize_text_field($_POST['f_id']) : '',
            'f_case'      => isset($_POST['f_case']) ? sanitize_text_field($_POST['f_case']) : '',
            'f_type'      => isset($_POST['f_type']) ? sanitize_text_field($_POST['f_type']) : '',
            'f_subject'   => isset($_POST['f_subject']) ? sanitize_text_field($_POST['f_subject']) : '',
            'f_expert'    => isset($_POST['f_expert']) ? sanitize_text_field($_POST['f_expert']) : '',
            'f_author'    => isset($_POST['f_author']) ? sanitize_text_field($_POST['f_author']) : '',
            'f_notes'     => isset($_POST['f_notes']) ? sanitize_text_field($_POST['f_notes']) : '',
        );

        $has_filters = !empty($filters['f_date_from']) || !empty($filters['f_date_to']) ||
            !empty($filters['f_case']) || !empty($filters['f_type']) ||
            !empty($filters['f_subject']) || !empty($filters['f_expert']) ||
            !empty($filters['f_author']) || !empty($filters['f_notes']) ||
            !empty($filters['f_id']);

        // استفاده از متد فیلترینگ پیشرفته بومی سحاب جهت گلچین کردن پست‌ها
        $target_ids = array();
        if (function_exists('sahab_sync_get_filtered_post_ids')) {
            $target_ids = sahab_sync_get_filtered_post_ids($filters);
        }

        if (empty($target_ids)) {
            wp_die('هیچ خبری متناسب با فیلترهای انتخابی شما جهت صدور یافت نشد. به صفحه قبل بازگردید.');
        }

        $upload_dir = wp_upload_dir();
        
        // پاکسازی فایل‌های زیپ موقت قدیمی
        $sync_dir = $upload_dir['basedir'] . '/sahab-sync-temp';
        if (file_exists($sync_dir) && is_dir($sync_dir)) {
            foreach (glob($sync_dir . '/*.zip') as $file) {
                if (time() - filemtime($file) > 300) {
                    unlink($file);
                }
            }
        }

        $export_data = array();
        $media_files = array();

        // واکشی اطلاعات اخبار گلچین شده فیلتر
        foreach ($target_ids as $post_id) {
            $post = get_post($post_id);
            if (!$post) continue;

            // ۱. مدیریت و تضمین وجود UUID
            $uuid = get_post_meta($post_id, 'sahab_uuid', true);
            if (empty($uuid)) {
                $uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
                update_post_meta($post_id, 'sahab_uuid', $uuid);
            }

            // ۲. مدیریت شماره نسخه
            $version = get_post_meta($post_id, 'sahab_version_number', true);
            if (empty($version)) {
                $version = 1;
                update_post_meta($post_id, 'sahab_version_number', $version);
            }

            $all_meta = get_post_custom($post_id);
            $clean_meta = array();
            foreach ($all_meta as $key => $values) {
                if (strpos($key, '_') === 0 && !in_array($key, array('_thumbnail_id', '_sahab_reg_date_shamsi'))) {
                    continue;
                }
                $clean_meta[$key] = $values[0];
            }

            $title   = $post->post_title;
            $content = $post->post_content;
            
            // ۳. تولید هش محتوا
            $hash_base = $title . $content . json_encode($clean_meta);
            $content_hash = md5($hash_base);
            update_post_meta($post_id, 'sahab_content_hash', $content_hash);

            $export_data[] = array(
                'uuid'          => $uuid,
                'version'       => (int)$version,
                'content_hash'  => $content_hash,
                'title'         => $title,
                'content'       => $content,
                'excerpt'       => $post->post_excerpt,
                'status'        => $post->post_status,
                'date'          => $post->post_date,
                'last_modified' => $post->post_modified,
                'metadata'      => $clean_meta,
                'taxonomies'    => $this->get_post_taxonomies_data($post_id)
            );

            // واکشی تصاویر شاخص
            if (has_post_thumbnail($post_id)) {
                $thumb_id = get_post_thumbnail_id($post_id);
                $thumb_path = get_attached_file($thumb_id);
                if ($thumb_path && file_exists($thumb_path)) {
                    $media_files[basename($thumb_path)] = array(
                        'absolute_path' => $thumb_path,
                        'relative_path' => 'media/' . basename($thumb_path)
                    );
                }
            }

            // واکشی ضمایم
            $attachments = get_attached_media('', $post_id);
            if (!empty($attachments)) {
                foreach ($attachments as $attachment) {
                    $file_path = get_attached_file($attachment->ID);
                    if ($file_path && file_exists($file_path)) {
                        $media_files[basename($file_path)] = array(
                            'absolute_path' => $file_path,
                            'relative_path' => 'media/' . basename($file_path)
                        );
                    }
                }
            }
        }

        // ایجاد فایل فشرده
        if (!file_exists($sync_dir)) {
            wp_mkdir_p($sync_dir);
        }

        $zip_filename = 'sahab_sync_node_' . date('Ymd_His') . '.zip';
        $zip_filepath = $sync_dir . '/' . $zip_filename;

        $zip = new ZipArchive();
        if ($zip->open($zip_filepath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
            $zip->addFromString('manifest.json', json_encode($export_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            if (!empty($media_files)) {
                foreach ($media_files as $file) {
                    if (file_exists($file['absolute_path'])) {
                        $zip->addFile($file['absolute_path'], $file['relative_path']);
                    }
                }
            }
            $zip->close();
        }

        // ارسال مستقیم هدرها جهت دانلود اتوماتیک در مرورگر کاربر
        if (file_exists($zip_filepath)) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            header('Content-Description: File Transfer');
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $zip_filename . '"');
            header('Expires: 0');
            header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
            header('Pragma: public');
            header('Content-Length: ' . filesize($zip_filepath));

            readfile($zip_filepath);
            unlink($zip_filepath); // حذف پس از اتمام دانلود جهت حفظ امنیت دیتای شبکه
            exit;
        }

        wp_die('خطا در تولید فایل پکیج همگام‌سازی.');
    }

    private function get_post_taxonomies_data($post_id) {
        $taxonomies = get_object_taxonomies('post');
        $output = array();
        foreach ($taxonomies as $tax) {
            $terms = wp_get_post_terms($post_id, $tax, array('fields' => 'names'));
            if (!is_wp_error($terms) && !empty($terms)) {
                $output[$tax] = $terms;
            }
        }
        return $output;
    }
}