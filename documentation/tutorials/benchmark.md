# Benchmark: PHP-FPM, FrankenPHP, Swoole, JIT, PHP 8.2–8.4

Harness Docker untuk membandingkan performa Codekop PHP MVC di beberapa
runtime PHP dan versi PHP, memakai command CLI `api:bench` yang sudah ada di
`system/console.php` (lihat bagian "Benchmark API" pada
[backend.md](?doc=tutorials%2Fbackend.md)) sebagai alat ukur.

## Ringkasan

Dokumen ini panjang karena setiap temuan diverifikasi nyata (build,
jalankan, ukur — bukan cuma dokumentasi rencana). Untuk yang buru-buru:

- **5 runtime diuji nyata** (bukan cuma scaffolding): PHP-FPM+Apache2,
  PHP-FPM+Nginx, FrankenPHP klasik, FrankenPHP worker mode, Swoole. Semua
  jalan penuh di PHP 8.2–8.4.
- **Dua endpoint diukur**: `GET /api/health` (stateless) dan
  `GET /api/db/transaksi` (query nyata ke tabel 20.000 baris mirip tabel
  `penjualan`/transaksi asli, di database benchmark terisolasi — bukan
  database production `estock`).
- **Swoole tercepat secara konsisten** di kedua endpoint (~17–25k rps
  health, ~8–11k rps DB) dan paling irit RAM, tapi statusnya eksperimental.
- **FrankenPHP worker mode ternyata cepat** (~4.7–8.1k rps health, ~5.5–6.0k
  rps DB — kedua tertinggi setelah Swoole, bahkan mengalahkan mode klasik
  dan php-fpm untuk endpoint DB) — **setelah** bug konfigurasi Caddy yang
  cukup fatal ditemukan dan diperbaiki (lihat poin di bawah). Sebelum
  diperbaiki, angka yang terukur salah total (worker-nya tidak pernah benar-
  benar menerima traffic).
- **PHP-FPM+Nginx/Apache2 paling aman**: kompatibel penuh apa adanya,
  throughput sebanding FrankenPHP klasik.
- **Empat bug nyata level framework/infrastruktur ditemukan & diperbaiki**
  saat validasi (bukan cuma bug di harness benchmark-nya): dua soal
  `global` scope PHP yang bikin Swoole gagal (routing berhenti setelah
  request pertama, lalu query DB gagal total), satu soal `CSP_NONCE`
  constant yang bikin worker crash di 404 apa pun, dan — yang paling
  signifikan — **FrankenPHP worker mode diam-diam tidak pernah aktif**
  selama sebagian besar sesi ini: `php_server` default-nya me-rewrite semua
  request ke `/index.php` dan mengeksekusinya secara klasik, bukan lewat
  worker script yang didaftarkan, sehingga semua angka "worker mode" yang
  terukur sebelumnya sebenarnya cuma mengukur classic mode ditambah
  overhead thread worker yang menganggur. Detail penuh di bagian "Bug yang
  ditemukan & diperbaiki" dan "Kesimpulan".
- Semua kombinasi nyaman jauh di bawah batas 2GB RAM — di beban 2 CPU,
  **CPU** yang jadi batas nyata, bukan RAM.
- Data mentah: `docker/benchmark/results/results.csv`,
  `results-db.csv`, `resource-usage.csv`.

## Metodologi

- **Alokasi resource tetap**: setiap kombinasi dijalankan dalam satu
  container dengan batas **2 CPU / 2GB RAM** (`--cpus=2 --memory=2g`), supaya
  perbandingan antar runtime adil dan hasilnya tidak bergantung pada resource
  host yang berbeda-beda.
- **Satu kombinasi aktif dalam satu waktu**: kombinasi dijalankan
  sequential (build → run → benchmark → teardown → kombinasi berikutnya),
  bukan seluruh matrix berjalan bersamaan, supaya tiap kombinasi benar-benar
  mendapat alokasi 2 CPU/2GB penuh tanpa berebut resource host.
- **Diukur dari host**, bukan dari dalam container, memakai
  `php system/console.php api:bench` — sama seperti mengukur API sungguhan
  dari klien eksternal.
