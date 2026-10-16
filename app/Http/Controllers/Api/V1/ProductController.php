<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Products\CreateProduct;
use App\Application\Products\UpdateProduct;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Company;
use App\Models\Product;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductController extends Controller
{
    public function index(
        Company $company
    ): AnonymousResourceCollection {
        $products = $company
            ->products()
            ->orderBy('description')
            ->paginate(20);

        return ProductResource::collection(
            $products
        );
    }

    public function store(
        StoreProductRequest $request,
        Company $company,
        CreateProduct $createProduct
    ): ProductResource {
        $product = $createProduct->execute(
            $request->validated()
        );

        return ProductResource::make(
            $product
        );
    }

    public function show(
        Company $company,
        Product $product
    ): ProductResource {
        return ProductResource::make(
            $product
        );
    }

    public function update(
        UpdateProductRequest $request,
        Company $company,
        Product $product,
        UpdateProduct $updateProduct
    ): ProductResource {
        $product = $updateProduct->execute(
            $product,
            $request->validated()
        );

        return ProductResource::make(
            $product
        );
    }
}