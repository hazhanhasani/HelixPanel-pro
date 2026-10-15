# نصب و اتصال نود به مستر HelixPanel

**نود سرور انتقال ترافیک VPN است؛ مستر فقط مدیریت می‌کند.** این راهنما برای هر دو نوع مستر یکسان است:

- **مستر روی cPanel / DirectAdmin:** PHP + MySQL مستقل روی هاست؛ نیاز به VPS واسط ندارد.
- **مستر روی VPS:** هسته مدیریتی Go با API یکسان.
- **Node VPS:** برنامه `helix-node-agent` و در آینده موتورهای Xray، Sing-box و... اجرا می‌شوند.

## مراحل اتصال

۱. در پنل مستر وارد قسمت «نودها» شو، نود جدید بساز و «صدور کد اتصال» را بزن.

۲. شناسه `Node ID` و توکن `enrollment_token` را بردار. توکن فقط ۱۵ دقیقه اعتبار دارد و با اولین ثبت صحیح مصرف می‌شود.

۳. نسخه کامپایل‌شده `helix-node-agent` را از مسیر رسمی فروشنده به نود انتقال بده. **در Developer Preview هنوز سرور انتشار امضاشده Agent فعال نشده است؛ بنابراین این گام برای مشتری عادی عملیاتی نیست.** تیم توسعه می‌تواند Agent را از سورس خصوصی بسازد.

۴. روی سرور نود:

```bash
sudo install -o root -g root -m 0755 ./helix-node-agent /usr/local/bin/helix-node-agent
sudo mkdir -p /etc/helixpanel-node /var/lib/helixpanel-node
sudo chmod 700 /etc/helixpanel-node /var/lib/helixpanel-node
```

برای ذخیره توکن بدون قراردادن آن در تاریخچه دستورات، یک ترمینال تعاملی SSH باز کن:

```bash
sudo bash -c 'umask 077; read -r -s -p "Enrollment token: " CODE < /dev/tty; printf "%s\n" "$CODE" > /etc/helixpanel-node/enrollment.key; echo'
```

۵. فایل `/etc/systemd/system/helixpanel-node.service` را بساز:

```ini
[Unit]
Description=HelixPanel Node Agent
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=root
Group=root
Environment=HELIX_MASTER_URL=https://master.yourdomain.com
Environment=HELIX_NODE_ID=YOUR_32_CHARACTER_NODE_ID
ExecStart=/usr/local/bin/helix-node-agent
Restart=always
RestartSec=10
NoNewPrivileges=true
ProtectHome=true
ProtectSystem=strict
ReadWritePaths=/var/lib/helixpanel-node /etc/helixpanel-node
PrivateTmp=true
ProtectKernelModules=true
ProtectKernelTunables=true
ProtectControlGroups=true
UMask=0077

[Install]
WantedBy=multi-user.target
```

۶. سرویس را راه‌اندازی کن:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now helixpanel-node
sudo journalctl -u helixpanel-node -n 80 --no-pager
```

مستر پس از اولین ثبت، توکن جداگانه Agent را صادر می‌کند. Agent هر ۳۰ ثانیه Heartbeat می‌فرستد و وضعیت نود در پنل به «آنلاین» تغییر می‌کند.

## اتصال مستر و ترافیک کاربران

```text
   مشتری VPN  ─────────►  Node VPS  ─────────► اینترنت
                              │
                              └── HTTPS/443 ──► Master
                                                cPanel/DirectAdmin PHP
                                                یا Go روی VPS
```

به جز HTTPS خروجی Agent، ترافیک ارتباطی کاربران از مستر عبور نمی‌کند؛ پورت‌های VPN باید روی خود نود باز شوند.

**این مرحله فقط اتصال مدیریتی و Heartbeat است.** مدیریت واقعی سرویس Xray/Sing-box، ایجاد حساب روی موتور، محاسبه دقیق حجم مصرف‌شده و Failover هنوز باید پیاده‌سازی شوند.

برای ساخت Master روی هاست، [راهنمای فارسی](../hosting-master/README.fa.md) را ببین.
