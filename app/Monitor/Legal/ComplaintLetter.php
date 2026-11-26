<?php

namespace App\Monitor\Legal;

use App\Models\ComplaintCase;
use App\Support\Fmt;
use Carbon\CarbonImmutable;

/**
 * Writes the complaint, filled in from the recorded session, so nobody has to
 * compose it. What it will not do is overstate: every figure comes from the
 * session and the wording says what was measured rather than who is at fault.
 * A letter that claims more than the attachments support is the easiest kind
 * for an operator to answer.
 *
 * Serbian is the version meant to be filed; English is a reading translation.
 */
final class ComplaintLetter
{
    private const BLANK = '____________________';

    public const REGULATOR = 'RATEL - Regulatorna agencija za elektronske komunikacije i poštanske usluge';

    public static function toOperator(ComplaintCase $case, array $d, string $locale): string
    {
        $sr = $locale === 'sr';
        $today = CarbonImmutable::now(config('monitor.display_timezone'));
        $L = [];

        $L[] = $case->operator_name ?: self::BLANK;
        $L[] = $sr ? 'Služba za prigovore korisnika' : 'Customer complaints department';
        $L[] = '';
        $L[] = ($sr ? 'Mesto i datum: ' : 'Place and date: ').self::BLANK.', '.Fmt::date($today, $locale);
        $L[] = '';
        $L[] = $sr ? 'PRIGOVOR NA KVALITET USLUGE PRISTUPA INTERNETU' : 'COMPLAINT ABOUT THE QUALITY OF INTERNET ACCESS SERVICE';
        $L[] = '';
        array_push($L, ...self::subscriber($case, $sr));
        array_push($L, ...self::findings($d, $locale, $sr ? 'PREDMET PRIGOVORA' : 'SUBJECT OF THE COMPLAINT'));

        $rule = Deadlines::rule('operator_response_due', $today, $case->customer_type ?: 'consumer');
        $L[] = $sr ? 'ZAHTEV' : 'REQUEST';
        $L[] = '';
        $L[] = Fmt::wrap($sr
            ? "Tražim da utvrdite uzrok navedenih prekida i otklonite ga, kao i da mi dostavite pisani odgovor na ovaj prigovor u roku od {$rule['days']} dana, kako propisuje {$rule['source']}."
            : "I request that you establish and remove the cause of these outages and send me a written reply to this complaint within {$rule['days']} days, as prescribed by {$rule['source']}.");
        $L[] = '';
        $L[] = Fmt::wrap($sr
            ? 'Tražim i srazmerno umanjenje mesečne naknade za period u kome usluga nije bila dostupna, u skladu sa ugovorom.'
            : 'I also request a proportionate reduction of the monthly fee for the period in which the service was unavailable, in accordance with the contract.');
        $L[] = '';
        $L[] = Fmt::wrap($sr
            ? 'Molim da mi potvrdite prijem ovog prigovora i dodelite broj predmeta.'
            : 'Please confirm receipt of this complaint and assign it a case number.');
        $L[] = '';

        $L[] = $sr ? 'PRILOZI' : 'ATTACHMENTS';
        $L[] = '';
        $attachments = $sr
            ? ['Izveštaj o nadzoru veze (Report.pdf)', 'Isti izveštaj za pregled na ekranu (Report.html)', 'Tabela prekida (Outages.csv)',
                'Tabela merenja (Measurements.csv)', 'Sirova evidencija sa lancem otisaka (RawEvidence.jsonl)', 'Potvrda integriteta evidencije (ChainVerification.txt)']
            : ['Connection monitoring report (Report.pdf)', 'The same report for on-screen reading (Report.html)', 'Table of outages (Outages.csv)',
                'Table of measurements (Measurements.csv)', 'Raw evidence with hash chain (RawEvidence.jsonl)', 'Evidence integrity check (ChainVerification.txt)'];
        if ($d['gaps']->count() > 0) {
            $attachments[] = $sr ? 'Tabela pauza nadzora (MonitoringPauses.csv)' : 'Table of monitoring pauses (MonitoringPauses.csv)';
        }
        foreach ($attachments as $n => $text) {
            $L[] = '  '.($n + 1).'. '.$text;
        }
        $L[] = '';
        $L[] = Fmt::wrap($sr
            ? 'Sirova evidencija je zaštićena lancem kriptografskih otisaka: svaki zapis sadrži otisak prethodnog, pa naknadna izmena bilo kog reda narušava sve otiske posle njega. Ispravnost lanca može proveriti bilo ko, nezavisno od mene.'
            : 'The raw evidence is protected by a chain of cryptographic hashes: each record contains the hash of the previous one, so a later change to any line breaks every hash after it. Anyone can verify the chain independently of me.');
        $L[] = '';
        $L[] = ($sr ? 'Potpis: ' : 'Signature: ').self::BLANK;
        $L[] = '';
        $L[] = '---';
        $L[] = Fmt::wrap(__('report.disclaimer', [], $locale));

        return implode("\n", $L)."\n";
    }

