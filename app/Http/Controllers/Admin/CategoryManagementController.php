<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CategoryManagementController extends Controller
{
    public function index(): View
    {
        $categories = Category::query()
            ->withCount('products')
            ->orderBy('id')
            ->get();

        return view('admin.categories.index', [
            'categories' => $categories,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        Category::create([
            'name' => trim($validated['name']),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return redirect('/admin/categories')->with('status', 'Đã thêm danh mục mới.');
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $category->update([
            'name' => trim($validated['name']),
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect('/admin/categories')->with('status', 'Đã cập nhật danh mục.');
    }

    public function destroy(Category $category): RedirectResponse
    {
        if ($category->products()->exists()) {
            return redirect('/admin/categories')->with(
                'error',
                'Không thể xóa danh mục đang chứa món ăn. Bạn có thể chuyển danh mục sang trạng thái tạm ẩn.',
            );
        }

        $category->delete();

        return redirect('/admin/categories')->with('status', 'Đã xóa danh mục.');
    }
}
