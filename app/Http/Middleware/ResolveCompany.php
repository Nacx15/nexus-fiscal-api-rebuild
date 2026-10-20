<?php

namespace App\Http\Middleware;

use App\Application\Company\CompanyContext;
use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveCompany
{
    public function __construct(
        private readonly CompanyContext $companyContext
    ) {}

    public function handle(
        Request $request,
        Closure $next
    ): Response {
        /** @var Company|null $company */
        $company = $request->route('company');

        if (! $company) {
            abort(404);
        }

        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        $membershipExists = $user
            ->companies()
            ->whereKey($company->id)
            ->wherePivot('status', 'active')
            ->exists();

        if (! $membershipExists) {
            abort(403);
        }

        $this->companyContext->set($company);

        return $next($request);
    }
}
