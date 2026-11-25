@extends('admin.layout')

@section('title', ($isEdit ? 'Cập nhật học sinh' : 'Thêm học sinh mới') . ' — Smart Canteen Admin')

@section('content')
<div style="max-width: 720px; margin: 0 auto;">
    <div style="margin-bottom: 1rem;">
        <a href="{{ route('admin.canteen.students.index') }}" style="font-size: 0.85rem; color: var(--brand-700); text-decoration: none; font-weight: 600;">← Quay lại danh sách học sinh</a>
        <h1 style="font-size: 1.35rem; margin: 0.3rem 0 0;">
            {{ $isEdit ? 'Cập nhật học sinh: ' . $student->full_name : 'Thêm học sinh mới' }}
        </h1>
    </div>

    <div class="card">
        <form method="POST" action="{{ $isEdit ? route('admin.canteen.students.update', $student) : route('admin.canteen.students.store') }}">
            @csrf
            @if ($isEdit)
                @method('PUT')
            @endif

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 0.9rem; margin-bottom: 1rem;">
                <div>
                    <label for="student_code" style="display:block; font-size:0.85rem; font-weight:600; margin-bottom:0.3rem;">Mã học sinh</label>
                    <input id="student_code" type="text" name="student_code" value="{{ old('student_code', $student->student_code) }}" required maxlength="40" placeholder="VD: HS-2026-0002">
                </div>
                <div>
                    <label for="full_name" style="display:block; font-size:0.85rem; font-weight:600; margin-bottom:0.3rem;">Họ và tên học sinh</label>
                    <input id="full_name" type="text" name="full_name" value="{{ old('full_name', $student->full_name) }}" required maxlength="120" placeholder="Nguyễn Văn A">
                </div>
                <div>
                    <label for="school_name" style="display:block; font-size:0.85rem; font-weight:600; margin-bottom:0.3rem;">Trường học</label>
                    <input id="school_name" type="text" name="school_name" value="{{ old('school_name', $student->school_name) }}" required maxlength="150">
                </div>
                <div>
                    <label for="class_name" style="display:block; font-size:0.85rem; font-weight:600; margin-bottom:0.3rem;">Lớp</label>
                    <input id="class_name" type="text" name="class_name" value="{{ old('class_name', $student->class_name) }}" required maxlength="40" placeholder="VD: 8A2">
                </div>
            </div>

            <div style="margin-bottom: 1rem;">
                <label for="user_id" style="display:block; font-size:0.85rem; font-weight:600; margin-bottom:0.3rem;">Tài khoản Phụ huynh giám hộ (hỗ trợ gắn nhiều học sinh)</label>
                <select id="user_id" name="user_id" required>
                    <option value="">-- Chọn phụ huynh --</option>
                    @foreach ($parents as $parent)
                        <option value="{{ $parent->id }}" {{ (int) old('user_id', $student->user_id) === $parent->id ? 'selected' : '' }}>
                            #{{ $parent->id }} · {{ $parent->name }} {{ $parent->phone ? '(' . $parent->phone . ')' : '' }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div style="margin-bottom: 1rem;">
                <label for="student_user_id" style="display:block; font-size:0.85rem; font-weight:600; margin-bottom:0.3rem;">Tài khoản Học sinh (role = student, tùy chọn)</label>
                <select id="student_user_id" name="student_user_id">
                    <option value="">-- Không gắn tài khoản riêng --</option>
                    @foreach ($studentUsers as $su)
                        <option value="{{ $su->id }}" {{ (int) old('student_user_id', $student->student_user_id) === $su->id ? 'selected' : '' }}>
                            #{{ $su->id }} · {{ $su->name }} {{ $su->phone ? '(' . $su->phone . ')' : '' }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div style="margin-bottom: 1rem;">
                <input type="hidden" name="is_active" value="0">
                <label style="display:flex; align-items:center; gap:0.5rem; font-size:0.9rem; font-weight:600;">
                    <input type="checkbox" name="is_active" value="1" {{ old('is_active', $student->is_active ?? true) ? 'checked' : '' }}>
                    Hồ sơ học sinh đang hoạt động
                </label>
            </div>

            @if (! $isEdit)
                <div style="margin-bottom: 1.25rem; padding: 0.85rem; border-radius: 10px; background: var(--brand-50); border: 1px solid var(--brand-100);">
                    <input type="hidden" name="issue_initial_card" value="0">
                    <label style="display:flex; align-items:center; gap:0.5rem; font-size:0.88rem; font-weight:700; margin-bottom:0.5rem;">
                        <input type="checkbox" name="issue_initial_card" value="1" {{ old('issue_initial_card', true) ? 'checked' : '' }}>
                        Phát hành thẻ học sinh đầu tiên ngay sau khi tạo
                    </label>
                    <input type="text" name="initial_card_code" value="{{ old('initial_card_code') }}" maxlength="16" placeholder="Mã thẻ tùy chỉnh (6-16 ký tự chữ/số, để trống để tự sinh)">
                </div>
            @endif

            <div class="inline-actions">
                <button type="submit" class="btn btn-primary">{{ $isEdit ? 'Lưu thay đổi' : 'Tạo học sinh' }}</button>
                <a href="{{ route('admin.canteen.students.index') }}" class="btn btn-secondary">Hủy</a>
            </div>
        </form>
    </div>

    @if ($isEdit)
        <div class="card">
            <h2 style="font-size: 1rem; margin-top: 0;">Thẻ học sinh đã phát hành ({{ $student->cards->count() }})</h2>
            @if ($student->cards->isNotEmpty())
                <table style="margin-bottom: 1rem;">
                    <thead>
                        <tr>
                            <th>Mã thẻ</th>
                            <th>Trạng thái</th>
                            <th>Ngày phát hành</th>
                            <th>Chi tiết</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($student->cards as $card)
                            <tr>
                                <td style="font-family: monospace; font-weight: 700;">{{ $card->card_code }}</td>
                                <td><span class="badge badge-{{ $card->status->value }}">{{ $card->status->value }}</span></td>
                                <td>{{ $card->issued_at?->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y') ?? '—' }}</td>
                                <td><a href="{{ route('admin.canteen.cards.show', $card) }}" class="btn btn-secondary" style="padding:0.25rem 0.6rem; font-size:0.78rem;">Quản lý thẻ</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            <form method="POST" action="{{ route('admin.canteen.students.cards.store', $student) }}" style="display:flex; gap:0.65rem; flex-wrap:wrap; align-items:flex-end;">
                @csrf
                <div style="flex:1; min-width:220px;">
                    <label for="card_code" style="display:block; font-size:0.8rem; font-weight:600; margin-bottom:0.25rem;">Cấp phát thẻ mới / thay thế (để trống để tự sinh)</label>
                    <input id="card_code" type="text" name="card_code" maxlength="16" placeholder="VD: B294CF (6-16 ký tự chữ/số in hoa)">
                </div>
                <button type="submit" class="btn btn-primary">+ Cấp phát thẻ mới</button>
            </form>
        </div>
    @endif
</div>
@endsection
