<?php

namespace App\Monitor\Legal;

use App\Models\ComplaintCase;
use Carbon\CarbonImmutable;

/**
 * Works out what has to happen by when in a complaint to a Serbian operator
 * and, after that, to the regulator (RATEL).
 *
 * Every date comes from a rule that names what it was counted from and where
 * it comes from. A date that cannot be settled is reported as unsettled, never
 * filled in with a guess, and never reported as missed.
 *
 * Rule values follow the registry of Internet Evidence Monitor (verified by its
 * author on 2026-08-17). This is not legal advice.
 */
final class Deadlines
{
    public const REGIME_CHANGE = '2025-01-01';

    private const ZEK = 'Zakon o elektronskim komunikacijama ("Sl. glasnik RS 35/2023")';

    private const ZEK_OLD = 'Zakon o elektronskim komunikacijama (raniji) ("Sl. glasnik RS 44/2010, 60/2013-US, 62/2014, 95/2018"), čl. 113 st. 6';

    private const PRAVILNIK = 'Pravilnik o vansudskom rešavanju sporova između krajnjih korisnika i operatora ("Sl. glasnik RS 58/2024")';

    /** @return array{days:int, source:string} */
    public static function rule(string $step, CarbonImmutable $anchor, string $customerType = 'consumer'): array
    {
        $legacy = $anchor->lt(CarbonImmutable::parse(self::REGIME_CHANGE));
        if ($legacy) {
            return ['days' => $step === 'complaint_due' ? 30 : 15, 'source' => self::ZEK_OLD];
        }
        $zzp = $anchor->gte(CarbonImmutable::parse('2026-08-02'))
            ? 'Zakon o zaštiti potrošača ("Sl. glasnik RS 35/2026")'
            : 'Zakon o zaštiti potrošača ("Sl. glasnik RS 88/2021")';

        return match ($step) {
            'complaint_due' => ['days' => 30, 'source' => self::ZEK.', čl. 139'],
            'operator_response_due' => $customerType === 'consumer'
                ? ['days' => 8, 'source' => self::ZEK.', čl. 139; '.$zzp]
                : ['days' => 30, 'source' => self::ZEK.', čl. 139'],
            'regulator_dispute_due' => ['days' => 60, 'source' => self::ZEK.', čl. 139; '.self::PRAVILNIK],
            'regulator_decision_target' => ['days' => 90, 'source' => self::ZEK.', čl. 140; '.self::PRAVILNIK],
        };
    }

    /**
     * @return list<array{step:string, date:?string, is_deadline:bool, days:?int, source:?string,
     *                    provisional:bool, status:string, days_left:?int}>
     */
    public static function build(ComplaintCase $case, ?CarbonImmutable $eventDate, CarbonImmutable $today): array
    {
        $today = $today->startOfDay();
        $event = $case->event_date ? CarbonImmutable::parse($case->event_date) : $eventDate?->startOfDay();
        $submitted = $case->submitted_at ? CarbonImmutable::parse($case->submitted_at) : null;
        $responded = $case->responded_at ? CarbonImmutable::parse($case->responded_at) : null;
        $regulator = $case->regulator_filed_at ? CarbonImmutable::parse($case->regulator_filed_at) : null;
        $type = $case->customer_type ?: 'consumer';
        $out = [];

        $fact = function (string $step, ?CarbonImmutable $date) use (&$out) {
            if ($date) {
                $out[] = ['step' => $step, 'date' => $date->toDateString(), 'is_deadline' => false, 'days' => null,
                    'source' => null, 'provisional' => false, 'status' => 'done', 'days_left' => null];
            }
        };
        $deadline = function (string $step, ?CarbonImmutable $anchor, bool $met, bool $provisional = false) use (&$out, $today, $type) {
            if ($anchor === null) {
                // The event the period is counted from is not known: unsettled, not missed.
                $out[] = ['step' => $step, 'date' => null, 'is_deadline' => true, 'days' => null, 'source' => null,
                    'provisional' => false, 'status' => 'unsettled', 'days_left' => null];

                return null;
            }
            $rule = self::rule($step, $anchor, $type);
            $due = $anchor->addDays($rule['days']);
            $left = (int) $today->diffInDays($due, false);
            $out[] = ['step' => $step, 'date' => $due->toDateString(), 'is_deadline' => true, 'days' => $rule['days'],
                'source' => $rule['source'], 'provisional' => $provisional,
                'status' => $met ? 'met' : ($left < 0 ? 'missed' : 'upcoming'), 'days_left' => $left];

            return $due;
        };

        $fact('event', $event);
        $deadline('complaint_due', $event, $submitted !== null);
        if ($submitted === null) {
            return $out;
        }

        $fact('complaint_submitted', $submitted);
        $responseDue = $deadline('operator_response_due', $submitted, $responded !== null);
        $fact('operator_responded', $responded);

        // Counted from the day the answer arrived; when none came, from the day it was owed.
        // The latter is provisional: an answer arriving later moves it.
        if (($case->outcome ?? null) !== 'accepted') {
            $deadline('regulator_dispute_due', $responded ?? $responseDue, $regulator !== null, $responded === null);
        }

        if ($regulator !== null) {
            $fact('regulator_filed', $regulator);
            // Not the subscriber's deadline: nothing of theirs is lost by it passing.
            $deadline('regulator_decision_target', $regulator, true);
        }

        return $out;
    }

    /** The next thing that has to be done, or null when nothing is outstanding. */
    public static function nextAction(array $milestones): ?array
    {
        $open = array_filter($milestones, fn ($m) => $m['is_deadline'] && $m['status'] === 'upcoming');
        usort($open, fn ($a, $b) => strcmp($a['date'], $b['date']));

        return $open[0] ?? null;
    }
}
