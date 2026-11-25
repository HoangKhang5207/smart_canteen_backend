@extends('admin.layout')

@section('title', 'Quản lý Học sinh — Smart Canteen Admin')

@section('content')
@php
    $isAdmin = (auth()->user()?->role?->value ?? auth()->user()?->role) === 'admin';
@endphp

<div class="flex-between" style="margin-bottom: 1.25rem;">
    <div>
        <h1 style="font-size: 1.35rem; margin: 0;">Quản lý Học sinh &amp; Phân bổ Phụ huynh</h1>
        <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0.2rem 0 0;">
            {{ $isAdmin ? 'Thêm/sửa hồ sơ học sinh, gắn nhiều học sinh cho một tài khoản phụ huynh, liên kết tài khoản học sinh và cấp thẻ mới.' : 'Chế độ tra cứu danh sách học sinh (Chỉ Admin được thêm/sửa/cấp thẻ).' }}
        </p>
    </div>
    @if ($isAdmin)
        <a href="{{ route('admin.canteen.students.create') }}" class="btn btn-primary">+ Thêm học sinh mới</a>
    @endif
</div>

<div class="grid-stats">
    <div class="stat-card">
        <div class="stat-label">Tổng số học sinh</div>
        <div class="stat-value">{{ $stats['total'] }}</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Đang hoạt động</div>
        <div class="stat-value brand">{{ $stats['active'] }}</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Đã gắn tài khoản Học sinh</div>
        <div class="stat-value">{{ $stats['with_student_account'] }}</div>
    </div>
</div>

<div class="card">
    <form method="GET" action="{{ route('admin.canteen.students.index') }}" style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: flex-end;">
        <div style="flex: 1; min-width: 220px;">
            <label for="search" style="display:block; font-size:0.8rem; font-weight:600; margin-bottom:0.25rem;">Tìm mã HS, họ tên, lớp, trường hoặc SĐT phụ huynh</label>
            <input id="search" type="text" name="search" value="{{ $search }}" placeholder="VD: HS-2026-0001, 8A2, 0901234567...">
        </div>
        <div style="min-width: 170px;">
            <label for="active" style="display:block; font-size:0.8rem; font-weight:600; margin-bottom:0.25rem;">Trạng thái</label>
            <select id="active" name="active">
                <option value="">Tất cả</option>
                <option value="1" {{ $activeFilter === '1' ? 'selected' : '' }}>Đang hoạt động</option>
                <option value="0" {{ $activeFilter === '0' ? 'selected' : '' }}>Ngừng hoạt động</option>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Tìm kiếm</button>
        <a href="{{ route('admin.canteen.students.index') }}" class="btn btn-secondary">Xóa lọc</a>
    </form>
</div>

<div class="card">
    @if ($students->isEmpty())
        <p style="color: var(--text-muted); margin: 0;">Chưa có học sinh nào khớp bộ lọc.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Mã HS / Họ tên</th>
                    <th>Trường &amp; Lớp</th>
                    <th>Phụ huynh giám hộ</th>
                    <th>Tài khoản Học sinh</th>
                    <th>Thẻ Căn tin</th>
                    <th>Trạng thái</th>
                    @if ($isAdmin)
                    <th>Thao tác</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach ($students as $student)
                    <tr>
                        <td>
                            <div style="font-weight: 700;">{{ $student->full_name }}</div>
                            <div style="font-family: monospace; font-size: 0.78rem; color: var(--text-muted);">{{ $student->student_code }}</div>
                        </td>
                        <td>
                            <div>{{ $student->school_name }}</div>
                            <div style="font-size: 0.8rem; color: var(--text-muted);">Lớp {{ $student->class_name }}</div>
                        </td>
                        <td>
                            @if ($student->user)
                                <div style="font-weight: 600;">{{ $student->user->name }}</div>
                                <div style="font-size: 0.78rem; color: var(--text-muted);">{{ $student->user->phone ?? 'Chưa có SĐT' }}</div>
                            @else
                                <span style="color: var(--text-muted);">—</span>
                            @endif
                        </td>
                        <td>
                            @if ($student->studentUser)
                                <div style="font-weight: 600;">{{ $student->studentUser->name }}</div>
                                <div style="font-size: 0.78rem; color: var(--text-muted);">{{ $student->studentUser->phone ?? '—' }}</div>
                            @else
                                <span style="color: var(--text-muted); font-size: 0.82rem;">Chưa gắn</span>
                            @endif
                        </td>
                        <td>
                            @if ($student->cards->isEmpty())
                                <span style="font-size: 0.8rem; color: var(--text-muted);">Chưa cấp thẻ</span>
                            @else
                                @foreach ($student->cards as $card)
                                    <div style="margin-bottom: 0.25rem;">
                                        <a href="{{ route('admin.canteen.cards.show', $card) }}" style="font-family: monospace; font-weight: 700; color: var(--brand-700); text-decoration: none;">
                                            {{ $card->card_code }}
                                        </a>
                                        <span class="badge badge-{{ $card->status->value }}">{{ $card->status->value }}</span>
                                    </div>
                                @endforeach
                            @endif
                        </td>
                        <td>
                            @if ($student->is_active)
                                <span class="badge badge-ACTIVE">Hoạt động</span>
                            @else
                                <span class="badge badge-CANCELLED">Ngừng hoạt động</span>
                            @endif
                        </td>
                        @if ($isAdmin)
                        <td>
                            <div class="inline-actions">
                                <a href="{{ route('admin.canteen.students.edit', $student) }}" class="btn btn-secondary">Sửa / Cấp thẻ</a>
                                @if ($student->cards->isEmpty())
                                    <form method="POST" action="{{ route('admin.canteen.students.destroy', $student) }}" style="margin:0;">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-danger">Xóa</button>
                                    </form>
                                @endif
                            </div>
                        </td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>

        @include('admin.partials.pagination', ['paginator' => $students, 'label' => 'học sinh'])
    @endif
</div>
@endsection
