<?php

namespace Tests\Feature;

use App\Application\Company\CompanyContext;
use App\Application\Fiscal\FiscalSequenceService;
use App\Domain\Fiscal\Enums\FiscalDocumentType;
use App\Models\Company;
use App\Models\FiscalSequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiscalSequenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_consecutive_folios(): void
    {
        $company = Company::factory()->create();

        FiscalSequence::create([
            'company_id' => $company->id,
            'document_type' => 'invoice',
            'series' => 'F',
            'current_number' => 0,
            'padding' => 6,
            'active' => true,
        ]);

        $context = app(CompanyContext::class);
        $context->set($company);

        $service = app(FiscalSequenceService::class);

        $first = $service->next(
            FiscalDocumentType::Invoice,
            'F'
        );

        $second = $service->next(
            FiscalDocumentType::Invoice,
            'F'
        );

        $this->assertSame(1, $first['number']);
        $this->assertSame('F000001', $first['formatted']);

        $this->assertSame(2, $second['number']);
        $this->assertSame('F000002', $second['formatted']);
    }

    public function test_sequences_are_isolated_by_company(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        FiscalSequence::create([
            'company_id' => $companyA->id,
            'document_type' => 'invoice',
            'series' => 'F',
            'current_number' => 10,
            'padding' => 6,
            'active' => true,
        ]);

        FiscalSequence::create([
            'company_id' => $companyB->id,
            'document_type' => 'invoice',
            'series' => 'F',
            'current_number' => 50,
            'padding' => 6,
            'active' => true,
        ]);

        $context = app(CompanyContext::class);
        $context->set($companyA);

        $service = app(FiscalSequenceService::class);

        $result = $service->next(
            FiscalDocumentType::Invoice,
            'F'
        );

        $this->assertSame(11, $result['number']);
        $this->assertSame('F000011', $result['formatted']);

        $this->assertDatabaseHas(
            'fiscal_sequences',
            [
                'company_id' => $companyB->id,
                'current_number' => 50,
            ]
        );
    }
}