    /**
     * The submission to the regulator, or the reason it cannot be prepared yet.
     *
     * @return array{ready:bool, reason:?string, text:?string}
     */
    public static function toRegulator(ComplaintCase $case, array $d, string $locale): array
    {
        $sr = $locale === 'sr';
        $today = CarbonImmutable::now(config('monitor.display_timezone'))->startOfDay();
        $no = fn (string $srText, string $enText) => ['ready' => false, 'reason' => $sr ? $srText : $enText, 'text' => null];

        if (! $case->submitted_at) {
            return $no('RATEL-u se obraćate tek nakon što ste se prigovorom obratili operateru. Prvo podnesite prigovor i sačuvajte potvrdu prijema.',
                'You may turn to RATEL only after complaining to the operator. File the complaint first and keep the confirmation of receipt.');
        }
        $milestones = collect(Deadlines::build($case, null, $today))->keyBy('step');
        $responseDue = $milestones->get('operator_response_due');
        if (! $case->responded_at && $responseDue && $responseDue['days_left'] >= 0) {
            return $no('Operateru još nije istekao rok za odgovor. Sačekajte da rok prođe - prijava podneta ranije se ne razmatra.',
                'The operator\'s deadline to reply has not passed yet. Wait until it does — a submission filed earlier is not considered.');
        }
        if ($case->outcome === 'accepted') {
            return $no('Operater je usvojio prigovor, pa nema šta da se prosleđuje. Ako problem i dalje traje, to je nov događaj i nov prigovor.',
                'The operator accepted the complaint, so there is nothing to escalate. If the problem continues, that is a new event and a new complaint.');
        }
        $dispute = $milestones->get('regulator_dispute_due');
        if ($dispute && $dispute['status'] === 'missed') {
            return $no('Rok za obraćanje RATEL-u je istekao. Za nove prekide pokrenite nov predmet.',
                'The deadline for turning to RATEL has passed. For new outages start a new case.');
        }

        $L = [];
        $L[] = self::REGULATOR;
        $L[] = '';
        $L[] = ($sr ? 'Mesto i datum: ' : 'Place and date: ').self::BLANK.', '.Fmt::date($today, $locale);
        $L[] = '';
        $L[] = $sr ? 'PRIJAVA ZBOG NEREŠENOG PRIGOVORA NA KVALITET USLUGE' : 'SUBMISSION CONCERNING AN UNRESOLVED COMPLAINT ABOUT SERVICE QUALITY';
        $L[] = '';
        array_push($L, ...self::subscriber($case, $sr, withContract: false));
        $L[] = $sr ? 'PODACI O OPERATERU I PRIGOVORU' : 'OPERATOR AND COMPLAINT';
        $L[] = '';
        $L[] = ($sr ? '  Operater:           ' : '  Operator:           ').($case->operator_name ?: self::BLANK);
        $L[] = ($sr ? '  Broj ugovora:       ' : '  Contract number:    ').($case->contract_number ?: self::BLANK);
        $L[] = ($sr ? '  Prigovor podnet:    ' : '  Complaint filed:    ').Fmt::date(CarbonImmutable::parse($case->submitted_at), $locale);
        $L[] = ($sr ? '  Rok za odgovor:     ' : '  Reply was due:      ').($responseDue ? Fmt::date(CarbonImmutable::parse($responseDue['date']), $locale) : '—');
        $L[] = $case->responded_at
            ? ($sr ? '  Operater odgovorio: ' : '  Operator replied:   ').Fmt::date(CarbonImmutable::parse($case->responded_at), $locale).($sr ? ' - prigovor odbijen' : ' - complaint rejected')
            : ($sr ? '  Operater odgovorio: nije odgovorio u roku' : '  Operator replied:   no reply within the deadline');
        $L[] = '';
        $L[] = Fmt::wrap($case->responded_at
            ? ($sr ? 'Operater je odbio moj prigovor, a smatram da su prekidi usluge dokazani, pa se obraćam RATEL-u.'
                   : 'The operator rejected my complaint, and I consider the service outages demonstrated, so I am turning to RATEL.')
            : ($sr ? 'Operater nije odgovorio na moj prigovor u zakonskom roku, pa se obraćam RATEL-u.'
                   : 'The operator did not reply to my complaint within the statutory deadline, so I am turning to RATEL.'));
        $L[] = '';
        array_push($L, ...self::findings($d, $locale, $sr ? 'NALAZI NADZORA' : 'MONITORING FINDINGS'));
        $L[] = Fmt::wrap($sr
            ? 'Molim da se ispita kvalitet usluge na mojoj vezi i da se naloži operateru otklanjanje uzroka prekida, kao i srazmerno umanjenje naknade za period u kome usluga nije bila dostupna.'
            : 'I ask that the quality of service on my connection be examined and that the operator be ordered to remove the cause of the outages and to reduce the fee proportionately for the period in which the service was unavailable.');
        $L[] = '';
        if ($dispute && $dispute['date']) {
            $L[] = Fmt::wrap($sr
                ? "Ovlašćenje za ovaj zahtev je {$dispute['source']}, prema kome se korisnik može obratiti Agenciji u roku od {$dispute['days']} dana od dana dostavljanja odgovora operatera, odnosno od dana isteka roka u kome je operater bio dužan da odgovori. U ovom predmetu taj rok ističe ".Fmt::date(CarbonImmutable::parse($dispute['date']), $locale)
                : "The basis for this request is {$dispute['source']}, under which a user may turn to the Agency within {$dispute['days']} days of receiving the operator's reply, or of the expiry of the period in which the operator was obliged to reply. In this case that period expires on ".Fmt::date(CarbonImmutable::parse($dispute['date']), $locale).'.');
            $L[] = '';
        }
        $L[] = $sr ? 'PRILOZI' : 'ATTACHMENTS';
        $L[] = '';
        foreach ($sr
            ? ['Prigovor upućen operateru, sa potvrdom prijema', $case->responded_at ? 'Odgovor operatera' : 'Dokaz da odgovor operatera nije primljen (izostanak odgovora u roku)',
                'Izveštaj o nadzoru veze (Report.pdf)', 'Sirova evidencija sa lancem otisaka (RawEvidence.jsonl)', 'Potvrda integriteta evidencije (ChainVerification.txt)']
            : ['The complaint sent to the operator, with confirmation of receipt', $case->responded_at ? 'The operator\'s reply' : 'Evidence that no reply was received within the deadline',
                'Connection monitoring report (Report.pdf)', 'Raw evidence with hash chain (RawEvidence.jsonl)', 'Evidence integrity check (ChainVerification.txt)'] as $n => $text) {
            $L[] = '  '.($n + 1).'. '.$text;
        }
        $L[] = '';
        $L[] = Fmt::wrap($sr
            ? 'Napomena: za zvanično merenje brzine koristi se RATEL NetTest aplikacija, odnosno merenje na internet portu modema Eternet kablom. Evidencija priložena uz ovu prijavu dokumentuje prekide usluge i vreme njihovog trajanja.'
            : 'Note: official speed measurement uses the RATEL NetTest application, i.e. a measurement on the modem\'s internet port over an Ethernet cable. The evidence attached here documents service outages and their duration.');
        $L[] = '';
        $L[] = ($sr ? 'Potpis: ' : 'Signature: ').self::BLANK;
        $L[] = '';
        $L[] = '---';
        $L[] = Fmt::wrap(__('report.disclaimer', [], $locale));

        return ['ready' => true, 'reason' => null, 'text' => implode("\n", $L)."\n"];
    }

