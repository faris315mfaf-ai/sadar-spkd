# Panduan Deploy SADAR-SPKD ke VPS

Panduan ini memasang SADAR-SPKD di satu VPS **Ubuntu 24.04 LTS**:

- **Aplikasi web** (Laravel 12, PHP 8.3) dilayani Nginx + PHP-FPM, database MySQL.
- **node-service** (bot WhatsApp + pembuat slip PDF) berjalan di VPS yang sama lewat PM2, hanya di `127.0.0.1:3000`, tidak terbuka ke internet.
- **HTTPS wajib.** Kamera (daftar/verifikasi wajah) dan GPS di browser hanya jalan di HTTPS.

Ganti semua contoh berikut dengan milik Anda:

| Contoh | Ganti dengan |
|---|---|
| `absensi.domainanda.com` | domain/subdomain aplikasi |
| `<akun-anda>/sadar-spkd` | repo GitHub/GitLab Anda |
| `deploy` | nama user Linux di VPS |

---

## 0. Yang perlu disiapkan

- VPS Ubuntu 24.04, minimal **2 vCPU / 2 GB RAM** (4 GB kalau fitur unduh slip gaji PDF dipakai, karena memakai Chrome).
- Domain dengan **A record** mengarah ke IP VPS.
- Repo git **milik Anda sendiri** (sebaiknya private). Remote `origin` di komputer lokal masih repo pembuat aslinya — jangan push ke sana.
- Nomor WhatsApp khusus untuk bot (bukan nomor pribadi utama). Bot memakai library tidak resmi, jadi ada risiko nomor diblokir WhatsApp.

## 1. Kirim kode ke repo Anda (di komputer lokal)

Buat repo kosong di GitHub, lalu dari folder proyek:

```bash
git remote add spkd https://github.com/<akun-anda>/sadar-spkd.git
git push -u spkd sadar-spkd:main
```

File yang **tidak** ikut ter-push (memang disengaja): `.env`, `node-service/.env`, `database/database.sqlite`, `vendor/`, `node_modules/`, isi `storage/`, dan sesi WhatsApp `node-service/auth/`.

## 2. Siapkan server

Masuk ke VPS sebagai user biasa yang punya `sudo`.

```bash
sudo timedatectl set-timezone Asia/Jakarta
sudo apt update && sudo apt upgrade -y
sudo apt install -y nginx mysql-server git unzip curl \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl \
  php8.3-gd php8.3-zip php8.3-intl php8.3-bcmath
```

Composer:

```bash
curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php
sudo php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
```

Node.js 22 + PM2 (untuk bot WhatsApp):

```bash
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt install -y nodejs
sudo npm install -g pm2
```

Batas upload PHP (foto wajah dan surat dokter) dan zona waktu:

```bash
sudo sed -i 's/^upload_max_filesize.*/upload_max_filesize = 20M/; s/^post_max_size.*/post_max_size = 25M/; s/^memory_limit.*/memory_limit = 512M/; s/^;*date.timezone.*/date.timezone = Asia\/Jakarta/' /etc/php/8.3/fpm/php.ini
sudo systemctl restart php8.3-fpm
```

## 3. Database

```bash
sudo mysql
```

```sql
CREATE DATABASE absensi CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'absensi'@'localhost' IDENTIFIED BY 'GANTI_DENGAN_PASSWORD_KUAT';
GRANT ALL PRIVILEGES ON absensi.* TO 'absensi'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

## 4. Pasang aplikasi

Repo `faris315mfaf-ai/sadar-spkd` bersifat private, jadi VPS perlu login GitHub sekali. Cara termudah dengan GitHub CLI (pilih *GitHub.com → HTTPS → Login with a web browser*, lalu buka kode yang muncul di browser mana pun):

```bash
sudo apt install -y gh
gh auth login
gh auth setup-git
```

```bash
sudo mkdir -p /var/www && sudo chown $USER:$USER /var/www
cd /var/www
git clone https://github.com/faris315mfaf-ai/sadar-spkd.git absensi
cd absensi
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

Buat dua token acak (satu untuk bot WhatsApp, satu untuk layanan PDF) dan simpan hasilnya:

```bash
openssl rand -hex 24
```

Edit `.env` (`nano .env`). Nilai yang **wajib** diubah:

```dotenv
APP_NAME="SADAR-SPKD"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://absensi.domainanda.com
APP_TIMEZONE=Asia/Jakarta

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=absensi
DB_USERNAME=absensi
DB_PASSWORD=GANTI_DENGAN_PASSWORD_KUAT

# Pendaftaran akun mandiri oleh karyawan. false = hanya HR yang menambah karyawan.
SELF_REGISTRATION=true

# Bot WhatsApp & PDF (node-service di server yang sama)
WA_BOT_URL=http://127.0.0.1:3000
WA_BOT_TOKEN=<token-bot>
WA_GROUP_ID=
PDF_SERVICE_URL=http://127.0.0.1:3000
PDF_SERVICE_TOKEN=<token-pdf>
```

