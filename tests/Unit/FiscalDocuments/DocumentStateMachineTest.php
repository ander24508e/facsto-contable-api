<?php

namespace Tests\Unit\FiscalDocuments;

use App\Domains\FiscalDocuments\Enums\DocumentStatus;
use App\Domains\FiscalDocuments\Services\DocumentStateMachine;
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
}
