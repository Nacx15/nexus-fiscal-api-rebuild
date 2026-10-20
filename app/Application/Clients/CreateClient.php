<?php

namespace App\Application\Clients;

use App\Application\Company\CompanyContext;
use App\Models\Client;

final class CreateClient
{
    public function __construct(
        private readonly CompanyContext $companyContext
    ) {}

    public function execute(array $data): Client
    {
        return Client::create([
            ...$data,

            'company_id' => $this->companyContext->id(),
        ]);
    }
}
