@extends('admin.layout')

@section('title', ($isEdit ? 'Cập nhật món ăn' : 'Thêm món ăn mới') . ' — Smart Canteen Admin')

@section('content')
<div style="max-width: 640px; margin: 0 auto;">
    <div style="margin-bottom: 1rem;">
        <a href="{{ route('admin.products.index') }}" style="font-size: 0.85rem; color: var(--brand-700); text-decoration: none; font-weight: 600;">← Quay lại danh sách món ăn</a>
        <h1 style="font-size: 1.35rem; margin: 0.3rem 0 0;">{{ $isEdit ? 'Cập nhật món ăn: ' . $product->name : 'Thêm món ăn mới' }}</h1>
    </div>

    <div class="card">
        <form method="POST" action="{{ $isEdit ? route('admin.products.update', $product) : route('admin.products.store') }}">
            @csrf
            @if ($isEdit)
                @method('PUT')
            @endif

            <div style="margin-bottom: 1rem;">
                <label for="category_id" style="display:block; font-size:0.85rem; font-weight:600; margin-bottom:0.3rem;">Danh mục</label>
                <select id="category_id" name="category_id" required>
                    @foreach ($categories as $cat)
                        <option value="{{ $cat->id }}" {{ (int) old('category_id', $product->category_id) === $cat->id ? 'selected' : '' }}>
                            {{ $cat->name }} {{ $cat->is_active ? '' : '(Đang ẩn)' }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div style="margin-bottom: 1rem;">
                <label for="name" style="display:block; font-size:0.85rem; font-weight:600; margin-bottom:0.3rem;">Tên món ăn</label>
                <input id="name" type="text" name="name" value="{{ old('name', $product->name) }}" required maxlength="150">
            </div>

            <div style="margin-bottom: 1rem;">
                <label for="price" style="display:block; font-size:0.85rem; font-weight:600; margin-bottom:0.3rem;">Giá bán (số nguyên VND)</label>
                <input id="price" type="number" name="price" value="{{ old('price', $product->price) }}" required min="1000" step="500">
            </div>

            <div style="margin-bottom: 1rem;">
                <label for="description" style="display:block; font-size:0.85rem; font-weight:600; margin-bottom:0.3rem;">Mô tả chi tiết</label>
                <textarea id="description" name="description" rows="3" maxlength="1000">{{ old('description', $product->description) }}</textarea>
            </div>

            <div style="margin-bottom: 1rem;">
                <label for="image_url" style="display:block; font-size:0.85rem; font-weight:600; margin-bottom:0.3rem;">URL hình ảnh (tùy chọn)</label>
                <input id="image_url" type="url" name="image_url" value="{{ old('image_url', $product->image_url) }}" maxlength="500">
            </div>

            <div style="margin-bottom: 1.25rem;">
                <input type="hidden" name="is_available" value="0">
                <label style="display:flex; align-items:center; gap:0.5rem; font-size:0.9rem; font-weight:600;">
                    <input type="checkbox" name="is_available" value="1" {{ old('is_available', $product->is_available) ? 'checked' : '' }}>
                    Đang còn món phục vụ
                </label>
            </div>

            <div class="inline-actions">
                <button type="submit" class="btn btn-primary">{{ $isEdit ? 'Lưu thay đổi' : 'Tạo món ăn' }}</button>
                <a href="{{ route('admin.products.index') }}" class="btn btn-secondary">Hủy</a>
            </div>
        </form>
    </div>
</div>
@endsection
