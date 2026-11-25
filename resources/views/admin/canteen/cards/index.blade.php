@extends('admin.layout')

@section('title', 'Quản lý Căn tin số — Smart Canteen Admin')

@section('content')
<div class="flex-between" style="margin-bottom: 1.25rem;">
    <div>
        <h1 style="font-size: 1.35rem; margin: 0;">Căn tin số — Học sinh, Thẻ & Hạn mức</h1>
        <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0.2rem 0 0;">
            Giám sát thẻ học sinh thông minh, liên kết ngân hàng (đã che mờ bảo mật) và hạn mức chi tiêu hằng ngày.
        </p>
    </div>
</div>

<section class="grid-stats" aria-label="Thống kê thẻ học sinh">
    <div class="stat-card">
        <div class="stat-label">Tổng số thẻ đã cấp</div>
        <div class="stat-value">{{ $stats['total_cards'] }}</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Thẻ đang hoạt động</div>
        <div class="stat-value brand">{{ $stats['active_cards'] }}</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Thẻ đã liên kết ngân hàng</div>
        <div class="stat-value" style="color: #1E40AF;">{{ $stats['linked_cards'] }}</div>
    </div>
</section>

<div class="card">
    <form method="GET" action="{{ route('admin.canteen.cards.index') }}" style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: flex-end;">
        <div style="flex: 1; min-width: 220px;">
            <label for="search" style="display:block; font-size:0.8rem; font-weight:600; margin-bottom:0.25rem;">Tìm theo mã thẻ / học sinh / lớp</label>
            <input id="search" type="text" name="search" value="{{ $search }}" placeholder="Ví dụ: F156BA, Titkul Student, 8A2">
        </div>
        <div style="min-width: 160px;">
            <label for="status" style="display:block; font-size:0.8rem; font-weight:600; margin-bottom:0.25rem;">Trạng thái thẻ</label>
            <select id="status" name="status">
                <option value="">Tất cả trạng thái</option>
                @foreach (\App\Enums\StudentCardStatus::cases() as $st)
                    <option value="{{ $st->value }}" {{ $statusFilter === $st->value ? 'selected' : '' }}>{{ $st->value }}</option>
                @endforeach
            </select>
        </div>
        <div style="min-width: 170px;">
            <label for="link_status" style="display:block; font-size:0.8rem; font-weight:600; margin-bottom:0.25rem;">Liên kết ngân hàng</label>
            <select id="link_status" name="link_status">
                <option value="">Tất cả</option>
                <option value="linked" {{ $linkFilter === 'linked' ? 'selected' : '' }}>Đã liên kết</option>
                <option value="unlinked" {{ $linkFilter === 'unlinked' ? 'selected' : '' }}>Chưa liên kết</option>
            </select>
        </div>
        <div style="min-width: 130px;">
            <label for="per_page" style="display:block; font-size:0.8rem; font-weight:600; margin-bottom:0.25rem;">Số dòng / trang</label>
            <select id="per_page" name="per_page">
                @foreach ([10, 20, 50] as $size)
                    <option value="{{ $size }}" {{ ($perPage ?? 10) === $size ? 'selected' : '' }}>{{ $size }} thẻ / trang</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Lọc danh sách</button>
        <a href="{{ route('admin.canteen.cards.index') }}" class="btn btn-secondary">Xóa lọc</a>
    </form>
</div>

<div class="card">
    @if ($cards->isEmpty())
        <p style="color: var(--text-muted); margin: 0;">Không tìm thấy thẻ học sinh phù hợp.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Mã thẻ</th>
                    <th>Học sinh & Lớp</th>
                    <th>Phụ huynh</th>
                    <th>Trạng thái thẻ</th>
                    <th>Ngân hàng liên kết</th>
                    <th>Hạn mức hôm nay</th>
                    <th>Thao tác</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($cards as $card)
                    @php
                        $cardStatusVal = $card->status instanceof \App\Enums\StudentCardStatus ? $card->status->value : (string) $card->status;
                        $limit = $limitSummaries[$card->id] ?? ['daily_limit' => 0, 'spent_today' => 0, 'remaining_limit' => 0];
                    @endphp
                    <tr>
                        <td>
                            <a href="{{ route('admin.canteen.cards.show', $card) }}" style="font-family: monospace; font-weight: 800; color: var(--brand-700); text-decoration: none; font-size: 0.95rem;">
                                {{ $card->card_code }}
                            </a>
                            <div style="font-size: 0.75rem; color: var(--text-muted);">{{ $card->student?->student_code }}</div>
                        </td>
                        <td>
                            <div style="font-weight: 700;">{{ $card->student?->full_name ?? '—' }}</div>
                            <div style="font-size: 0.78rem; color: var(--text-muted);">
                                Lớp {{ $card->student?->class_name ?? '—' }} · {{ $card->student?->school_name ?? '—' }}
                            </div>
                        </td>
                        <td>{{ $card->student?->user?->name ?? '—' }}</td>
                        <td><span class="badge badge-{{ $cardStatusVal }}">{{ $cardStatusVal }}</span></td>
                        <td>
                            @if ($card->activeBankLink)
                                <div style="font-weight: 600;">{{ $card->activeBankLink->bank_name }}</div>
                                <div style="font-size: 0.78rem; font-family: monospace; color: var(--text-muted);">
                                    {{ $card->activeBankLink->account_number_masked }}
                                </div>
                            @else
                                <span class="badge badge-UNLINKED">Chưa liên kết</span>
                            @endif
                        </td>
                        <td>
                            <div style="font-weight: 700; color: var(--brand-700);">
                                Còn {{ number_format($limit['remaining_limit'], 0, ',', '.') }} ₫
                            </div>
                            <div style="font-size: 0.76rem; color: var(--text-muted);">
                                Hạn mức: {{ number_format($limit['daily_limit'], 0, ',', '.') }} ₫ · Đã chi: {{ number_format($limit['spent_today'], 0, ',', '.') }} ₫
                            </div>
                        </td>
                        <td>
                            <a href="{{ route('admin.canteen.cards.show', $card) }}" class="btn btn-secondary">Chi tiết</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @include('admin.partials.pagination', ['paginator' => $cards, 'label' => 'thẻ học sinh'])
    @endif
</div>
@endsection
