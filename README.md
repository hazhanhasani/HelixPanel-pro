<p align="center">
  <img src="assets/banner.svg" alt="HelixPanel Pro" width="100%" />
</p>

<p align="center">
  <a href="#-نصب-سریع"><img src="https://img.shields.io/badge/Install-One%20Command-1688F0?style=for-the-badge&amp;logo=gnubash&amp;logoColor=white" alt="One command" /></a>
  <a href="#-امنیت-و-حفاظت-از-سورس"><img src="https://img.shields.io/badge/Release-Ed25519%20Signed-148C7E?style=for-the-badge&amp;logo=letsencrypt&amp;logoColor=white" alt="Signed release" /></a>
  <img src="https://img.shields.io/badge/Core-Private-323C55?style=for-the-badge&amp;logo=github&amp;logoColor=white" alt="Private Core" />
  <a href="#-وضعیت-انتشار"><img src="https://img.shields.io/badge/Status-Developer%20Preview-EBA43A?style=for-the-badge" alt="Developer preview" /></a>
</p>

<p align="center" dir="rtl"><strong>یک تجربه یکپارچه برای نصب، مدیریت و به‌روزرسانی HelixPanel</strong></p>
<p align="center" dir="rtl"><a href="#-نصب-سریع">نصب سریع</a> · <a href="#-پیشنیازها">پیش‌نیازها</a> · <a href="#-مدیریت-و-بروزرسانی">مدیریت پنل</a> · <a href="#-امنیت-و-حفاظت-از-سورس">امنیت</a></p>

---

## ✨ معرفی HelixPanel Pro

**هلیکس پنل پرو** در حال توسعه به‌عنوان یک پلتفرم تجاری برای مدیریت سرویس‌ها و زیرساخت چندسروری است. این مخزن، محل انتشار **نصب‌کننده عمومی، راهنمای راه‌اندازی و ابزارهای بررسی امنیتی** است؛ سورس اختصاصی پنل در GitHub عمومی منتشر نمی‌شود.

> [!IMPORTANT]
> **وضعیت فعلی: نسخه توسعه‌دهندگان (Developer Preview).**
> نصب‌کننده تک‌دستوری منتشر شده، اما تا زمانی که سرویس رسمی توزیع نسخه، کلیدهای عمومی امضا و فایل اجرایی تأییدشده فعال نشوند، نصب عملیاتی انجام نمی‌شود. نصب‌کننده به‌جای دانلود نسخه جعلی یا فاقد امضا، پیام واضح نمایش می‌دهد.

### 🌟 قابلیت‌های طراحی‌شده برای محصول

| حوزه | امکانات هدف |
|:---|:---|
| 🛰️ زیرساخت | معماری چندنودی، مدیریت سرورها و پایش سلامت |
| 👥 مشترکین | سهمیه حجم و زمان، طرح‌ها و مدیریت وضعیت اشتراک |
| 🛍️ تجاری | نمایندگی، فروش و اتصال به سرویس‌های خارجی |
| 🔐 لایسنس | مجوز اختصاصی امضاشده، محدودیت کاربر و نود |
| 🔄 انتشار | نصب یک‌دستوری، کنترل اصالت نسخه و بازگشت از نصب ناموفق |
| 🧰 توسعه‌پذیری | API و طراحی ماژولار برای افزودن موتورهای شبکه |

این جدول **چشم‌انداز محصول** است؛ به‌معنی آماده‌بودن همه قابلیت‌ها در نسخه فعلی نیست.

## ⚡ نصب سریع

**فقط یک دستور در ترمینال سرور لینوکسی اجرا کن:**


```bash
curl -fsSL https://raw.githubusercontent.com/hazhanhasani/HelixPanel-pro/main/install.sh | sudo bash
```


نصب‌کننده به‌صورت تعاملی کار می‌کند: پیش‌نیازها را بررسی می‌کند، شناسه نصب ثابت می‌سازد، وضعیت سرور رسمی انتشار را می‌سنجد و **پس از دریافت لایسنس معتبر** نسخه امضاشده را نصب می‌کند.

**برای امنیت بیشتر، پیش از اجرای اسکریپت با دسترسی مدیر، محتوای آن را بررسی کن:** [مشاهده سورس عمومی install.sh](install.sh).

<details>
<summary><b>🔎 روش نصب با بررسی دستی اسکریپت</b></summary>


```bash
curl -fsSLo install.sh https://raw.githubusercontent.com/hazhanhasani/HelixPanel-pro/main/install.sh
less install.sh
sudo bash install.sh
```


</details>

### 🧩 پیشنیازها

| مورد | توضیح |
|:---|:---|
| سیستم‌عامل | سرور لینوکسی با systemd، ترجیحاً Ubuntu 22.04+ یا Debian 12+ |
| معماری پردازنده | `x86_64` یا `arm64` |
| دسترسی | `root` یا `sudo` |
| ابزارها | Python 3.10+، OpenSSL، curl، useradd (در سیستم‌های سازگار برخی پیش‌نیازها خودکار نصب می‌شوند) |
| ارتباط شبکه | دسترسی HTTPS به GitHub و سرور رسمی انتشار |
| مجوز | لایسنس معتبر صادرشده برای شناسه نصب همان سرور |

در حالت انتشار آزمایشی، حتی اگر پیش‌نیازها کامل باشند، نبود فایل اجرایی امضاشده مانع نصب خواهد شد.

## 🛠️ مدیریت و بروزرسانی

**بررسی وضعیت نصب و سرویس:**


