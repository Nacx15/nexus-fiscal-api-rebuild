<?php

namespace App\Application\Products;

use App\Models\Product;

final class UpdateProduct
{
    public function execute(
        Product $product,
        array $data
    ): Product {
        $product->update($data);

        return $product->refresh();
    }
}