- **Endpoint uji: `GET /api/health`** — endpoint JSON stateless, tanpa
  session dan tanpa query database, supaya hasil murni mengukur overhead
  runtime/web-server, bukan variabel lain (lihat catatan mode worker di
  bawah untuk alasan endpoint ini juga jadi batas cakupan matrix).

## Matrix

| Runtime | 8.2 | 8.3 | 8.4 | Status |
|---|---|---|---|---|
| PHP-FPM + Apache2 | **diukur** | **diukur** | **diukur** | smoke test lulus |
| PHP-FPM + Nginx | **diukur** | **diukur** | **diukur** | smoke test lulus |
| FrankenPHP (klasik) | **diukur** | **diukur** | **diukur** | smoke test lulus |
| FrankenPHP (worker mode) | **diukur** | **diukur** | **diukur** | **eksperimental**, lihat catatan — smoke test lulus setelah bug diperbaiki |
| Swoole | **diukur** | **diukur** | **diukur** | **eksperimental**, lihat catatan — smoke test lulus setelah bug diperbaiki |

Kelima runtime tervalidasi jalan (bukan cuma scaffold) di PHP 8.2–8.4 —
`run-matrix.sh` sudah menyelesaikan seluruh matrix itu. Lihat
`results/results.csv` untuk data lengkap dan `results/results-db.csv` untuk
hasil endpoint `GET /api/db/transaksi` (lihat bagian "Pengujian dengan
database"). PHP 8.5 tidak dicakup — gagal build konsisten di kelima
runtime (`docker-php-ext-install` gagal compile ekstensi terhadap base
image PHP 8.5), kemungkinan besar isu kompatibilitas ekstensi yang belum
ada solusinya, di luar cakupan harness ini.

JIT (`opcache.jit`) diukur on vs off pada tiap baris di atas lewat env
`BENCH_JIT=on|off` (lihat bagian Cara pakai).

### Bug yang ditemukan & diperbaiki selama validasi

Ini bukan cuma scaffolding yang "kelihatannya jalan" — smoke test nyata
menemukan dan memperbaiki masalah berikut:

- **Swoole gagal build**: `pecl install swoole` default mengaktifkan dukungan
  Brotli, butuh `libbrotli-dev` yang belum ter-install →
  `docker/benchmark/swoole/Dockerfile` ditambah paket itu.
- **Swoole crash setelah request pertama** (dikonfirmasi langsung lewat
  stack trace nyata di container Swoole): `app/Config/Routes.php` mengisi
  variabel lokal `$routes` (bukan static class property), dan
  `system/run.php` memakainya lewat `require_once` — yang cuma benar-benar
  jalan sekali per proses. Di proses long-running (Swoole), request kedua
  di proses yang sama gagal dengan `$routes` undefined. Diperbaiki di level
  bootstrap (`worker.php`/`server.php`, keduanya dipakai pola yang sama):
  `app/Config/Routes.php` di-`require_once` sekali di awal, hasilnya
  ditangkap ke variabel, lalu disuntikkan ulang ke scope `system/run.php`
  setiap request — tanpa mengubah file inti framework sama sekali.
- **Crash pada 404 apa pun di proses long-running**: view bawaan
  `app/Views/errors/error_404.php` memakai constant `CSP_NONCE` langsung;
  karena bootstrap ini sengaja tidak mendefinisikan constant itu per
  request (lihat di bawah), setiap 404 bikin worker fatal. Diperbaiki
  dengan `define('CSP_NONCE', '')` sekali di awal proses — aman karena
  bootstrap ini juga sengaja tidak mengirim header CSP.
- **`apache2-foreground` tidak ada**: script itu cuma ada di image resmi
  `php:apache`, bukan di `apache2` yang diinstal manual di atas
  `php:fpm`. `docker/benchmark/php-fpm-apache/start.sh` diganti memakai
  `apache2ctl -D FOREGROUND`.
- **Caddy pakai RE2, bukan PCRE**: percobaan pertama Caddyfile memakai
  negative lookahead `(?!...)` untuk aturan deny — gagal start karena Go's
  regexp (dipakai Caddy) tidak mendukung lookahead. Diganti pakai matcher
  `not path ...`.
- **`composer.json`/`composer.lock` bocor lewat Caddy**: aturan deny awal
  di Caddyfile hanya meniru `nginx.conf.example` (yang ternyata juga tidak
  menutup nama file ini), padahal root `.htaccess` proyek secara eksplisit
  menutupnya. Caddyfile ditambah matcher `@meta_files` supaya sejajar
  dengan `.htaccess`.
- **Bug di `api:bench` sendiri**: urutan argumen `sprintf` untuk baris
  `p99=...ms max=...ms` tertukar (nilai `p99` dan `max` saling tertukar).
  Diperbaiki di `system/console.php`.
- **Query DB gagal total di Swoole** (baru ditemukan saat menambah endpoint
  `GET /api/db/transaksi`, dikonfirmasi lewat respons `{"success":false,
  "message":"Database not configured"}`):
  `System\Database::__construct()` membaca konfigurasi lewat
  `global $dbconfig;`, dan keyword `global` di PHP **selalu** mengikat ke
  `$GLOBALS` asli — bukan ke scope lokal siapa pun yang me-`require` file
  ini. Karena bootstrap worker/Swoole meng-`require` seluruh rantai
  (`app/Config/Database.php` → `system/run.php`) dari dalam closure request
  handler, `$dbconfig` yang di-set di sana cuma jadi variabel lokal closure,
  tidak pernah sampai ke `$GLOBALS` — beda dengan `$routes` yang dibaca
  `system/run.php` sebagai variabel lokal biasa (bukan lewat `global`),
  sehingga trik "suntik ulang" yang sama tidak otomatis berlaku untuk
  `$dbconfig`. Dibuktikan dengan test langsung di dalam container
  (`global $dbconfig` di luar closure tetap `isset()` false meski di-set di
  dalam function). Diperbaiki di `worker.php`/`server.php`: tangkap
  `$dbconfig` sekali di awal proses, lalu di setiap request eksplisit
  `global $dbconfig; $dbconfig = $bootDbConfig;` sebelum `require
  system/run.php` — ini yang benar-benar menulis ke `$GLOBALS`, bukan
  sekadar assignment lokal.
- **FrankenPHP worker mode diam-diam tidak pernah aktif** — temuan paling
  signifikan dari sesi ini. Semua data "frankenphp-worker" yang terukur
  sebelum bug ini ditemukan **salah**: `php_server` bawaan Caddy punya
  perilaku default me-rewrite request non-file ke `/index.php` lalu
  mengeksekusinya secara **klasik** (proses per-request biasa) — bukan
  lewat script worker yang didaftarkan di `frankenphp { worker { file
  ... } } }`. Worker-nya sendiri berhasil start dan menunggu
  ("waiting for request" di log debug Caddy), tapi karena `/index.php`
  adalah path yang berbeda dari `/frankenphp-worker.php`, tidak ada request
  yang pernah benar-benar sampai ke worker itu. Dibuktikan definitif dengan
  menaruh `throw new \RuntimeException(...)` persis di baris pertama
  closure request handler: sebelum fix, exception itu **tidak pernah**
  muncul di log meski puluhan request dikirim; setelah fix, exception yang
  sama langsung muncul di request pertama.

  Perbaikan: tambah `rewrite * /frankenphp-worker.php` di Caddyfile sebelum
  `php_server`, memaksa Caddy mengarahkan semua request ke script worker
  yang benar. Ini sendiri membuka celah baru — file statis nyata (mis.
  `assets/*`) jadi ikut ter-rewrite dan gagal dilayani lewat PHP (worker
  tidak tahu cara serve file statis) — jadi ditambah matcher `@static
  { file }` untuk memisahkan: file yang benar-benar ada di disk tetap
  dilayani `file_server` seperti biasa, sisanya baru di-rewrite ke worker.

  Perbaikan ini juga **menyingkap bug keamanan baru**: begitu
  `frankenphp-worker.php` jadi path yang valid untuk di-`rewrite`,
  pengecualian lama pada matcher `@other_php` (`not path
  /frankenphp-worker.php`, dulunya `not path /index.php`) membuat file itu
  bisa **diakses langsung oleh client** — dan karena filenya ada secara
  fisik di disk, `@static { file }` mencocokkannya dan Caddy
  men-*serve*-nya sebagai file teks biasa lewat `file_server`, membocorkan
  **source code PHP mentah** ke client (`curl .../frankenphp-worker.php`
  mengembalikan isi file `.php`-nya, bukan hasil eksekusi). Diperbaiki
  dengan menghapus pengecualian itu sepenuhnya — matcher `@any_php`
  sekarang menolak **semua** path `.php` tanpa kecuali, karena `rewrite`
  internal ke worker terjadi di `handle` block berikutnya yang tidak
  pernah dicek ulang terhadap matcher-matcher deny di atasnya.

  Dampak ke hasil ukur: setelah diperbaiki dan matrix diukur ulang
  sepenuhnya, FrankenPHP worker mode **jauh lebih cepat** dari angka
  sebelumnya — `GET /api/health` naik dari kisaran ~1.6–4.1k rps (classic
  mode yang menyamar) menjadi ~4.7–8.1k rps (worker sungguhan), dan
  `GET /api/db/transaksi` naik dari ~1.05–1.34k rps menjadi ~5.5–6.0k rps
  (bahkan mengalahkan php-fpm dan FrankenPHP klasik untuk endpoint ini).
  Data lama di `results.csv`/`results-db.csv` sudah diganti dengan hasil
  yang benar (kolom `note` = `worker_rewrite_fix_verified`).

## Struktur

```
docker/benchmark/
  _shared/jit-entrypoint.sh   # toggle opcache.jit dari env BENCH_JIT
  php-fpm-apache/              # php:${PHP_VERSION}-fpm + Apache (mod_proxy_fcgi)
  php-fpm-nginx/                # php:${PHP_VERSION}-fpm + Nginx
  frankenphp/                    # dunglas/frankenphp:php${PHP_VERSION}, mode klasik
  frankenphp-worker/              # sama, mode worker (frankenphp-worker.php)
  swoole/                          # php:${PHP_VERSION}-cli + ext-swoole
  db/init.sql                       # skema+data seed benchmark-only (lihat "Pengujian dengan database")
  docker-compose.yml               # 1 service contoh per runtime (PHP 8.4) + db untuk uji manual
  run-matrix.sh                     # orkestrator: build+run+bench+teardown seluruh matrix
  results/results.csv               # hasil GET /api/health yang benar-benar terukur (di-commit)
  results/results-db.csv            # hasil GET /api/db/transaksi (kolom endpoint tambahan)
```

Semua image mem-build source project yang sama (`COPY . .` dari root repo)
dengan `composer install --no-dev`, mengikuti pola `docker/php/Dockerfile`
yang sudah ada untuk deployment normal.

## Cara pakai

Jalankan seluruh matrix (bisa lama — banyak `docker build`):

```bash
bash docker/benchmark/run-matrix.sh
```

Batasi ke kombinasi tertentu:

```bash
# Hanya php-fpm-nginx dan frankenphp, hanya PHP 8.4
bash docker/benchmark/run-matrix.sh php-fpm-nginx frankenphp -- 8.4
```

Coba satu kombinasi secara manual (mis. untuk eksplorasi endpoint lain selain
`/api/health`):

```bash
docker build --build-arg PHP_VERSION=8.4 \
  -f docker/benchmark/php-fpm-nginx/Dockerfile -t bench-nginx-84 .
docker run -d --rm --cpus=2 --memory=2g -p 18080:80 \
  -e BENCH_JIT=on --name bench_run bench-nginx-84
php system/console.php api:bench --url=http://127.0.0.1:18080/api/health \
  --requests=300 --concurrency=20
docker stop bench_run
```

Atau lewat compose (satu combo per service, PHP 8.4, JIT off):

```bash
docker compose -f docker/benchmark/docker-compose.yml up --build php-fpm-nginx
```

## Pengujian dengan database (transaksi)

Semua yang di atas menguji `GET /api/health` — endpoint stateless tanpa
query DB. Untuk mengukur biaya nyata query database, harness ini juga punya
endpoint `GET /api/db/transaksi` (`app/Controllers/Api.php`, route di
`app/Routes/api.php`) yang benar-benar `SELECT ... FROM penjualan ORDER BY
id DESC LIMIT :limit` lewat `$this->db` (PDO) bawaan `System\Controller`.

**Database yang dipakai bukan `estock` (database asli project)** — itu
sengaja dihindari supaya beban benchmark (ratusan/ribuan request paralel)
tidak pernah menyentuh data produksi atau bikin kontensi dengan pekerjaan
lain. Sebagai gantinya, `docker/benchmark/db/init.sql` membuat tabel
`penjualan` dengan struktur **persis sama** dengan tabel `penjualan` asli
(kolom, primary key, unique key `no_trx`, index `tanggal_input` dan
`id_pelanggan+tanggal_input`), lalu diisi 20.000 baris data sintetis —
cukup besar supaya `ORDER BY id DESC LIMIT` dan pemakaian index terasa
nyata, tanpa lama saat container pertama kali start.

`app/Config/Database.php` sekarang membaca `DB_HOST`/`DB_PORT`/`DB_NAME`/
`DB_USER`/`DB_PASSWORD` dari environment variable (fallback ke nilai
default yang sudah ada kalau env tidak di-set) — jadi container benchmark
bisa diarahkan ke database benchmark yang terisolasi tanpa mengubah
konfigurasi lokal/production.

Jalankan:

```bash
# Matrix penuh (5 runtime x 4 versi x 2 JIT), endpoint health + DB sekaligus
bash docker/benchmark/run-matrix.sh --with-db

# Dibatasi ke kombinasi tertentu, sama seperti tanpa --with-db
bash docker/benchmark/run-matrix.sh --with-db php-fpm-nginx swoole -- 8.4
```

`--with-db` menyalakan satu container `mariadb:11` **sekali** di awal (di
luar jatah 2 CPU/2GB tiap runtime — DB adalah dependency bersama, bukan
yang sedang diukur), dipakai bareng oleh seluruh kombinasi lewat Docker
network `codekop_bench_net`, lalu dimatikan di akhir. Hasilnya masuk ke
`docker/benchmark/results/results-db.csv` (skema sama seperti
`results.csv`, plus kolom `endpoint`).

Manual/compose:

```bash
docker compose -f docker/benchmark/docker-compose.yml up -d --build db php-fpm-nginx
curl "http://127.0.0.1:18082/api/db/transaksi?limit=20"
php system/console.php api:bench \
  --url="http://127.0.0.1:18082/api/db/transaksi?limit=20" --requests=300 --concurrency=20
```

## Hasil (smoke test nyata, PHP 8.4)

Dijalankan langsung di sesi ini: build image, `docker run --cpus=2
--memory=2g`, tunggu `/api/health` sehat, lalu `api:bench --requests=300
--concurrency=20` dari host. Host benchmark: 12 core / 14GB (shared dengan
proses lain saat pengukuran), jadi angka ini indikatif, bukan hasil
lab-terisolasi — jalankan ulang di mesin target sebelum dipakai untuk
keputusan kapasitas produksi.

| Runtime | PHP | JIT | RPS | avg (ms) | p95 (ms) | p99 (ms) | Sukses |
|---|---|---|---|---|---|---|---|
| php-fpm+nginx | 8.4 | off | 5618.00 | 3.20 | 12.37 | 13.82 | 300/300 |
| php-fpm+nginx | 8.4 | on | 6452.01 | 2.74 | 3.63 | 11.23 | 300/300 |
| php-fpm+apache | 8.4 | off | 5461.40 | 3.23 | 10.46 | 13.22 | 300/300 |
| php-fpm+apache | 8.4 | on | 6038.65 | 2.86 | 3.75 | 10.21 | 300/300 |
| FrankenPHP (klasik) | 8.4 | off | 4955.81 | 3.38 | 6.99 | 8.93 | 300/300 |
| FrankenPHP (klasik) | 8.4 | on | 5324.07 | 3.37 | 14.70 | 17.12 | 300/300 |
| FrankenPHP (worker, eksperimental) | 8.4 | off | 7715.08 | 2.24 | 3.93 | 5.22 | 300/300 |
| FrankenPHP (worker, eksperimental) | 8.4 | on | 5828.42 | 3.01 | 10.29 | 11.86 | 300/300 |
| Swoole (eksperimental) | 8.4 | off | 21851.40 | 0.65 | 1.35 | 9.97 | 300/300 |
| Swoole (eksperimental) | 8.4 | on | 16676.93 | 0.79 | 2.62 | 9.09 | 300/300 |

Data mentah: `docker/benchmark/results/results.csv`. Semua lima kombinasi
juga diverifikasi lolos aturan front-controller-only
(`/system/console.php`, `/.env`, `/composer.json`, `/composer.lock`,
`/README.md`, `/vendor/autoload.php` semuanya ditolak — 404 di
Nginx/FrankenPHP/Swoole, 403 di Apache karena `[F]` di `.htaccess`;
`/assets/...` tetap 200), dan untuk FrankenPHP worker mode + Swoole juga
diverifikasi proses tidak crash setelah request pertama maupun setelah
menerima 404 (lihat bug yang diperbaiki di atas).

Swoole jauh lebih cepat karena tidak ada biaya spin-up proses per request
sama sekali (murni event loop + worker process tetap hidup). Angka
FrankenPHP worker mode di tabel di atas (~5.8–7.7k rps, kedua tertinggi)
sudah yang benar — diukur ulang setelah bug rewrite Caddy yang membuatnya
diam-diam jalan sebagai classic mode diperbaiki (lihat "Bug yang
ditemukan & diperbaiki").

FrankenPHP mode klasik sedikit lebih lambat dari php-fpm+apache/nginx di
tabel ini (~5.0k vs ~5.5–6.5k rps); gap sekecil ini di sampel 300 request
pada host yang tidak terisolasi penuh **tidak cukup untuk disimpulkan
sebagai perbedaan performa yang nyata** (dicoba naikkan gzip dan sampel
lebih besar, tetap di kisaran yang sama — lihat host-noise di bagian
Kesimpulan untuk kisaran akhir yang lebih representatif). Untuk
perbandingan yang bisa dipercaya, ulangi `run-matrix.sh` saat host
benar-benar idle dan naikkan `--requests` (mis. 2000–5000).

Matrix PHP 8.2–8.4 untuk `GET /api/health` **dan** `GET /api/db/transaksi`
di seluruh runtime **sudah selesai** lewat `run-matrix.sh` /
`run-matrix.sh --with-db` — lihat `docker/benchmark/results/results.csv`
dan `results-db.csv` untuk data lengkapnya.

## Kesimpulan

Ringkasan dari seluruh pengukuran di sesi ini (bukan cuma satu perbandingan),
dari dua sumber: `results/results.csv` (satu request singkat per kombinasi,
host relatif idle) dan `results/resource-usage.csv` (beban berkelanjutan ~8
detik sambil disadap `docker stats`, **direkam saat host sedang sibuk**
membangun image lain di background — jadi angka RPS di sini lebih rendah dari
`results.csv`, tapi perbandingan CPU/RAM **antar runtime** tetap valid karena
kelimanya diukur di bawah gangguan host yang sama).

**RPS — `GET /api/health`** (kisaran final dari seluruh PHP 8.2–8.4 x JIT
on/off yang terukur, `--requests=300`, `--concurrency=20`, gabungan
beberapa kali run jadi rentangnya mencerminkan variasi nyata antar run,
bukan cuma satu sampel; angka FrankenPHP worker mode di tabel ini sudah
yang **benar**, diukur ulang setelah bug rewrite Caddy diperbaiki — lihat
"Bug yang ditemukan"):

| Runtime | Kisaran RPS | Karakter |
|---|---|---|
| Swoole | ~16.700–24.800 | Tercepat, jauh di atas yang lain — tidak ada biaya spin-up proses/thread per request |
| FrankenPHP (worker) | ~4.700–8.100 | Kedua tercepat, kompetitif dengan php-fpm/FrankenPHP klasik — **sesuai ekspektasi teori worker mode**, setelah bug rewrite Caddy diperbaiki |
| FrankenPHP (klasik) | ~2.500–7.100 | Sebanding dengan php-fpm+apache/nginx, rentang lebar karena noise host antar run |
| PHP-FPM + Nginx | ~2.500–6.900 | Rentang lebar — beberapa run kena noise host (p99 melonjak), bukan pola konsisten |
| PHP-FPM + Apache2 | ~2.200–6.000 | Sebanding dengan Nginx |

**RPS — `GET /api/db/transaksi`** (query nyata ke tabel 20.000 baris, PHP
8.2–8.4 x JIT on/off, database benchmark terisolasi lihat "Pengujian dengan
database"):

| Runtime | Kisaran RPS | Karakter |
|---|---|---|
| Swoole | ~8.200–11.100 | Tetap tercepat, tapi turun ~2× dari `/api/health` — query DB (koneksi PDO, fetch, encode 20 baris) jadi biaya dominan, bukan overhead runtime |
| FrankenPHP (worker) | ~5.500–6.000 | **Kedua tercepat, mengalahkan php-fpm dan FrankenPHP klasik** untuk endpoint ini — remarkably stabil antar versi PHP/JIT (rentangnya sempit dibanding runtime lain) |
| PHP-FPM + Nginx | ~2.200–3.000 | Paling stabil antar run untuk endpoint ini |
| FrankenPHP (klasik) | ~1.700–3.600 | Sebanding php-fpm |
| PHP-FPM + Apache2 | ~1.400–2.000 | Sedikit di bawah Nginx |

**CPU dan RAM** (`docker stats`, snapshot beban berkelanjutan ~8 detik):
data ini **direkam SEBELUM bug rewrite FrankenPHP worker ditemukan** —
angka untuk baris "FrankenPHP (worker)" di bawah mengukur classic-mode-
menyamar (bug lama), bukan worker mode sungguhan, dan **belum diukur
ulang** di sesi ini. Perlakukan baris itu sebagai tidak valid; baris
lainnya (php-fpm, FrankenPHP klasik, Swoole) tidak terpengaruh bug
tersebut dan tetap valid.

| Runtime | CPU terpakai | RAM (awal → ~8 detik beban) |
|---|---|---|
| PHP-FPM+Apache | ~190–210% (penuh) | 28MB → 56MB |
| PHP-FPM+Nginx | ~190–210% (penuh) | 30MB → 61MB |
| FrankenPHP (klasik) | ~190–210% (penuh) | 42MB → 80MB (paling boros — wajar, proses Caddy/Go plus interpreter PHP tertanam) |
| Swoole | ~190–210% (penuh) | 22MB → 36MB (paling irit) |
| ~~FrankenPHP (worker)~~ | ~~120–140%~~ **tidak valid, lihat catatan** | ~~39MB → 70MB~~ **tidak valid, lihat catatan** |

Semua kombinasi (termasuk yang sudah dikonfirmasi valid) jalan nyaman jauh
di bawah batas 2GB RAM — untuk kapasitas produksi, **CPU** yang jadi batas
nyata di beban 2 core ini, bukan RAM.

**JIT (`opcache.jit` on vs off)**: tidak ada pola menang-konsisten di data
ini — kadang RPS naik saat JIT on (mis. php-fpm+nginx 8.4: 5618→6452),
kadang malah turun (mis. FrankenPHP worker 8.4: 7715→5828). Ini masuk akal:
endpoint `/api/health` didominasi overhead I/O + JSON encode singkat, bukan
loop/komputasi angka berat yang biasanya diuntungkan JIT. Jangan simpulkan
"JIT tidak berguna" dari sini — cuma berarti endpoint sesederhana ini bukan
kasus yang representatif untuk mengukur manfaat JIT.

**Kesimpulan praktis**:

1. **Swoole** menang telak di throughput dan RAM, di endpoint stateless
   maupun endpoint DB — tapi statusnya eksperimental (lihat catatan di
   bawah) — jangan pakai untuk endpoint yang butuh body JSON
   (`php://input` tidak terisi) atau halaman HTML dengan CSP nonce, sebelum
   masalah itu diselesaikan di level framework. Query SELECT via GET
   (seperti `/api/db/transaksi`) sudah terbukti aman setelah bug
   `global $dbconfig` diperbaiki.
2. **FrankenPHP mode worker ternyata kompetitif** setelah bug rewrite Caddy
   diperbaiki — kedua tercepat untuk `/api/health` (~4.7–8.1k rps) dan
   bahkan **mengungguli php-fpm dan FrankenPHP klasik** untuk
   `/api/db/transaksi` (~5.5–6.0k rps, stabil di semua versi PHP/JIT). Ini
   sesuai ekspektasi teori (bootstrap sekali, tetap di RAM), tapi tetap
   berstatus eksperimental karena batasan CSP_NONCE yang sama dengan Swoole
   (lihat catatan di bawah) — dan karena baru diperbaiki di sesi ini, belum
   ada riwayat produksi nyata untuk konfigurasi ini.
3. **PHP-FPM + Nginx/Apache2** tetap pilihan paling aman dan sudah
   tervalidasi penuh kompatibel dengan framework ini apa adanya — throughput
   sebanding dengan FrankenPHP klasik, tanpa risiko state-leak worker mode,
   baik untuk endpoint stateless maupun endpoint DB. Kalau tidak mau
   berurusan dengan kerumitan konfigurasi worker mode, ini pilihan paling
   waras.
4. **FrankenPHP mode klasik** setara php-fpm dari sisi RPS, tapi memakai RAM
   paling banyak di antara semuanya dan image-nya paling besar — dan, tidak
   seperti mode worker-nya, tidak dapat manfaat bootstrap-sekali sama
   sekali.
5. Data CPU/RAM untuk FrankenPHP worker mode di atas **tidak valid** (diukur
   sebelum bug rewrite ditemukan) — kalau butuh angka CPU/RAM worker mode
   yang benar, ukur ulang manual dengan `docker stats` (lihat resource-usage
   di bagian "Cara pakai" untuk pola scriptnya).
6. Query DB (20.000 baris, `ORDER BY id DESC LIMIT 20`) membelah throughput
   kira-kira 2× untuk sebagian besar runtime dibanding endpoint stateless
   (kecuali FrankenPHP worker, yang justru RPS-nya nyaris sama di kedua
   endpoint — konsisten dengan koneksi PDO yang ter-*pool* dan dipakai ulang
   antar request dalam proses yang sama, keuntungan lain dari worker mode
   yang tidak dimiliki runtime process-per-request).
7. **Pelajaran metodologis paling penting dari sesi ini**: sebuah benchmark
   yang "kelihatan masuk akal" (angka wajar, tidak error, tidak crash) tetap
   bisa mengukur hal yang salah total. FrankenPHP worker mode terlihat
   "berjalan normal" — response benar, header masuk akal, tidak crash —
   padahal mekanisme yang sedang diuji (worker) sama sekali tidak aktif.
   Kalau bukan karena diverifikasi langsung lewat `throw` di titik kode
   yang seharusnya dieksekusi, kesimpulan yang salah ("worker mode lebih
   lambat") akan tetap berdiri sebagai fakta terdokumentasi.

Data mentah lengkap: `docker/benchmark/results/results.csv` (endpoint
`/api/health`), `docker/benchmark/results/results-db.csv` (endpoint
`/api/db/transaksi`, kolom `endpoint` tambahan), dan
`docker/benchmark/results/resource-usage.csv` (sampel CPU/RAM).

## Catatan penting: mode worker (FrankenPHP worker & Swoole)

Framework ini ditulis dengan asumsi satu proses PHP per request (model
tradisional PHP-FPM/mod_php). FrankenPHP worker mode dan Swoole justru
menjaga proses PHP tetap hidup lintas banyak request supaya cepat, dan itu
berkonsekuensi:

- `index.php` mendefinisikan `CSP_NONCE` sebagai **PHP constant** sekali per
  proses. Constant tidak bisa didefinisikan ulang, jadi di worker/Swoole
  nonce itu akan beku setelah request pertama. Bootstrap eksperimental di
  `docker/benchmark/frankenphp-worker/worker.php` dan
  `docker/benchmark/swoole/server.php` sengaja **tidak** mengirim header CSP
  supaya tidak berbohong soal nonce — bukan bug yang lupa diperbaiki.
- Swoole's HTTP server tidak mengisi `php://input`, jadi
  `Request::json()` (dipakai `POST /api/echo`) akan membaca body kosong di
  bawah Swoole.
- Bootstrap Swoole ini juga tidak mengaktifkan static file handler bawaan
  Swoole, jadi `assets/` (CSS/JS) tidak akan terlayani di kombinasi ini.

Karena itu **matrix ini sengaja dibatasi ke `GET /api/health`** (stateless,
tanpa session/DB, tanpa body) untuk kombinasi FrankenPHP worker mode dan
Swoole. Jangan pakai dua mode ini untuk halaman HTML dengan inline
script/style ber-nonce, atau untuk endpoint yang butuh body JSON, sebelum
menyelesaikan isu di atas di level framework.

## Menjaga host tetap bersih

`run-matrix.sh` menghentikan container di setiap kombinasi sebelum lanjut ke
kombinasi berikutnya. Image hasil build tetap tersimpan (supaya bisa dipakai
ulang); hapus manual kalau perlu:

```bash
docker images 'codekop-bench-*' -q | xargs -r docker rmi
```
