<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListProductsRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductController extends Controller
{
    public function index(ListProductsRequest $request): AnonymousResourceCollection
    {
        $validated = $request->validated();

        $query = Product::query()
            ->whereHas('category', fn ($q) => $q->active())
            ->orderBy('category_id')
            ->orderBy('id');

        if (! empty($validated['category_id'])) {
            $query->where('category_id', (int) $validated['category_id']);
        }

        if (isset($validated['search']) && trim($validated['search']) !== '') {
            $search = mb_strtolower(trim($validated['search']), 'UTF-8');
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
            $query->whereRaw('LOWER(name) LIKE ?', ["%{$escaped}%"]);
        }

        return ProductResource::collection($query->get());
    }

    public function show(int $id): ProductResource
    {
        $product = Product::query()
            ->whereHas('category', fn ($q) => $q->active())
            ->findOrFail($id);

        return new ProductResource($product);
    }
}
