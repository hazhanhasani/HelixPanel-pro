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
  node.php                  # مستر به نود؛ HTTPS JSON
  assets/master.css
place-outside-public_html/.helixpanel-master/
  config.example.php        # تنظیمات خصوصی
  store.php                 # بک‌اند مستقل PHP/MySQL
schema.sql                  # دیتابیس
README.fa.md
```

## مراحل نصب بدون SSH

1. در cPanel یا DirectAdmin، یک دامنه اختصاصی برای مستر بساز. سند ریشه (**Document Root**) باید به `public_html` ختم شود؛ زیردایرکتوری یا پوشه‌ای داخل `public_html` برای قرار دادن فایل‌های خصوصی مناسب نیست.
2. گواهی SSL معتبر فعال کن. PHP نسخه 8.2 یا بالاتر به‌همراه `PDO MySQL`، `curl`، `session` و ترجیحاً `sodium` لازم است.
3. در MySQL Databases هاست، یک دیتابیس و کاربر اختصاصی بساز و در **phpMyAdmin** فایل `schema.sql` را Import کن.
4. فایل‌های `upload-to-public_html/` را در Document Root دامنه کپی کن.
5. پوشه `.helixpanel-master` را **کنار** `public_html` ایجاد کن (نه داخل آن) و `store.php` را همراه با فایل `config.php` داخلش قرار بده.
6. از `config.example.php` کپی بگیر و نام آن را `config.php` بگذار. مقادیر MySQL، رمز مدیریتی هش‌شده و در صورت خرید، لایسنس و کلید عمومی امضا را وارد کن.
7. دامنه مستر را با HTTPS باز کن، وارد پنل شو، نود بساز و گزینه **صدور کد اتصال** را انتخاب کن.

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
  'license_public_key' => '',
];
```

هش رمز را در ترمینال قابل اعتماد خودت بساز:

```bash
php -r 'echo password_hash("REPLACE_WITH_A_LONG_UNIQUE_PASSWORD", PASSWORD_DEFAULT), PHP_EOL;'
```

`license_key` و `license_public_key` برای تایید مجوز Ed25519 در مستر PHP استفاده می‌شوند؛ اگر مجوز معتبر وجود نداشته باشد، نسخه پایه با سقف **یک نود و ۲۵ رکورد کاربر** فعال است. این محدودیت در API ثبت کاربر/نود اعمال می‌شود، ولی اتصال واقعی موتورهای VPN هنوز اضافه نشده است.

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
