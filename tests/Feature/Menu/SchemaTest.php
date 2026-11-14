<?php

namespace Tests\Feature\Menu;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_price_is_stored_as_integer_vnd(): void
    {
        $category = Category::factory()->create();
        Product::factory()->for($category)->create(['price' => 35000]);

        $this->assertSame(35000, Product::first()->price);
    }

    public function test_category_cannot_be_deleted_while_it_has_products(): void
    {
        $category = Category::factory()->create();
        Product::factory()->for($category)->create();

        $this->expectException(QueryException::class);
        $category->delete();
    }
}