```bash
curl -fsSL https://raw.githubusercontent.com/hazhanhasani/HelixPanel-pro/main/install.sh | sudo bash -s -- status
```


**دریافت شناسه نصب برای صدور لایسنس:**


```bash
curl -fsSL https://raw.githubusercontent.com/hazhanhasani/HelixPanel-pro/main/install.sh | sudo bash -s -- identity
```


**به‌روزرسانی از کانال رسمی با حفظ لایسنس نصب‌شده:**


```bash
curl -fsSL https://raw.githubusercontent.com/hazhanhasani/HelixPanel-pro/main/install.sh | sudo bash -s -- update
```


پس از نصب واقعی، سرویس تحت `systemd` اجرا می‌شود. دستورات کاربردی:


```bash
sudo systemctl status helixpanel
sudo journalctl -u helixpanel -n 100 --no-pager
sudo systemctl restart helixpanel
```


## 🏗️ ساختار توزیع

```mermaid
flowchart TB
    A["Private Core<br/>Source • Builds • Licensing"] --> B["Vendor Release Service<br/>HTTPS • License Validation"]
    B --> C["Signed Release<br/>Ed25519 + SHA-256"]
    D["Public GitHub<br/>install.sh • installer.py"] --> E["Customer Server<br/>Install / Update / Rollback"]
    C --> E
```

- **ریپازیتوری خصوصی:** سورس اصلی، منطق تجاری و ابزارهای صدور و امضای نسخه فقط برای تیم توسعه.
- **ریپازیتوری عمومی (همین مخزن):** اسکریپت‌های نصب، مستندات، تست‌ها و کلیدهای *عمومی* اعتبارسنجی.
- **سرور توزیع فروشنده:** تحویل فایل اجرایی کامپایل‌شده تنها پس از بررسی لایسنس و ایجاد لینک دانلود موقت.

## 🛡️ امنیت و حفاظت از سورس

| کنترل امنیتی | نحوه کار |
|:---|:---|
| مجوز دیجیتال | بررسی لایسنس امضاشده با Ed25519 |
| اصالت نسخه | تأیید امضای مانیفست انتشار |
| یکپارچگی فایل | تطبیق SHA-256 باینری دانلودشده |
| اتصال امن | دانلود فقط از HTTPS و میزبان مجاز |
| پایداری آپدیت | تعویض نسخه و تلاش برای Rollback در صورت شکست Health Check |
| جداسازی سورس | عدم انتشار کد اصلی پنل یا کلیدهای خصوصی در مخزن عمومی |

> [!CAUTION]
> عمومی‌بودن نصب‌کننده به معنی عمومی‌بودن سورس پنل نیست؛ بااین‌حال هیچ نرم‌افزار نصب‌شده روی سرور مشتری را نمی‌توان کاملاً غیرقابل مهندسی معکوس دانست. برای گزارش آسیب‌پذیری، از [راهنمای امنیت](SECURITY.md) استفاده کن و هرگز لایسنس یا کلید خصوصی را در Issues منتشر نکن.

## 📂 فایل‌های مهم

| فایل | کاربرد |
|:---|:---|
| [`install.sh`](install.sh) | نصب و آپدیت تک‌دستوری با راهنمای تعاملی |
| [`installer.py`](installer.py) | بررسی امضا، دریافت نسخه و مدیریت سرویس |
| [`config.public.json`](config.public.json) | وضعیت انتشار و کلیدهای عمومی مورد اعتماد؛ فعلاً Preview |
| [`tests/`](tests/) | تست امنیتی نصب‌کننده |
| [`SECURITY.md`](SECURITY.md) | سیاست گزارش مسائل امنیتی |
| [`LICENSE`](LICENSE) | مجوز اجزای منتشرشده در این مخزن |

## ❓ پرسش‌های متداول

<details>
<summary><b>آیا کاربران می‌توانند سورس اصلی را ببینند؟</b></summary>

خیر، سورس اختصاصی پنل داخل این ریپازیتوری وجود ندارد. کاربران می‌توانند کد عمومی نصب‌کننده را مشاهده یا Fork کنند و فایل اجرایی نصب‌شده نیز از نظر فنی قابل تحلیل است.

</details>

<details>
<summary><b>آیا نصب نیاز به GitHub Token دارد؟</b></summary>

خیر؛ نصب‌کننده از فایل‌های عمومی GitHub استفاده می‌کند و برای دریافت باینری، به سرویس توزیع دارای لایسنس متصل می‌شود.

</details>

<details>
<summary><b>چرا فعلاً نصب کامل نمی‌شود؟</b></summary>

چون سرور رسمی صدور/توزیع نسخه و کلیدهای انتشار هنوز برای استفاده مشتری فعال نشده‌اند. عمداً اجازه نصب فایل فاقد امضای معتبر داده نمی‌شود.

</details>

<details>
<summary><b>پنل بعد از نصب از اینترنت باز می‌شود؟</b></summary>

سرویس در حالت پیش‌فرض روی `127.0.0.1:8080` اجرا می‌شود. برای دسترسی عمومی باید Reverse Proxy با HTTPS و کنترل دسترسی مناسب تنظیم شود.

</details>

## 🤝 مشارکت و پشتیبانی

پیشنهادها و گزارش خطاهای **نصب‌کننده عمومی** را می‌توان از طریق [Issues](https://github.com/hazhanhasani/HelixPanel-pro/issues) ثبت کرد. این مخزن محل انتشار سورس تجاری نیست.

---

**HelixPanel Pro** · Secure delivery. Private core. Professional operations.

© 2026 HelixPanel · [Public Installer License](LICENSE)


