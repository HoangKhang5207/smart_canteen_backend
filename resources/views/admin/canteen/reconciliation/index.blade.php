@extends('admin.layout')

@section('title', 'Đối soát Ngân hàng & Sổ cái Giao dịch — Smart Canteen Admin')

@section('content')
@php
    $isAdmin = (auth()->user()->role->value ?? auth()->user()->role) === \App\Enums\UserRole::ADMIN->value;
@endphp

<div class="flex-between" style="margin-bottom: 1.25rem;">
    <div>
        <h1 style="font-size: 1.35rem; margin: 0;">Đối soát Giao dịch Ngân hàng &amp; Sổ cái Thanh toán</h1>
        <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0.2rem 0 0;">
            So khớp sổ cái <code>payment_transactions</code> với sao kê ngân hàng Sandbox / VNPay theo ngày giờ Việt Nam.
        </p>
    </div>
</div>

@if ($isAdmin)
<div class="card">
    <h2 style="font-size: 1rem; margin-top: 0;">Chạy phiên đối soát mới</h2>
    <form method="POST" action="{{ route('admin.canteen.reconciliation.run') }}" style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: flex-end;">
        @csrf
        <div style="min-width: 180px;">
            <label for="reconcile_date" style="display:block; font-size:0.8rem; font-weight:600; margin-bottom:0.25rem;">Ngày đối soát (VN)</label>
            <input id="reconcile_date" type="date" name="date" value="{{ old('date', $todayVn) }}" required>
        </div>
        <div style="min-width: 180px;">
            <label for="reconcile_provider" style="display:block; font-size:0.8rem; font-weight:600; margin-bottom:0.25rem;">Cổng thanh toán</label>
            <select id="reconcile_provider" name="provider">
                <option value="sandbox_epay">Sandbox Banking (EPay)</option>
                <option value="mock_epay">Mock EPay</option>
                <option value="vnpay">VNPay Sandbox</option>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Chạy đối soát ngay</button>
    </form>
</div>
@endif

