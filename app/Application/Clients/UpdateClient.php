<?php

namespace App\Application\Clients;

use App\Models\Client;

final class UpdateClient
{
    public function execute(
        Client $client,
        array $data
    ): Client {
        $client->update($data);

        return $client->refresh();
    }
}