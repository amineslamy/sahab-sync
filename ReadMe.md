Markdown
# Sahab Decentralized Node Sync Plugin
> Advanced data-synchronization and package management system for Sahab Platform.

---

## 🇬🇧 English Documentation

### Overview
Sahab Node Sync is a custom WordPress plugin designed for decentralized, local-first environments. It allows analysts and developers to export targeted news, metadata, and attached media assets into version-controlled cryptographic packages (`.zip`), which can then be seamlessly imported into other independent Sahab nodes.

### Features
* **Live Dashboard Filters:** Query posts instantly by Date, ID, Category (Case), News Type, Subject, Expert, Author, and Comments/Notes.
* **Real-time Count & Preview:** Dynamic AJAX counter that displays eligible news titles before generating the final bundle.
* **Smart Revisioning & History:** Automatic revision checks during import to handle conflicts, skips identical data, and logs updates safely.
* **Robust UI Integration:** Smoothly handles frontend shortcodes, native asset queues, and safely bypasses third-party datepicker crashes.

### Installation & Shortcode
1. Upload the `sahab-sync` folder to your `/wp-content/plugins/` directory.
2. Activate the plugin through the WordPress admin panel.
3. Use the following shortcode to render the advanced interactive dashboard on any frontend page:
   ```wordpress
   [sahab_sync_form]
Technical Workflow
Export: Filters query post IDs using optimal WP_Query hooks, feeds them to Sahab_Sync_Exporter to pack assets, and delivers a clean download stream via admin-ajax.php.

Import: Sahab_Sync_Importer decompresses the zip, verifies object data integrity, logs conflict status (Imported / Updated / Skipped), and dynamically refreshes the target environment.

🇮🇷 راهنمای فارسی
معرفی افزونه
افزونه همگام‌ساز سحاب (Node Sync) یک راهکار اختصاصی و بومی برای جابجایی غیرمتمرکز داده‌ها بین نودهای مختلف سامانه سحاب است. این افزونه به کارشناسان اجازه می‌دهد تا اخبار، متادیتاها و فایل‌های پیوست را بر اساس فیلترهای پیشرفته در قالب یک پکیج فشرده و با ساختار ورژنینگ دقیق خروجی گرفته و در یک سیستم مجزا درون‌ریزی (Import) کنند.

قابلیت‌های کلیدی
فیلترهای زنده و آنی: فیلتر آنی اخبار بر اساس بازه تاریخ شمسی، شماره خبر، کیس (دسته‌بندی)، نوع خبر، موضوع، کارشناس، ثبت‌کننده و نوع پی‌نوشت.

پیش‌نمایش هوشمند داده‌ها: نمایش تعداد اخبار واجد شرایط و عناوین آن‌ها قبل از استارت فرآیند اکسپورت به صورت آژاکس (AJAX).

مدیریت تعارض و ورژنینگ زنجیره‌ای: شناسایی هوشمند هش داده‌ها در زمان امپورت؛ پکیج‌های قدیمی یا تکراری رد (Skip) شده و پکیج‌های جدید جایگزین یا بروزرسانی می‌شوند.

معماری پایدار جاوااسکریپت: سازگاری کامل با دیت‌پیکرهای پیش‌فرض پوسته و جلوگیری از کراش یا تداخل کتابخانه‌های فرعی.

نحوه راه‌اندازی و شورت‌کد
۱. پوشه sahab-sync را در مسیر پلاگین‌های وردپرس خود بارگذاری کنید.
۲. افزونه را از پیشخوان وردپرس فعال نمایید.
۳. جهت نمایش داشبورد پیشرفته فیلترها و عملیات پکیج در فرانت‌اند سایت، شورت‌کد زیر را در صفحه مورد نظر قرار دهید:

Code snippet
[sahab_sync_form]
فرآیند فنی
بخش صدور (Export): شناسه اخبار از طریق تابع sahab_sync_get_filtered_post_ids فیلتر شده و توسط لایه اکسپورت فشرده‌سازی می‌شود.

بخش ورود (Import): فایل زیپ بارگذاری شده رمزگشایی و اعتبارسنجی شده و گزارش دقیقی از تعداد اخبار جدید، بروزرسانی شده و رد شده به کاربر ارائه می‌دهد.