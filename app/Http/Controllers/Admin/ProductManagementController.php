<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductManagementController extends Controller
{
    public function index(): View
    {
        $products = Product::query()
            ->with('category')
            ->orderBy('category_id')
            ->orderBy('id')
            ->get();

        return view('admin.products.index', [
            'products' => $products,
        ]);
    }

    public function create(): View
    {
        return view('admin.products.form', [
            'product' => new Product(['is_available' => true, 'price' => 35000]),
            'categories' => Category::query()->orderBy('id')->get(),
            'isEdit' => false,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateProduct($request);

        Product::create([
            'category_id' => (int) $validated['category_id'],
            'name' => trim($validated['name']),
            'description' => ! empty($validated['description']) ? trim((string) $validated['description']) : null,
            'price' => (int) $validated['price'],
            'image_url' => ! empty($validated['image_url']) ? trim((string) $validated['image_url']) : null,
            'is_available' => $request->boolean('is_available'),
        ]);

        return redirect('/admin/products')->with('status', 'Đã thêm món ăn mới vào thực đơn.');
    }

    public function edit(Product $product): View
    {
        return view('admin.products.form', [
            'product' => $product,
            'categories' => Category::query()->orderBy('id')->get(),
            'isEdit' => true,
        ]);
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $validated = $this->validateProduct($request);

        $product->update([
            'category_id' => (int) $validated['category_id'],
            'name' => trim($validated['name']),
            'description' => ! empty($validated['description']) ? trim((string) $validated['description']) : null,
            'price' => (int) $validated['price'],
            'image_url' => ! empty($validated['image_url']) ? trim((string) $validated['image_url']) : null,
            'is_available' => $request->boolean('is_available'),
        ]);

        return redirect('/admin/products')->with('status', 'Đã cập nhật thông tin món ăn.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $hasOrders = OrderItem::query()->where('product_id', $product->id)->exists();

        if ($hasOrders) {
            return redirect('/admin/products')->with(
                'error',
                'Không thể xóa món ăn đã phát sinh trong lịch sử đơn hàng. Hãy chuyển trạng thái sang "Tạm hết món".',
            );
        }

        $product->delete();

        return redirect('/admin/products')->with('status', 'Đã xóa món ăn.');
    }

    private function validateProduct(Request $request): array
    {
        return $request->validate([
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'price' => ['required', 'integer', 'min:1000', 'max:10000000'],
            'image_url' => ['nullable', 'url', 'max:500'],
            'is_available' => ['nullable', 'boolean'],
        ]);
    }
}
