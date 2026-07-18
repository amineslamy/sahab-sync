<?php
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="sahab-sync-container"
    style="direction: rtl; text-align: right; font-family: Tahoma, sans-serif; margin: 20px 0;">
    <div
        style="background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 25px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);">
        <h2
            style="color: #0284c7; border-bottom: 2px solid #e2e8f0; padding-bottom: 10px; margin-top: 0; font-weight: 900; font-size: 18px;">
            بخش کارشناس: تولید بسته پشتیبان (Export)
        </h2>
        <p style="color: #64748b; font-size: 13px; line-height: 1.8; margin-bottom: 20px;">
            کارشناس گرامی، با استفاده از این بخش می‌توانید تمام اخبار ثبت شده توسط خودتان را به همراه فایل‌های ضمیمه در
            قالب یک بسته هوشمند فشرده (.zip) دریافت کنید. سیستم به صورت خودکار فایلهای منقضی شده قبلی را جهت بهینه‌سازی
            فضا حذف می‌کند.
        </p>

        <div style="display: flex; gap: 15px; align-items: center; margin-bottom: 25px; flex-wrap: wrap;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <span style="font-weight: bold; font-size: 13px; color: #334155;">از تاریخ:</span>
                <input type="text"
                    style="padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 6px; width: 120px; text-align: center; height: 38px; background:#f1f5f9;"
                    value="همه سوابق" disabled>
            </div>
            <div style="display: flex; align-items: center; gap: 8px;">
                <span style="font-weight: bold; font-size: 13px; color: #334155;">تا تاریخ:</span>
                <input type="text"
                    style="padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 6px; width: 120px; text-align: center; height: 38px; background:#f1f5f9;"
                    value="امروز" disabled>
            </div>
        </div>

        <!-- دکمه پردازش اصلی -->
        <button id="sahab-export-btn" class="button"
            style="background: #0284c7; border: none; color: #fff; font-weight: bold; height: 42px; padding: 0 25px; border-radius: 6px; cursor: pointer; font-size: 14px;">
            تولید پکیج زیپ سحاب
        </button>

        <!-- باکس نمایش وضعیت و لینک نهایی -->
        <div id="sahab-sync-result" style="margin-top: 20px; display: none;"></div>
    </div>
</div>

<!-- اسکریپت کنترلر جاوااسکریپت برای دور زدن تداخل IDM -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const btn = document.getElementById('sahab-export-btn');
    const resultBox = document.getElementById('sahab-sync-result');

    if(!btn) return;

    btn.addEventListener('click', function(e) {
        e.preventDefault();
        
        btn.disabled = true;
        btn.innerText = 'در حال پردازش و فشرده‌سازی اطلاعات سحاب...';
        resultBox.style.display = 'block';
        resultBox.innerHTML = '<div style="background: #f8fafc; border: 1px solid #cbd5e1; padding: 15px; border-radius: 8px; color: #334155;">موتور در حال بسته‌بندی داده‌ها است، لطفاً شکیبا باشید...</div>';

        const formData = new FormData();
        formData.append('action', 'sahab_do_export');
        formData.append('security', '<?php echo wp_create_nonce("sahab_sync_nonce"); ?>');

            fetch('<?php echo admin_url("admin-ajax.php"); ?>', {
                method: 'POST',
                body: formData
            })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('خطا در پاسخ‌دهی سرور لارگون');
                    }
                    return response.json();
                })
                .then(res => {
                    if (res.success) {
                        resultBox.innerHTML = `
                    <div style="background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; padding: 15px; border-radius: 8px;">
                        <strong>بسته با موفقیت تولید شد! 🎉</strong><br>
                        دانلود به صورت خودکار آغاز می‌شود. اگر دانلود نشد، <a href="<?php echo admin_url('admin.php'); ?>?action=sahab_download_file&file=${res.data.filename}" style="color: #0284c7; font-weight: bold; text-decoration: underline;">اینجا کلیک کنید</a>.
                    </div>
                `;

                        // هدایت امن مرورگر به لینک دانلود مستقیم استریم (کاملاً سازگار با HTTPS لاراگون)
                        window.location.href = `<?php echo admin_url('admin.php'); ?>?action=sahab_download_file&file=${res.data.filename}`;

                        btn.disabled = false;
                        btn.innerText = 'تولید پکیج زیپ سحاب';
                    } else {
                        throw new Error(res.data.message || 'مشکلی در تولید فایل رخ داد.');
                    }
                })
                .catch(err => {
                    btn.disabled = false;
                    btn.innerText = 'تولید پکیج زیپ سحاب';
                    resultBox.innerHTML = `
                <div style="background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 15px; border-radius: 8px;">
                    خطا در فرآیند همگام‌سازی: ${err.message}
                </div>
            `;
                });
        });
    });
</script>