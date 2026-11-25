<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BankLinkStatus;
use App\Enums\StudentCardStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\BankLink;
use App\Models\Student;
use App\Models\StudentCard;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class StudentManagementController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $activeFilter = trim((string) $request->query('active', ''));

        $query = Student::query()
            ->with(['user:id,name,phone,role', 'studentUser:id,name,phone,role', 'cards' => fn ($q) => $q->orderByDesc('id')])
            ->orderByDesc('id');

        if ($activeFilter === '1') {
            $query->where('is_active', true);
        } elseif ($activeFilter === '0') {
            $query->where('is_active', false);
        } else {
            $activeFilter = '';
        }

        if ($search !== '') {
            $normalized = mb_strtolower($search, 'UTF-8');
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $normalized);
            $needle = "%{$escaped}%";
            $query->where(function ($sub) use ($needle): void {
                $sub->whereRaw('LOWER(full_name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(student_code) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(class_name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(school_name) LIKE ?', [$needle])
                    ->orWhereHas('user', function ($uq) use ($needle): void {
                        $uq->whereRaw('LOWER(name) LIKE ?', [$needle])
                            ->orWhereRaw('LOWER(COALESCE(phone, \'\')) LIKE ?', [$needle]);
                    });
            });
        }

        return view('admin.canteen.students.index', [
            'students' => $query->paginate(12)->withQueryString(),
            'search' => $search,
            'activeFilter' => $activeFilter,
            'stats' => [
                'total' => Student::query()->count(),
                'active' => Student::query()->where('is_active', true)->count(),
                'with_student_account' => Student::query()->whereNotNull('student_user_id')->count(),
            ],
        ]);
    }

    public function create(): View
    {
        return view('admin.canteen.students.form', [
            'student' => new Student(['is_active' => true, 'school_name' => 'THCS Trần Phú']),
            'parents' => $this->listParentUsers(),
            'studentUsers' => $this->listAvailableStudentUsers(),
            'isEdit' => false,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateStudent($request);

        $created = DB::transaction(function () use ($request, $validated): array {
            $student = Student::create([
                'user_id' => (int) $validated['user_id'],
                'student_user_id' => ! empty($validated['student_user_id']) ? (int) $validated['student_user_id'] : null,
                'student_code' => (string) $validated['student_code'],
                'full_name' => trim((string) $validated['full_name']),
                'school_name' => trim((string) $validated['school_name']),
                'class_name' => trim((string) $validated['class_name']),
                'is_active' => $request->boolean('is_active', true),
            ]);

            $issuedCard = null;
            if ($request->boolean('issue_initial_card')) {
                $cardCode = ! empty($validated['initial_card_code'])
                    ? (string) $validated['initial_card_code']
                    : $this->generateUniqueCardCode();

                $issuedCard = StudentCard::create([
                    'student_id' => $student->id,
                    'card_code' => $cardCode,
                    'status' => StudentCardStatus::ACTIVE,
                    'issued_at' => now(),
                ]);
            }

            return ['student' => $student, 'card' => $issuedCard];
        });

        $message = $created['card'] !== null
            ? sprintf('Đã tạo học sinh %s và phát hành thẻ %s.', $created['student']->full_name, $created['card']->card_code)
            : sprintf('Đã tạo hồ sơ học sinh %s.', $created['student']->full_name);

        return redirect()->route('admin.canteen.students.index')->with('status', $message);
    }

    public function edit(Student $student): View
    {
        $student->load(['cards' => fn ($q) => $q->orderByDesc('id')]);

        return view('admin.canteen.students.form', [
            'student' => $student,
            'parents' => $this->listParentUsers(),
            'studentUsers' => $this->listAvailableStudentUsers($student->student_user_id),
            'isEdit' => true,
        ]);
    }

    public function update(Request $request, Student $student): RedirectResponse
    {
        $validated = $this->validateStudent($request, $student);

        $errorMessage = DB::transaction(function () use ($request, $student, $validated): ?string {
            /** @var Student $lockedStudent */
            $lockedStudent = Student::query()
                ->whereKey($student->id)
                ->lockForUpdate()
                ->firstOrFail();

            $newParentId = (int) $validated['user_id'];
            if ($newParentId !== (int) $lockedStudent->user_id) {
                $cardIds = $lockedStudent->cards()->lockForUpdate()->pluck('id');
                if ($cardIds->isNotEmpty()) {
                    $hasOpenBankLink = BankLink::query()
                        ->whereIn('student_card_id', $cardIds)
                        ->whereIn('status', [
                            BankLinkStatus::ACTIVE->value,
                            BankLinkStatus::PENDING_OTP->value,
                        ])
                        ->lockForUpdate()
                        ->exists();

                    if ($hasOpenBankLink) {
                        return 'Không thể chuyển phụ huynh khi thẻ học sinh vẫn đang có liên kết ngân hàng đang hoạt động hoặc chờ OTP. Vui lòng hủy liên kết ngân hàng / thu hồi thẻ trước.';
                    }
                }
            }

            $lockedStudent->update([
                'user_id' => $newParentId,
                'student_user_id' => ! empty($validated['student_user_id']) ? (int) $validated['student_user_id'] : null,
                'student_code' => (string) $validated['student_code'],
                'full_name' => trim((string) $validated['full_name']),
                'school_name' => trim((string) $validated['school_name']),
                'class_name' => trim((string) $validated['class_name']),
                'is_active' => $request->boolean('is_active'),
            ]);

            return null;
        });

        if ($errorMessage !== null) {
            return back()->withInput()->with('error', $errorMessage);
        }

        return redirect()->route('admin.canteen.students.index')
            ->with('status', sprintf('Đã cập nhật hồ sơ học sinh %s.', $student->fresh()->full_name));
    }

    public function issueCard(Request $request, Student $student): RedirectResponse
    {
        if ($request->filled('card_code')) {
            $request->merge([
                'card_code' => strtoupper(trim((string) $request->input('card_code'))),
            ]);
        }

        $validated = $request->validate([
            'card_code' => [
                'nullable',
                'string',
                'min:6',
                'max:16',
                'regex:/^[A-Z0-9]+$/',
                Rule::unique('student_cards', 'card_code'),
            ],
        ]);

        $result = DB::transaction(function () use ($student, $validated): array {
            /** @var Student|null $lockedStudent */
            $lockedStudent = Student::query()
                ->whereKey($student->id)
                ->lockForUpdate()
                ->first();

            if ($lockedStudent === null) {
                return ['error' => 'Hồ sơ học sinh không tồn tại.', 'card' => null];
            }

            if (! $lockedStudent->is_active) {
                return ['error' => 'Không thể cấp phát thẻ cho học sinh đang ở trạng thái ngừng hoạt động.', 'card' => null];
            }

            $cardCode = ! empty($validated['card_code'])
                ? (string) $validated['card_code']
                : $this->generateUniqueCardCode();

            $card = StudentCard::create([
                'student_id' => $lockedStudent->id,
                'card_code' => $cardCode,
                'status' => StudentCardStatus::ACTIVE,
                'issued_at' => now(),
            ]);

            return ['error' => null, 'card' => $card, 'student_name' => $lockedStudent->full_name];
        });

        if ($result['error'] !== null) {
            return back()->with('error', $result['error']);
        }

        return back()->with('status', sprintf('Đã cấp phát thẻ học sinh %s cho %s.', $result['card']->card_code, $result['student_name']));
    }

    public function destroy(Student $student): RedirectResponse
    {
        $result = DB::transaction(function () use ($student): array {
            /** @var Student|null $lockedStudent */
            $lockedStudent = Student::query()
                ->whereKey($student->id)
                ->lockForUpdate()
                ->first();

            if ($lockedStudent === null) {
                return ['deleted' => false, 'message' => 'Hồ sơ học sinh không tồn tại.'];
            }

            if ($lockedStudent->cards()->lockForUpdate()->exists()) {
                return [
                    'deleted' => false,
                    'message' => 'Không thể xóa học sinh đã phát hành thẻ. Hãy chuyển trạng thái sang "Ngừng hoạt động".',
                ];
            }

            $name = $lockedStudent->full_name;
            $lockedStudent->delete();

            return ['deleted' => true, 'message' => sprintf('Đã xóa hồ sơ học sinh %s.', $name)];
        });

        return redirect()->route('admin.canteen.students.index')
            ->with($result['deleted'] ? 'status' : 'error', $result['message']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateStudent(Request $request, ?Student $existing = null): array
    {
        $merge = [];
        if ($request->has('student_code')) {
            $merge['student_code'] = strtoupper(trim((string) $request->input('student_code')));
        }
        if ($request->filled('initial_card_code')) {
            $merge['initial_card_code'] = strtoupper(trim((string) $request->input('initial_card_code')));
        }
        if ($merge !== []) {
            $request->merge($merge);
        }

        return $request->validate([
            'user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(fn ($q) => $q->whereIn('role', [
                    UserRole::PARENT->value,
                    UserRole::CUSTOMER->value,
                ])),
            ],
            'student_user_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(fn ($q) => $q->where('role', UserRole::STUDENT->value)),
                Rule::unique('students', 'student_user_id')->ignore($existing?->id),
            ],
            'student_code' => [
                'required',
                'string',
                'max:40',
                Rule::unique('students', 'student_code')->ignore($existing?->id),
            ],
            'full_name' => ['required', 'string', 'max:120'],
            'school_name' => ['required', 'string', 'max:150'],
            'class_name' => ['required', 'string', 'max:40'],
            'is_active' => ['nullable', 'boolean'],
            'issue_initial_card' => ['nullable', 'boolean'],
            'initial_card_code' => [
                'nullable',
                'string',
                'min:6',
                'max:16',
                'regex:/^[A-Z0-9]+$/',
                Rule::unique('student_cards', 'card_code'),
            ],
        ]);
    }

    private function listParentUsers()
    {
        return User::query()
            ->whereIn('role', [UserRole::PARENT->value, UserRole::CUSTOMER->value])
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'phone', 'zalo_user_id', 'role']);
    }

    private function listAvailableStudentUsers(?int $includeUserId = null)
    {
        $assignedIds = Student::query()
            ->whereNotNull('student_user_id')
            ->when($includeUserId !== null, fn ($q) => $q->where('student_user_id', '!=', $includeUserId))
            ->pluck('student_user_id');

        return User::query()
            ->where('role', UserRole::STUDENT->value)
            ->when($assignedIds->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $assignedIds))
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'phone', 'zalo_user_id']);
    }

    private function generateUniqueCardCode(): string
    {
        for ($i = 0; $i < 10; $i++) {
            $candidate = strtoupper(bin2hex(random_bytes(3)));
            if (! StudentCard::query()->where('card_code', $candidate)->exists()) {
                return $candidate;
            }
        }

        return Str::upper(Str::random(8));
    }
}
