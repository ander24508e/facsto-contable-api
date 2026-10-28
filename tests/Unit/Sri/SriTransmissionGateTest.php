<?php

namespace Tests\Unit\Sri;

use App\Domains\Companies\Fiscal\Models\CompanyFiscalSettings;
use App\Domains\Sri\Services\SriTransmissionGate;
use Tests\TestCase;

final class SriTransmissionGateTest extends TestCase
{
    public function test_nothing_is_transmitted_with_the_default_configuration(): void
    {
        $this->assertSame(SriTransmissionGate::TRANSPORT_DISABLED, $this->gate()->blockingReason($this->settings(true)));
    }

    public function test_the_company_must_enable_submission(): void
    {
        config(['sri.transport_enabled' => true]);

        $this->assertSame(SriTransmissionGate::COMPANY_SUBMISSION_DISABLED, $this->gate()->blockingReason($this->settings(false)));
    }

    public function test_an_unregistered_provider_blocks_production_only(): void
    {
        config(['sri.transport_enabled' => true, 'sri.software_provider.registered' => false]);

        $this->assertSame(SriTransmissionGate::PROVIDER_NOT_REGISTERED, $this->gate()->blockingReason($this->settings(true), 'PRODUCTION'));
        // An unknown environment is treated as production.
        $this->assertSame(SriTransmissionGate::PROVIDER_NOT_REGISTERED, $this->gate()->blockingReason($this->settings(true)));
        // The test environment has no tax effect: it only needs the global and company switches.
        $this->assertNull($this->gate()->blockingReason($this->settings(true), 'TEST'));
        $this->assertNull($this->gate()->blockingReason($this->settings(true, 'TEST')));
    }

    public function test_all_three_keys_allow_transmission(): void
    {
        config(['sri.transport_enabled' => true, 'sri.software_provider.registered' => true]);

        $this->assertNull($this->gate()->blockingReason($this->settings(true)));
    }

    private function gate(): SriTransmissionGate
    {
        return new SriTransmissionGate;
    }

    private function settings(bool $submissionEnabled, ?string $environment = null): CompanyFiscalSettings
    {
        return (new CompanyFiscalSettings)->forceFill(['sri_submission_enabled' => $submissionEnabled, 'sri_environment' => $environment]);
    }
}
