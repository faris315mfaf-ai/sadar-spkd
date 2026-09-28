# SADAR-SPKD di VPS bersama (Docker)

Cara SADAR-SPKD dipasang di VPS `PORTAL` (187.77.113.63). Server ini juga menjalankan aplikasi lain, jadi SADAR dibuat **terisolasi dan bisa dihapus bersih**. (Untuk VPS kosong tanpa Docker, lihat [DEPLOY_VPS.md](DEPLOY_VPS.md).)

## Susunan di server

```
/opt/sadar-spkd/
├── app/            # clone repo + .env produksi + storage (foto, log)
├── deploy_key      # SSH key read-only untuk git pull (deploy key di GitHub)
├── known_hosts     # sidik jari GitHub
└── backups/        # hasil deploy/backup.sh
```

| Container | Isi | Batas |
|---|---|---|
| `sadar-web` | Nginx, satu-satunya yang masuk jaringan Caddy (`caddy_default`) | 128 MB, 0.5 CPU |
| `sadar-app` | PHP 8.3-FPM (Laravel) | 1 GB, 1.5 CPU |
| `sadar-scheduler` | Jadwal otomatis: alfa 23:00, izin kedaluwarsa 00:00 | 256 MB, 0.5 CPU |
| `sadar-db` | MySQL 8.4 khusus SADAR (volume `sadar-spkd-mysql`) | 1 GB, 1 CPU |
| `sadar-bot` | Bot WhatsApp + PDF slip (volume `sadar-spkd-wa-auth`) | 1.5 GB, 1 CPU |

Tidak ada port yang dibuka ke luar. Satu-satunya titik bersama dengan aplikasi lain adalah blok berikut di `/opt/caddy/Caddyfile`:

```caddy
# BEGIN sadar-spkd
absensi.spkd.store {
	encode gzip
	request_body {
		max_size 25MB
	}
	reverse_proxy sadar-web:80
}
# END sadar-spkd
```

Setiap mengubah Caddyfile: backup dulu, validasi, lalu reload (tanpa restart, situs lain tidak terputus):

```bash
cp /opt/caddy/Caddyfile /opt/caddy/Caddyfile.cadangan-$(date +%Y%m%d-%H%M%S)
docker exec caddy caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile
docker exec caddy caddy reload --config /etc/caddy/Caddyfile --adapter caddyfile
```

## Perintah sehari-hari

Semua dijalankan di `/opt/sadar-spkd/app`:

```bash
cd /opt/sadar-spkd/app
alias sadar='docker compose -f docker-compose.prod.yml'
```

| Keperluan | Perintah |
|---|---|
| Status | `docker ps --filter name=sadar-` |
| Log aplikasi | `tail -f storage/logs/laravel-$(date +%F).log` |
| Log bot / QR WhatsApp | `docker logs -f sadar-bot` |
| Perintah artisan | `sadar exec -u www-data sadar-app php artisan <perintah>` |
| Buat/ubah superadmin | `sadar exec -u www-data sadar-app php artisan user:create-superadmin --email=EMAIL --name="NAMA"` |
| Cek grup WhatsApp | `sadar exec -u www-data sadar-app php artisan whatsapp:groups` |
| Restart satu layanan | `sadar restart sadar-bot` |

`user:create-superadmin` tanpa `--password` membuat password acak dan menampilkannya **sekali** di terminal. Ganti lewat menu Profile setelah login.

### Menghubungkan WhatsApp

1. Masukkan nomor bot ke grup tujuan.
2. `docker logs -f sadar-bot` → scan QR dari HP (WhatsApp → Perangkat tertaut → Tautkan perangkat) → tunggu `Connected successfully` → `Ctrl+C` (bot tetap jalan).
3. `sadar exec -u www-data sadar-app php artisan whatsapp:groups` → salin ID grup ke `WA_GROUP_ID=` di `.env`.
4. `sadar exec -u www-data sadar-app php artisan optimize` lalu `... whatsapp:groups --test`.

Sesi tersimpan di volume `sadar-spkd-wa-auth`, jadi restart container tidak meminta QR lagi.

## Update aplikasi

Di komputer lokal: commit, `npm run build` kalau CSS/JS berubah, lalu `git push`. Di server:

