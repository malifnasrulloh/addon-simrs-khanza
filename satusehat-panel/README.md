# SATUSEHAT Admin Panel

Interactive admin panel untuk mengirim data pasien ke SATUSEHAT secara manual via **Bundle transaction**.
Add-on untuk SIMRS Khanza — tidak menggantikan CLI sync, hanya sebagai pelengkap untuk pengiriman manual.

## Fitur

- **Daftar pasien** — semua registrasi dengan status billing (Sudah Bayar / Belum Bayar), filter pencarian, status pembayaran, jenis kunjungan, dan status resource
- **Detail per pasien** — daftar resource FHIR yang tersedia berdasarkan data klinis di SIMRS (Encounter, Condition, Procedure, Medication*, CarePlan, Lab pipeline, dll.)
- **Payload editor** — lihat/edit payload sebelum dikirim
- **Bundle transaction** — pilih resource yang mau dikirim, kirim via 1 POST Bundle (mengikuti struktur Postman SATUSEHAT)
- **Audit log** — riwayat pengiriman di SQLite lokal
- **Adopsi logika CLI** — payload dibangun oleh `SatuSehatPayloadBuilder` yang sama dengan `php-service/lib/satusehat/`, jadi payloadnya identik

## Desain & tema (v2)

Frontend v2 adalah SPA vanilla-JS tanpa build step, dengan sistem token terpusat:

- **`css/tokens.css`** — design tokens (warna, tipografi, spacing, motion, elevasi, z-index). Satu-satunya tempat warna didefinisikan; ubah di sini, bukan di komponen.
- **`css/base.css`** — komponen & primitives (button, input, badge, table, drawer, modal, palette, toast).
- **`css/layout.css`** — layout halaman (topbar, sidebar, content, grid, responsive).
- **`js/app.js`** — logika SPA: routing hash (`#/`, `#/audit`), render, theme toggle, keyboard (Ctrl/Cmd+K palette, Esc, focus trap).
- **Font** — Geist + Geist Mono (OFL) dibundel di `public/fonts/`, offline-safe, tanpa CDN.

### Tema

- Dual-mode: light/dark, mengikuti `prefers-color-scheme` secara default; toggle di topbar menyimpan override ke `localStorage["sh-theme"]` yang menang atas atribut `data-theme` saat reload.
- Warna status: `--ok` (terkirim), `--warn` (belum bayar), `--bad` (gagal), `--info` (info) — lihat `tokens.css`.
- **Aksesibilitas adalah gate**: semua pasangan warna lulus WCAG AA (teks 4.5:1, UI 3:1) di kedua tema; `prefers-reduced-motion: reduce` menonaktifkan seluruh animasi; fokus keyboard selalu terlihat; semua journey bisa diselesaikan tanpa mouse.
- **Kinerja**: hanya `transform`/`opacity` yang dianimasikan (60fps, tanpa layout shift); DCL ~190ms, CLS ≈ 0.01, 0 long task pada shell.

### Menambah komponen baru

1. Warna/shape/spacing dari `tokens.css` — jangan hardcode hex di komponen.
2. Komponen di `base.css`, layout di `layout.css`, ikuti pola yang ada.
3. Pastikan kontras AA (kedua tema), `prefers-reduced-motion`, dan focus ring.

## Instalasi

```bash
cd satusehat-panel
cp .env.example .env
# Isi kredensial SATUSEHAT (sama dengan CLI) dan koneksi DB SIMRS
```

## Menjalankan

```bash
# Development
php -S 127.0.0.1:8099 -t public public/router.php

# Production (Apache/Nginx)
# DocumentRoot → satusehat-panel/public
# Semua request /api/* → public/index.php
# Semua request lain → public/shell.php (SPA shell + base-path injection)
```

### Plug-and-play (copy folder ke webroot — Nginx / Apache)