    private static function subscriber(ComplaintCase $c, bool $sr, bool $withContract = true): array
    {
        $rows = [$sr ? 'PODACI O KORISNIKU' : 'SUBSCRIBER', ''];
        $rows[] = ($sr ? '  Ime i prezime:      ' : '  Full name:          ').($c->subscriber_name ?: self::BLANK);
        if ($withContract) {
            $rows[] = ($sr ? '  Broj ugovora:       ' : '  Contract number:    ').($c->contract_number ?: self::BLANK);
        }
        $rows[] = ($sr ? '  Adresa usluge:      ' : '  Service address:    ').($c->service_address ?: self::BLANK);
        $rows[] = ($sr ? '  Telefon:            ' : '  Phone:              ').($c->contact_phone ?: self::BLANK);
        $rows[] = ($sr ? '  E-pošta:            ' : '  E-mail:             ').($c->contact_email ?: self::BLANK);
        $rows[] = '';

        return $rows;
    }

    /**
     * Only the outages that ruled out the customer's own equipment. Listing every
     * recorded fault would put the customer's own Wi-Fi drops in a letter about
     * the operator, and hand the operator its answer.
     */
    private static function findings(array $d, string $locale, string $heading): array
    {
        $sr = $locale === 'sr';
        $s = $d['session'];
        $upstream = $d['upstream']->sortByDesc('duration_reported_ms')->values();
        $n = $upstream->count();
        $L = [$heading, ''];

        $L[] = Fmt::wrap($sr
            ? 'Od '.Fmt::date($s->started_at->copy()->setTimezone(config('monitor.display_timezone')), $locale).' vršio sam neprekidno merenje kvaliteta pristupa internetu na svojoj vezi, uređajem stalno povezanim na ruter. Nadzor je trajao '.Fmt::duration($d['monitored_ms'], false).', uz zapis u evidenciju zaštićenu lancem kriptografskih otisaka.'
            : 'Since '.Fmt::date($s->started_at->copy()->setTimezone(config('monitor.display_timezone')), $locale).' I have continuously measured the quality of internet access on my connection, using a device permanently connected to the router. Monitoring ran for '.Fmt::duration($d['monitored_ms'], false).', recorded in evidence protected by a chain of cryptographic hashes.');
        $L[] = '';

        if ($n > 0) {
            $longest = $upstream->first();
            $L[] = Fmt::wrap($sr
                ? "Tokom tog perioda zabeleženo je $n ".Fmt::plural($n, 'prekid', 'prekida', 'prekida', 'sr').' usluge u ukupnom trajanju od '.Fmt::duration($d['upstream_ms']).' (najmanje '.Fmt::duration($d['upstream_min_ms']).'). Najduži pojedinačni prekid trajao je '.Fmt::duration($longest->duration_reported_ms).', dana '.Fmt::date($longest->started_at->copy()->setTimezone(config('monitor.display_timezone')), 'sr')
                : "During that period $n service ".Fmt::plural($n, 'outage was', 'outages were', '', 'en').' recorded, with a total duration of '.Fmt::duration($d['upstream_ms']).' (at least '.Fmt::duration($d['upstream_min_ms']).'). The longest single outage lasted '.Fmt::duration($longest->duration_reported_ms).', on '.Fmt::date($longest->started_at->copy()->setTimezone(config('monitor.display_timezone')), 'en').'.');
            $L[] = '';
            $L[] = Fmt::wrap($sr
                ? 'Tokom svakog od tih prekida moj ruter je uredno odgovarao, a nijedna meta van lokalne mreže nije bila dostupna. To isključuje moju lokalnu mrežu i putanju između mernog uređaja i rutera kao uzrok.'
                : 'During each of these outages my router answered normally while no destination outside the local network was reachable. That rules out my local network and the path between the measuring device and the router as the cause.');
            $L[] = '';
            $L[] = $sr ? 'Najduži prekidi:' : 'Longest outages:';
            $L[] = '';
            foreach ($upstream->take(10) as $i) {
                $L[] = sprintf('  %s   %12s   (%s %s)', Fmt::dateTime($i->started_at, $locale),
                    Fmt::duration($i->duration_reported_ms), $sr ? 'najmanje' : 'at least', Fmt::duration($i->duration_min_ms));
            }
            if ($n > 10) {
                $L[] = $sr ? '  … i još '.($n - 10).' u priloženoj tabeli.' : '  … and '.($n - 10).' more in the attached table.';
            }
            $L[] = '';
        }

        $L[] = Fmt::wrap(($sr ? 'Izmerena dostupnost usluge u nadziranom periodu iznosi ' : 'Measured service availability over the monitored period is ')
            .Fmt::percent($d['upstream_availability'], $locale).'.');
        $L[] = '';

        return $L;
    }
}