```bash
cd /opt/sadar-spkd/app
git pull
sadar build                      # hanya kalau docker/ atau node-service/ berubah
sadar run --rm --no-deps -T sadar-app composer install --no-dev --optimize-autoloader --no-interaction
sadar exec -u www-data sadar-app php artisan migrate --force
sadar exec -u www-data sadar-app php artisan optimize
sadar up -d
```

Setiap mengubah `.env`, jalankan ulang `php artisan optimize` (baris kedua dari bawah).

## Backup

```bash
/opt/sadar-spkd/app/deploy/backup.sh /opt/sadar-spkd/backups
```

Hasilnya satu file `sadar-spkd-TANGGAL.tar.gz` berisi `database.sql`, `storage-app.tar` (foto profil, selfie absen, surat dokter), `wa-auth.tar` (sesi WhatsApp) dan `env`. File berisi rahasia (`.env`), jadi simpan di tempat aman. Backup harian otomatis (simpan 14 hari):

```cron
30 2 * * * /opt/sadar-spkd/app/deploy/backup.sh /opt/sadar-spkd/backups >/dev/null 2>&1 && find /opt/sadar-spkd/backups -name 'sadar-spkd-*.tar.gz' -mtime +14 -delete
```

## Pindah ke server baru

1. Buat backup terbaru (di atas), salin file `.tar.gz` ke server baru, lalu ekstrak ke folder sementara:
   `mkdir /tmp/sadar-restore && tar -xzf sadar-spkd-*.tar.gz -C /tmp/sadar-restore`
2. Di server baru: pasang Docker, clone repo ke `/opt/sadar-spkd/app`, salin `env` → `.env`, ubah `APP_URL` kalau domain berganti, `chown root:33 .env && chmod 640 .env`.
3. Kalau server baru **tidak** punya Caddy bersama: hapus `caddy` dari `networks:` pada `sadar-web` di `docker-compose.prod.yml` dan tambahkan `ports: ["80:80"]`, atau pasang proxy HTTPS sendiri di depannya.
4. Jalankan:

```bash
cd /opt/sadar-spkd/app
alias sadar='docker compose -f docker-compose.prod.yml'
sadar build
sadar run --rm --no-deps -T sadar-app composer install --no-dev --optimize-autoloader --no-interaction
sadar up -d sadar-db
sadar exec -T sadar-db sh -c 'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' < /tmp/sadar-restore/database.sql
tar -xf /tmp/sadar-restore/storage-app.tar -C storage && chown -R 33:33 storage bootstrap/cache
sadar up -d
sadar exec -T sadar-bot sh -c 'tar -C /app/auth -xf -' < /tmp/sadar-restore/wa-auth.tar && sadar restart sadar-bot
sadar exec sadar-app php artisan storage:link
sadar exec -u www-data sadar-app php artisan migrate --force
sadar exec -u www-data sadar-app php artisan optimize
```

## Ganti domain (misalnya dari spkd.store ke domain permanen)

1. Buat A record domain baru ke IP server.
2. Di Caddyfile, ubah alamat di blok `sadar-spkd` menjadi domain baru, dan tambahkan pengalihan domain lama:
   ```caddy
   absensi.spkd.store {
   	redir https://absensi.domainbaru.id{uri} permanent
   }
   ```
3. Ubah `APP_URL` dan `MAIL_FROM_ADDRESS` di `.env`, lalu `php artisan optimize`, lalu validasi + reload Caddy.

Data dan foto tidak terpengaruh. Karyawan cukup login ulang dan mengizinkan kamera/lokasi sekali lagi di domain baru.

## Menghapus total dari server ini

Buat backup dulu, lalu:

```bash
cd /opt/sadar-spkd/app && docker compose -f docker-compose.prod.yml down -v --rmi local
docker image rm sadar-spkd-php:latest sadar-spkd-bot:latest 2>/dev/null
```

1. Hapus blok `# BEGIN sadar-spkd … # END sadar-spkd` dari `/opt/caddy/Caddyfile`, lalu validasi + reload Caddy.
2. `rm -rf /opt/sadar-spkd`
3. Hapus deploy key "VPS PORTAL (read-only)" di GitHub → repo `sadar-spkd` → Settings → Deploy keys.
4. Hapus A record `absensi` di DNS kalau tidak dipakai lagi.

Setelah itu tidak ada lagi container, volume, jaringan, atau file SADAR di server.
