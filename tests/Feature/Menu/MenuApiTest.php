<?php

namespace Tests\Feature\Menu;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MenuApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_only_active_categories(): void
    {
        $c1 = Category::factory()->create(['name' => 'Cơm', 'is_active' => true]);
        $c2 = Category::factory()->create(['name' => 'Đồ uống', 'is_active' => true]);
        Category::factory()->inactive()->create(['name' => 'Ẩn']);

        $response = $this->getJson('/api/v1/categories');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $c1->id)
            ->assertJsonPath('data.0.name', 'Cơm')
            ->assertJsonPath('data.1.id', $c2->id);
    }

    public function test_lists_products_with_integer_price_and_availability(): void
    {
        $category = Category::factory()->create();
        $product = Product::factory()->for($category)->create([
            'name' => 'Cơm sườn',
            'price' => 35000,
            'is_available' => false,
        ]);

        $response = $this->getJson('/api/v1/products');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $product->id)
            ->assertJsonPath('data.0.category_id', $category->id)
            ->assertJsonPath('data.0.price', 35000)
            ->assertJsonPath('data.0.is_available', false);
    }

    public function test_filters_products_by_category(): void
    {
        $c1 = Category::factory()->create();
        $c2 = Category::factory()->create();
        $p1 = Product::factory()->for($c1)->create();
        Product::factory()->for($c2)->create();

        $response = $this->getJson("/api/v1/products?category_id={$c1->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $p1->id);
    }

    public function test_search_is_case_insensitive_with_vietnamese(): void
    {
        $category = Category::factory()->create();
        $match = Product::factory()->for($category)->create(['name' => 'Phở bò tái nạm']);
        Product::factory()->for($category)->create(['name' => 'Cơm gà xối mỡ']);

        $this->getJson('/api/v1/products?search=PHỞ')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id);

        $this->getJson('/api/v1/products?search=phở')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id);
    }

    public function test_excludes_products_of_inactive_categories(): void
    {
        $active = Category::factory()->create();
        $inactive = Category::factory()->inactive()->create();
        $visible = Product::factory()->for($active)->create();
        Product::factory()->for($inactive)->create();

        $response = $this->getJson('/api/v1/products');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id);
    }

    public function test_invalid_category_id_returns_422(): void
    {
        $this->getJson('/api/v1/products?category_id=abc')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category_id']);

        $this->getJson('/api/v1/products?category_id=999999')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category_id']);
    }

    public function test_show_returns_product(): void
    {
        $category = Category::factory()->create();
        $product = Product::factory()->for($category)->create([
            'name' => 'Bún chả',
            'price' => 45000,
        ]);

        $this->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.name', 'Bún chả')
            ->assertJsonPath('data.price', 45000);
    }

    public function test_show_returns_404_for_missing_or_inactive_category(): void
    {
        $inactive = Category::factory()->inactive()->create();
        $hiddenProduct = Product::factory()->for($inactive)->create();

        $this->getJson('/api/v1/products/999999')->assertNotFound();
        $this->getJson("/api/v1/products/{$hiddenProduct->id}")->assertNotFound();
    }
}
