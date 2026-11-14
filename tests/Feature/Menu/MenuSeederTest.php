<?php

namespace Tests\Feature\Menu;

use App\Models\Category;
use App\Models\Product;
use Database\Seeders\MenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MenuSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_menu_seeder_creates_at_least_3_categories_and_10_products(): void
    {
        $this->seed(MenuSeeder::class);

        $this->assertGreaterThanOrEqual(3, Category::count());
        $this->assertGreaterThanOrEqual(10, Product::count());

        Product::all()->each(function (Product $product): void {
            $this->assertGreaterThan(0, $product->price);
            $this->assertSame(0, $product->price % 1000);
        });
    }

    public function test_menu_seeder_is_idempotent(): void
    {
        $this->seed(MenuSeeder::class);
        $firstCategories = Category::count();
        $firstProducts = Product::count();

        $this->seed(MenuSeeder::class);

        $this->assertSame($firstCategories, Category::count());
        $this->assertSame($firstProducts, Product::count());
    }
}