Panel mendukung **drop-in**: salin folder `satusehat-panel/` (atau symlink) ke
webroot server Anda, buka `http://ip-server/satusehat-panel/`, dan **langsung
bisa dipakai** — tanpa mengedit `.env`, `database.php`, atau konfigurasi server.

**Bagaimana ini bekerja (mirip add-on Khanza `dashboard_eksekutif` & `mapping_satu_sehat`):**

- **DB**: jika tidak ada `.env`, panel memakai default Khanza
  `localhost / root / <kosong> / sik` (db `sik`), sama seperti add-on lain.
- **Login**: tanpa setelan password di `.env`, panel menerima **login Khanza**
  (tabel `admin` dengan pola AES `usere`/`passworde`), jadi pakai akun petugas
  yang sudah ada. Jika `.env` punya `PANEL_AUTH_PASSWORD`, mode masyarakat yang
  lama tetap berjalan (username diabaikan).
- **Kredensial Satu Sehat**: diatur lewat UI panel → **Pengaturan Satu Sehat**
  (`#/settings`), disimpan ke `config/satusehat_credential.json` (secret di-mask
  saat dibaca). Tanpa meng-edit `.env` sama sekali.
- **Sub-folder**: base path otomatis terdeteksi dari `SCRIPT_NAME`
  (`config/base_path.php`), dipakai Router + auth + shell + `fetch()`.

**Yang perlu Anda lakukan (Nginx atau Apache):** cukup salin folder ke webroot —
**tidak perlu menyentuh konfigurasi server**. `index.php` di root folder adalah
file PHP nyata (sama seperti `dashboard_eksekutif/index.php`), jadi semua server
yang sudah menjalankan PHP langsung mengeksekusinya. API dipanggil lewat
`index.php?r=/api/...`, aset via `public/css|js/...` (folder-relative), dan base
path folder terdeteksi otomatis.

Jika situs Khanza Anda sudah melayani `.php` (Nginx+FPM atau Apache), maka:
1. `cp -r satusehat-panel /path/to/webroot/`
2. Buka `http://ip/satusehat-panel/`
3. Login pakai akun Khanza (`sik.admin`), atur kredensial Satu Sehat di
   Pengaturan (`#/settings`) — selesai.

(Dokumentasi teknis `nginx.sample.conf` tetap ada untuk pengguna yang mau
panel-own-server / alias tersendiri.)

**Catatan**: unit deploy yang valid adalah folder `satusehat-panel/` penuh
(`index.php` + `public/` + `config/` + `src/` + `storage/`), bukan `public/` saja.

## Struktur

```
satusehat-panel/
├── index.php          # Drop-in entry (shell + API via ?r=/api/...)
├── .htaccess          # Apache: block config/src/storage/.env
├── public/
│   ├── index.php      # Front controller (API routes, docroot mode)
│   ├── index.html     # SPA shell (hanya markup awal; konten dirender app.js)
│   ├── router.php     # Dev server router
│   ├── css/           # tokens.css · base.css · layout.css (v2, tanpa app.css)
│   ├── fonts/         # Geist + Geist Mono (OFL), offline-safe
│   └── js/app.js      # Logika SPA (routing, render, tema, a11y, palette)
├── src/
│   ├── Core/          # Router, Config, Database (SQLite + MySQL)
│   ├── Controller/    # Patient, Resource, Send, Audit
│   └── Util/          # Adopted CLI logic (PayloadBuilder, SatuSehatClient, dll.)
├── config/            # app.php, database.php
└── .env               # Kredensial (self-contained, tidak referensi luar)
```

## API Endpoints

| Method | Endpoint | Deskripsi |
|---|---|---|
| GET | `/api/patients` | Daftar pasien + status billing + resource counts |
| GET | `/api/patients/{noRawat}` | Detail pasien + manifest resource |
| GET | `/api/patients/{noRawat}/resources/{resource}` | Preview payload FHIR |
| POST | `/api/patients/{noRawat}/send` | Kirim Bundle transaction |
| GET | `/api/audit` | Audit log pengiriman |
