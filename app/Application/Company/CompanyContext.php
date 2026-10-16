<?php

namespace App\Application\Company;

use App\Models\Company;
use LogicException;

final class CompanyContext
{
    private ?Company $company = null;

    public function set(Company $company): void
    {
        $this->company = $company;
    }

    public function get(): Company
    {
        if ($this->company === null) {
            throw new LogicException(
                'Company context has not been initialized.'
            );
        }

        return $this->company;
    }

    public function id(): int
    {
        return $this->get()->id;
    }

    public function clear(): void
    {
        $this->company = null;
    }
}