<?php

namespace App\Application\Products;

use App\Application\Company\CompanyContext;
use App\Models\Product;

final class CreateProduct
{
    public function __construct(
        private readonly CompanyContext $companyContext
    ) {
    }

    public function execute(array $data): Product
    {
        return Product::create([
            ...$data,

            'company_id' =>
                $this->companyContext->id(),
        ]);
    }
}