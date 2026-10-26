<?php

namespace Tests\Unit\FiscalDocuments;

use App\Domains\FiscalDocuments\Enums\DocumentStatus;
use App\Domains\FiscalDocuments\Services\DocumentStateMachine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DocumentStateMachineTest extends TestCase
{
    #[Test]
    public function it_allows_only_the_defined_early_document_transitions(): void
    {
        $machine = app(DocumentStateMachine::class);
        $this->assertTrue($machine->canTransition(DocumentStatus::DRAFT, DocumentStatus::NUMBERED));
        $this->assertTrue($machine->canTransition(DocumentStatus::NUMBERED, DocumentStatus::XML_BUILT));
        $this->assertFalse($machine->canTransition(DocumentStatus::AUTHORIZED, DocumentStatus::NUMBERED));
    }

    /** @return array<string, array{DocumentStatus}> */
    public static function unsignedStatuses(): array
    {
        return [
            'draft' => [DocumentStatus::DRAFT],
            'numbered' => [DocumentStatus::NUMBERED],
            'xml built' => [DocumentStatus::XML_BUILT],
            'returned' => [DocumentStatus::RETURNED],
        ];
    }

    #[Test]
    #[DataProvider('unsignedStatuses')]
    public function unsigned_documents_never_reach_sri_reception(DocumentStatus $status): void
    {
        $machine = app(DocumentStateMachine::class);

        $this->assertFalse($machine->canSubmitToSri($status));
        $this->assertFalse($machine->canTransition($status, DocumentStatus::QUEUED));
        $this->assertFalse($machine->canTransition($status, DocumentStatus::RECEIVED));
    }

    #[Test]
    public function only_signed_documents_can_be_queued_for_the_sri(): void
    {
        $machine = app(DocumentStateMachine::class);

        $this->assertTrue($machine->canSubmitToSri(DocumentStatus::SIGNED));
        $this->assertTrue($machine->canTransition(DocumentStatus::SIGNED, DocumentStatus::QUEUED));
        $this->assertTrue($machine->canTransition(DocumentStatus::RETURNED, DocumentStatus::XML_BUILT));
    }
}
