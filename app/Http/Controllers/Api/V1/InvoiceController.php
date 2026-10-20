<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Invoices\CreateInvoice;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Resources\InvoiceResource;
use App\Models\Company;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\JsonResponse;

class InvoiceController extends Controller
{
    public function index(
        Company $company
    ): AnonymousResourceCollection {
        $invoices = $company
            ->invoices()
            ->latest()
            ->paginate(20);

        return InvoiceResource::collection(
            $invoices
        );
    }

    public function store(
        StoreInvoiceRequest $request,
        Company $company,
        CreateInvoice $createInvoice
    ): JsonResponse {
        $invoice = $createInvoice->execute(
            $request->validated(),
            $request->user()
        );

        return InvoiceResource::make(
            $invoice
        )
            ->response()
            ->setStatusCode(201);
    }

    public function show(
        Company $company,
        Invoice $invoice
    ): InvoiceResource {
        $invoice->load(
            'items.taxes'
        );

        return InvoiceResource::make(
            $invoice
        );
    }
}
