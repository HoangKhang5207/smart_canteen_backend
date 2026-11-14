<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminWebPortalTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_renders_and_authenticates_staff_or_admin_while_rejecting_customer(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/admin/login')->assertOk()->assertSee('Smart Canteen');

        $customer = User::factory()->create([
            'email' => 'customer@smartcanteen.local',
            'password' => 'Secret@123',
            'role' => UserRole::CUSTOMER,
        ]);

        $this->post('/admin/login', [
            'email' => $customer->email,
            'password' => 'Secret@123',
        ])->assertStatus(403);
        $this->assertGuest();

        $staff = User::factory()->create([
            'email' => 'staff@smartcanteen.local',
            'password' => 'Staff@123456',
            'role' => UserRole::STAFF,
        ]);

        $this->post('/admin/login', [
            'email' => $staff->email,
            'password' => 'Staff@123456',
        ])->assertRedirect('/admin');

        $this->assertAuthenticatedAs($staff);

        $this->post('/admin/logout')->assertRedirect('/admin/login');
        $this->assertGuest();
    }

    public function test_dashboard_and_order_dispatch_board_render_today_stats_and_transition_order_status_with_version_check(): void
    {
        $staff = User::factory()->create(['role' => UserRole::STAFF, 'name' => 'Nhân viên Bếp']);
        $customer = User::factory()->create(['name' => 'Trần Thị B']);

        $order = Order::factory()->for($customer)->create([
            'code' => 'SC-261114-000501',
            'status' => OrderStatus::PENDING,
            'total_amount' => 75000,
            'version' => 1,
        ]);

        $this->actingAs($staff)
            ->get('/admin')
            ->assertOk()
            ->assertSee('SC-261114-000501')
            ->assertSee('75.000');

        $this->actingAs($staff)
            ->get('/admin/orders?status=PENDING')
            ->assertOk()
            ->assertSee('SC-261114-000501')
            ->assertSee('Trần Thị B');

        $this->actingAs($staff)
            ->get("/admin/orders/{$order->id}")
            ->assertOk()
            ->assertSee('SC-261114-000501');

        // Valid transition via Blade form with version = 1
        $this->actingAs($staff)
            ->from("/admin/orders/{$order->id}")
            ->patch("/admin/orders/{$order->id}/status", [
                'status' => 'PREPARING',
                'version' => 1,
            ])
            ->assertRedirect("/admin/orders/{$order->id}")
            ->assertSessionHas('status');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'PREPARING',
            'version' => 2,
        ]);
        $this->assertDatabaseHas('order_status_logs', [
            'order_id' => $order->id,
            'from_status' => 'PENDING',
            'to_status' => 'PREPARING',
            'changed_by' => $staff->id,
        ]);

        // Stale version = 1 flashes error and does not mutate order
        $this->actingAs($staff)
            ->from("/admin/orders/{$order->id}")
            ->patch("/admin/orders/{$order->id}/status", [
                'status' => 'READY',
                'version' => 1,
            ])
            ->assertRedirect("/admin/orders/{$order->id}")
            ->assertSessionHas('error');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'PREPARING',
            'version' => 2,
        ]);
    }

    public function test_category_crud_creates_updates_and_prevents_deleting_category_with_products(): void
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);

        $this->actingAs($admin)
            ->post('/admin/categories', [
                'name' => 'Món Chay',
                'is_active' => '1',
            ])
            ->assertRedirect('/admin/categories')
            ->assertSessionHas('status');

        $category = Category::query()->where('name', 'Món Chay')->firstOrFail();
        $this->assertTrue($category->is_active);

        $this->actingAs($admin)
            ->put("/admin/categories/{$category->id}", [
                'name' => 'Món Chay Đặc Biệt',
                'is_active' => '0',
            ])
            ->assertRedirect('/admin/categories');

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'name' => 'Món Chay Đặc Biệt',
            'is_active' => false,
        ]);

        Product::factory()->for($category)->create(['name' => 'Cơm chay hạt sen']);

        // Cannot delete category while it still has products
        $this->actingAs($admin)
            ->delete("/admin/categories/{$category->id}")
            ->assertRedirect('/admin/categories')
            ->assertSessionHas('error');

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    public function test_product_crud_creates_updates_price_and_availability_without_mutating_past_orders_and_prevents_deleting_ordered_product(): void
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $customer = User::factory()->create();
        $category = Category::factory()->create(['name' => 'Cơm Trưa', 'is_active' => true]);

        $this->actingAs($admin)
            ->post('/admin/products', [
                'category_id' => $category->id,
                'name' => 'Cơm gà xối mỡ',
                'description' => 'Đùi gà góc tư giòn rụm',
                'price' => 45000,
                'image_url' => 'https://example.com/com-ga.jpg',
                'is_available' => '1',
            ])
            ->assertRedirect('/admin/products')
            ->assertSessionHas('status');

        $product = Product::query()->where('name', 'Cơm gà xối mỡ')->firstOrFail();
        $this->assertSame(45000, $product->price);

        // Historical order referencing this product at 45,000 VND
        $order = Order::factory()->for($customer)->create([
            'total_amount' => 90000,
            'status' => OrderStatus::COMPLETED,
        ]);
        $orderItem = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_price' => 45000,
            'subtotal' => 90000,
        ]);

        // Admin updates product price to 55,000 and marks it unavailable
        $this->actingAs($admin)
            ->put("/admin/products/{$product->id}", [
                'category_id' => $category->id,
                'name' => 'Cơm gà xối mỡ đặc biệt',
                'description' => 'Đùi gà góc tư + trứng ốp la',
                'price' => 55000,
                'image_url' => 'https://example.com/com-ga.jpg',
                'is_available' => '0',
            ])
            ->assertRedirect('/admin/products')
            ->assertSessionHas('status');

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'price' => 55000,
            'is_available' => false,
        ]);

        // Historical order item & total remain untouched (AC-11)
        $this->assertDatabaseHas('order_items', [
            'id' => $orderItem->id,
            'unit_price' => 45000,
            'subtotal' => 90000,
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'total_amount' => 90000,
        ]);

        // Deleting a product that is referenced in order_items is blocked gracefully
        $this->actingAs($admin)
            ->delete("/admin/products/{$product->id}")
            ->assertRedirect('/admin/products')
            ->assertSessionHas('error');

        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_authenticated_customer_visiting_admin_login_is_logged_out_and_sees_login_form(): void
    {
        $customer = User::factory()->create([
            'role' => UserRole::CUSTOMER,
        ]);

        $response = $this->actingAs($customer)->get('/admin/login');

        $response->assertOk()->assertSee('Smart Canteen Portal');
        $this->assertGuest();
    }

    public function test_staff_can_view_menu_lists_but_only_admin_can_mutate_categories_and_products(): void
    {
        $staff = User::factory()->create(['role' => UserRole::STAFF]);
        $category = Category::factory()->create(['name' => 'Đồ uống', 'is_active' => true]);
        $product = Product::factory()->for($category)->create([
            'name' => 'Trà đào cam sả',
            'price' => 30000,
        ]);

        // Staff can view categories and products lists
        $this->actingAs($staff)->get('/admin/categories')->assertOk()->assertSee('Đồ uống');
        $this->actingAs($staff)->get('/admin/products')->assertOk()->assertSee('Trà đào cam sả');

        // Staff is forbidden from creating/updating/deleting categories or products
        $this->actingAs($staff)->post('/admin/categories', ['name' => 'Mới'])->assertStatus(403);
        $this->actingAs($staff)->put("/admin/categories/{$category->id}", ['name' => 'Đổi'])->assertStatus(403);
        $this->actingAs($staff)->delete("/admin/categories/{$category->id}")->assertStatus(403);

        $this->actingAs($staff)->get('/admin/products/create')->assertStatus(403);
        $this->actingAs($staff)->post('/admin/products', [
            'category_id' => $category->id,
            'name' => 'Món mới',
            'price' => 25000,
        ])->assertStatus(403);
        $this->actingAs($staff)->get("/admin/products/{$product->id}/edit")->assertStatus(403);
        $this->actingAs($staff)->put("/admin/products/{$product->id}", [
            'category_id' => $category->id,
            'name' => 'Trà đào sửa',
            'price' => 35000,
        ])->assertStatus(403);
        $this->actingAs($staff)->delete("/admin/products/{$product->id}")->assertStatus(403);
    }
}
