@extends('admin.layout')

@section('title', 'Đăng nhập Quản trị — Smart Canteen')

@section('content')
<div style="max-width: 420px; margin: 3.5rem auto;">
    <div class="card" style="padding: 2rem;">
        <div style="text-align: center; margin-bottom: 1.5rem;">
            <img src="/images/canteen-logo.jpg" alt="Smart Canteen Logo" style="width: 72px; height: 72px; border-radius: 16px; border: 1px solid var(--brand-100); object-fit: cover;">
            <h1 style="font-size: 1.35rem; margin: 0.75rem 0 0.25rem;">Smart Canteen Portal</h1>
            <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0;">Đăng nhập dành cho Nhân viên Bếp & Quản trị viên</p>
        </div>

        <form method="POST" action="{{ route('admin.login.submit') }}">
            @csrf
            <div style="margin-bottom: 1rem;">
                <label for="email" style="display:block; font-size:0.85rem; font-weight:600; margin-bottom:0.35rem;">Email công vụ</label>
                <input id="email" type="email" name="email" value="{{ old('email', 'admin@smartcanteen.local') }}" required autofocus>
            </div>

            <div style="margin-bottom: 1.25rem;">
                <label for="password" style="display:block; font-size:0.85rem; font-weight:600; margin-bottom:0.35rem;">Mật khẩu</label>
                <input id="password" type="password" name="password" required>
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 0.7rem;">
                Đăng nhập hệ thống
            </button>
        </form>

        <div style="margin-top: 1.25rem; padding-top: 1rem; border-top: 1px solid var(--border); font-size: 0.78rem; color: var(--text-muted);">
            <strong>Tài khoản Demo Local:</strong><br>
            • Admin: <code>admin@smartcanteen.local</code> / <code>Admin@123456</code><br>
            • Staff: <code>staff@smartcanteen.local</code> / <code>Staff@123456</code>
        </div>
    </div>
</div>
@endsection
