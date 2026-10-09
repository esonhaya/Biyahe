<?php

namespace Tests\Unit;

use App\Services\Routing\PedestrianFailureResponseContractAudit;
use Tests\TestCase;

final class PedestrianFailureResponseContractAuditTest extends TestCase
{
    public function test_current_backend_client_and_harness_keep_unavailable_walking_claims_honest(): void
    {
        $report = (new PedestrianFailureResponseContractAudit)->audit(dirname(base_path()));

        $this->assertTrue($report['honest_unavailable_state']);
        $this->assertSame('PASS', $report['status']);
        $this->assertFalse($report['postgresql_contacted']);
        $this->assertCount(4, $report['sources']);
        $this->assertNotContains(false, $report['checks']);
    }
}
