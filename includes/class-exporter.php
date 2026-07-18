<?php
if (!defined('ABSPATH')) {
    exit;
}

class Sahab_Sync_Exporter {

    public function __construct() {
        add_action('wp_ajax_sahab_do_export', array($this, 'handle_ajax_export'));
        add_action('admin_init', array($this, 'handle_direct_download'));
    }

    public function handle_ajax_export() {
        check_ajax_referer('sahab_sync_nonce', 'security');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('message' => 'شما دسترسی لازم را ندارید.'));
        }

        $current_user_id = get_current_user_id();
        
        // پاکسازی فایل‌های زیپ موقت قدیمی
        $upload_dir = wp_upload_dir();
        $sync_dir = $upload_dir['basedir'] . '/sahab-sync-temp';
        if (file_exists($sync_dir) && is_dir($sync_dir)) {
            foreach (glob($sync_dir . '/*.zip') as $file) {
                if (time() - filemtime($file) > 600) {
                    unlink($file);
                }
            }
        }

        $args = array(
            'post_type'      => 'post', 
            'post_status'    => array('publish', 'pending', 'draft'),
            'posts_per_page' => -1,
        );

        $query = new WP_Query($args);
        $export_data = array();
        $media_files = array();

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();
                
                // ۱. مدیریت و تضمین وجود UUID
                $uuid = get_post_meta($post_id, 'sahab_uuid', true);
                if (empty($uuid)) {
                    $uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
                    update_post_meta($post_id, 'sahab_uuid', $uuid);
                }

                // ۲. مدیریت شماره نسخه (Version Control)
                $version = get_post_meta($post_id, 'sahab_version_number', true);
                if (empty($version)) {
                    $version = 1;
                    update_post_meta($post_id, 'sahab_version_number', $version);
                }

                $all_meta = get_post_custom($post_id);
                $clean_meta = array();
                foreach ($all_meta as $key => $values) {
                    // حذف متادیتاهای سیستمی غیرضروری برای سبک شدن هش
                    if (strpos($key, '_') === 0 && !in_array($key, array('_thumbnail_id', '_sahab_reg_date_shamsi'))) {
                        continue;
                    }
                    $clean_meta[$key] = $values[0];
                }

                $title   = get_the_title();
                $content = get_the_content();
                
                // ۳. تولید هش محتوا (Content Hash) برای تشخیص تغییرات واقعی ساختار داده
                $hash_base = $title . $content . json_encode($clean_meta);
                $content_hash = md5($hash_base);

                // ذخیره هش در دیتابیس خود سیستم برای مقایسه‌های بعدی
                update_post_meta($post_id, 'sahab_content_hash', $content_hash);

                $last_modified = get_the_modified_date('Y-m-d H:i:s', $post_id);

                $export_data[] = array(
                    'uuid'          => $uuid,
                    'version'       => (int)$version,
                    'content_hash'  => $content_hash,
                    'title'         => $title,
                    'content'       => $content,
                    'excerpt'       => get_the_excerpt(),
                    'status'        => get_post_status($post_id),
                    'date'          => get_the_date('Y-m-d H:i:s'),
                    'last_modified' => $last_modified,
                    'metadata'      => $clean_meta,
                    'taxonomies'    => $this->get_post_taxonomies_data($post_id)
                );

                // واکشی تصویر شاخص
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

                // واکشی ضمایم متصل به پست
                $attachments = get_attached_media('', $post_id);
                if (!empty($attachments)) {
                    foreach ($attachments as $attachment) {
                        $file_path = get_attached_file($attachment->ID);
                        if ($file_path && file_exists($file_path)) {
                            $media_files[basename($file_path)] = array(
                                'absolute_path' => $file_path,
                                0 => 'media/' . basename($file_path), // سازگاری با متدهای قدیمی در صورت وجود
                                'relative_path' => 'media/' . basename($file_path)
                            );
                        }
                    }
                }

                // اسکن تصاویر داخل متون
                preg_match_all('/src="([^"]+)"/', $content, $matches);
                if (!empty($matches[1])) {
                    foreach ($matches[1] as $url) {
                        if (strpos($url, $upload_dir['baseurl']) !== false) {
                            $relative_url_path = str_replace($upload_dir['baseurl'], '', $url);
                            $file_path = $upload_dir['basedir'] . $relative_url_path;
                            $file_path = preg_replace('/-\d+x\d+\.(jpg|jpeg|png|gif|webp)$/i', '.$1', $file_path);

                            if (file_exists($file_path)) {
                                $media_files[basename($file_path)] = array(
                                    'absolute_path' => $file_path,
                                    'relative_path' => 'media/' . basename($file_path)
                                );
                            }
                        }
                    }
                }
            }
            wp_reset_postdata();
        }

        // ساخت فایل زیپ روی هارد دیسک نود جاری
        $zip_filename = $this->generate_zip_on_disk($export_data, $media_files, $current_user_id);

        if ($zip_filename) {
            wp_send_json_success(array('filename' => $zip_filename));
        } else {
            wp_send_json_error(array('message' => 'خطا در تولید فایل فشرده یا خبری یافت نشد.'));
        }
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

    private function generate_zip_on_disk($data, $media, $user_id) {
        $zip = new ZipArchive();
        $upload_dir = wp_upload_dir();
        $sync_dir = $upload_dir['basedir'] . '/sahab-sync-temp';
        
        if (!file_exists($sync_dir)) {
            wp_mkdir_p($sync_dir);
        }

        $zip_filename = 'sahab_sync_node_' . date('Ymd_His') . '.zip';
        $zip_filepath = $sync_dir . '/' . $zip_filename;

        if ($zip->open($zip_filepath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
            return false;
        }

        $zip->addFromString('manifest.json', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        if (!empty($media)) {
            foreach ($media as $file) {
                if (file_exists($file['absolute_path'])) {
                    $zip->addFile($file['absolute_path'], $file['relative_path']);
                }
            }
        }

        $zip->close();

        return file_exists($zip_filepath) ? $zip_filename : false;
    }

    public function handle_direct_download() {
        if (isset($_GET['action']) && $_GET['action'] === 'sahab_download_file' && isset($_GET['file'])) {
            if (!current_user_can('edit_posts')) {
                wp_die('دسترسی غیرمجاز');
            }

            $filename = sanitize_file_name($_GET['file']);
            $upload_dir = wp_upload_dir();
            $filepath = $upload_dir['basedir'] . '/sahab-sync-temp/' . $filename;

            if (file_exists($filepath) && strpos($filename, 'sahab_sync_node_') === 0) {
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }

                header('Content-Description: File Transfer');
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Expires: 0');
                header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
                header('Pragma: public');
                header('Content-Length: ' . filesize($filepath));

                readfile($filepath);
                unlink($filepath); 
                exit;
            }
            wp_die('فایل یافت نشد یا منقضی شده است.');
        }
    }
}