@extends('admin.layout')

@section('title', 'Biên lai POS ' . $order->code . ' — Smart Canteen')

@section('content')
<div style="max-width: 440px; margin: 0 auto;">
    <div class="flex-between no-print" style="margin-bottom: 1rem;">
        <a href="{{ route('admin.pos.index') }}" class="btn btn-secondary">← Về màn hình POS</a>
        <button type="button" onclick="window.print()" class="btn btn-primary">In biên lai</button>
    </div>

    <div class="card" style="font-size: 0.9rem;">
        <div style="text-align: center; border-bottom: 1px dashed #9CA3AF; padding-bottom: 0.75rem; margin-bottom: 0.75rem;">
            <strong style="font-size: 1.05rem;">SMART CANTEEN — BIÊN LAI POS</strong>
            <div style="font-size: 0.8rem; color: var(--text-muted);">{{ $order->store_name ?? 'Quầy POS Căn tin' }}</div>
            <div style="font-family: monospace; font-weight: 800; font-size: 1rem; margin-top: 0.3rem;">#{{ $order->code }}</div>
            <div style="font-size: 0.76rem; color: var(--text-muted);">
                {{ $order->created_at?->timezone('Asia/Ho_Chi_Minh')->format('H:i:s d/m/Y') }}
            </div>
        </div>

        @if ($order->studentCard)
            <div style="margin-bottom: 0.75rem; font-size: 0.84rem; padding: 0.5rem 0.65rem; background: var(--brand-50); border-radius: 8px;">
                <div>Học sinh: <strong>{{ $order->studentCard->student?->full_name }}</strong> ({{ $order->studentCard->student?->class_name }})</div>
                <div>Mã thẻ: <code>{{ $order->studentCard->card_code }}</code></div>
                @if ($order->remaining_limit_snapshot !== null)
                    <div>Hạn mức còn lại hôm nay: <strong>{{ number_format($order->remaining_limit_snapshot, 0, ',', '.') }} ₫</strong></div>
                @endif
            </div>
        @endif

        <table style="font-size: 0.84rem; margin-bottom: 0.75rem;">
            <thead>
                <tr>
                    <th>Món</th>
                    <th style="text-align:center;">SL</th>
                    <th style="text-align:right;">Thành tiền</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($order->items as $item)
                    <tr>
                        <td>{{ $item->product?->name ?? ('Món #' . $item->product_id) }}</td>
                        <td style="text-align:center;">{{ $item->quantity }}</td>
                        <td style="text-align:right; font-weight:600;">{{ number_format($item->subtotal, 0, ',', '.') }} ₫</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div style="border-top: 1px dashed #9CA3AF; padding-top: 0.65rem;">
            <div class="flex-between" style="font-size: 1rem; font-weight: 800;">
                <span>Tổng thanh toán</span>
                <span style="color: var(--brand-700);">{{ number_format($order->total_amount, 0, ',', '.') }} ₫</span>
            </div>
            <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 0.35rem;">
                Hình thức: <strong>{{ $order->payment_method?->label() ?? $order->payment_method }}</strong>
                · Trạng thái: <strong>{{ $order->payment_status?->label() ?? $order->payment_status }}</strong>
            </div>
            @if ($order->payment_reference)
                <div style="font-size: 0.75rem; color: var(--text-muted); font-family: monospace; margin-top: 0.2rem;">
                    Ref: {{ $order->payment_reference }}
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
