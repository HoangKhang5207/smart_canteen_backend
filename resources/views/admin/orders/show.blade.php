@extends('admin.layout')

@section('title', 'Chi tiết đơn ' . $order->code . ' — Smart Canteen Admin')

@section('content')
@php
    $statusValue = $order->status instanceof \App\Enums\OrderStatus ? $order->status->value : (string) $order->status;
    $statusEnum = \App\Enums\OrderStatus::from($statusValue);
@endphp

<div class="flex-between" style="margin-bottom: 1.25rem;">
    <div>
        <a href="{{ route('admin.orders.index') }}" style="font-size: 0.85rem; color: var(--brand-700); text-decoration: none; font-weight: 600;">← Quay lại danh sách đơn</a>
        <h1 style="font-size: 1.4rem; margin: 0.25rem 0 0;">Đơn hàng {{ $order->code }}</h1>
        <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0.2rem 0 0;">
            Khách hàng: <strong>{{ $order->user?->name ?? 'Khách' }}</strong> · Phiên bản khóa đồng thời: <code>v{{ $order->version }}</code>
        </p>
    </div>
    <div>
        <span class="badge badge-{{ $statusValue }}" style="font-size: 0.9rem; padding: 0.35rem 0.85rem;">{{ $statusValue }}</span>
    </div>
</div>

<div class="card">
    <h2 style="font-size: 1.05rem; margin-top: 0;">Chuyển trạng thái đơn hàng</h2>
    @if (empty($statusEnum->allowedTransitions()))
        <p style="color: var(--text-muted); font-size: 0.9rem; margin: 0;">Đơn hàng đã ở trạng thái kết thúc ({{ $statusValue }}), không thể chuyển tiếp.</p>
    @else
        <div class="inline-actions">
            @foreach ($statusEnum->allowedTransitions() as $next)
                <form method="POST" action="{{ route('admin.orders.status', $order) }}" style="margin:0;">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="status" value="{{ $next->value }}">
                    <input type="hidden" name="version" value="{{ $order->version }}">
                    <button type="submit" class="btn {{ $next->value === 'CANCELLED' ? 'btn-danger' : 'btn-primary' }}">
                        Chuyển sang {{ $next->value }}
                    </button>
                </form>
            @endforeach
        </div>
    @endif
</div>

<div class="card">
    <h2 style="font-size: 1.05rem; margin-top: 0;">Danh sách món ăn đã chốt giá</h2>
    @if ($order->note)
        <p style="background: #FEF3C7; color: #92400E; padding: 0.65rem 0.9rem; border-radius: 8px; font-size: 0.875rem;">
            <strong>Ghi chú của khách:</strong> {{ $order->note }}
        </p>
    @endif
    <table>
        <thead>
            <tr>
                <th>Món ăn</th>
                <th>Đơn giá lúc đặt</th>
                <th>Số lượng</th>
                <th>Thành tiền</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($order->items as $item)
                <tr>
                    <td style="font-weight: 600;">{{ $item->product?->name ?? 'Món #'.$item->product_id }}</td>
                    <td>{{ number_format($item->unit_price, 0, ',', '.') }} ₫</td>
                    <td>{{ $item->quantity }}</td>
                    <td style="font-weight: 700;">{{ number_format($item->subtotal, 0, ',', '.') }} ₫</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <th colspan="3" style="text-align: right;">Tổng cộng:</th>
                <th style="font-size: 1.1rem; color: var(--brand-700);">{{ number_format($order->total_amount, 0, ',', '.') }} ₫</th>
            </tr>
        </tfoot>
    </table>
</div>

<div class="card">
    <h2 style="font-size: 1.05rem; margin-top: 0;">Nhật ký chuyển trạng thái (Audit Log)</h2>
    @if ($order->statusLogs->isEmpty())
        <p style="color: var(--text-muted); font-size: 0.9rem; margin: 0;">Chưa có lịch sử chuyển trạng thái.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Thời gian</th>
                    <th>Từ trạng thái</th>
                    <th>Sang trạng thái</th>
                    <th>Người thực hiện</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($order->statusLogs as $log)
                    @php
                        $fromVal = $log->from_status instanceof \App\Enums\OrderStatus ? $log->from_status->value : (string) $log->from_status;
                        $toVal = $log->to_status instanceof \App\Enums\OrderStatus ? $log->to_status->value : (string) $log->to_status;
                    @endphp
                    <tr>
                        <td>{{ $log->created_at?->format('H:i:s d/m/Y') }}</td>
                        <td><span class="badge badge-{{ $fromVal }}">{{ $fromVal }}</span></td>
                        <td><span class="badge badge-{{ $toVal }}">{{ $toVal }}</span></td>
                        <td>{{ $log->actor?->name ?? 'User #'.$log->changed_by }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
@endsection
