<?php
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="sahab-sync-container"
    style="direction: rtl; text-align: right; font-family: tahoma, sans-serif; max-width: 800px; margin: 20px auto; padding: 20px; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">

    <h2 style="border-bottom: 2px solid #3b82f6; padding-bottom: 10px; color: #1e3a8a;">سامانه همگام‌سازی غیرمتمرکز سحاب
        (Node Sync)</h2>
    <p style="color: #64748b; font-size: 13px;">از این بخش می‌توانید اطلاعات اخبار و متادیتاها را بین نودها و سیستم‌های
        مختلف سحاب جابجا و همگام‌سازی کنید.</p>

    <hr style="border: 0; border-top: 1px solid #e2e8f0; margin: 20px 0;">

    <div style="display: flex; gap: 20px; flex-wrap: wrap;">

        <!-- بخش اکسپورت (تولید پکیج خروجی) -->
        <div
            style="flex: 1; min-width: 300px; border: 1px solid #cbd5e1; padding: 15px; border-radius: 6px; background: #f8fafc;">
            <h3 style="color: #0f172a; margin-top: 0;">۱. پشتیبان‌گیری و خروجی داده‌ها</h3>
            <p style="color: #475569; font-size: 12px; min-height: 40px;">یک فایل فشرده حاوی تمام اخبار، متادیتاها،
                شماره نسخه‌ها و تصاویر متصل برای انتقال به سیستم‌های دیگر تولید کنید.</p>
            <button id="sahab-export-btn"
                style="background: #2563eb; color: #fff; border: 0; padding: 10px 20px; border-radius: 4px; cursor: pointer; font-weight: bold; width: 100%;">تولید
                و دانلود پکیج زیپ سحاب</button>
        </div>

        <!-- بخش امپورت (ورود پکیج و همگام‌سازی ورژن‌ها) -->
        <div
            style="flex: 1; min-width: 300px; border: 1px solid #cbd5e1; padding: 15px; border-radius: 6px; background: #f8fafc;">
            <h3 style="color: #0f172a; margin-top: 0;">۲. ورود اطلاعات و همگام‌سازی زنجیره‌ای</h3>
            <p style="color: #475569; font-size: 12px; min-height: 40px;">فایل زیپ دریافت شده از سیستم‌های دیگر را
                انتخاب کنید تا فرآیند بررسی هش داده‌ها و ورژنینگ به صورت خودکار انجام شود.</p>

            <form id="sahab-import-form" enctype="multipart/form-data" style="margin-top: 10px;">
                <input type="file" name="sahab_import_file" id="sahab-import-file" accept=".zip"
                    style="display: block; margin-bottom: 10px; width: 100%; font-size: 12px;">
                <button type="submit" id="sahab-import-btn"
                    style="background: #16a34a; color: #fff; border: 0; padding: 10px 20px; border-radius: 4px; cursor: pointer; font-weight: bold; width: 100%;">شروع
                    پردازش و درون‌ریزی پکیج</button>
            </form>
        </div>

    </div>

    <!-- بخش نمایش وضعیت و لاگ‌ها -->
    <div id="sahab-sync-status"
        style="margin-top: 20px; padding: 12px; border-radius: 4px; display: none; font-size: 13px; line-height: 1.6;">
    </div>
</div>

<!-- اسکریپت پردازش کلاینت لایه ارتباطی با پنل سحاب -->
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
        const nonce = '<?php echo wp_create_nonce('sahab_sync_nonce'); ?>';
        const statusDiv = document.getElementById('sahab-sync-status');

        function showStatus(message, isSuccess = true) {
            statusDiv.style.display = 'block';
            statusDiv.style.background = isSuccess ? '#f0fdf4' : '#fef2f2';
            statusDiv.style.color = isSuccess ? '#166534' : '#991b1b';
            statusDiv.style.border = `1px solid ${isSuccess ? '#bbf7d0' : '#fecaca'}`;
            statusDiv.innerHTML = message;
        }

        // --- مدیریت فرآیند اکسپورت (تولید خروجی زیپ) ---
        document.getElementById('sahab-export-btn').addEventListener('click', function () {
            const btn = this;
            btn.disabled = true;
            btn.innerText = 'در حال پردازش داده‌ها و فشرده‌سازی...';
            showStatus('سیستم در حال جمع‌آوری اطلاعات و تصاویر است. شکیبا باشید...');

            const formData = new FormData();
            formData.append('action', 'sahab_do_export');
            formData.append('security', nonce);

            fetch(ajaxUrl, {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    btn.disabled = false;
                    btn.innerText = 'تولید و دانلود پکیج زیپ سحاب';
                    if (data.success) {
                        showStatus('پکیج داده‌ها با موفقیت ساخته شد. دانلود فایل تا لحظاتی دیگر آغاز می‌شود.');
                        // هدایت امن به متد دانلود مستقیم و حذف خودکار فایل موقت بعد از تحویل
                        window.location.href = ajaxUrl + '?action=sahab_download_file&file=' + data.data.filename;
                    } else {
                        showStatus('خطا: ' + data.data.message, false);
                    }
                })
                .catch(error => {
                    btn.disabled = false;
                    btn.innerText = 'تولید و دانلود پکیج زیپ سحاب';
                    showStatus('خطا در برقراری ارتباط با سرور سحاب.', false);
                });
        });

        // --- مدیریت فرآیند امپورت (ورود اطلاعات و حل تعارض‌ها) ---
        document.getElementById('sahab-import-form').addEventListener('submit', function (e) {
            e.preventDefault();

            const fileInput = document.getElementById('sahab-import-file');
            const btn = document.getElementById('sahab-import-btn');

            if (fileInput.files.length === 0) {
                showStatus('لطفاً ابتدا یک فایل زیپ انتخاب کنید.', false);
                return;
            }

            btn.disabled = true;
            btn.innerText = 'در حال اکسترکت و تحلیل نسخه‌های زنجیره داده...';
            showStatus('فایل پکیج در حال بارگذاری روی نود جاری است. لطفاً پنجره را نبندید...');

            const formData = new FormData();
            formData.append('action', 'sahab_do_import');
            formData.append('security', nonce);
            formData.append('sahab_import_file', fileInput.files[0]);

            fetch(ajaxUrl, {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    btn.disabled = false;
                    btn.innerText = 'شروع پردازش و درون‌ریزی پکیج';
                    if (data.success) {
                        let report = `<strong>${data.data.message}</strong><br>`;
                        report += `🔹 اخبار جدید ساخته شده: ${data.data.imported}<br>`;
                        report += `🔄 اخبار بروزرسانی شده (ایجاد نسخه ریویژن جدید): ${data.data.updated}<br>`;
                        report += `⏭️ اخبار بدون تغییر یا پکیج‌های قدیمی‌تر (رد شده): ${data.data.skipped}`;
                        showStatus(report, true);
                        fileInput.value = ''; // ریست کردن فیلد فایل
                    } else {
                        showStatus('خطا در امپورت داده‌ها: ' + data.data.message, false);
                    }
                })
                .catch(error => {
                    btn.disabled = false;
                    btn.innerText = 'شروع پردازش و درون‌ریزی پکیج';
                    showStatus('خطا در ارسال داده‌ها به پردازشگر امپورت سحاب.', false);
                });
        });
    });
</script>