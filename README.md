# Smart Canteen API — Laravel 13 Backend, Admin Portal & Staff POS

Sub-repository độc lập cho tầng Backend của hệ thống **Smart Canteen & Căn tin Số**:
- **REST API (`/api/v1/*`):** Xác thực Laravel Sanctum (Zalo Phone Auth, Zalo OAuth, Demo Phone local), quản lý thực đơn/tồn kho, đơn hàng, thẻ học sinh, liên kết/hủy ngân hàng + SMS OTP, hạn mức ngày, Sandbox Banking + VNPay Sandbox + Webhook HMAC-SHA256, thông báo In-App + Push Zalo OA/ZNS.
- **Cổng Quản trị Admin (`/admin/*`) & Quầy Thu ngân POS (`/admin/pos`):** Laravel Blade + Session Auth + CSRF + **RBAC 4 vai trò** (`admin`, `staff`, `parent`/`customer`, `student`).
- **CSDL:** PostgreSQL 17 (`27` migrations, `4` seeders idempotent).

---

## Bảng Chọn Nhanh 3 Chế độ Khởi chạy

| Chế độ | File `.env` mẫu | Database | Địa chỉ truy cập |
|---|---|---|---|
| **Mode 1: Thuần Local (Host)** | `.env.example` | PostgreSQL trên host (`127.0.0.1:54329`) | `http://localhost:8000` |
| **Mode 2: Docker / Docker Compose** | `.env.docker.example` | Container `postgres:5432` (forward `:54329`) | `http://localhost:8000` |
| **Mode 3: Zalo Mobile (Testing)** | `.env.zalo.example` | PostgreSQL (Docker hoặc Local) | `https://<domain-https>` |

---

## Mode 1 — Khởi chạy Thuần Local trên Host (Không dùng Docker)

**Yêu cầu:** PHP `>= 8.4.1` (`pdo_pgsql`, `pgsql`, `bcmath`, `intl`, `mbstring`, `opcache`, `zip`), Composer `2.x`, PostgreSQL `17.x` chạy trên host (tạo sẵn 2 DB `titkul_canteen_demo` và `titkul_canteen_test`).

```powershell
# 1. Tạo file .env từ mẫu Local và chỉnh DB_PORT / DB_PASSWORD theo PostgreSQL trên máy bạn
Copy-Item .env.example .env

# 2. Cài dependencies, tạo APP_KEY và khởi tạo dữ liệu demo đầy đủ (4 seeders)
composer install
php artisan key:generate
php artisan migrate:fresh --seed

# 3. Khởi động máy chủ API + Admin/POS ở cổng 8000
php artisan serve --host=127.0.0.1 --port=8000
```

- **REST API:** `http://localhost:8000/api/v1` · **Healthcheck:** `http://localhost:8000/up`
- **Admin Portal:** `http://localhost:8000/admin/login` · **Quầy POS:** `http://localhost:8000/admin/pos`
  - Admin demo: `admin@smartcanteen.local` / `Admin@123456`
  - Staff demo: `staff@smartcanteen.local` / `Staff@123456`

---

## Mode 2 — Khởi chạy bằng Docker / Docker Compose (Khuyến nghị)

**Yêu cầu:** Docker Engine 24+ & Docker Compose V2 (không cần cài PHP hay PostgreSQL trên host).

### 2.1. Chế độ Development / Demo (Live mount code)
```powershell
Copy-Item .env.docker.example .env
docker compose up -d --build --wait
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate:fresh --force --seed
```
- Container `postgres` tự khởi tạo sẵn `titkul_canteen_demo` & `titkul_canteen_test` (expose host `:54329`).
- Container `nginx` lắng nghe nội bộ `:8080` và expose ra host **`http://localhost:8000`** (`API_PORT=8000`).
- **Dừng stack:** `docker compose down` *(thêm `-v` nếu muốn xóa sạch dữ liệu DB)*.

### 2.2. Chế độ Production-like (Immutable Image Non-root)
```powershell
# Đảm bảo .env đã có APP_KEY và DB_PASSWORD mạnh
docker compose -f compose.yaml -f compose.prod.yaml up -d --build --wait
docker compose -f compose.yaml -f compose.prod.yaml exec app php artisan migrate --force
```

---

## Mode 3 — Khởi chạy Phục vụ Zalo Mobile (Development / Testing)

Zalo Mini App chạy trên điện thoại **bắt buộc** gọi API qua **HTTPS công khai** và có `ZALO_APP_ID` / `ZALO_APP_SECRET` để xác minh `access_token` + `phone_token` từ `graph.zalo.me`.

```powershell
# 1. Tạo .env từ mẫu Zalo Mobile và điền YOUR_HTTPS_DOMAIN, ZALO_APP_ID, ZALO_APP_SECRET
Copy-Item .env.zalo.example .env

# 2. Khởi chạy Backend (qua Docker hoặc Local) ở cổng 8000:
docker compose up -d --build --wait
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --force --seed
docker compose exec app php artisan config:clear

# 3. Mở tunnel HTTPS công khai (nếu chạy từ máy cá nhân):
cloudflared tunnel --url http://127.0.0.1:8000
# -> Lấy domain https://xxx.trycloudflare.com cập nhật vào APP_URL & VNPAY_RETURN_URL trong api/.env,
#    rồi chạy lại: docker compose exec app php artisan config:clear
```

> Kiểm tra nhanh CORS cho Zalo Mobile: `CORS_ALLOWED_ORIGINS=https://h5.zdn.vn,zbrowser://h5.zdn.vn` (đã cài sẵn trong `.env.zalo.example`).

---

## Kiểm thử & Cổng chất lượng (Quality Gates)

```powershell
# Chạy bên trong Docker container (PostgreSQL 17 thật — khuyến nghị)
docker compose exec app vendor/bin/pint --test
docker compose exec app php artisan test --compact

# Hoặc chạy trực tiếp trên host có PHP 8.4+
composer validate --strict
vendor/bin/pint --test
php artisan test --compact
```

## GitHub Actions CI

Pipeline tự động tại [.github/workflows/ci.yml](.github/workflows/ci.yml) gồm 4 job: `code-quality` (Composer + Pint), `tests` (PostgreSQL 17 + 126 PHPUnit tests), `security-audit` (`composer audit --locked`) và `docker-build` (validate Compose + smoke test non-root image).