Opsional:

- `MAIL_*` — isi SMTP agar fitur **Lupa kata sandi** dan email penolakan izin terkirim. Tanpa SMTP, email hanya dicatat di log.
- `API_CO_ID_KEY` — untuk sinkron hari libur nasional di menu Kalender Kerja.
- `ATTENDANCE_API_TOKEN` — hanya kalau ada aplikasi lain yang membaca API absensi.

Lindungi `.env` dan siapkan folder yang ditulis aplikasi:

```bash
sudo chown $USER:www-data .env && chmod 640 .env
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

Mulai sekarang, jalankan perintah `php artisan` sebagai `www-data` supaya file log dan cache punya pemilik yang sama dengan web server.

Buat tabel dan data awal:

```bash
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan db:seed --class=RoleSeeder --force
sudo -u www-data php artisan db:seed --class=WorkScheduleSeeder --force
sudo -u www-data php artisan db:seed --class=SalaryComponentSeeder --force
sudo -u www-data php artisan db:seed --class=WorkCalendarSeeder --force
sudo -u www-data php artisan db:seed --class=CompanyProfileSeeder --force
```

> **Jangan** menjalankan `php artisan db:seed` tanpa `--class` di server. Seeder bawaan membuat akun `admin@example.com` dengan password yang tertulis di kode sumber.

Buat akun superadmin Anda (password minimal 8 karakter):

```bash
sudo -u www-data php artisan user:create-superadmin --email=admin@domainanda.com --name="Nama Anda" --password='PasswordKuatAnda'
```

Tautan storage (dijalankan sebagai user Anda, karena folder `public/` milik Anda) dan cache produksi:

```bash
php artisan storage:link
sudo -u www-data php artisan optimize
```

Aset CSS/JS sudah ikut di repo (`public/build`), jadi server tidak perlu `npm run build` untuk aplikasi web.

## 5. Nginx

```bash
sudo nano /etc/nginx/sites-available/absensi
```

```nginx
server {
    listen 80;
    server_name absensi.domainanda.com;
    root /var/www/absensi/public;
    index index.php;

    client_max_body_size 25M;
    charset utf-8;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/absensi /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

## 6. HTTPS dan firewall

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d absensi.domainanda.com
```

```bash
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'
sudo ufw enable
```

Port 3000 (node-service) **jangan** dibuka — bot hanya mendengarkan di `127.0.0.1`.

Buka `https://absensi.domainanda.com`, lalu masuk dengan akun superadmin. Segera atur:

1. **Pengaturan → Lokasi**: titik kantor dan radius absensi.
2. **Pengaturan → Jam Kerja**.
3. **Kalender Kerja**: data bawaan hanya tahun 2026; tahun berikutnya dibuat dengan tombol **Generate**.
4. Daftar divisi untuk formulir pendaftaran ada di `config/divisions.php` (ubah di repo, lalu deploy ulang).

## 7. Bot WhatsApp (node-service)

Library sistem untuk Chrome (dipakai membuat slip gaji PDF):

```bash
sudo apt install -y libasound2t64 libatk-bridge2.0-0t64 libatk1.0-0t64 libcups2t64 libdrm2 \
  libgbm1 libgtk-3-0t64 libnss3 libxcomposite1 libxdamage1 libxfixes3 libxkbcommon0 \
  libxrandr2 libpango-1.0-0 libcairo2 fonts-liberation
```

Pasang dan build:

```bash
cd /var/www/absensi/node-service
npm install
npm run build
cp .env.example .env
nano .env
```

Isi `node-service/.env` dengan token yang **sama persis** dengan `.env` Laravel:

```dotenv
PORT=3000
BOT_TOKEN=<token-bot>      # = WA_BOT_TOKEN
AUTH_TOKEN=<token-pdf>     # = PDF_SERVICE_TOKEN
```

**Tautkan nomor WhatsApp (sekali saja).** Pastikan nomor bot sudah menjadi anggota grup tujuan (jadikan admin kalau grup hanya mengizinkan admin mengirim pesan). Lalu:

```bash
npm start
```

QR muncul di terminal. Di HP: WhatsApp → ⋮ / Setelan → **Perangkat tertaut** → **Tautkan perangkat** → scan QR. Tunggu tulisan `Connected successfully`, lalu tekan `Ctrl+C`. Sesi tersimpan di `node-service/auth/`.

Jalankan terus-menerus dengan PM2:

```bash
pm2 start dist/server.js --name spkd-bot --cwd /var/www/absensi/node-service
pm2 save
pm2 startup
```

`pm2 startup` mencetak satu perintah `sudo env PATH=...` — salin dan jalankan perintah itu agar bot hidup lagi setelah server restart.

Hubungkan ke grup:

```bash
cd /var/www/absensi
sudo -u www-data php artisan whatsapp:groups
```

Salin **ID grup** tujuan (contoh `120363xxxxxxxx@g.us`) ke `WA_GROUP_ID=` di `.env`, lalu:

```bash
sudo -u www-data php artisan optimize
sudo -u www-data php artisan whatsapp:groups --test
```

Kalau grup menerima pesan uji, absen masuk/pulang dan izin yang disetujui HR akan otomatis terkirim ke grup itu.

## 8. Tugas terjadwal (cron)

```bash
sudo crontab -u www-data -e
```

Tambahkan (jam mengikuti zona waktu server, WIB):

```cron
# Tandai alfa karyawan yang tidak absen hari ini
0 23 * * * cd /var/www/absensi && php artisan attendance:mark-alpha >> /dev/null 2>&1
# Tolak otomatis pengajuan izin/sakit kemarin yang belum diverifikasi HR
0 0 * * * cd /var/www/absensi && php artisan attendance:expire-pending-leaves >> /dev/null 2>&1
# (Opsional) rekap absen masuk ke grup WhatsApp, Senin–Sabtu 11:00
0 11 * * 1-6 cd /var/www/absensi && php artisan attendance:send-whatsapp-report masuk >> /dev/null 2>&1
```

Aplikasi tidak memakai antrean (queue), jadi tidak perlu menjalankan `queue:work`.

## 9. Backup harian

Simpan password database di file khusus agar tidak muncul di daftar proses:

```bash
sudo mkdir -p /var/backups/absensi
printf '[client]\nuser=absensi\npassword=GANTI_DENGAN_PASSWORD_KUAT\n' | sudo tee /root/.absensi.my.cnf >/dev/null
sudo chmod 600 /root/.absensi.my.cnf
sudo crontab -e
```

```cron
30 2 * * * mysqldump --defaults-extra-file=/root/.absensi.my.cnf absensi | gzip > /var/backups/absensi/db-$(date +\%F).sql.gz
45 2 * * * tar -czf /var/backups/absensi/files-$(date +\%F).tar.gz -C /var/www/absensi/storage/app public
0 3 * * * find /var/backups/absensi -type f -mtime +14 -delete
```

Folder `storage/app/public` berisi foto profil, selfie absen, dan surat dokter. Salin backup secara berkala ke tempat lain (misalnya Google Drive atau komputer kantor).

## 10. Update aplikasi di kemudian hari

Di komputer lokal: commit perubahan, jalankan `npm run build` kalau mengubah CSS/JS, lalu `git push spkd sadar-spkd:main`. Di VPS:

```bash
cd /var/www/absensi
git pull
composer install --no-dev --optimize-autoloader
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan optimize
```

Kalau ada perubahan di `node-service/`:

```bash
cd /var/www/absensi/node-service && npm install && npm run build && pm2 restart spkd-bot
```

Setiap mengubah `.env` (termasuk `SELF_REGISTRATION`), jalankan ulang `sudo -u www-data php artisan optimize`.

## 11. Cek setelah deploy

- [ ] `https://absensi.domainanda.com` terbuka dengan gembok HTTPS.
- [ ] Login superadmin berhasil; `APP_DEBUG=false`.
- [ ] Dari HP: daftar akun → biodata → daftar wajah (kamera terbuka) → cek lokasi di kantor.
- [ ] Absen masuk dari HP di area kantor, lalu pesan + foto muncul di grup WhatsApp.
- [ ] Menu Penggajian → Proses Gaji → Unduh PDF slip berhasil.
- [ ] `sudo -u www-data php artisan whatsapp:groups` menunjukkan grup bertanda ✓.

## 12. Kalau ada masalah

| Gejala | Periksa |
|---|---|
| Halaman "Server Error" | `tail -n 50 /var/www/absensi/storage/logs/laravel.log` |
| `Permission denied` di storage/log | ulangi perintah `chown`/`chmod` di langkah 4 |
| Kamera/GPS tidak mau terbuka | aplikasi harus dibuka lewat `https://` |
| Foto wajah gagal diunggah | `client_max_body_size` Nginx dan `post_max_size` PHP (langkah 2 & 5) |
| Pesan WA tidak terkirim | `pm2 logs spkd-bot`, lalu `sudo -u www-data php artisan whatsapp:groups` |
| Bot minta scan QR lagi | perangkat tertaut dihapus dari HP: `pm2 stop spkd-bot`, hapus `node-service/auth`, ulangi langkah 7 |
| Pesan WA masuk tanpa foto | bot harus bisa membuka `APP_URL`; pastikan `APP_URL` benar dan HTTPS aktif |
| Unduh slip PDF gagal | library Chrome di langkah 7 sudah terpasang, `pm2 logs spkd-bot` |
| Error saat `migrate` di MySQL | kirim pesan error lengkapnya — pengujian lokal memakai SQLite |
