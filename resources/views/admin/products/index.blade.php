@extends('admin.layout')

@section('title', 'Quản lý món ăn — Smart Canteen Admin')

@section('content')
@php
    $isAdmin = (auth()->user()?->role?->value ?? auth()->user()?->role) === 'admin';
@endphp

<div class="flex-between" style="margin-bottom: 1.25rem;">
    <div>
        <h1 style="font-size: 1.35rem; margin: 0;">Quản lý món ăn</h1>
        <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0.2rem 0 0;">
            {{ $isAdmin ? 'Cập nhật giá bán (VND) và trạng thái phục vụ mà không làm thay đổi các đơn hàng lịch sử' : 'Chế độ tra cứu thực đơn dành cho Nhân viên Bếp (Chỉ Admin được chỉnh sửa)' }}
        </p>
    </div>
    @if ($isAdmin)
        <a href="{{ route('admin.products.create') }}" class="btn btn-primary">+ Thêm món ăn mới</a>
    @endif
</div>

<div class="card">
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Tên món</th>
                <th>Danh mục</th>
                <th>Giá bán (VND)</th>
                <th>Trạng thái</th>
                @if ($isAdmin)
                <th>Thao tác</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach ($products as $product)
                <tr>
                    <td>#{{ $product->id }}</td>
                    <td>
                        <div style="font-weight: 700;">{{ $product->name }}</div>
                        @if ($product->description)
                            <div style="font-size: 0.78rem; color: var(--text-muted);">{{ $product->description }}</div>
                        @endif
                    </td>
                    <td>{{ $product->category?->name ?? '—' }}</td>
                    <td style="font-weight: 700; color: var(--brand-700);">{{ number_format($product->price, 0, ',', '.') }} ₫</td>
                    <td>
                        @if ($product->is_available)
                            <span class="badge badge-READY">Còn món</span>
                        @else
                            <span class="badge badge-CANCELLED">Hết món</span>
                        @endif
                    </td>
                    @if ($isAdmin)
                    <td>
                        <div class="inline-actions">
                            <a href="{{ route('admin.products.edit', $product) }}" class="btn btn-secondary">Sửa</a>
                            <form method="POST" action="{{ route('admin.products.destroy', $product) }}" style="margin:0;">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-danger">Xóa</button>
                            </form>
                        </div>
                    </td>
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endsection
