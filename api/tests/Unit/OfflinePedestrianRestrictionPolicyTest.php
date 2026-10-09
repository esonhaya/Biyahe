<?php

namespace Tests\Unit;

use App\Services\Routing\OfflinePedestrianRestrictionPolicy;
use PHPUnit\Framework\TestCase;

final class OfflinePedestrianRestrictionPolicyTest extends TestCase
{
    public function test_generic_vehicle_turn_restriction_is_not_applied_to_pedestrians(): void
    {
        $classification = OfflinePedestrianRestrictionPolicy::classify([
            'type' => 'restriction',
            'restriction' => 'no_left_turn',
        ], $this->viaNodeMembers());

        $this->assertSame('vehicle_only', $classification['mode_applicability']);
        $this->assertFalse($classification['unsupported_for_routing']);
        $this->assertSame('via_node', $classification['member_form']);
    }

    public function test_explicit_pedestrian_turn_restriction_blocks_node_only_routing(): void
    {
        $classification = OfflinePedestrianRestrictionPolicy::classify([
            'type' => 'restriction',
            'restriction:foot' => 'no_left_turn',
        ], $this->viaNodeMembers());

        $this->assertSame('pedestrian_applicable', $classification['mode_applicability']);
        $this->assertTrue($classification['unsupported_for_routing']);
        $this->assertFalse($classification['enforced_by_router']);
    }

    public function test_except_foot_exempts_pedestrians_from_generic_restriction(): void
    {
        $classification = OfflinePedestrianRestrictionPolicy::classify([
            'type' => 'restriction',
            'restriction' => 'no_left_turn',
            'except' => 'bus;foot;motorcar',
        ], $this->viaNodeMembers());

        $this->assertSame('pedestrian_exempt', $classification['mode_applicability']);
        $this->assertFalse($classification['unsupported_for_routing']);
    }

    public function test_generic_and_pedestrian_conditionals_are_separated(): void
    {
        $generic = OfflinePedestrianRestrictionPolicy::classify([
            'type' => 'restriction',
            'restriction:conditional' => 'no_left_turn @ (Mo-Fr 06:00-09:00)',
        ], $this->viaNodeMembers());
        $pedestrian = OfflinePedestrianRestrictionPolicy::classify([
            'type' => 'restriction',
            'restriction:foot:conditional' => 'no_left_turn @ (Mo-Fr 06:00-09:00)',
        ], $this->viaNodeMembers());

        $this->assertSame('vehicle_only', $generic['mode_applicability']);
        $this->assertTrue($generic['conditional']);
        $this->assertFalse($generic['pedestrian_conditional']);
        $this->assertSame('pedestrian_applicable', $pedestrian['mode_applicability']);
        $this->assertTrue($pedestrian['pedestrian_conditional']);
        $this->assertTrue($pedestrian['unsupported_for_routing']);
    }

    public function test_via_way_chains_and_malformed_or_nested_members_are_reported(): void
    {
        $viaWay = [
            ['member_type' => 'way', 'member_ref' => 10, 'role' => 'from'],
            ['member_type' => 'way', 'member_ref' => 11, 'role' => 'via'],
            ['member_type' => 'way', 'member_ref' => 12, 'role' => 'via'],
            ['member_type' => 'way', 'member_ref' => 13, 'role' => 'to'],
        ];
        $malformed = [
            ['member_type' => 'way', 'member_ref' => 10, 'role' => 'from'],
            ['member_type' => 'node', 'member_ref' => 11, 'role' => 'via'],
        ];
        $nested = [
            ['member_type' => 'way', 'member_ref' => 10, 'role' => 'from'],
            ['member_type' => 'relation', 'member_ref' => 11, 'role' => 'via'],
            ['member_type' => 'way', 'member_ref' => 12, 'role' => 'to'],
        ];

        $this->assertSame('via_way', OfflinePedestrianRestrictionPolicy::classify(['type' => 'restriction', 'restriction' => 'no_left_turn'], $viaWay)['member_form']);
        $this->assertSame('malformed', OfflinePedestrianRestrictionPolicy::classify(['type' => 'restriction', 'restriction' => 'no_left_turn'], $malformed)['member_form']);
        $this->assertSame('nested_complex', OfflinePedestrianRestrictionPolicy::classify(['type' => 'restriction', 'restriction:foot' => 'no_left_turn'], $nested)['member_form']);
        $this->assertTrue(OfflinePedestrianRestrictionPolicy::classify(['type' => 'restriction', 'restriction:foot' => 'no_left_turn'], $nested)['unsupported_for_routing']);
    }

    public function test_unknown_mode_and_conflicting_foot_exception_fail_closed(): void
    {
        $unknown = OfflinePedestrianRestrictionPolicy::classify([
            'type' => 'restriction',
            'restriction:mobility_scooter' => 'no_left_turn',
        ], $this->viaNodeMembers());
        $conflict = OfflinePedestrianRestrictionPolicy::classify([
            'type' => 'restriction',
            'restriction:foot' => 'no_left_turn',
            'except' => 'foot',
        ], $this->viaNodeMembers());
        $unknownException = OfflinePedestrianRestrictionPolicy::classify([
            'type' => 'restriction',
            'restriction' => 'no_left_turn',
            'except' => 'mobility_scooter',
        ], $this->viaNodeMembers());
        $orphanException = OfflinePedestrianRestrictionPolicy::classify([
            'type' => 'restriction',
            'except' => 'foot',
        ], $this->viaNodeMembers());

        $this->assertSame('ambiguous', $unknown['mode_applicability']);
        $this->assertTrue($unknown['unsupported_for_routing']);
        $this->assertSame('ambiguous', $conflict['mode_applicability']);
        $this->assertTrue($conflict['unsupported_for_routing']);
        $this->assertSame('ambiguous', $unknownException['mode_applicability']);
        $this->assertSame('ambiguous', $orphanException['mode_applicability']);
    }

    public function test_classification_output_is_deterministic(): void
    {
        $tags = ['type' => 'restriction', 'restriction' => 'no_u_turn'];
        $members = $this->viaNodeMembers();

        $this->assertSame(
            OfflinePedestrianRestrictionPolicy::classify($tags, $members),
            OfflinePedestrianRestrictionPolicy::classify($tags, $members),
        );
    }

    /** @return list<array{member_type: string, member_ref: int, role: string}> */
    private function viaNodeMembers(): array
    {
        return [
            ['member_type' => 'way', 'member_ref' => 10, 'role' => 'from'],
            ['member_type' => 'node', 'member_ref' => 11, 'role' => 'via'],
            ['member_type' => 'way', 'member_ref' => 12, 'role' => 'to'],
        ];
    }
}
