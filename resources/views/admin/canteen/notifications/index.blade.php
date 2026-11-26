@extends('admin.layout')

@section('title', 'Nhật ký Thông báo & Push Zalo — Smart Canteen Admin')

@section('content')
<div class="flex-between" style="margin-bottom: 1.25rem;">
    <div>
        <h1 style="font-size: 1.35rem; margin: 0;">Nhật ký Thông báo In-App &amp; Push Zalo OA / ZNS</h1>
        <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0.2rem 0 0;">
            Theo dõi toàn bộ thông báo giao dịch, cảnh báo hạn mức thấp và trạng thái đẩy qua kênh push (không lưu dữ liệu nhạy cảm).
        </p>
    </div>
    <span class="badge badge-ACTIVE">Driver Push: {{ config('services.notifications.push_driver', 'log') }}</span>
</div>

<div class="grid-stats">
    <div class="stat-card">
        <div class="stat-label">Tổng số thông báo đã phát</div>
        <div class="stat-value">{{ $stats['total'] }}</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Chưa đọc trên Mini App</div>
        <div class="stat-value brand">{{ $stats['unread'] }}</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Lỗi đẩy Push (In-App vẫn giữ nguyên)</div>
        <div class="stat-value" style="color: #991B1B;">{{ $stats['push_failed'] }}</div>
    </div>
</div>

<div class="card">
    <form method="GET" action="{{ route('admin.canteen.notifications.index') }}" style="display:flex; gap:0.75rem; flex-wrap:wrap; align-items:flex-end;">
        <div style="min-width: 220px;">
            <label for="type" style="display:block; font-size:0.8rem; font-weight:600; margin-bottom:0.25rem;">Loại sự kiện</label>
            <select id="type" name="type">
                <option value="">Tất cả sự kiện</option>
                @foreach ($types as $t)
                    <option value="{{ $t }}" {{ $typeFilter === $t ? 'selected' : '' }}>{{ $t }}</option>
                @endforeach
            </select>
        </div>
        <div style="min-width: 180px;">
            <label for="push_status" style="display:block; font-size:0.8rem; font-weight:600; margin-bottom:0.25rem;">Trạng thái Push</label>
            <select id="push_status" name="push_status">
                <option value="">Tất cả</option>
                @foreach (['SIMULATED', 'SENT', 'SKIPPED', 'FAILED'] as $st)
                    <option value="{{ $st }}" {{ $pushStatusFilter === $st ? 'selected' : '' }}>{{ $st }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Lọc nhật ký</button>
        <a href="{{ route('admin.canteen.notifications.index') }}" class="btn btn-secondary">Xóa lọc</a>
    </form>
</div>

<div class="card">
    @if ($notifications->isEmpty())
        <p style="color: var(--text-muted); margin: 0;">Chưa có thông báo nào được ghi nhận.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>ID / Thời gian</th>
                    <th>Người nhận</th>
                    <th>Sự kiện</th>
                    <th>Nội dung</th>
                    <th>Push Channel &amp; Trạng thái</th>
                    <th>Đã đọc</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($notifications as $item)
                    @php
                        $typeVal = $item->type?->value ?? (string) $item->type;
                        $sevVal = $item->severity?->value ?? (string) $item->severity;
                    @endphp
                    <tr>
                        <td>
                            <strong>#{{ $item->id }}</strong>
                            <div style="font-size: 0.75rem; color: var(--text-muted);">{{ $item->created_at?->timezone('Asia/Ho_Chi_Minh')->format('H:i:s d/m/Y') }}</div>
                        </td>
                        <td>
                            <div style="font-weight: 600;">{{ $item->user?->name ?? ('User #' . $item->user_id) }}</div>
                            <div style="font-size: 0.74rem; color: var(--text-muted);">{{ strtoupper($item->user?->role?->value ?? '') }}</div>
                        </td>
                        <td>
                            <div style="font-family: monospace; font-size: 0.78rem; font-weight: 700;">{{ $typeVal }}</div>
                            <span class="badge badge-{{ $sevVal === 'ERROR' ? 'FAILED' : ($sevVal === 'WARNING' ? 'PENDING' : ($sevVal === 'SUCCESS' ? 'ACTIVE' : 'REFUNDED')) }}">{{ $sevVal }}</span>
                        </td>
                        <td style="max-width: 340px;">
                            <div style="font-weight: 700; font-size: 0.86rem;">{{ $item->title }}</div>
                            <div style="font-size: 0.8rem; color: var(--text-muted);">{{ $item->body }}</div>
                        </td>
                        <td>
                            <code>{{ $item->push_channel }}</code>
                            <div style="margin-top: 0.2rem;">
                                <span class="badge badge-{{ $item->push_status === 'FAILED' ? 'FAILED' : ($item->push_status === 'SENT' || $item->push_status === 'SIMULATED' ? 'ACTIVE' : 'COMPLETED') }}">
                                    {{ $item->push_status }}
                                </span>
                            </div>
                        </td>
                        <td style="font-size: 0.78rem;">
                            @if ($item->read_at)
                                {{ $item->read_at->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m') }}
                            @else
                                <span style="color: var(--brand-700); font-weight: 600;">Chưa đọc</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @include('admin.partials.pagination', ['paginator' => $notifications, 'label' => 'thông báo'])
    @endif
</div>
@endsection
