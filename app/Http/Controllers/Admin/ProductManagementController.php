<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProductManagementController extends Controller
{
    public function index(Request $request): View
    {
        $categoryId = (int) $request->query('category_id', 0);
        $search = trim((string) $request->query('search', ''));
        $stockFilter = strtolower(trim((string) $request->query('stock_filter', '')));
        $requestedPerPage = (int) $request->query('per_page', 10);
        $perPage = $requestedPerPage >= 1 && $requestedPerPage <= 100 ? $requestedPerPage : 10;

        $query = Product::query()
            ->with('category')
            ->orderBy('category_id')
            ->orderBy('id');

        if ($categoryId > 0) {
            $query->where('category_id', $categoryId);
        }

        if ($stockFilter === 'low') {
            $query->whereNotNull('stock_quantity')
                ->where('stock_quantity', '>', 0)
                ->whereColumn('stock_quantity', '<=', 'low_stock_threshold');
        } elseif ($stockFilter === 'out') {
            $query->where(function ($sub): void {
                $sub->where('is_available', false)
                    ->orWhere('stock_quantity', 0);
            });
        } elseif ($stockFilter === 'unlimited') {
            $query->whereNull('stock_quantity');
        } else {
            $stockFilter = '';
        }

        if ($search !== '') {
            $normalized = mb_strtolower($search, 'UTF-8');
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $normalized);
            $query->whereRaw('LOWER(name) LIKE ?', ["%{$escaped}%"]);
        }

        $lowStockCount = Product::query()
            ->whereNotNull('stock_quantity')
            ->where('stock_quantity', '>', 0)
            ->whereColumn('stock_quantity', '<=', 'low_stock_threshold')
            ->count();
        $outOfStockCount = Product::query()
            ->where(function ($sub): void {
                $sub->where('is_available', false)->orWhere('stock_quantity', 0);
            })
            ->count();

        return view('admin.products.index', [
            'products' => $query->paginate($perPage)->withQueryString(),
            'categories' => Category::query()->orderBy('id')->get(),
            'selectedCategoryId' => $categoryId > 0 ? $categoryId : null,
            'search' => $search,
            'stockFilter' => $stockFilter,
            'perPage' => $perPage,
            'inventoryStats' => [
                'low_stock' => $lowStockCount,
                'out_of_stock' => $outOfStockCount,
            ],
        ]);
    }

    public function create(): View
    {
        return view('admin.products.form', [
            'product' => new Product([
                'is_available' => true,
                'price' => 35000,
                'stock_quantity' => null,
                'low_stock_threshold' => 5,
            ]),
            'categories' => Category::query()->orderBy('id')->get(),
            'isEdit' => false,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateProduct($request);
        $stockQty = array_key_exists('stock_quantity', $validated) && $validated['stock_quantity'] !== null && $validated['stock_quantity'] !== ''
            ? (int) $validated['stock_quantity']
            : null;

        Product::create([
            'category_id' => (int) $validated['category_id'],
            'name' => trim($validated['name']),
            'description' => ! empty($validated['description']) ? trim((string) $validated['description']) : null,
            'price' => (int) $validated['price'],
            'image_url' => ! empty($validated['image_url']) ? trim((string) $validated['image_url']) : null,
            'is_available' => $request->boolean('is_available'),
            'stock_quantity' => $stockQty,
            'low_stock_threshold' => isset($validated['low_stock_threshold']) ? (int) $validated['low_stock_threshold'] : 5,
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
        $stockQty = array_key_exists('stock_quantity', $validated) && $validated['stock_quantity'] !== null && $validated['stock_quantity'] !== ''
            ? (int) $validated['stock_quantity']
            : null;
        $hasOriginalSnapshot = $request->has('original_stock_quantity');
        $rawOriginal = $validated['original_stock_quantity'] ?? null;
        $originalStockQty = ($rawOriginal !== null && $rawOriginal !== '') ? (int) $rawOriginal : null;
        $stockExplicitlyChanged = ! $hasOriginalSnapshot || $stockQty !== $originalStockQty;

        DB::transaction(function () use ($request, $product, $validated, $stockQty, $stockExplicitlyChanged): void {
            /** @var Product $locked */
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            $updates = [
                'category_id' => (int) $validated['category_id'],
                'name' => trim($validated['name']),
                'description' => ! empty($validated['description']) ? trim((string) $validated['description']) : null,
                'price' => (int) $validated['price'],
                'image_url' => ! empty($validated['image_url']) ? trim((string) $validated['image_url']) : null,
                'is_available' => $request->boolean('is_available'),
                'low_stock_threshold' => isset($validated['low_stock_threshold']) ? (int) $validated['low_stock_threshold'] : 5,
            ];

            // Avoid overwriting concurrent order stock decrements when Admin only edits metadata
            if ($stockExplicitlyChanged) {
                $updates['stock_quantity'] = $stockQty;
            }

            $locked->update($updates);
        });

        return redirect('/admin/products')->with('status', 'Đã cập nhật thông tin món ăn.');
    }

    public function updateStock(Request $request, Product $product): RedirectResponse
    {
        $validated = $request->validate([
            'mode' => ['required', 'string', Rule::in(['set', 'add', 'unlimited'])],
            'quantity' => ['required_if:mode,set,add', 'nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        $mode = (string) $validated['mode'];
        $quantity = isset($validated['quantity']) ? (int) $validated['quantity'] : 0;

        if ($mode === 'add' && $quantity < 1) {
            return back()->withErrors(['quantity' => 'Số lượng nhập thêm phải lớn hơn hoặc bằng 1.']);
        }

        $newStock = DB::transaction(function () use ($product, $mode, $quantity): ?int {
            /** @var Product $locked */
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            if ($mode === 'unlimited') {
                $locked->update(['stock_quantity' => null]);

                return null;
            }

            $next = $mode === 'add'
                ? min(100000, ((int) ($locked->stock_quantity ?? 0)) + $quantity)
                : $quantity;

            $locked->update(['stock_quantity' => $next]);

            return $next;
        });

        $label = $newStock === null ? 'Không giới hạn' : $newStock.' phần';

        return back()->with('status', sprintf('Đã cập nhật tồn kho "%s" thành %s.', $product->name, $label));
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
            'stock_quantity' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'original_stock_quantity' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'low_stock_threshold' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'image_url' => [
                'nullable',
                'string',
                'max:500',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null || trim((string) $value) === '') {
                        return;
                    }
                    $trimmed = trim((string) $value);
                    $isHttpUrl = filter_var($trimmed, FILTER_VALIDATE_URL) !== false
                        && preg_match('/^https?:\/\//i', $trimmed) === 1;
                    $isLocalImagePath = preg_match('/^\/images\/[a-zA-Z0-9_\-\/\.]+\.(jpg|jpeg|png|webp|svg)$/i', $trimmed) === 1
                        && ! str_contains($trimmed, '..');

                    if (! $isHttpUrl && ! $isLocalImagePath) {
                        $fail('Đường dẫn hình ảnh phải là URL hợp lệ (http/https) hoặc đường dẫn nội bộ /images/products/<ten-file>.jpg.');
                    }
                },
            ],
            'is_available' => ['nullable', 'boolean'],
        ]);
    }
}
