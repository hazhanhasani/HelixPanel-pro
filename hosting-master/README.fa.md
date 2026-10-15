# نصب HelixPanel Master روی cPanel و DirectAdmin

> **Developer Preview — PHP Master Gateway (not a standalone VPN server)**

این بسته برای نصب رابط مدیریتی فارسی HelixPanel روی هاست‌هایی طراحی شده که **PHP** دارند، اما امکان اجرای دائمی سرویس‌های Go، systemd و موتورهای VPN روی آن‌ها فراهم نیست. UI از هاست اجرا می‌شود؛ منطق اختصاصی، لایسنس، موتورهای شبکه و API اصلی در **Control Plane روی VPS مجزا** باقی می‌مانند. هیچ کلید خصوصی امضا یا سورس اختصاصی Go در این مخزن قرار ندارد.

## پیش‌نیازها

- دامنه یا زیردامنه اختصاصی با SSL معتبر؛ **HTTPS اجباری**.
- PHP **8.2+** با افزونه‌های `curl`, `json` و `session`.
- یک **Document Root اختصاصی که دقیقاً به نام `public_html` ختم می‌شود**؛ داخل پوشه تودرتو مثل `public_html/master/` نصب نکنید.
- امکان ساخت پوشه خصوصی **بیرون Document Root** با File Manager.
- VPS دارای HelixPanel Go Control Plane که API آن با HTTPS معتبر در دسترس میزبان PHP باشد (DNS، فایروال و outbound HTTPS بررسی شوند).
- **API Token حداقل ۳۲ کاراکتر**؛ این نسخه از API فعلی استفاده می‌کند. برای محصول Production باید توکن Scope محدود و دسترسی امن بین هاست و VPS ساخته شود.

### تفاوت cPanel و DirectAdmin

| میزبان | Document Root رایج | محل پیشنهادی تنظیمات محرمانه |
|:---|:---|:---|
| cPanel | `/home/USER/public_html` | `/home/USER/.helixpanel-master/config.php` |
| DirectAdmin | `/home/USER/domains/DOMAIN/public_html` | `/home/USER/domains/DOMAIN/.helixpanel-master/config.php` |

**نکته:** مسیر واقعی را در File Manager کنترل کنید. اگر زیردامنه شما به جای `public_html` از مسیر دیگری استفاده می‌کند، در نسخه فعلی نصب را به یک Document Root مناسب منتقل کنید؛ این محدودیت برای جلوگیری از افشای کلیدهاست.

## نصب روی cPanel (بدون SSH)

1. در cPanel یک دامنه مناسب برای مستر (مانند `master.example.com`) ایجاد کن و HTTPS/AutoSSL را فعال کن.
2. از **MultiPHP Manager**، نسخه PHP 8.2 یا بالاتر را انتخاب کن؛ از وجود cURL مطمئن شو.
3. فایل‌های داخل `hosting-master/public/` را با File Manager در **Document Root واقعی** دامنه قرار بده؛ `index.php` باید مستقیم در همان پوشه باشد.
4. در پوشه مادر `public_html`، یک پوشه به نام `.helixpanel-master` بساز؛ این پوشه نباید داخل هیچ مسیر عمومی قابل مشاهده باشد.
5. فایل `hosting-master/config.example.php` را به آن پوشه منتقل کرده و به `config.php` تغییر نام بده. اطلاعات سه فیلد را طبق بخش پیکربندی جایگزین کن.
6. مجوز پوشه را در صورت پشتیبانی `0700` و مجوز فایل را `0600` قرار بده؛ ممکن است میزبان با تنظیمات مالکیت خودش از اعداد متفاوت استفاده کند.
7. دامنه را با **HTTPS** باز کن و با رمز مدیریت وارد شو.

## نصب روی DirectAdmin

1. یک دامنه با PHP 8.2+ و SSL ایجاد کن. معمولاً مسیر فایل‌ها `domains/DOMAIN/public_html` است.
2. فایل‌های داخل `hosting-master/public/` را به `public_html` منتقل کن.
3. پوشه `.helixpanel-master` را در کنار `public_html` (نه داخل آن) بساز.
4. `config.example.php` را با نام `config.php` در پوشه خصوصی کپی و تنظیم کن.
5. در **Domain Setup / PHP Version Selector** مطمئن شو که PHP مناسب فعال است. با اتصال HTTPS وارد پنل شو.

