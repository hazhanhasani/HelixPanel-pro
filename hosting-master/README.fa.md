# راهنمای نصب مستر مستقل HelixPanel روی cPanel و DirectAdmin

> **نسخه آزمایشی توسعه (Developer Preview) — مستر مستقل PHP + MySQL**

**دو روش نصب، یک معماری:** مسترِ هاست و مسترِ VPS فقط عملیات مدیریت انجام می‌دهند. کاربر VPN مستقیماً به نودها وصل می‌شود؛ هیچ ترافیک یا بسته پروکسی از سرور مستر عبور نمی‌کند.

- **مستر روی هاست:** PHP 8.2+ / MySQL روی cPanel یا DirectAdmin؛ ثبت کاربران، صدور توکن نود و دریافت Heartbeat مستقیماً روی همان هاست.
- **مستر روی VPS:** Control Plane نوشته‌شده با Go روی سرور جداگانه؛ با همان API نود.
- **نود:** برنامه `helix-node-agent` روی VPSهای ترافیکی، با اتصال **خروجی HTTPS** به مستر منتخب. موتورهای VPN بعداً از همین نود مدیریت می‌شوند.

این بسته **هاست را به VPN Server تبدیل نمی‌کند** و برای کاربر هاست دیگر مستر Go جداگانه لازم نیست.

## دانلود ZIP آماده

