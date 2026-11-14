@extends('admin.layout')

@section('title', 'Quản lý danh mục — Smart Canteen Admin')

@section('content')
<div class="flex-between" style="margin-bottom: 1.25rem;">
    <div>
        <h1 style="font-size: 1.35rem; margin: 0;">Quản lý danh mục thực đơn</h1>
        <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0.2rem 0 0;">Thêm, cập nhật hoặc ẩn danh mục món ăn</p>
    </div>
</div>

<div class="card">
    <h2 style="font-size: 1rem; margin-top: 0;">Thêm danh mục mới</h2>
    <form method="POST" action="{{ route('admin.categories.store') }}" style="display: flex; gap: 0.75rem; align-items: flex-end; flex-wrap: wrap;">
        @csrf
        <div style="flex: 1; min-width: 240px;">
            <label for="new-cat-name" style="display:block; font-size:0.8rem; font-weight:600; margin-bottom:0.25rem;">Tên danh mục</label>
            <input id="new-cat-name" type="text" name="name" required maxlength="100" placeholder="Ví dụ: Món Chay">
        </div>
        <div>
            <label style="display:flex; align-items:center; gap:0.4rem; font-size:0.85rem; font-weight:600; padding-bottom: 0.5rem;">
                <input type="checkbox" name="is_active" value="1" checked> Đang phục vụ
            </label>
        </div>
        <button type="submit" class="btn btn-primary">Thêm danh mục</button>
    </form>
</div>

<div class="card">
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Tên danh mục & Trạng thái</th>
                <th>Số món ăn</th>
                <th>Xóa</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($categories as $category)
                <tr>
                    <td>#{{ $category->id }}</td>
                    <td>
                        <form method="POST" action="{{ route('admin.categories.update', $category) }}" style="display:flex; gap:0.6rem; align-items:center; flex-wrap:wrap; margin:0;">
                            @csrf
                            @method('PUT')
                            <input type="text" name="name" value="{{ $category->name }}" required maxlength="100" style="max-width: 260px;">
                            <input type="hidden" name="is_active" value="0">
                            <label style="display:flex; align-items:center; gap:0.35rem; font-size:0.82rem;">
                                <input type="checkbox" name="is_active" value="1" {{ $category->is_active ? 'checked' : '' }}>
                                Đang hoạt động
                            </label>
                            <button type="submit" class="btn btn-secondary">Lưu</button>
                        </form>
                    </td>
                    <td>{{ $category->products_count }} món</td>
                    <td>
                        <form method="POST" action="{{ route('admin.categories.destroy', $category) }}" style="margin:0;">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-danger">Xóa</button>
                        </form>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endsection
