@extends('admin.layout')

@section('title', 'Tổng quan hôm nay — Smart Canteen Admin')
@section('auto_refresh', '1')

@section('content')
<div class="flex-between" style="margin-bottom: 1.25rem;">
    <div>
        <h1 style="font-size: 1.4rem; margin: 0;">Bảng điều hành hôm nay ({{ $summary['date'] }})</h1>
        <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0.2rem 0 0;">Tự động làm mới mỗi 10 giây · Dữ liệu thời gian thực từ PostgreSQL</p>
    </div>
    <a href="{{ route('admin.orders.index') }}" class="btn btn-primary">Xem toàn bộ đơn hàng</a>
</div>

<section class="grid-stats" aria-label="Thống kê đơn hàng trong ngày">
    <div class="stat-card">
        <div class="stat-label">Tổng đơn hôm nay</div>
        <div class="stat-value">{{ $summary['today_total_orders'] }}</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Đơn đang xử lý (Bếp)</div>
        <div class="stat-value" style="color: #1E40AF;">{{ $summary['today_active_orders'] }}</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Doanh thu hoàn thành hôm nay</div>
        <div class="stat-value brand">{{ number_format($summary['today_completed_revenue'], 0, ',', '.') }} ₫</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Phân bổ trạng thái</div>
        <div style="font-size: 0.8rem; margin-top: 0.45rem; display: flex; gap: 0.4rem; flex-wrap: wrap;">
            <span class="badge badge-PENDING">Chờ: {{ $summary['counts_by_status']['PENDING'] }}</span>
            <span class="badge badge-PREPARING">Bếp: {{ $summary['counts_by_status']['PREPARING'] }}</span>
            <span class="badge badge-READY">Sẵn sàng: {{ $summary['counts_by_status']['READY'] }}</span>
            <span class="badge badge-COMPLETED">Xong: {{ $summary['counts_by_status']['COMPLETED'] }}</span>
        </div>
    </div>
</section>

<section class="card" aria-label="Đơn hàng cần xử lý ngay">
    <div class="flex-between" style="margin-bottom: 1rem;">
        <h2 style="font-size: 1.1rem; margin: 0;">Đơn hàng đang chờ xử lý ({{ $activeOrders->count() }})</h2>
    </div>

    @if ($activeOrders->isEmpty())
        <p style="color: var(--text-muted); font-size: 0.9rem;">Hiện chưa có đơn hàng nào đang chờ xử lý.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Mã đơn</th>
                    <th>Khách hàng</th>
                    <th>Món đặt</th>
                    <th>Tổng tiền</th>
                    <th>Trạng thái</th>
                    <th>Thao tác nhanh</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($activeOrders as $order)
                    @php
                        $statusValue = $order->status instanceof \App\Enums\OrderStatus ? $order->status->value : (string) $order->status;
                        $statusEnum = \App\Enums\OrderStatus::from($statusValue);
                    @endphp
                    <tr>
                        <td>
                            <a href="{{ route('admin.orders.show', $order) }}" style="font-weight: 700; color: var(--brand-700); text-decoration: none;">
                                {{ $order->code }}
                            </a>
                            <div style="font-size: 0.75rem; color: var(--text-muted);">v{{ $order->version }}</div>
                        </td>
                        <td>{{ $order->user?->name ?? 'Khách' }}</td>
                        <td>
                            @foreach ($order->items as $item)
                                <div>{{ $item->quantity }}x {{ $item->product?->name ?? 'Món #'.$item->product_id }}</div>
                            @endforeach
                            @if ($order->note)
                                <div style="font-size: 0.75rem; color: #92400E;">Ghi chú: {{ $order->note }}</div>
                            @endif
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
    @endif
</section>
@endsection
