<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Fiscal\PrepareFiscalDocument;
use App\Http\Controllers\Controller;
use App\Http\Requests\PrepareFiscalDocumentRequest;
use App\Http\Resources\FiscalDocumentResource;
use App\Models\Company;
use App\Models\Invoice;

class FiscalDocumentController extends Controller
{
    public function store(
        PrepareFiscalDocumentRequest $request,
        Company $company,
        Invoice $invoice,
        PrepareFiscalDocument $prepare
    ): FiscalDocumentResource {
        $document = $prepare->execute($invoice);

        return FiscalDocumentResource::make($document);
    }

    public function show(
        Company $company,
        Invoice $invoice
    ): FiscalDocumentResource {
        $document = $invoice->fiscalDocument()
            ->with('files')
            ->firstOrFail();

        return FiscalDocumentResource::make($document);
    }
}