[آخرین بسته نصب PHP Master در GitHub Actions](https://github.com/hazhanhasani/HelixPanel-pro/actions/workflows/hosting-master-package.yml) — اجرای موفق را باز کن و از قسمت **Artifacts** خروجی `HelixPanel-Master-cPanel-DirectAdmin` را بگیر.

داخل ZIP:

```text
upload-to-public_html/
  index.php
  node.php                  # API نود روی هاست
  setup.php                 # ویزارد راه‌اندازی فارسی
  setup-store.php           # ماژول اولیه؛ بعد از نصب حذف می‌شود
  assets/setup.css
  assets/master.css
  assets/logo-mark.webp       # Official emblem and browser icon
place-outside-public_html/.helixpanel-master/
  config.example.php        # تنظیمات خصوصی
  store.php                 # بک‌اند مستقل PHP/MySQL
schema.sql                  # دیتابیس
README.fa.md
```

## داشبورد Control Center V2

داشبورد واقعی نسخه هاست از رابط قدیمی به **Control Center V2** ارتقا یافته است:
- سایدبار حرفه‌ای، موبایل RTL با منوی کشویی، تاریخ شمسی، پشتیبانی از کیبورد و نمایش وضعیت واقعی اتصال نود.
- جست‌وجو و فیلتر روی جدول‌های مشترکین و نودها، نمایش انقضا، تأیید عملیات حذف.
- کارت‌های مدیریتی واقعی، ظرفیت مجوز، تعداد نودهای آنلاین و مشترکین در آستانه انقضا.
- تا پیاده‌سازی Telemetry و Xray Adapter، نمودار ترافیک و CPU/RAM **داده ساختگی تولید نمی‌کنند**.
- بدون نیاز به Node.js یا CDN روی هاست؛ CSS/JS از همان دامنه ارائه می‌شوند و دسترسی‌های PHP Session/CSRF حفظ می‌شوند.

### آپدیت خودکار نسخه هاست

از نسخه مجهز به `auto-update.php`، داشبورد به‌صورت خودکار نسخه جدید را بررسی و نصب می‌کند:
- هر ۶ ساعت **پس از مراجعه مدیر احرازشده به داشبورد** (بدون دکمه نصب، ZIP یا آپلود دستی).
- برای آپدیت واقعاً بی‌نیاز از مراجعه به پنل، در **cPanel → Cron Jobs** یا **DirectAdmin → Cron Jobs** یک‌بار اجرای ساعتی زیر را فعال کنید (مسیر را با آدرس واقعی هاست جایگزین کنید):

```sh
/usr/local/bin/php -q /home/USERNAME/public_html/auto-update.php
```

در بعضی هاست‌ها مسیر PHP متفاوت است؛ از PHP CLI 8.2+ استفاده کنید. اگر Cron در هاست قابل تنظیم نیست، آپدیت با نخستین ورود مدیر بعد از هر بازه شش‌ساعته انجام می‌شود. هیچ کران یا دسترسی هاستی به‌طور خودکار از GitHub ساخته نمی‌شود.

آپدیتر فقط نسخه‌ای را می‌پذیرد که Workflow رسمی `hosting-master-package.yml` برای **همان Commit** با موفقیت اجرا شده باشد؛ فایل‌ها با Hash مربوط به Git blob همان Commit تطبیق داده می‌شوند، از دامنه‌های GitHub با HTTPS معتبر دانلود می‌شوند و پیش از جایگزینی در `.helixpanel-master/update-backups` بکاپ خصوصی گرفته می‌شود. فایل‌های `config.php`، `store.php`، دیتابیس، `license.php` و `node.php` را آپدیتر داشبورد تغییر نمی‌دهد.

> **نصب‌های قدیمی:** هاستی که هنوز `auto-update.php` ندارد نمی‌تواند خودبه‌خود قابلیت آپدیت را دریافت کند. برای آن نصب‌ها، نسخه `HelixPanel-Dashboard-V2-Update.zip` را **فقط یک‌بار** نصب کنید یا از روش Deploy مستقیم با دسترسی خود هاست استفاده کنید. بعد از فعال‌شدن آپدیتر، نسخه‌های بعدی داشبورد بدون ZIP نصب می‌شوند.

> **محدوده فعلی:** این آپدیتر مخصوص فایل‌های عمومی داشبورد PHP است؛ تغییرات دیتابیس و API نودها نیازمند مهاجرت نسخه‌بندی‌شده و تست سازگاری‌اند. روی VPS تایمر systemd مجزا برای انتشارهای **امضاشده Go** وجود دارد؛ Push ساده GitHub به‌تنهایی یک Release امضاشده نمی‌سازد.

### ارتقای امن یک نصب موجود cPanel/DirectAdmin

از [خروجی CI بسته هاست](https://github.com/hazhanhasani/HelixPanel-pro/actions/workflows/hosting-master-package.yml)، فایل `HelixPanel-Dashboard-V2-Update.zip` را بگیر. **قبل از جایگزینی فایل‌ها از `public_html/index.php` و `public_html/assets/` نسخه پشتیبان بگیر.**

فایل ZIP را در `public_html` دامنه مستر Extract کن؛ شامل `index.php`، `assets/master.css`، `assets/dashboard-v2.css`، `assets/dashboard-v2.js` و لوگو است. پوشه بیرون از `public_html/.helixpanel-master`، دیتابیس، `node.php`، `license.php` و `setup.php` تغییر نمی‌کنند. پس از آپلود، صفحه را Hard Refresh کن. برای بازگشت به نسخه قبل، فایل‌های بکاپ `index.php` و `assets/` را برگردان.

**نکته:** به‌روزرسانی GitHub به‌صورت خودکار فایل‌های یک هاست از قبل نصب‌شده را تغییر نمی‌دهد؛ بسته فوق برای اعمال نسخه جدید روی هاست است. در نصب تازه، فایل‌های V2 در بسته کامل حضور دارند.

## ⚡ نصب سریع تحت وب — بدون SSH یا phpMyAdmin

در **cPanel** و **DirectAdmin** نصب اولیه تقریباً یکسان است:

1. یک دامنه یا ساب‌دامنه با **HTTPS معتبر**، PHP نسخه **8.2+** و افزونه‌های `PDO MySQL`، `curl`، `session` ایجاد کن. مسیر Document Root باید مستقیماً به `public_html` ختم شود.
2. از **MySQL Databases** یا **Database Management** یک دیتابیس خالی و کاربر دارای مجوز ساخت جدول ایجاد کن. دیگر نیازی به اجرای دستی فایل SQL نیست.
3. بسته ZIP را دریافت کن و فایل‌های داخل `upload-to-public_html` را در `public_html` آپلود کن. یک پوشه مخصوص زیر دامنه با Document Root مستقل لازم است.
4. این صفحه را در مرورگر باز کن:

   ```text
   https://your-domain.example/setup.php
   ```

5. راه‌انداز خودش یک **کد امنیتی ۶۴ کاراکتری** در فایل `.helixpanel-master/setup.key`، **بیرون** از `public_html`، می‌سازد. از File Manager آن فایل را باز و کد را کپی کن. آن را برای شخص دیگری نفرست.
6. کد، مشخصات دیتابیس و رمز مدیر (حداقل ۱۴ کاراکتر) را در فرم فارسی وارد کن و «نصب و فعال‌سازی رایگان» را بزن.

پس از موفقیت، جداول MySQL خودکار ساخته می‌شوند، فایل تنظیمات رمزدار بیرون از مسیر وب ایجاد می‌شود، کلید نصب اولیه پاک می‌شود و صفحه ورود مدیریت باز خواهد شد. مراجعه مجدد به `setup.php` به پنل هدایت می‌شود.

**نسخه رایگان دائمی Community، خودکار فعال است:** یک نود، ۲۵ کاربر و بدون نیاز به لایسنس خرید. ارتقا به نسخه تجاری بعداً با لایسنس امضاشده انجام می‌شود.

> [!IMPORTANT]
> اگر هاست اجازه نوشتن در پوشه مادر `public_html` را ندهد، فولدر `.helixpanel-master` را از File Manager **کنار** `public_html` بساز و دسترسی نوشتن مناسب برای PHP بده. فایل‌های محرمانه نباید در فضای وب منتشر شوند. اگر PHP در حالت FPM با کاربری متفاوت اجرا می‌شود، تنظیم سطح دسترسی باید با پشتیبانی هاست هماهنگ شود.

### روش نصب دستی (اختیاری)

فایل `schema.sql` و `config.example.php` همچنان برای نصب دستی، بازیابی یا عیب‌یابی در بسته نگه داشته شده‌اند. در روش دستی باید `store.php` و `config.php` را بیرون از Document Root قرار دهی؛ اما در **نصب سریع** این مراحل به‌صورت خودکار انجام می‌شوند.

### مسیر فایل‌ها

| سرویس | مسیر معمول فایل عمومی | مسیر خصوصی |
|---|---|---|
| cPanel | `/home/USER/public_html` | `/home/USER/.helixpanel-master` |
| DirectAdmin | `/home/USER/domains/DOMAIN/public_html` | `/home/USER/domains/DOMAIN/.helixpanel-master` |

مجوز فایل‌های خصوصی باید تا جای ممکن محدود باشد؛ دسترسی کاربر سرویس PHP به `config.php` و `store.php` ضروری است، اما این پوشه **نباید از وب قابل دریافت** باشد.

## تنظیمات خصوصی

```php
<?php
return [
  'mode' => 'standalone',
  'db_dsn' => 'mysql:host=localhost;port=3306;dbname=helix_master;charset=utf8mb4',
  'db_user' => 'helix_user',
  'db_password' => 'YOUR_PRIVATE_MYSQL_PASSWORD',
  'admin_password_hash' => 'HASH_OF_ADMIN_PASSWORD',
  'license_key' => '',
  'license_public_key' => 'UgMjnCA3zJnoH7Sn4CVZ-GucoqitVOdmrP6t03XlH9o',
];
```

هش رمز را در ترمینال قابل اعتماد خودت بساز:

```bash
php -r 'echo password_hash("REPLACE_WITH_A_LONG_UNIQUE_PASSWORD", PASSWORD_DEFAULT), PHP_EOL;'
```

**کلید عمومی رسمی فروشنده** در نسخه تازه مستر PHP قرار گرفته است. برای نصب‌های جدید، ویزارد آن را خودکار تنظیم می‌کند. اگر نصب قبلی با کلید عمومی خالی ساخته شده، فایل‌های به‌روزشده `private/store.php` و `public/license.php` را از بسته رسمی جدید دریافت و جایگزین کن؛ پیکربندی مشتری، رمزها و کلید خصوصی فروشنده نباید منتشر شوند. قبل از فروش واقعی، تطبیق کلید خصوصی Cloudflare با همین کلید عمومی باید در بررسی سلامت فروشنده تأیید شود.

`license_key` و `license_public_key` برای تایید مجوز Ed25519 در مستر PHP استفاده می‌شوند؛ اگر مجوز معتبر وجود نداشته باشد، نسخه رایگان دائمی با سقف **یک نود و ۲۵ رکورد کاربر** فعال است. این محدودیت در API ثبت کاربر/نود اعمال می‌شود، ولی اتصال واقعی موتورهای VPN هنوز اضافه نشده است.

## اتصال نود به مستر

1. نود جدید را در داشبورد بساز و شناسه `node_id` و توکن تصادفی `enrollment_token` را بردار.
2. توکن **۱۵ دقیقه** اعتبار دارد و تنها یک بار قابل مصرف است.
3. فایل اجرایی Go `helix-node-agent` را از بیلد اختصاصی و امضاشده فروشنده روی VPS نود نصب کن (در Developer Preview باید توسط تیم توسعه ساخته شود).
4. در تنظیمات Agent، `HELIX_MASTER_URL=https://master.example.com` و `HELIX_NODE_ID=...` را مشخص کن و توکن را در فایل محافظت‌شده `/etc/helixpanel-node/enrollment.key` قرار بده.
5. Node Agent با درخواست خروجی HTTPS به `https://master.example.com/node.php?action=enroll` ثبت می‌شود و سپس به `...action=heartbeat` گزارش وضعیت می‌دهد.

**هر دو نسخه مستر، پروتکل Node Agent یکسانی دارند.** دستورهای کامل ساخت و نصب توسعه‌ای Agent در [راهنمای نود](../docs/NODE_SETUP.fa.md) نوشته شده است.

> [!WARNING]
> تغییر یا صدور مجدد توکن اتصال، اعتبارنامه قبلی Agent را باطل می‌کند. از قرار دادن توکن در Issue، لاگ، QR عمومی یا URL خودداری کن.

## تفکیک ترافیک

```text
کاربر VPN ───────> Node VPS ──────> اینترنت
                      │
               HTTPS مدیریتی
                      ▼
       Master (PHP shared hosting یا Go VPS)
          ثبت کاربر / Heartbeat / مجوز
```

- مستر فقط «Control Plane» است.
- Data Plane فقط روی نود است.
- تا زمان پیاده‌سازی Node Engine Adapter برای Xray/Sing-box/SSH، پنل فعلی فقط ثبت نود و Heartbeat دارد؛ **نمی‌توان ادعا کرد سرویس VPN از قبل عملیاتی شده است**.
- اگر مستر قطع شود، طراحی هدف این است که موتورهای VPN روی نودها بدون نیاز به مستر به کار ادامه دهند.

## حفاظت و آماده‌سازی Production

- HTTPS واقعی، جلوگیری از Redirect به HTTP و محدودسازی IP مدیریت؛ API نودها با توکن مجزا اعتبارسنجی می‌شود.
- در نسخه آزمایشی، PHP یک مستر مستقل و یک‌مستاجری (Single-Tenant) است. فروش چندمشتری روی یک نصب مشترک نیازمند تفکیک Tenant و مجوزهای RBAC است.
- مستر PHP برای هر نود، توکن Agent هش‌شده نگه می‌دارد. توکن اولیه پس از جفت‌سازی باطل می‌شود. فایل‌های PHP عمومی‌اند، ولی سورس Go خصوصی باقی می‌ماند.
- نرخ‌دهی درخواست‌ها، ثبت ممیزی، لغو دسترسی، پایگاه داده Production و ارتقای پروتکل هنوز باید قبل از فروش تکمیل شوند.

تست‌های CI فعلاً شامل PHP Lint، ورود اولیه، ساخت بسته ZIP و تست‌های امنیتی Installer هستند. **اتصال روی هاست واقعی همراه با MySQL و موتور VPN هنوز آزمایش عملیاتی نشده است.**


## برند و لوگو

لوگوی رسمی HelixPanel در فایل `public/assets/logo-mark.webp` همراه بسته نصب قرار گرفته و در صفحه ورود، داشبورد و آیکن مرورگر نمایش داده می‌شود.
