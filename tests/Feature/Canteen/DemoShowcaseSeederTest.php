<?php

namespace Tests\Feature\Canteen;

use App\Models\BankLink;
use App\Models\CanteenNotification;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\ReconciliationBatch;
use App\Models\SandboxBankAccount;
use App\Models\Student;
use App\Models\StudentCard;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DemoShowcaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_showcase_seeder_is_idempotent_and_preserves_clean_today_stats(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(4, Student::query()->count());
        $this->assertSame(4, StudentCard::query()->count());
        $this->assertSame(2, BankLink::query()->count());
        $this->assertSame(2, SandboxBankAccount::query()->count());
        $this->assertSame(2, Order::query()->count());
        $this->assertSame(3, PaymentTransaction::query()->count());
        $this->assertSame(1, ReconciliationBatch::query()->count());
        $this->assertSame(4, CanteenNotification::query()->count());

        // Card 1 (F156BA) is pre-linked with 500k sandbox balance & 100k limit; Card 2 (B294CD) is unlinked for live OTP demo
        $card1 = StudentCard::query()->where('card_code', 'F156BA')->firstOrFail();
        $card2 = StudentCard::query()->where('card_code', 'B294CD')->firstOrFail();
        $card3 = StudentCard::query()->where('card_code', 'E741MN')->firstOrFail();
        $card4 = StudentCard::query()->where('card_code', 'L003KK')->firstOrFail();

        $this->assertTrue($card1->activeBankLink()->exists());
        $this->assertFalse($card2->activeBankLink()->exists());
        $this->assertSame(15000, SandboxBankAccount::query()->where('provider_reference', 'SBX-DEMO-BIDV-E741MN')->value('balance'));
        $this->assertSame('LOCKED', $card4->status->value);

        // Today's statistics start cleanly at 0 because seeded orders are dated yesterday
        $admin = User::query()->where('email', 'admin@smartcanteen.local')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/statistics')
            ->assertOk()
            ->assertJsonPath('data.today_total_orders', 0)
            ->assertJsonPath('data.today_completed_revenue', 0);

        // Verify Slice P4 Minor M-4 admin filter indexes exist in PostgreSQL
        $indexes = collect(DB::select("SELECT indexname FROM pg_indexes WHERE tablename = 'canteen_notifications'"))
            ->pluck('indexname')
            ->all();

        $this->assertContains('canteen_notifications_push_status_id_idx', $indexes);
        $this->assertContains('canteen_notifications_type_id_idx', $indexes);
        $this->assertContains('canteen_notifications_read_at_idx', $indexes);
    }
}
