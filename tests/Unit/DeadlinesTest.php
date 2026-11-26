<?php

namespace Tests\Unit;

use App\Models\ComplaintCase;
use App\Monitor\Legal\Deadlines;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class DeadlinesTest extends TestCase
{
    private function build(array $attrs, string $today = '2026-11-25', ?string $event = '2026-11-01'): array
    {
        $case = new ComplaintCase($attrs + ['customer_type' => 'consumer']);
        $rows = Deadlines::build($case, $event ? CarbonImmutable::parse($event) : null, CarbonImmutable::parse($today));

        return array_column($rows, null, 'step');
    }

    public function test_complaint_is_due_thirty_days_from_the_event(): void
    {
        $m = $this->build([]);
        $this->assertSame('2026-12-01', $m['complaint_due']['date']);
        $this->assertSame('upcoming', $m['complaint_due']['status']);
        $this->assertSame(6, $m['complaint_due']['days_left']);
        $this->assertArrayNotHasKey('operator_response_due', $m);
    }

    public function test_unknown_event_date_is_unsettled_never_missed(): void
    {
        $m = $this->build([], event: null);
        $this->assertNull($m['complaint_due']['date']);
        $this->assertSame('unsettled', $m['complaint_due']['status']);
    }

    public function test_consumer_gets_eight_days_and_business_thirty(): void
    {
        $this->assertSame('2026-11-18', $this->build(['submitted_at' => '2026-11-10'])['operator_response_due']['date']);
        $this->assertSame('2026-12-10', $this->build(['submitted_at' => '2026-11-10', 'customer_type' => 'non_consumer'])['operator_response_due']['date']);
    }

    public function test_regulator_period_runs_from_the_reply_or_provisionally_from_the_day_it_was_owed(): void
    {
        $silent = $this->build(['submitted_at' => '2026-11-10']);
        $this->assertSame('2027-01-17', $silent['regulator_dispute_due']['date']);
        $this->assertTrue($silent['regulator_dispute_due']['provisional']);
        $this->assertSame('missed', $silent['operator_response_due']['status']);

        $answered = $this->build(['submitted_at' => '2026-11-10', 'responded_at' => '2026-11-15', 'outcome' => 'rejected']);
        $this->assertSame('2027-01-14', $answered['regulator_dispute_due']['date']);
        $this->assertFalse($answered['regulator_dispute_due']['provisional']);
        $this->assertSame('met', $answered['operator_response_due']['status']);
    }

    public function test_accepted_complaint_has_nothing_to_escalate(): void
    {
        $m = $this->build(['submitted_at' => '2026-11-10', 'responded_at' => '2026-11-15', 'outcome' => 'accepted']);
        $this->assertArrayNotHasKey('regulator_dispute_due', $m);
    }

    public function test_regulator_decision_target_appears_only_once_filed(): void
    {
        $m = $this->build(['submitted_at' => '2026-11-10', 'responded_at' => '2026-11-15', 'outcome' => 'rejected', 'regulator_filed_at' => '2026-12-01']);
        $this->assertSame('2027-03-01', $m['regulator_decision_target']['date']);
        $this->assertSame('met', $m['regulator_dispute_due']['status']);
    }

    public function test_cases_anchored_before_2025_use_the_earlier_law(): void
    {
        $rule = Deadlines::rule('operator_response_due', CarbonImmutable::parse('2024-06-01'));
        $this->assertSame(15, $rule['days']);
        $this->assertStringContainsString('čl. 113', $rule['source']);
    }

    public function test_next_action_is_the_earliest_open_deadline(): void
    {
        $case = new ComplaintCase(['customer_type' => 'consumer']);
        $rows = Deadlines::build($case, CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-25'));
        $this->assertSame('complaint_due', Deadlines::nextAction($rows)['step']);
        $late = Deadlines::build($case, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-11-25'));
        $this->assertNull(Deadlines::nextAction($late));
    }
}
