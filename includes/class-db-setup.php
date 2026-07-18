<?php
if (!defined('ABSPATH')) {
    exit;
}

class Sahab_DB_Setup
{

    public function __construct()
    {
        // هوک زدن به زمان ذخیره شدن اخبار/پست‌ها در وردپرس
        add_action('save_post', array($this, 'generate_uuid_for_new_news'), 10, 3);
    }

    /**
     * تولید خودکار UUIDv4 در زمان ثبت خبر جدید
     */
    public function generate_uuid_for_new_news($post_id, $post, $update)
    {
        // جلوگیری از اجرای کد در زمان ذخیره خودکار (Auto-save) وردپرس
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // بررسی اینکه آیا از قبل برای این خبر UUID تولید شده است یا خیر
        $existing_uuid = get_post_meta($post_id, 'sahab_uuid', true);

        if (empty($existing_uuid)) {
            // تولید یک رشته UUIDv4 استاندارد
            $new_uuid = $this->uuidv4();

            // ذخیره یکتای جهانی در متادیتای سحاب
            update_post_meta($post_id, 'sahab_uuid', $new_uuid);
        }
    }

    /**
     * متد استاندارد تولید رشته UUIDv4 بدون وابستگی به کتابخانه‌های خارجی
     */
    private function uuidv4()
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // تنظیم نسخه به 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // تنظیم واریانت
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}