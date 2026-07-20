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

                // اگر نسخه ورودی جدیدتر یا مساوی بود، Overwrite انجام می‌شود
                if ($incoming_version >= $current_version) {
                    // افزایش شماره نسخه یکی بیشتر از ماکزیمم دو نود برای هماهنگی زنجیره ورژن‌ها
                    $new_version = max($incoming_version, $current_version) + 1;

                    $this->update_existing_post($post_id, $post_data, $new_version, $incoming_hash, $extract_dir);
                    $updated_count++;
                } else {
                    // نسخه ورودی قدیمی‌تر از نسخه لوکال جاری است
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
        global $wpdb;

        // ۱. تنظیم نویسنده اصلی خبر بر اساس نام کاربری مبدا
        if (!empty($data['author_username'])) {
            $target_author = get_user_by('login', $data['author_username']);
            if ($target_author) {
                $wpdb->update($wpdb->posts, array('post_author' => $target_author->ID), array('ID' => $post_id));
            }
        }

        // ثبت متادیتاهای پایه سحاب
        update_post_meta($post_id, 'sahab_uuid', $data['uuid']);
        update_post_meta($post_id, 'sahab_version_number', $version);
        update_post_meta($post_id, 'sahab_content_hash', $hash);

        $media_folder = $extract_dir . '/media';

        // ۲. پردازش و درون‌ریزی فایل‌های پیوست سه‌گانه واقعی
        if (file_exists($media_folder) && is_dir($media_folder)) {
            for ($i = 1; $i <= 3; $i++) {
                $meta_field_key = "attachment_file_{$i}";
                if (isset($data['metadata'][$meta_field_key])) {
                    $old_file_value = $data['metadata'][$meta_field_key];
                    if (!empty($old_file_value)) {
                        $filename = basename($old_file_value);
                        $local_file_path = $media_folder . '/' . $filename;

                        if (file_exists($local_file_path)) {
                            $new_attach_id = $this->insert_file_to_wp_media($local_file_path, $post_id);
                            if ($new_attach_id) {
                                if (function_exists('update_field')) {
                                    update_field($meta_field_key, $new_attach_id, $post_id);
                                } else {
                                    update_post_meta($post_id, $meta_field_key, $new_attach_id);
                                }
                            }
                        }
                    }
                }
            }
        }

        // ۳. بررسی و استخراج فیلد موضوع (subject)
        $raw_subject = null;
        if (isset($data['subject'])) {
            $raw_subject = $data['subject'];
        } elseif (isset($data['metadata']['subject'])) {
            $raw_subject = $data['metadata']['subject'];
        }

        if ($raw_subject !== null) {
            $final_subjects = array();
            if (is_array($raw_subject)) {
                $final_subjects = $raw_subject;
            } elseif (is_string($raw_subject)) {
                $unserialized = @unserialize($raw_subject);
                if ($unserialized !== false || $raw_subject === 'b:0;') {
                    $final_subjects = (array) $unserialized;
                } else {
                    $final_subjects = array_map('trim', explode('|', $raw_subject));
                }
            }

            if (function_exists('update_field')) {
                update_field('subject', $final_subjects, $post_id);
            } else {
                update_post_meta($post_id, 'subject', $final_subjects);
            }
        }

        // ۴. ذخیره‌سازی سایر متادیتاها و اصلاح فیلد ثبت‌کننده (news_creator_id)
        if (!empty($data['metadata']) && is_array($data['metadata'])) {
            foreach ($data['metadata'] as $meta_key => $meta_value) {
                if (in_array($meta_key, array('sahab_uuid', 'sahab_version_number', 'sahab_content_hash', 'subject', 'attachment_file_1', 'attachment_file_2', 'attachment_file_3', '_thumbnail_id'))) {
                    continue;
                }

                if ($meta_key === 'news_creator_id' && !empty($data['creator_username'])) {
                    $target_creator = get_user_by('login', $data['creator_username']);
                    if ($target_creator) {
                        $meta_value = $target_creator->ID;
                    }
                }

                if ($meta_key === 'reports_to') {
                    if (function_exists('update_field')) {
                        update_field($meta_key, $meta_value, $post_id);
                    } else {
                        update_post_meta($post_id, $meta_key, $meta_value);
                    }
                    continue;
                }

                if (function_exists('update_field')) {
                    update_field($meta_key, $meta_value, $post_id);
                } else {
                    update_post_meta($post_id, $meta_key, $meta_value);
                }
            }
        }

        // ۵. پردازش و درون‌ریزی کامنت‌های تحلیلی چندلایه سحاب
        if (!empty($data['structured_comments']) && is_array($data['structured_comments'])) {
            foreach ($data['structured_comments'] as $c_data) {
                $c_uuid = sanitize_text_field($c_data['comment_uuid']);

                $existing_comments = get_comments(array(
                    'meta_key' => 'sahab_comment_uuid',
                    'meta_value' => $c_uuid,
                    'status' => 'any',
                    'fields' => 'ids'
                ));

                $comment_arr = array(
                    'comment_post_ID' => $post_id,
                    'comment_author' => sanitize_text_field($c_data['author']),
                    'comment_content' => wp_kses_post($c_data['content']),
                    'comment_date_gmt' => sanitize_text_field($c_data['date_gmt']),
                    'comment_approved' => 1,
                );

                if (!empty($existing_comments)) {
                    $comment_arr['comment_ID'] = $existing_comments[0];
                    wp_update_comment($comment_arr);
                    $comment_id = $existing_comments[0];
                } else {
                    $comment_id = wp_insert_comment($comment_arr);
                }

                if ($comment_id) {
                    update_comment_meta($comment_id, 'sahab_comment_uuid', $c_uuid);
                    update_comment_meta($comment_id, 'comment_type', sanitize_text_field($c_data['analysis_metadata']['comment_type']));
                    update_comment_meta($comment_id, '_comment_type', 'field_6a50f060ebcfb');
                }
            }
        }

        // همگام‌سازی دسته‌بندی‌ها و تگ‌ها برای خبر اصلی
        if (!empty($data['taxonomies']) && is_array($data['taxonomies'])) {
            foreach ($data['taxonomies'] as $taxonomy => $terms) {
                if (taxonomy_exists($taxonomy)) {
                    wp_set_object_terms($post_id, $terms, $taxonomy, false);
                }
            }
        }

        // ۶. پردازش و ورود تضمینی رونوشت‌ها (Revisions) با حفظ دقیق نویسنده و ساختار سایه
        if (!empty($data['revisions']) && is_array($data['revisions'])) {
            foreach ($data['revisions'] as $revision_data) {
                $existing_revision = $wpdb->get_var($wpdb->prepare(
                    "SELECT ID FROM $wpdb->posts WHERE post_type = 'revision' AND post_parent = %d AND post_date = %s",
                    $post_id,
                    $revision_data['date']
                ));

                if (!$existing_revision) {
                    // درج موقت به عنوان پست عادی برای دور زدن فیلترهای پیش‌فرض هسته وردپرس
                    $temp_post_id = wp_insert_post(array(
                        'post_title' => sanitize_text_field($revision_data['title']),
                        'post_content' => wp_kses_post($revision_data['content']),
                        'post_excerpt' => sanitize_text_field($revision_data['excerpt']),
                        'post_status' => 'inherit',
                        'post_type' => 'post',
                        'post_parent' => $post_id,
                        'post_date' => sanitize_text_field($revision_data['date']),
                        'post_date_gmt' => get_gmt_from_date($revision_data['date']),
                    ));

                    if ($temp_post_id && !is_wp_error($temp_post_id)) {
                        // تعیین نویسنده واقعی رونوشت بر اساس یوزرنیم دریافتی از مبدا جهت جلوگیری از انتساب به کاربر ایمپورت کننده
                        $revision_author_id = 0;
                        if (!empty($revision_data['author_username'])) {
                            $rev_user = get_user_by('login', $revision_data['author_username']);
                            if ($rev_user) {
                                $revision_author_id = $rev_user->ID;
                            }
                        }

                        // در صورت عدم تطابق نام کاربری، از نویسنده اصلی خبر به عنوان بک‌آپ استفاده می‌شود
                        if ($revision_author_id === 0 && !empty($data['author_username'])) {
                            $main_author = get_user_by('login', $data['author_username']);
                            if ($main_author) {
                                $revision_author_id = $main_author->ID;
                            }
                        }

                        // تبدیل فیزیکی نوع پست در دیتابیس به داده‌ی ریل و معتبر Revision همراه با درج شناسه نویسنده واقعی رونوشت
                        $wpdb->update(
                            $wpdb->posts,
                            array(
                                'post_type' => 'revision',
                                'post_name' => $post_id . '-revision-v1',
                                'post_author' => $revision_author_id
                            ),
                            array('ID' => $temp_post_id)
                        );

                        // بازسازی لایه متادیتاها و تاکسونومی‌های زمان ثبت رونوشت (Shadow Revision)
                        if (!empty($revision_data['sahab_revision_snapshot'])) {
                            $snapshot = json_decode($revision_data['sahab_revision_snapshot'], true);
                            if (is_array($snapshot)) {
                                // تزریق کاستوم فیلدهای متادیتا به خودِ شناسه رونوشت
                                if (!empty($snapshot['metadata']) && is_array($snapshot['metadata'])) {
                                    foreach ($snapshot['metadata'] as $s_meta_key => $s_meta_value) {
                                        update_metadata('post', $temp_post_id, $s_meta_key, $s_meta_value);
                                    }
                                }
                                // همگام‌سازی دسته‌بندی‌های زمان وقوع رونوشت روی رکورد رونوشت
                                if (!empty($snapshot['taxonomies']) && is_array($snapshot['taxonomies'])) {
                                    foreach ($snapshot['taxonomies'] as $s_tax => $s_terms) {
                                        if (taxonomy_exists($s_tax)) {
                                            wp_set_object_terms($temp_post_id, $s_terms, $s_tax, false);
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        // ۷. پردازش و درون‌ریزی دقیق تصویر شاخص (Thumbnail) بر اساس اسکن پکیج
        if (isset($data['metadata']['_thumbnail_id']) && !empty($data['metadata']['_thumbnail_id'])) {
            $thumb_value = $data['metadata']['_thumbnail_id'];
            $thumb_filename = basename($thumb_value);

            // اگر مقدار صرفاً یک آی‌دی فیزیکی بود، پوشه مدیا را اسکن کرده تا فایل تصویر مربوطه را بیابیم
            if (is_numeric($thumb_filename) && file_exists($media_folder)) {
                $possible_files = glob($media_folder . '/*');
                if (!empty($possible_files)) {
                    foreach ($possible_files as $p_file) {
                        $p_base = pathinfo($p_file, PATHINFO_FILENAME);
                        if (strpos($p_base, 'thumb') !== false || $p_base == $thumb_filename) {
                            $thumb_filename = basename($p_file);
                            break;
                        }
                    }
                    if (is_numeric($thumb_filename) && isset($possible_files[0])) {
                        $thumb_filename = basename($possible_files[0]);
                    }
                }
            }

            $thumb_path = $media_folder . '/' . $thumb_filename;

            if (file_exists($thumb_path) && !is_dir($thumb_path)) {
                $attach_id = $this->insert_file_to_wp_media($thumb_path, $post_id);
                if ($attach_id && !is_wp_error($attach_id)) {
                    set_post_thumbnail($post_id, $attach_id);
                }
            }
        }
    }

    private function insert_file_to_wp_media($file_path, $post_id)
    {
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
                return $attach_id;
            }
        }

        return false;
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