@extends('admin.layout')

@section('title', 'Chi tiết thẻ ' . $card->card_code . ' — Smart Canteen Admin')

@section('content')
@php
    $cardStatusVal = $card->status instanceof \App\Enums\StudentCardStatus ? $card->status->value : (string) $card->status;
    $isAdmin = (auth()->user()->role->value ?? auth()->user()->role) === \App\Enums\UserRole::ADMIN->value;
@endphp

<div class="flex-between" style="margin-bottom: 1.25rem;">
    <div>
        <a href="{{ route('admin.canteen.cards.index') }}" style="font-size: 0.85rem; color: var(--brand-700); text-decoration: none; font-weight: 600;">← Quay lại danh sách thẻ</a>
        <h1 style="font-size: 1.4rem; margin: 0.25rem 0 0;">
            Thẻ học sinh <code style="color: var(--brand-700);">{{ $card->card_code }}</code>
        </h1>
        <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0.2rem 0 0;">
            Học sinh: <strong>{{ $card->student?->full_name }}</strong> (Lớp {{ $card->student?->class_name }} · {{ $card->student?->school_name }})
            · Phụ huynh: <strong>{{ $card->student?->user?->name ?? '—' }}</strong>
        </p>
    </div>
    <div>
        <span class="badge badge-{{ $cardStatusVal }}" style="font-size: 0.9rem; padding: 0.35rem 0.85rem;">{{ $cardStatusVal }}</span>
    </div>
</div>

<section class="grid-stats" aria-label="Tóm tắt hạn mức hôm nay">
    <div class="stat-card">
        <div class="stat-label">Hạn mức chi tiêu / ngày</div>
        <div class="stat-value">{{ number_format($limitSummary['daily_limit'], 0, ',', '.') }} ₫</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Đã chi hôm nay ({{ $limitSummary['effective_date'] }})</div>
        <div class="stat-value" style="color: #92400E;">{{ number_format($limitSummary['spent_today'], 0, ',', '.') }} ₫</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Hạn mức còn lại hôm nay</div>
        <div class="stat-value brand">{{ number_format($limitSummary['remaining_limit'], 0, ',', '.') }} ₫</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Ngân hàng liên kết hiện tại</div>
        @if ($card->activeBankLink)
            <div style="font-weight: 800; font-size: 1.05rem; margin-top: 0.3rem;">{{ $card->activeBankLink->bank_name }}</div>
            <div style="font-family: monospace; font-size: 0.82rem; color: var(--text-muted);">
                {{ $card->activeBankLink->account_number_masked }} · {{ $card->activeBankLink->phone_masked }}
            </div>
        @else
            <div style="margin-top: 0.45rem;"><span class="badge badge-UNLINKED">Chưa liên kết</span></div>
        @endif
    </div>
</section>

