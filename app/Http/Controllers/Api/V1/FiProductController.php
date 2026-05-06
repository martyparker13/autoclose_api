<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\FiProduct\StoreFiProductRequest;
use App\Http\Requests\FiProduct\UpdateFiProductRequest;
use App\Http\Resources\FiProductResource;
use App\Models\FiProduct;
use Illuminate\Http\JsonResponse;

class FiProductController extends BaseController
{
    /**
     * List all active F&I products for the current dealer.
     */
    public function index(): JsonResponse
    {
        $dealer   = app('current_dealer');
        $products = FiProduct::where('dealer_id', $dealer->id)
            ->where('is_active', true)
            ->orderBy('type')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => FiProductResource::collection($products)]);
    }

    /**
     * List all F&I products (including inactive) for dealer staff management.
     */
    public function adminIndex(): JsonResponse
    {
        $dealer   = app('current_dealer');
        $products = FiProduct::where('dealer_id', $dealer->id)
            ->orderBy('type')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => FiProductResource::collection($products)]);
    }

    /**
     * Create a new F&I product for the current dealer.
     */
    public function store(StoreFiProductRequest $request): JsonResponse
    {
        $dealer  = app('current_dealer');
        $product = FiProduct::create(
            array_merge($request->validated(), ['dealer_id' => $dealer->id])
        );

        return $this->resourceResponse(new FiProductResource($product), 201);
    }

    /**
     * Show a single F&I product.
     */
    public function show(int $fiProduct): JsonResponse
    {
        $dealer  = app('current_dealer');
        $product = FiProduct::where('dealer_id', $dealer->id)
            ->findOrFail($fiProduct);

        return $this->resourceResponse(new FiProductResource($product));
    }

    /**
     * Update an existing F&I product.
     */
    public function update(UpdateFiProductRequest $request, int $fiProduct): JsonResponse
    {
        $dealer  = app('current_dealer');
        $product = FiProduct::where('dealer_id', $dealer->id)
            ->findOrFail($fiProduct);

        $product->update($request->validated());

        return $this->resourceResponse(new FiProductResource($product));
    }

    /**
     * Delete an F&I product.
     */
    public function destroy(int $fiProduct): JsonResponse
    {
        $dealer  = app('current_dealer');
        $product = FiProduct::where('dealer_id', $dealer->id)
            ->findOrFail($fiProduct);

        $product->delete();

        return $this->noContent();
    }
}
