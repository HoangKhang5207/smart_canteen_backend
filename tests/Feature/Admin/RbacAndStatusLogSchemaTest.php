<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\OrderStatusLog;
use App\Models\User;
use Database\Seeders\StaffSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RbacAndStatusLogSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_status_log_records_transition_and_links_to_order_and_actor(): void
    {
        $customer = User::factory()->create(['role' => UserRole::CUSTOMER]);
        $staff = User::factory()->create(['role' => UserRole::STAFF]);
        $order = Order::factory()->for($customer)->create([
            'status' => OrderStatus::PREPARING,
            'version' => 2,
        ]);

        $log = OrderStatusLog::create([
            'order_id' => $order->id,
            'from_status' => OrderStatus::PENDING,
            'to_status' => OrderStatus::PREPARING,
            'changed_by' => $staff->id,
        ]);

        $this->assertDatabaseHas('order_status_logs', [
            'id' => $log->id,
            'order_id' => $order->id,
            'from_status' => 'PENDING',
            'to_status' => 'PREPARING',
            'changed_by' => $staff->id,
        ]);

        $this->assertCount(1, $order->fresh()->statusLogs);
        $this->assertSame($staff->id, $order->fresh()->statusLogs->first()->actor->id);
    }

    public function test_staff_seeder_is_idempotent_and_creates_admin_and_staff_accounts(): void
    {
        $this->seed(StaffSeeder::class);
        $this->seed(StaffSeeder::class);

        $admin = User::query()->where('email', 'admin@smartcanteen.local')->first();
        $staff = User::query()->where('email', 'staff@smartcanteen.local')->first();

        $this->assertNotNull($admin);
        $this->assertSame(UserRole::ADMIN, $admin->role);
        $this->assertTrue(Hash::check('Admin@123456', (string) $admin->password));

        $this->assertNotNull($staff);
        $this->assertSame(UserRole::STAFF, $staff->role);
        $this->assertTrue(Hash::check('Staff@123456', (string) $staff->password));

        $this->assertSame(
            2,
            User::query()->whereIn('email', ['admin@smartcanteen.local', 'staff@smartcanteen.local'])->count(),
        );
    }

    public function test_role_middleware_blocks_customer_and_allows_staff_and_admin(): void
    {
        Route::middleware(['auth:sanctum', 'role:staff,admin'])
            ->get('/api/v1/_test-rbac-probe', fn () => response()->json(['ok' => true]));

        $this->getJson('/api/v1/_test-rbac-probe')->assertStatus(401);

        $customer = User::factory()->create(['role' => UserRole::CUSTOMER]);
        Sanctum::actingAs($customer);
        $this->getJson('/api/v1/_test-rbac-probe')->assertStatus(403);

        $staff = User::factory()->create(['role' => UserRole::STAFF]);
        Sanctum::actingAs($staff);
        $this->getJson('/api/v1/_test-rbac-probe')->assertOk()->assertJsonPath('ok', true);

        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/_test-rbac-probe')->assertOk()->assertJsonPath('ok', true);
    }
}
