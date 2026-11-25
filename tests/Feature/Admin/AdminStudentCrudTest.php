<?php

namespace Tests\Feature\Admin;

use App\Enums\BankLinkStatus;
use App\Enums\UserRole;
use App\Models\BankLink;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminStudentCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_crud_multiple_students_for_one_parent_issue_cards_and_staff_is_read_only(): void
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $staff = User::factory()->create(['role' => UserRole::STAFF]);
        $parent = User::factory()->create(['role' => UserRole::PARENT, 'phone' => '0909000111']);
        $studentAccount = User::factory()->create(['role' => UserRole::STUDENT, 'phone' => '0909000222']);

        // Staff can read list, but gets 403 on create/store
        $this->actingAs($staff)->get(route('admin.canteen.students.index'))->assertOk();
        $this->actingAs($staff)->get(route('admin.canteen.students.create'))->assertForbidden();
        $this->actingAs($staff)->post(route('admin.canteen.students.store'), [
            'user_id' => $parent->id,
            'student_code' => 'HS-FORBID-1',
            'full_name' => 'No Access',
            'school_name' => 'THCS Trần Phú',
            'class_name' => '6A1',
        ])->assertForbidden();

        // Admin creates Student 1 with initial card + linked student user account
        $this->actingAs($admin)->post(route('admin.canteen.students.store'), [
            'user_id' => $parent->id,
            'student_user_id' => $studentAccount->id,
            'student_code' => 'HS-CRUD-01',
            'full_name' => 'Trần Gia Bảo',
            'school_name' => 'THCS Trần Phú',
            'class_name' => '8A1',
            'is_active' => '1',
            'issue_initial_card' => '1',
            'initial_card_code' => 'CARD01',
        ])->assertRedirect(route('admin.canteen.students.index'));

        // Admin creates Student 2 under the SAME parent (multi-student per parent) without initial card
        $this->actingAs($admin)->post(route('admin.canteen.students.store'), [
            'user_id' => $parent->id,
            'student_code' => 'HS-CRUD-02',
            'full_name' => 'Trần Gia Hân',
            'school_name' => 'THCS Trần Phú',
            'class_name' => '6A2',
            'is_active' => '1',
            'issue_initial_card' => '0',
        ])->assertRedirect(route('admin.canteen.students.index'));

        $student1 = Student::where('student_code', 'HS-CRUD-01')->firstOrFail();
        $student2 = Student::where('student_code', 'HS-CRUD-02')->firstOrFail();

        $this->assertSame($parent->id, $student1->user_id);
        $this->assertSame($studentAccount->id, $student1->student_user_id);
        $this->assertSame($parent->id, $student2->user_id);
        $this->assertDatabaseHas('student_cards', ['student_id' => $student1->id, 'card_code' => 'CARD01']);

        // Admin updates Student 1 class & issues an additional card
        $this->actingAs($admin)->put(route('admin.canteen.students.update', $student1), [
            'user_id' => $parent->id,
            'student_user_id' => $studentAccount->id,
            'student_code' => 'HS-CRUD-01',
            'full_name' => 'Trần Gia Bảo',
            'school_name' => 'THCS Trần Phú',
            'class_name' => '9A1',
            'is_active' => '1',
        ])->assertRedirect(route('admin.canteen.students.index'));
        $this->assertSame('9A1', $student1->fresh()->class_name);

        $this->actingAs($admin)
            ->post(route('admin.canteen.students.cards.store', $student1), ['card_code' => 'CARD02'])
            ->assertRedirect()
            ->assertSessionHas('status');
        $this->assertDatabaseHas('student_cards', ['student_id' => $student1->id, 'card_code' => 'CARD02']);

        // Staff cannot issue cards or delete students
        $this->actingAs($staff)
            ->post(route('admin.canteen.students.cards.store', $student1), ['card_code' => 'CARD99'])
            ->assertForbidden();
        $this->actingAs($staff)
            ->delete(route('admin.canteen.students.destroy', $student2))
            ->assertForbidden();

        // Deleting Student 1 (has cards) is blocked cleanly
        $this->actingAs($admin)
            ->delete(route('admin.canteen.students.destroy', $student1))
            ->assertRedirect(route('admin.canteen.students.index'))
            ->assertSessionHas('error');
        $this->assertDatabaseHas('students', ['id' => $student1->id]);

        // Deleting Student 2 (no cards) succeeds
        $this->actingAs($admin)
            ->delete(route('admin.canteen.students.destroy', $student2))
            ->assertRedirect(route('admin.canteen.students.index'))
            ->assertSessionHas('status');
        $this->assertDatabaseMissing('students', ['id' => $student2->id]);

        // Case-insensitive duplicate student_code & card_code fail with validation errors (302), never 500
        $this->actingAs($admin)->post(route('admin.canteen.students.store'), [
            'user_id' => $parent->id,
            'student_code' => 'hs-crud-01',
            'full_name' => 'Duplicate Case Test',
            'school_name' => 'THCS Trần Phú',
            'class_name' => '8A1',
        ])->assertSessionHasErrors('student_code');

        $this->actingAs($admin)
            ->post(route('admin.canteen.students.cards.store', $student1), ['card_code' => 'card01'])
            ->assertSessionHasErrors('card_code');

        // Parent transfer is blocked if any card has an ACTIVE bank link
        $otherParent = User::factory()->create(['role' => UserRole::PARENT, 'phone' => '0909000333']);
        $card1 = $student1->cards()->where('card_code', 'CARD01')->firstOrFail();
        BankLink::create([
            'student_card_id' => $card1->id,
            'user_id' => $parent->id,
            'bank_code' => 'MB',
            'bank_name' => 'MB Bank',
            'account_number_encrypted' => '0987654321111',
            'account_number_masked' => '****1111',
            'account_holder_name' => 'PHU HUYNH 1',
            'national_id_encrypted' => '079090001234',
            'national_id_masked' => '********1234',
            'phone_number' => '0909000111',
            'phone_masked' => '090***0111',
            'auto_debit_agreed' => true,
            'auto_debit_agreed_at' => now(),
            'provider_reference' => 'EPAY-SBX-GUARD-1',
            'status' => BankLinkStatus::ACTIVE,
            'linked_at' => now(),
        ]);

        $this->actingAs($admin)->put(route('admin.canteen.students.update', $student1), [
            'user_id' => $otherParent->id,
            'student_user_id' => $studentAccount->id,
            'student_code' => 'HS-CRUD-01',
            'full_name' => 'Trần Gia Bảo',
            'school_name' => 'THCS Trần Phú',
            'class_name' => '9A1',
            'is_active' => '1',
        ])->assertSessionHas('error');

        $this->assertSame($parent->id, $student1->fresh()->user_id);
    }
}