## پیکربندی سه گزینه

فایل `config.php` در پوشه خصوصی باید مقادیر زیر را داشته باشد:

```php
<?php
return [
    'upstream_api_base' => 'https://core.yourdomain.com',
    'upstream_api_token' => 'YOUR_STRONG_CONTROL_PLANE_API_TOKEN',
    'admin_password_hash' => '$2y$12$PASTE_REAL_PASSWORD_HASH_HERE',
];
```

رمز هش‌شده را روی کامپیوتر یا ترمینال قابل اعتماد خودت تولید کن؛ هرگز پسورد را برای ساخت هش در وب‌سایت‌های ناشناس وارد نکن.

```bash
php -r 'echo password_hash("CHANGE_TO_A_UNIQUE_LONG_PASSWORD", PASSWORD_DEFAULT), PHP_EOL;'
```

حداقل ۱۲ کاراکتر تصادفی قوی انتخاب کن. مقدار هش خروجی را در config.php قرار بده. برای امنیت بیشتر، روی دامنه Master **IP Allowlist یا Cloudflare Access** اعمال کن. اطلاعات حساس را در فایل عمومی، URL یا JavaScript قرار نده.

## ساختار فایل‌ها

```text
# Document Root (public_html)
index.php               -> Persian master UI, login and allowed API actions
assets/master.css       -> responsive CSS, without external CDN

# Outside Document Root
.helixpanel-master/config.php   -> private Go API token and admin hash
```

این رابط اکنون از API های موجود `/api/v1/summary`، `nodes`، `users` و `license` استفاده می‌کند. نمایش نود آنلاین یا ترافیک واقعی فقط پس از پیاده‌سازی Agent در Core امکان‌پذیر است؛ در این نسخه عدد غیرواقعی ساخته نمی‌شود.

## محدودیت مهم معماری و لایسنس

- **این بسته جایگزین VPS هسته Go نیست.** فقط Master **Dashboard/Gateway** روی هاست اشتراکی نصب می‌شود.
- مالک اکانت میزبانی می‌تواند فایل‌های PHP خود را بخواند. پس الگوریتم‌های اختصاصی، کلیدهای خصوصی لایسنس و کد اصلی نباید در PHP مستقر روی هاست خریدار قرار بگیرند.
- اعتبارسنجی و محدودیت لایسنس همچنان در هسته خصوصی Go اجرا می‌شود.
- این نسخه از یک توکن Bearer با دسترسی مدیریتی استفاده می‌کند؛ برای فروش تجاری باید **Scoped Tokens، MFA، اتصال mTLS یا شبکه خصوصی، لاگ ممیزی و محافظت در برابر Brute Force** پیاده‌سازی شود.
- روی هاست اشتراکی اجرای Cron متداول است، اما Process دائم Go، نودها و تونل‌های شبکه نیازمند VPS یا زیرساخت اختصاصی هستند.

## بررسی خطاها

| خطا | اقدام |
|:---|:---|
| HTTPS is required | SSL دامنه را فعال کن |
| Document root ... public_html | از یک داکیومنت‌روت اختصاصی و مجزا استفاده کن |
| not configured | مسیر خصوصی config.php را بررسی کن |
| 503 PHP requirements | نسخه PHP و افزونه cURL/Session را بررسی کن |
| ارتباط با سرور مرکزی برقرار نشد | وضعیت VPS، URL، SSL، DNS، firewall و outbound HTTPS را بررسی کن |
| HTTP 401 | توکن API را روی هاست و VPS هماهنگ کن |
| HTTP 403 | لایسنس یا سطح دسترسی API را بررسی کن |

### به‌روزرسانی رابط مستر

فایل `index.php` و `assets/` را با نسخه جدید جایگزین کن؛ **فایل config.php خصوصی حفظ می‌شود**. سیستم انتشار و آپدیت خودکار امضاشده برای PHP Gateway هنوز به نسخه عملیاتی نرسیده و نباید با آپدیت خودکار باینری Go اشتباه گرفته شود.

---
این نسخه اولین پیاده‌سازی قابل آزمون برای cPanel و DirectAdmin است، نه محصول آماده عرضه تجاری.
