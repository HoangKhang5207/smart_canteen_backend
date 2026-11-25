# Smart Canteen API — Laravel 13 Backend & Admin Blade Portal

Sub-repository độc lập cho tầng Backend của hệ thống **Smart Canteen**: REST API (`/api/v1/*`, xác thực Laravel Sanctum) và Cổng Quản trị Admin/Staff (`/admin/*`, Laravel Blade + Session Auth + RBAC 3 cấp `customer`/`staff`/`admin`), sử dụng PostgreSQL 17.

## Yêu cầu môi trường

- **Khuyến nghị (Docker):** Docker Engine 24+ & Docker Compose V2 (không cần cài PHP/PostgreSQL trên host).
- **Chạy thủ công trên host:** PHP `>= 8.4.1` (`pdo_pgsql`, `pgsql`, `bcmath`, `intl`, `mbstring`, `opcache`, `zip`), Composer `2.x`, PostgreSQL `17.x`.

## Khởi chạy nhanh với Docker (Local / Dev / Testing)

```bash
cp .env.docker.example .env
docker compose up -d --build
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```

- **REST API:** `http://localhost:8000/api/v1`
- **Healthcheck:** `http://localhost:8000/up`
- **Cổng Quản trị Admin/Staff:** `http://localhost:8000/admin/login`
  - Admin mẫu: `admin@smartcanteen.local` / `Admin@123456`
  - Staff mẫu: `staff@smartcanteen.local` / `Staff@123456`

Container `postgres` tự động khởi tạo sẵn 2 database: `titkul_canteen_demo` (dev/demo) và `titkul_canteen_test` (PHPUnit).

### Chạy Production-like Stack (Local Staging Verification)

```bash
# Đảm bảo .env đã có APP_KEY và DB_PASSWORD mạnh
docker compose -f compose.yaml -f compose.prod.yaml up -d --build
```

Production stack dùng image immutable (`target: production` không chứa `composer`/dev deps, chạy non-root `appuser:1000`) kết hợp `nginxinc/nginx-unprivileged:1.27-alpine` đóng gói sẵn `public/`.

## Kiểm thử & Chất lượng (Quality Gates)

```bash
# Chạy bên trong Docker container (khuyến nghị)
docker compose exec app vendor/bin/pint --test
docker compose exec app php artisan test

# Hoặc chạy trực tiếp trên host có PHP 8.4+
composer validate --strict
vendor/bin/pint --test
php artisan test
```

## GitHub Actions CI

Pipeline tự động tại [.github/workflows/ci.yml](.github/workflows/ci.yml) chạy trên `push` và `pull_request` (`main`, `develop`):
1. `code-quality`: PHP 8.4, `composer validate --strict`, Laravel Pint (`--test`).
2. `tests`: PostgreSQL 17 service container, migrations & toàn bộ PHPUnit test suite.
3. `security-audit`: `composer audit --locked`.
4. `docker-build`: Validate Compose, build cả `dev`, `production` và `nginx`, smoke test non-root user & vắng mặt `composer` trong production image.

## Gắn Remote GitHub cho Sub-repo độc lập

```bash
git remote add origin https://github.com/<org-or-user>/smart-canteen-api.git
# Sau khi review xong nhánh làm việc, merge vào main rồi push:
git checkout main
git merge --no-ff feat/docker-ci
git push -u origin main
```
