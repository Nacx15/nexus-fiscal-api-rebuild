<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Clients\CreateClient;
use App\Application\Clients\UpdateClient;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use App\Models\Company;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ClientController extends Controller
{
    public function index(
        Company $company
    ): AnonymousResourceCollection {
        $clients = $company
            ->clients()
            ->orderBy('business_name')
            ->paginate(20);

        return ClientResource::collection($clients);
    }

    public function store(
        StoreClientRequest $request,
        Company $company,
        CreateClient $createClient
    ): ClientResource {
        $client = $createClient->execute(
            $request->validated()
        );

        return ClientResource::make($client);
    }

    public function show(
        Company $company,
        Client $client
    ): ClientResource {
        return ClientResource::make($client);
    }

    public function update(
        UpdateClientRequest $request,
        Company $company,
        Client $client,
        UpdateClient $updateClient
    ): ClientResource {
        $client = $updateClient->execute(
            $client,
            $request->validated()
        );

        return ClientResource::make($client);
    }
}
