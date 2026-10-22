<?php

namespace App\Application\Fiscal;

use App\Domain\Fiscal\Enums\FiscalStampAttemptStatus;
use App\Domain\Fiscal\Enums\FiscalStampAuditAction;
use App\Models\FiscalStampAttempt;
use App\Models\FiscalStampAuditEvent;
use Illuminate\Support\Facades\DB;
use LogicException;

final class RecordFiscalStampTransition
{
    public function record(
        FiscalStampAttempt $attempt,
        FiscalStampAuditAction $action,
        ?FiscalStampAttemptStatus $previousStatus
    ): FiscalStampAuditEvent {
        /*
         * Una transición y su evento deben
         * compartir la misma transacción SQL.
         */
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                'Fiscal audit events require a database transaction.'
            );
        }

        if (! $attempt->exists) {
            throw new LogicException(
                'Cannot audit an unsaved stamping attempt.'
            );
        }

        $currentStatus = $attempt->status;

        if (
            ! $currentStatus
                instanceof FiscalStampAttemptStatus
        ) {
            throw new LogicException(
                'Invalid stamping attempt status.'
            );
        }

        $expectedStatus = match ($action) {
            FiscalStampAuditAction::Reserved => FiscalStampAttemptStatus::Preparing,

            FiscalStampAuditAction::Prepared => FiscalStampAttemptStatus::Prepared,

            FiscalStampAuditAction::DispatchStarted => FiscalStampAttemptStatus::Dispatching,

            FiscalStampAuditAction::BecameUnknown => FiscalStampAttemptStatus::Unknown,

            FiscalStampAuditAction::Stamped => FiscalStampAttemptStatus::Stamped,

            FiscalStampAuditAction::Rejected => FiscalStampAttemptStatus::Rejected,
        };

        $allowedPrevious = match ($action) {
            FiscalStampAuditAction::Reserved => $previousStatus === null,

            FiscalStampAuditAction::Prepared => $previousStatus
                    === FiscalStampAttemptStatus::Preparing,

            FiscalStampAuditAction::DispatchStarted => $previousStatus
                    === FiscalStampAttemptStatus::Prepared,

            FiscalStampAuditAction::BecameUnknown,
            FiscalStampAuditAction::Rejected => $previousStatus
                    === FiscalStampAttemptStatus::Dispatching,

            FiscalStampAuditAction::Stamped => in_array(
                $previousStatus,
                [
                    FiscalStampAttemptStatus::Dispatching,
                    FiscalStampAttemptStatus::Unknown,
                ],
                true
            ),
        };

        if (
            $currentStatus !== $expectedStatus
            || ! $allowedPrevious
        ) {
            throw new LogicException(
                'Invalid fiscal audit transition.'
            );
        }

        $uuid = null;

        if ($action === FiscalStampAuditAction::Stamped) {
            $uuid = $attempt->provider_reference;

            if (
                ! is_string($uuid)
                || trim($uuid) === ''
            ) {
                throw new LogicException(
                    'A stamped event requires a UUID.'
                );
            }
        }

        /*
         * Solamente campos permitidos:
         * nunca tokens, credenciales, XML
         * completo o respuestas HTTP crudas.
         */
        return FiscalStampAuditEvent::query()->create([
            'company_id' => $attempt->company_id,

            'fiscal_document_id' => $attempt->fiscal_document_id,

            'fiscal_stamp_attempt_id' => $attempt->id,

            'event_type' => $action->value,

            'previous_status' => $previousStatus?->value,

            'current_status' => $currentStatus->value,

            'xml_sha256' => $attempt->xml_sha256,

            'uuid' => $uuid,

            'occurred_at' => now(),
        ]);
    }
}
