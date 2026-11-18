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

    public function test_menu_seeder_creates_6_categories_and_30_products_with_standardized_image_paths(): void
    {
        $this->seed(MenuSeeder::class);

        $this->assertSame(6, Category::count());
        $this->assertSame(30, Product::count());

        Product::all()->each(function (Product $product): void {
            $this->assertGreaterThan(0, $product->price);
            $this->assertSame(0, $product->price % 1000);
            $this->assertNotNull($product->image_url);
            $this->assertMatchesRegularExpression(
                '/^\/images\/products\/[a-z0-9\-]+\.jpg$/',
                (string) $product->image_url,
            );
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
