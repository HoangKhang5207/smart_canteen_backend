@extends('admin.layout')

@section('title', 'Điều phối đơn hàng — Smart Canteen Admin')
@section('auto_refresh', '1')

@section('content')
<div class="flex-between" style="margin-bottom: 1.25rem;">
    <div>
        <h1 style="font-size: 1.35rem; margin: 0;">Điều phối đơn hàng</h1>
        <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0.2rem 0 0;">Kiểm soát trạng thái đơn hàng an toàn</p>
    </div>
</div>

<div class="card">
    <form method="GET" action="{{ route('admin.orders.index') }}" style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: flex-end;">
        <div style="flex: 1; min-width: 200px;">
            <label for="search" style="display:block; font-size:0.8rem; font-weight:600; margin-bottom:0.25rem;">Tìm theo mã đơn</label>
            <input id="search" type="text" name="search" value="{{ $search }}" placeholder="Ví dụ: SC-261114-000001">
        </div>
        <div style="min-width: 180px;">
            <label for="status" style="display:block; font-size:0.8rem; font-weight:600; margin-bottom:0.25rem;">Trạng thái</label>
            <select id="status" name="status">
                <option value="">Tất cả trạng thái</option>
                @foreach (\App\Enums\OrderStatus::cases() as $st)
                <option value="{{ $st->value }}" {{ $statusFilter === $st->value ? 'selected' : '' }}>{{ $st->value }}</option>
                @endforeach
            </select>
        </div>
        <div style="min-width: 140px;">
            <label for="per_page" style="display:block; font-size:0.8rem; font-weight:600; margin-bottom:0.25rem;">Số dòng / trang</label>
            <select id="per_page" name="per_page">
                @foreach ([10, 20, 50] as $size)
                <option value="{{ $size }}" {{ ($perPage ?? 10) === $size ? 'selected' : '' }}>{{ $size }} đơn / trang</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Lọc đơn</button>
        <a href="{{ route('admin.orders.index') }}" class="btn btn-secondary">Xóa lọc</a>
    </form>
</div>

<div class="card">
    @if ($orders->isEmpty())
    <p style="color: var(--text-muted);">Không tìm thấy đơn hàng phù hợp.</p>
    @else
    <table>
        <thead>
            <tr>
                <th>Mã đơn</th>
                <th>Khách hàng</th>
                <th>Chi tiết món</th>
                <th>Tổng tiền</th>
                <th>Trạng thái</th>
                <th>Cập nhật trạng thái</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($orders as $order)
            @php
            $statusValue = $order->status instanceof \App\Enums\OrderStatus ? $order->status->value : (string) $order->status;
            $statusEnum = \App\Enums\OrderStatus::from($statusValue);
            @endphp
            <tr>
                <td>
                    <a href="{{ route('admin.orders.show', $order) }}" style="font-weight: 700; color: var(--brand-700); text-decoration: none;">
                        {{ $order->code }}
                    </a>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">{{ $order->created_at?->format('H:i:s d/m') }} · v{{ $order->version }}</div>
                </td>
                <td>{{ $order->user?->name ?? 'Khách' }}</td>
                <td>
                    @foreach ($order->items as $item)
                    <div>{{ $item->quantity }}x {{ $item->product?->name ?? 'Món #'.$item->product_id }}</div>
                    @endforeach
                </td>
                <td style="font-weight: 700;">{{ number_format($order->total_amount, 0, ',', '.') }} ₫</td>
                <td><span class="badge badge-{{ $statusValue }}">{{ $statusValue }}</span></td>
                <td>
                    <div class="inline-actions">
                        @foreach ($statusEnum->allowedTransitions() as $next)
                        <form method="POST" action="{{ route('admin.orders.status', $order) }}" style="margin:0;">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="status" value="{{ $next->value }}">
                            <input type="hidden" name="version" value="{{ $order->version }}">
                            <button type="submit" class="btn {{ $next->value === 'CANCELLED' ? 'btn-danger' : 'btn-primary' }}">
                                → {{ $next->value }}
                            </button>
                        </form>
                        @endforeach
                        <a href="{{ route('admin.orders.show', $order) }}" class="btn btn-secondary">Chi tiết</a>
                    </div>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>

    @include('admin.partials.pagination', ['paginator' => $orders, 'label' => 'đơn hàng'])
    @endif
</div>
@endsection
