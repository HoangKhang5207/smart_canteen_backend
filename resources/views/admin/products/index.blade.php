@extends('admin.layout')

@section('title', 'Quản lý món ăn & Tồn kho — Smart Canteen Admin')

@section('content')
@php
    $isAdmin = (auth()->user()?->role?->value ?? auth()->user()?->role) === 'admin';
@endphp

<div class="flex-between" style="margin-bottom: 1.25rem;">
    <div>
        <h1 style="font-size: 1.35rem; margin: 0;">Quản lý món ăn &amp; Tồn kho</h1>
        <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0.2rem 0 0;">
            {{ $isAdmin ? 'Quản lý giá bán (VND), số lượng tồn kho nguyên tử và trạng thái phục vụ' : 'Chế độ tra cứu thực đơn & tồn kho (Chỉ Admin được chỉnh sửa)' }}
        </p>
    </div>
    @if ($isAdmin)
        <a href="{{ route('admin.products.create') }}" class="btn btn-primary">+ Thêm món ăn mới</a>
    @endif
</div>

<div class="grid-stats">
    <div class="stat-card">
        <div class="stat-label">Món sắp hết hàng (&le; ngưỡng cảnh báo)</div>
        <div class="stat-value" style="color: #92400E;">{{ $inventoryStats['low_stock'] ?? 0 }}</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Món hết hàng / Tạm ngừng bán</div>
        <div class="stat-value" style="color: #991B1B;">{{ $inventoryStats['out_of_stock'] ?? 0 }}</div>
    </div>
</div>

<div class="card">
    <form method="GET" action="{{ route('admin.products.index') }}" style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: flex-end;">
        <div style="flex: 1; min-width: 190px;">
            <label for="search" style="display:block; font-size:0.8rem; font-weight:600; margin-bottom:0.25rem;">Tìm tên món ăn</label>
            <input id="search" type="text" name="search" value="{{ $search ?? '' }}" placeholder="Ví dụ: Phở, Cơm sườn...">
        </div>
        <div style="min-width: 170px;">
            <label for="category_id" style="display:block; font-size:0.8rem; font-weight:600; margin-bottom:0.25rem;">Danh mục</label>
            <select id="category_id" name="category_id">
                <option value="">Tất cả danh mục</option>
                @foreach ($categories ?? [] as $cat)
                    <option value="{{ $cat->id }}" {{ ($selectedCategoryId ?? null) === $cat->id ? 'selected' : '' }}>
                        {{ $cat->name }}
                    </option>
                @endforeach
            </select>
        </div>
        <div style="min-width: 160px;">
            <label for="stock_filter" style="display:block; font-size:0.8rem; font-weight:600; margin-bottom:0.25rem;">Trạng thái kho</label>
            <select id="stock_filter" name="stock_filter">
                <option value="">Tất cả tồn kho</option>
                <option value="low" {{ ($stockFilter ?? '') === 'low' ? 'selected' : '' }}>Sắp hết hàng</option>
                <option value="out" {{ ($stockFilter ?? '') === 'out' ? 'selected' : '' }}>Hết hàng / Tạm ngừng</option>
                <option value="unlimited" {{ ($stockFilter ?? '') === 'unlimited' ? 'selected' : '' }}>Không giới hạn</option>
            </select>
        </div>
        <div style="min-width: 135px;">
            <label for="per_page" style="display:block; font-size:0.8rem; font-weight:600; margin-bottom:0.25rem;">Số dòng / trang</label>
            <select id="per_page" name="per_page">
                @foreach ([10, 20, 50] as $size)
                    <option value="{{ $size }}" {{ ($perPage ?? 10) === $size ? 'selected' : '' }}>{{ $size }} món / trang</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Lọc món</button>
        <a href="{{ route('admin.products.index') }}" class="btn btn-secondary">Xóa lọc</a>
    </form>
</div>

<div class="card">
    @if ($products->isEmpty())
        <p style="color: var(--text-muted);">Không tìm thấy món ăn phù hợp.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Hình ảnh</th>
                    <th>Tên món</th>
                    <th>Danh mục</th>
                    <th>Giá bán</th>
                    <th>Tồn kho</th>
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
                            <div style="width: 48px; height: 48px; border-radius: 8px; overflow: hidden; background: var(--brand-50); border: 1px solid var(--brand-100); display: flex; align-items: center; justify-content: center;">
                                @if ($product->image_url)
                                    <img
                                        src="{{ $product->image_url }}"
                                        alt="{{ $product->name }}"
                                        loading="lazy"
                                        style="width: 100%; height: 100%; object-fit: cover; object-position: center;"
                                        onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                                    >
                                    <span style="display: none; font-size: 0.65rem; font-weight: 700; color: var(--brand-700); text-align: center; padding: 2px;">SC</span>
                                @else
                                    <span style="font-size: 0.65rem; font-weight: 700; color: var(--brand-700); text-align: center; padding: 2px;">SC</span>
                                @endif
                            </div>
                        </td>
                        <td>
                            <div style="font-weight: 700;">{{ $product->name }}</div>
                            @if ($product->description)
                                <div style="font-size: 0.78rem; color: var(--text-muted);">{{ $product->description }}</div>
                            @endif
                        </td>
                        <td>{{ $product->category?->name ?? '—' }}</td>
                        <td style="font-weight: 700; color: var(--brand-700);">{{ number_format($product->price, 0, ',', '.') }} ₫</td>
                        <td>
                            @if ($product->stock_quantity === null)
                                <span class="badge badge-COMPLETED">Không giới hạn</span>
                            @elseif ($product->stock_quantity === 0)
                                <span class="badge badge-CANCELLED">Hết hàng (0)</span>
                            @elseif ($product->isLowStock())
                                <span class="badge badge-PENDING">Sắp hết ({{ $product->stock_quantity }})</span>
                            @else
                                <span class="badge badge-READY">Còn {{ $product->stock_quantity }}</span>
                            @endif

                            @if ($isAdmin && $product->stock_quantity !== null)
                                <form method="POST" action="{{ route('admin.products.stock', $product) }}" style="display:flex; gap:0.25rem; margin-top:0.35rem; align-items:center;">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="mode" value="add">
                                    <input type="number" name="quantity" min="1" max="10000" value="10" aria-label="Nhập thêm tồn kho" style="width: 68px; padding: 0.25rem 0.4rem; font-size: 0.76rem;">
                                    <button type="submit" class="btn btn-secondary" style="padding: 0.25rem 0.45rem; font-size: 0.74rem;">+ Nhập</button>
                                </form>
                            @endif
                        </td>
                        <td>
                            @if ($product->is_available)
                                <span class="badge badge-READY">Đang bán</span>
                            @else
                                <span class="badge badge-CANCELLED">Tạm ngừng</span>
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

        @include('admin.partials.pagination', ['paginator' => $products, 'label' => 'món ăn'])
    @endif
</div>
@endsection