@if ($isAdmin)
<div class="card">
    <h2 style="font-size: 1.05rem; margin-top: 0;">Quản trị thẻ & Hạn mức (Chỉ dành cho Admin)</h2>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.25rem;">
        <div>
            <h3 style="font-size: 0.9rem; margin: 0 0 0.5rem;">Trạng thái thẻ học sinh</h3>
            @if ($cardStatusVal === \App\Enums\StudentCardStatus::REVOKED->value)
                <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0;">
                    Thẻ đã ở trạng thái thu hồi vĩnh viễn (REVOKED) và không thể kích hoạt lại.
                </p>
            @else
                <div class="inline-actions">
                    @if ($cardStatusVal === \App\Enums\StudentCardStatus::ACTIVE->value)
                        <form method="POST" action="{{ route('admin.canteen.cards.status', $card) }}" style="margin:0;">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="status" value="LOCKED">
                            <button type="submit" class="btn btn-secondary">Tạm khóa thẻ (LOCKED)</button>
                        </form>
                    @elseif ($cardStatusVal === \App\Enums\StudentCardStatus::LOCKED->value)
                        <form method="POST" action="{{ route('admin.canteen.cards.status', $card) }}" style="margin:0;">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="status" value="ACTIVE">
                            <button type="submit" class="btn btn-primary">Mở khóa thẻ (ACTIVE)</button>
                        </form>
                    @endif
                    <form method="POST" action="{{ route('admin.canteen.cards.status', $card) }}" style="margin:0;" onsubmit="return confirm('Bạn có chắc chắn muốn THU HỒI VĨNH VIỄN thẻ này? Mọi liên kết ngân hàng đang hoạt động sẽ bị hủy ngay lập tức và thẻ không thể kích hoạt lại.');">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="status" value="REVOKED">
                        <button type="submit" class="btn btn-danger">Thu hồi thẻ (REVOKED)</button>
                    </form>
                </div>
                <p style="font-size: 0.78rem; color: var(--text-muted); margin: 0.5rem 0 0;">
                    Lưu ý: Thu hồi thẻ (REVOKED) sẽ tự động hủy liên kết ngân hàng đang hoạt động (nếu có).
                </p>
            @endif
        </div>

        <div>
            <h3 style="font-size: 0.9rem; margin: 0 0 0.5rem;">Điều chỉnh hạn mức chi tiêu hằng ngày</h3>
            @if ($card->activeBankLink && $cardStatusVal === \App\Enums\StudentCardStatus::ACTIVE->value)
                <form method="POST" action="{{ route('admin.canteen.cards.limit', $card) }}" style="display: flex; flex-direction: column; gap: 0.6rem;">
                    @csrf
                    @method('PUT')
                    <div>
                        <label for="daily_limit" style="display:block; font-size:0.8rem; font-weight:600; margin-bottom:0.2rem;">Hạn mức mới (VNĐ, bội số 1.000, tối đa 5.000.000)</label>
                        <input id="daily_limit" type="number" name="daily_limit" min="0" max="5000000" step="1000" value="{{ old('daily_limit', $limitSummary['daily_limit']) }}" required>
                    </div>
                    <div>
                        <label for="reason" style="display:block; font-size:0.8rem; font-weight:600; margin-bottom:0.2rem;">Lý do điều chỉnh (tuỳ chọn)</label>
                        <input id="reason" type="text" name="reason" maxlength="255" value="{{ old('reason') }}" placeholder="Ví dụ: Hỗ trợ phụ huynh thay đổi hạn mức">
                    </div>
                    <div>
                        <button type="submit" class="btn btn-primary">Lưu hạn mức mới</button>
                    </div>
                </form>
            @else
                <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0;">
                    Chỉ có thể điều chỉnh hạn mức khi thẻ đang ở trạng thái <strong>ACTIVE</strong> và đã liên kết tài khoản ngân hàng.
                </p>
            @endif
        </div>
    </div>

    @if ($sandboxControlsEnabled && $card->activeBankLink)
    <div style="margin-top: 1.25rem; padding-top: 1rem; border-top: 1px solid var(--border);">
        <h3 style="font-size: 0.9rem; margin: 0 0 0.5rem;">Cấu hình Tài khoản Ngân hàng Sandbox (Dev/QA)</h3>
        <form method="POST" action="{{ route('admin.canteen.cards.sandbox', $card) }}" style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: flex-end;">
            @csrf
            @method('PUT')
            <div style="min-width: 180px;">
                <label for="sandbox_balance" style="display:block; font-size:0.8rem; font-weight:600; margin-bottom:0.2rem;">Số dư ngân hàng giả lập (VNĐ)</label>
                <input id="sandbox_balance" type="number" name="balance" min="0" max="50000000" step="1000" value="{{ old('balance', $sandboxAccount?->balance ?? 500000) }}" required>
            </div>
            <div style="min-width: 210px;">
                <label for="simulated_mode" style="display:block; font-size:0.8rem; font-weight:600; margin-bottom:0.2rem;">Chế độ mô phỏng ngân hàng</label>
                @php
                    $currentMode = $sandboxAccount?->simulated_mode instanceof \App\Enums\SandboxBankMode
                        ? $sandboxAccount->simulated_mode->value
                        : 'NORMAL';
                @endphp
                <select id="simulated_mode" name="simulated_mode">
                    @foreach (\App\Enums\SandboxBankMode::cases() as $mode)
                        <option value="{{ $mode->value }}" {{ $currentMode === $mode->value ? 'selected' : '' }}>{{ $mode->value }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="btn btn-secondary">Cập nhật Sandbox</button>
        </form>
    </div>
    @endif
</div>
@endif

<div class="card">
    <h2 style="font-size: 1.05rem; margin-top: 0;">Lịch sử liên kết ngân hàng (Thông tin đã che mờ bảo mật)</h2>
    @if ($bankLinkHistory->isEmpty())
        <p style="color: var(--text-muted); font-size: 0.9rem; margin: 0;">Chưa có lịch sử liên kết ngân hàng nào cho thẻ này.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Ngân hàng</th>
                    <th>Số tài khoản</th>
                    <th>Chủ tài khoản</th>
                    <th>CCCD / SĐT OTP</th>
                    <th>Trạng thái</th>
                    <th>Thời điểm</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($bankLinkHistory as $link)
                    @php
                        $linkStatus = $link->status instanceof \App\Enums\BankLinkStatus ? $link->status->value : (string) $link->status;
                    @endphp
                    <tr>
                        <td style="font-weight: 700;">{{ $link->bank_name }} ({{ $link->bank_code }})</td>
                        <td style="font-family: monospace;">{{ $link->account_number_masked }}</td>
                        <td>{{ $link->account_holder_name }}</td>
                        <td style="font-family: monospace; font-size: 0.82rem;">
                            {{ $link->national_id_masked }} · {{ $link->phone_masked }}
                        </td>
                        <td><span class="badge badge-{{ $linkStatus }}">{{ $linkStatus }}</span></td>
                        <td style="font-size: 0.82rem; color: var(--text-muted);">
                            @if ($link->linked_at)
                                Liên kết: {{ $link->linked_at->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y') }}
                            @endif
                            @if ($link->unlinked_at)
                                <div>Hủy: {{ $link->unlinked_at->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y') }}</div>
                            @endif
                            @if (! $link->linked_at && ! $link->unlinked_at)
                                Khởi tạo: {{ $link->created_at?->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y') ?? '—' }}
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>

<div class="card">
    <h2 style="font-size: 1.05rem; margin-top: 0;">Lịch sử điều chỉnh hạn mức (20 lần gần nhất)</h2>
    @if ($limitLogs->isEmpty())
        <p style="color: var(--text-muted); font-size: 0.9rem; margin: 0;">Chưa có lịch sử điều chỉnh hạn mức.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Thời gian (VN)</th>
                    <th>Hạn mức cũ</th>
                    <th>Hạn mức mới</th>
                    <th>Người thực hiện</th>
                    <th>Ghi chú / Lý do</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($limitLogs as $log)
                    <tr>
                        <td>{{ $log->created_at?->timezone('Asia/Ho_Chi_Minh')->format('H:i:s d/m/Y') }}</td>
                        <td>{{ number_format($log->previous_limit, 0, ',', '.') }} ₫</td>
                        <td style="font-weight: 700; color: var(--brand-700);">{{ number_format($log->new_limit, 0, ',', '.') }} ₫</td>
                        <td>{{ $log->actor?->name ?? 'User #'.$log->changed_by }}</td>
                        <td>{{ $log->reason ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>

<div class="card">
    <h2 style="font-size: 1.05rem; margin-top: 0;">Giao dịch & Đơn hàng gắn thẻ gần đây</h2>
    @if ($recentOrders->isEmpty())
        <p style="color: var(--text-muted); font-size: 0.9rem; margin: 0;">Chưa có giao dịch mua hàng nào sử dụng thẻ học sinh này.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Mã đơn</th>
                    <th>Cửa hàng / Món</th>
                    <th>Tổng tiền</th>
                    <th>Thanh toán</th>
                    <th>Hạn mức sau đơn</th>
                    <th>Trạng thái đơn</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($recentOrders as $order)
                    @php
                        $ordStatus = $order->status instanceof \App\Enums\OrderStatus ? $order->status->value : (string) $order->status;
                        $payStatus = $order->payment_status instanceof \App\Enums\PaymentStatus ? $order->payment_status->value : (string) $order->payment_status;
                    @endphp
                    <tr>
                        <td>
                            <a href="{{ route('admin.orders.show', $order) }}" style="font-weight: 700; color: var(--brand-700); text-decoration: none;">
                                {{ $order->code }}
                            </a>
                            <div style="font-size: 0.75rem; color: var(--text-muted);">{{ $order->created_at?->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y') }}</div>
                        </td>
                        <td>
                            <div style="font-size: 0.78rem; color: var(--text-muted);">{{ $order->store_name ?? 'Căn tin trường' }}</div>
                            @foreach ($order->items as $item)
                                <div>{{ $item->quantity }}x {{ $item->product?->name ?? 'Món #'.$item->product_id }}</div>
                            @endforeach
                        </td>
                        <td style="font-weight: 700;">{{ number_format($order->total_amount, 0, ',', '.') }} ₫</td>
                        <td><span class="badge badge-{{ $payStatus }}">{{ $payStatus }}</span></td>
                        <td>{{ $order->remaining_limit_snapshot !== null ? number_format($order->remaining_limit_snapshot, 0, ',', '.').' ₫' : '—' }}</td>
                        <td><span class="badge badge-{{ $ordStatus }}">{{ $ordStatus }}</span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
@endsection