<div class="card">
    <h2 style="font-size: 1.05rem; margin-top: 0;">Các phiên đối soát gần đây</h2>
    @if ($batches->isEmpty())
        <p style="color: var(--text-muted); font-size: 0.9rem; margin: 0;">Chưa có phiên đối soát nào được thực hiện.</p>
    @else
        @foreach ($batches as $batch)
            <div style="border: 1px solid var(--border); border-radius: 10px; padding: 0.9rem; margin-bottom: 0.85rem;">
                <div class="flex-between" style="margin-bottom: 0.5rem;">
                    <div>
                        <strong>Phiên #{{ $batch->id }}</strong> · Ngày <strong>{{ $batch->reconciliation_date->format('Y-m-d') }}</strong>
                        · Cổng <code>{{ $batch->provider }}</code>
                        <div style="font-size: 0.78rem; color: var(--text-muted);">
                            Khớp: <strong>{{ $batch->matched_count }}</strong> · Chênh lệch: <strong>{{ $batch->discrepancy_count }}</strong>
                            · Nội bộ: {{ number_format($batch->total_local_amount, 0, ',', '.') }} ₫
                            · Ngân hàng: {{ number_format($batch->total_provider_amount, 0, ',', '.') }} ₫
                        </div>
                    </div>
                    <span class="badge badge-{{ $batch->status }}">{{ $batch->status }}</span>
                </div>

                @if ($batch->items->isNotEmpty())
                    <table>
                        <thead>
                            <tr>
                                <th>Mã đối chiếu</th>
                                <th>Mã đơn</th>
                                <th>Nội bộ</th>
                                <th>Ngân hàng</th>
                                <th>Kết quả</th>
                                <th>Ghi chú / Xử lý</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($batch->items as $item)
                                <tr>
                                    <td style="font-family: monospace; font-size: 0.82rem;">{{ $item->provider_reference ?? '—' }}</td>
                                    <td>{{ $item->order_code ?? '—' }}</td>
                                    <td>{{ $item->local_amount !== null ? number_format($item->local_amount, 0, ',', '.').' ₫' : '—' }}</td>
                                    <td>{{ $item->provider_amount !== null ? number_format($item->provider_amount, 0, ',', '.').' ₫' : '—' }}</td>
                                    <td><span class="badge badge-{{ $item->status }}">{{ $item->status }}</span></td>
                                    <td>
                                        <div style="font-size: 0.8rem;">{{ $item->note ?? '—' }}</div>
                                        @if ($item->resolved_at)
                                            @if ($item->resolution_note)
                                                <div style="font-size: 0.78rem; color: #166534; font-weight: 600; margin-top: 0.2rem;">
                                                    Hướng xử lý: {{ $item->resolution_note }}
                                                </div>
                                            @endif
                                            <div style="font-size: 0.74rem; color: var(--brand-700);">
                                                Đã xử lý bởi {{ $item->resolver?->name ?? 'Admin' }} ({{ $item->resolved_at->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y') }})
                                            </div>
                                        @elseif ($isAdmin && $item->status !== 'MATCHED')
                                            <form method="POST" action="{{ route('admin.canteen.reconciliation.resolve', $item) }}" style="display:flex; gap:0.4rem; margin-top:0.35rem;">
                                                @csrf
                                                @method('PATCH')
                                                <input type="text" name="note" placeholder="Ghi chú hướng xử lý lệch..." required style="font-size:0.78rem; padding:0.3rem 0.5rem;">
                                                <button type="submit" class="btn btn-secondary" style="padding:0.3rem 0.6rem; font-size:0.76rem; white-space:nowrap;">Đánh dấu xong</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        @endforeach
    @endif
</div>

<div class="card">
    <div class="flex-between" style="margin-bottom: 0.85rem;">
        <h2 style="font-size: 1.05rem; margin: 0;">Sổ cái Giao dịch Thanh toán (payment_transactions)</h2>
        <form method="GET" action="{{ route('admin.canteen.reconciliation.index') }}" class="inline-actions">
            <select name="provider" style="width: auto;">
                <option value="">Tất cả cổng</option>
                <option value="sandbox_epay" {{ $providerFilter === 'sandbox_epay' ? 'selected' : '' }}>sandbox_epay</option>
                <option value="vnpay" {{ $providerFilter === 'vnpay' ? 'selected' : '' }}>vnpay</option>
                <option value="mock_epay" {{ $providerFilter === 'mock_epay' ? 'selected' : '' }}>mock_epay</option>
            </select>
            <select name="tx_status" style="width: auto;">
                <option value="">Tất cả trạng thái</option>
                @foreach (['SUCCEEDED', 'PENDING', 'FAILED', 'TIMEOUT'] as $st)
                    <option value="{{ $st }}" {{ $statusFilter === $st ? 'selected' : '' }}>{{ $st }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn btn-primary">Lọc</button>
            <a href="{{ route('admin.canteen.reconciliation.index') }}" class="btn btn-secondary">Xóa lọc</a>
        </form>
    </div>

    @if ($transactions->isEmpty())
        <p style="color: var(--text-muted); margin: 0;">Chưa có bản ghi giao dịch thanh toán nào.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>ID / Thời gian</th>
                    <th>Cổng &amp; Loại</th>
                    <th>Đơn / Thẻ</th>
                    <th>Số tiền</th>
                    <th>Trạng thái</th>
                    <th>Mã tham chiếu / Lỗi</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($transactions as $tx)
                    <tr>
                        <td>
                            <strong>#{{ $tx->id }}</strong>
                            <div style="font-size: 0.75rem; color: var(--text-muted);">{{ $tx->created_at?->timezone('Asia/Ho_Chi_Minh')->format('H:i:s d/m/Y') }}</div>
                        </td>
                        <td>
                            <code>{{ $tx->provider }}</code> · <strong>{{ $tx->type }}</strong>
                        </td>
                        <td>
                            @if ($tx->order)
                                <a href="{{ route('admin.orders.show', $tx->order) }}" style="color: var(--brand-700); font-weight: 600; text-decoration: none;">{{ $tx->order->code }}</a>
                            @else
                                <span style="color: var(--text-muted);">Không tạo đơn</span>
                            @endif
                            @if ($tx->studentCard)
                                <div style="font-size: 0.76rem; color: var(--text-muted);">Thẻ {{ $tx->studentCard->card_code }}</div>
                            @endif
                        </td>
                        <td style="font-weight: 700;">{{ number_format($tx->amount, 0, ',', '.') }} ₫</td>
                        <td><span class="badge badge-{{ $tx->status }}">{{ $tx->status }}</span></td>
                        <td style="font-size: 0.78rem;">
                            @if ($tx->provider_reference)
                                <div style="font-family: monospace;">{{ $tx->provider_reference }}</div>
                            @endif
                            @if ($tx->failure_code)
                                <div style="color: #991B1B; font-weight: 600;">{{ $tx->failure_code }}: {{ $tx->failure_message }}</div>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @include('admin.partials.pagination', ['paginator' => $transactions, 'label' => 'giao dịch'])
    @endif
</div>
@endsection
