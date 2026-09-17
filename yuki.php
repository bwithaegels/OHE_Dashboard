<?php
/**
 * yuki.php — alle communicatie met Yuki, plus de opbouw van de cijfers.
 *
 * Twee dingen om te onthouden bij het lezen van deze code:
 *  1. Yuki boekt krediet negatief. Voor resultatenrekeningen draaien we het
 *     teken één keer om, hier, zodat opbrengsten positief zijn en kosten
 *     negatief. Balansrekeningen laten we ongemoeid.
 *  2. Resultatenrekeningen zijn cumulatief binnen het boekjaar en het boekjaar
 *     is het kalenderjaar. De maandmutatie is dus het verschil met de vorige
 *     maand, behalve in januari: daar is de stand zelf de mutatie.
 */

declare(strict_types=1);

class Yuki
{
    private array $cfg;
    private string $cacheDir;
    private ?string $session = null;
    private array $clients = [];
    public int $calls = 0;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
        $this->cacheDir = __DIR__ . '/cache';
        if (!is_dir($this->cacheDir)) @mkdir($this->cacheDir, 0755, true);
    }

    // ------------------------------------------------------------- techniek --

    private function client(string $service): SoapClient
    {
        if (!isset($this->clients[$service])) {
            $this->clients[$service] = new SoapClient(
                $this->cfg['base_url'] . $service . '.asmx?WSDL',
                ['trace' => true, 'exceptions' => true,
                 'cache_wsdl' => WSDL_CACHE_MEMORY, 'connection_timeout' => 25]
            );
        }
        return $this->clients[$service];
    }

    /** Sessies gaan ongeveer 24 uur mee; we bewaren ze om calls te sparen. */
    private function session(): string
    {
        if ($this->session !== null) return $this->session;

        $f = $this->cacheDir . '/session.json';
        if (is_readable($f)) {
            $d = json_decode((string) file_get_contents($f), true);
            if (is_array($d) && ($d['t'] ?? 0) > time() - 20 * 3600 && !empty($d['id'])) {
                return $this->session = (string) $d['id'];
            }
        }

        $this->calls++;
        $r = $this->client('Accounting')->Authenticate(['accessKey' => $this->cfg['api_key']]);
        $id = (string) ($r->AuthenticateResult ?? '');
        if ($id === '') throw new RuntimeException('Yuki gaf geen sessie terug.');

        @file_put_contents($f, json_encode(['id' => $id, 't' => time()]));
        return $this->session = $id;
    }

    /** Sessie ongeldig? Dan één keer opnieuw aanmelden en de call herhalen. */
    private function metSessie(string $service, string $methode, array $args): object
    {
        for ($poging = 1; $poging <= 2; $poging++) {
            try {
                $this->calls++;
                return $this->client($service)->$methode(
                    array_merge(['sessionID' => $this->session()], $args)
                );
            } catch (Throwable $e) {
                if ($poging === 2) throw $e;
                @unlink($this->cacheDir . '/session.json');
                $this->session = null;
            }
        }
        throw new RuntimeException('Onbereikbaar.');
    }

    // ------------------------------------------------------------ proefbalans --

    /**
     * Proefbalans op één datum.
     * Geeft per rekeningnummer: type (B of W), naam en bedrag.
     * Bij W-rijen is het teken al omgedraaid.
     */
    public function proefbalans(string $datum, bool $forceer = false): array
    {
        $f = $this->cacheDir . '/bal_' . $datum . '.json';

        // Afgesloten maanden veranderen zelden; recente maanden wel.
        $ttl = (strtotime($datum) < strtotime('-100 days')) ? 7 * 86400 : 900;
        if (!$forceer && is_readable($f) && filemtime($f) > time() - $ttl) {
            $d = json_decode((string) file_get_contents($f), true);
            if (is_array($d)) return $d;
        }

        $r = $this->metSessie('Accounting', 'GLAccountBalance', [
            'administrationID' => $this->cfg['administration_id'],
            'transactionDate'  => $datum,
        ]);

        $xml = $r->GLAccountBalanceResult->any ?? '';
        $uit = [];
        if ($xml !== '' && ($sx = @simplexml_load_string((string) $xml))) {
            foreach ($sx->GLAccount as $g) {
                $code = trim((string) $g['Code']);
                if ($code === '') continue;
                $type = trim((string) $g['BalanceType']);
                $ruw  = (float) (string) $g->Amount;
                $uit[$code] = [
                    'type'   => $type,
                    'naam'   => trim((string) $g->Description),
                    // Alleen resultaatrekeningen draaien we om.
                    'bedrag' => $type === 'W' ? -$ruw : $ruw,
                ];
            }
        }
        if (!$uit) throw new RuntimeException("Lege proefbalans op $datum.");

        @file_put_contents($f, json_encode($uit));
        return $uit;
    }

    /** Laatste dag van een maand, als datum. */
    public static function maandeinde(int $jaar, int $maand): string
    {
        return date('Y-m-t', mktime(0, 0, 0, $maand, 1, $jaar));
    }

    /**
     * Maandmutaties per rekening voor een heel boekjaar.
     * Januari is de stand zelf; elke andere maand het verschil met de vorige.
     */
    public function maandmutaties(int $jaar, bool $forceer = false): array
    {
        $tot = ($jaar === (int) date('Y')) ? (int) date('n') : 12;
        $standen = [];
        for ($m = 1; $m <= $tot; $m++) {
            $standen[$m] = $this->proefbalans(self::maandeinde($jaar, $m), $forceer);
        }

        $mut = [];
        for ($m = 1; $m <= $tot; $m++) {
            $nu   = $standen[$m];
            $vrg  = $m === 1 ? [] : $standen[$m - 1];
            foreach ($nu as $code => $rij) {
                if ($rij['type'] !== 'W') continue;
                $delta = $rij['bedrag'] - (float) ($vrg[$code]['bedrag'] ?? 0.0);
                $mut[$code]['naam'] = $rij['naam'];
                $mut[$code]['maanden'][$m] = $delta;
            }
            // Rekeningen die in een eerdere maand wel bestonden en nu niet meer.
            foreach ($vrg as $code => $rij) {
                if ($rij['type'] !== 'W' || isset($nu[$code])) continue;
                $mut[$code]['naam'] = $rij['naam'];
                $mut[$code]['maanden'][$m] = -(float) $rij['bedrag'];
            }
        }
        return ['maanden' => $tot, 'rekeningen' => $mut, 'standen' => $standen];
    }

    // ------------------------------------------------------------ boekingen --

    /**
     * Boekingen van één grootboekrekening in één maand.
     *
     * Let op de omvang: rekening 604000 levert voor augustus 2026 ruim 2,6 MB
     * XML op, duizenden regels van een paar euro. We bewaren die niet; we
     * groeperen meteen op omschrijving en gooien de rest weg.
     */
    public function transacties(string $code, int $jaar, int $maand, bool $forceer = false): array
    {
        $sleutel = sprintf('%s/tx_%s_%04d-%02d.json', $this->cacheDir, $code, $jaar, $maand);
        $van = sprintf('%04d-%02d-01', $jaar, $maand);
        $tot = self::maandeinde($jaar, $maand);

        $ttl = (strtotime($tot) < strtotime('-100 days')) ? 7 * 86400 : 900;
        if (!$forceer && is_readable($sleutel) && filemtime($sleutel) > time() - $ttl) {
            $d = json_decode((string) file_get_contents($sleutel), true);
            if (is_array($d)) return $d;
        }

        $r = $this->metSessie('Accounting', 'GLAccountTransactions', [
            'administrationID' => $this->cfg['administration_id'],
            'GLAccountCode'    => $code,
            'StartDate'        => $van,
            'EndDate'          => $tot,
        ]);

        $xml = $r->GLAccountTransactionsResult->any ?? '';
        // Resultatenrekeningen krijgen hetzelfde tekenomdraaien als elders.
        $draai = ($code !== '' && ($code[0] === '6' || $code[0] === '7')) ? -1 : 1;

        $groepen = [];
        $los = [];
        $aantal = 0;
        $totaal = 0.0;

        if ($xml !== '' && ($sx = @simplexml_load_string((string) $xml))) {
            foreach ($sx->GLAccountTransaction as $t) {
                $oms = trim((string) $t->Description);
                if ($oms === '') $oms = '(geen omschrijving)';
                $bedrag = $draai * (float) (string) $t->Amount;

                $aantal++;
                $totaal += $bedrag;

                if (!isset($groepen[$oms])) $groepen[$oms] = ['aantal' => 0, 'bedrag' => 0.0];
                $groepen[$oms]['aantal']++;
                $groepen[$oms]['bedrag'] += $bedrag;

                $los[] = ['datum' => substr((string) $t->Date, 0, 10),
                          'oms' => $oms, 'bedrag' => $bedrag];
            }
        }

        // Grootste groepen eerst; de staart vatten we samen op één regel.
        uasort($groepen, fn($a, $b) => abs($b['bedrag']) <=> abs($a['bedrag']));
        $lijst = [];
        $restAantal = 0; $restBedrag = 0.0;
        $i = 0;
        foreach ($groepen as $oms => $g) {
            if ($i < 40) {
                $lijst[] = ['oms' => $oms, 'aantal' => $g['aantal'], 'bedrag' => round($g['bedrag'], 2)];
            } else {
                $restAantal += $g['aantal'];
                $restBedrag += $g['bedrag'];
            }
            $i++;
        }

        // En de twintig zwaarste losse boekingen, voor als je één post zoekt.
        usort($los, fn($a, $b) => abs($b['bedrag']) <=> abs($a['bedrag']));
        $los = array_slice($los, 0, 20);
        foreach ($los as &$l) $l['bedrag'] = round($l['bedrag'], 2);
        unset($l);

        $uit = [
            'rekening'    => $code,
            'periode'     => $van . ' t/m ' . $tot,
            'aantal'      => $aantal,
            'totaal'      => round($totaal, 2),
            'groepen'     => $lijst,
            'rest_aantal' => $restAantal,
            'rest_bedrag' => round($restBedrag, 2),
            'grootste'    => $los,
        ];

        @file_put_contents($sleutel, json_encode($uit));
        return $uit;
    }

    // ------------------------------------------------------ openstaande posten --

    /**
     * Openstaande verkoopfacturen, met ouderdom ten opzichte van de vervaldatum.
     *
     * Het antwoord van Yuki bevat ook adressen, e-mailadressen en btw-nummers.
     * Die laten we vallen: we bewaren alleen wat nodig is om te zien wie te
     * laat betaalt. Wat je niet opslaat kan ook niet lekken.
     */
    public function openstaandeDebiteuren(bool $forceer = false): array
    {
        $f = $this->cacheDir . '/debiteuren.json';
        // Een uur: betaalgedrag verandert niet per kwartier, en het antwoord
        // is groot genoeg om niet vaker op te halen dan nodig.
        if (!$forceer && is_readable($f) && filemtime($f) > time() - 3600) {
            $d = json_decode((string) file_get_contents($f), true);
            if (is_array($d)) return $d;
        }

        $r = $this->metSessie('Accounting', 'OutstandingDebtorItems', [
            'administrationID'        => $this->cfg['administration_id'],
            'includeBankTransactions' => false,
            'sortOrder'               => 'DateAsc',
        ]);

        $xml = $r->OutstandingDebtorItemsResult->any ?? '';
        $vandaag = new DateTimeImmutable('today');
        $posten = [];

        if ($xml !== '' && ($sx = @simplexml_load_string((string) $xml))) {
            foreach ($sx->Item as $it) {
                $open = (float) (string) $it->OpenAmount;
                if (abs($open) < 0.005) continue;

                $verval = trim((string) $it->DueDate);
                $dagen = null;
                if ($verval !== '') {
                    try {
                        $dagen = (int) $vandaag->diff(new DateTimeImmutable(substr($verval, 0, 10)))
                                              ->format('%r%a') * -1;
                    } catch (Throwable $e) { $dagen = null; }
                }

                $posten[] = [
                    'contact'   => trim((string) $it->Contact),
                    'contactid' => trim((string) $it->ContactID),
                    'referentie' => trim((string) $it->Reference),
                    'datum'     => substr(trim((string) $it->Date), 0, 10),
                    'verval'    => substr($verval, 0, 10),
                    'soort'     => trim((string) $it->Type),
                    'bedrag'    => round($open, 2),
                    // OutstandingDebtorItems heeft geen valutaveld (nagekeken op
                    // 1.676 echte posten, ook niet-EU-klanten) — het oorspronkelijke
                    // bedrag hieronder staat altijd in de basisvaluta (EUR). De
                    // eventuele vreemde-valuta-weergave ("Bedrag VV" in Yuki's eigen
                    // scherm) komt apart, uit GetTransactions/ForeignCurrency —
                    // zie Yuki::valutaPerFactuur().
                    'origineel' => round((float) (string) $it->OriginalAmount, 2),
                    // Positief = dagen over tijd, negatief = nog niet vervallen.
                    'te_laat'   => $dagen,
                ];
            }
        }

        $bakken = [
            'niet_vervallen' => ['label' => 'Nog niet vervallen', 'bedrag' => 0.0, 'aantal' => 0],
            'd1_30'          => ['label' => '1 tot 30 dagen te laat',  'bedrag' => 0.0, 'aantal' => 0],
            'd31_60'         => ['label' => '31 tot 60 dagen te laat', 'bedrag' => 0.0, 'aantal' => 0],
            'd61_90'         => ['label' => '61 tot 90 dagen te laat', 'bedrag' => 0.0, 'aantal' => 0],
            'd90plus'        => ['label' => 'Meer dan 90 dagen te laat', 'bedrag' => 0.0, 'aantal' => 0],
            'onbekend'       => ['label' => 'Zonder vervaldatum', 'bedrag' => 0.0, 'aantal' => 0],
        ];

        $perKlant = [];
        $totaal = 0.0; $teLaat = 0.0;

        foreach ($posten as $p) {
            $d = $p['te_laat'];
            if ($d === null)      $bak = 'onbekend';
            elseif ($d <= 0)      $bak = 'niet_vervallen';
            elseif ($d <= 30)     $bak = 'd1_30';
            elseif ($d <= 60)     $bak = 'd31_60';
            elseif ($d <= 90)     $bak = 'd61_90';
            else                  $bak = 'd90plus';

            $bakken[$bak]['bedrag'] += $p['bedrag'];
            $bakken[$bak]['aantal']++;

            $totaal += $p['bedrag'];
            if ($d !== null && $d > 0) $teLaat += $p['bedrag'];

            $k = $p['contact'] !== '' ? $p['contact'] : '(zonder naam)';
            if (!isset($perKlant[$k])) {
                $perKlant[$k] = ['contact' => $k, 'contactid' => $p['contactid'] ?? '',
                                 'bedrag' => 0.0, 'te_laat' => 0.0,
                                 'aantal' => 0, 'oudste' => null];
            }
            $perKlant[$k]['bedrag'] += $p['bedrag'];
            $perKlant[$k]['aantal']++;
            if ($d !== null && $d > 0) {
                $perKlant[$k]['te_laat'] += $p['bedrag'];
                $perKlant[$k]['oudste'] = max($perKlant[$k]['oudste'] ?? 0, $d);
            }
        }

        foreach ($bakken as $sleutelBak => $b) $bakken[$sleutelBak]['bedrag'] = round($b['bedrag'], 2);
        uasort($perKlant, fn($a, $b) => abs($b['te_laat']) <=> abs($a['te_laat'])
                                     ?: abs($b['bedrag']) <=> abs($a['bedrag']));
        foreach ($perKlant as $kk => $v) {
            $perKlant[$kk]['bedrag']  = round($v['bedrag'], 2);
            $perKlant[$kk]['te_laat'] = round($v['te_laat'], 2);
        }

        $uit = [
            'opgehaald'    => date('c'),
            'aantal'       => count($posten),
            'totaal'       => round($totaal, 2),
            'te_laat'      => round($teLaat, 2),
            'pct_te_laat'  => $totaal > 0.005 ? round($teLaat / $totaal * 100, 1) : null,
            'bakken'       => $bakken,
            'klanten'      => array_values($perKlant),
            'posten'       => $posten,
        ];

        @file_put_contents($f, json_encode($uit));
        return $uit;
    }

    // ------------------------------------------------------------ betalingen --

    /**
     * Betalingsoverzicht van één klant: facturen en de boekingen die ze
     * (vermoedelijk) afletteren, over een gekozen periode op de
     * debiteurenrekeningen.
     *
     * Geen enkele Yuki-operatie koppelt een betaling rechtstreeks aan een
     * factuur — geprobeerd op 2026-09-16: GetTransactionDetails bestaat niet
     * op de Accounting-service, en GLAccountTransactions heeft geen
     * klantfilter. De koppeling hier is dus altijd best-effort, opgebouwd uit
     * wat er in de omschrijving staat:
     *  - webshopklant ("B2C sales - …"): omschrijving is "[ref]" voor de
     *    factuur en "Betaling: [ref]" voor de aflettering — exact dezelfde
     *    referentie, tegengesteld bedrag. Betrouwbaar.
     *  - een "echte" klant: de factuur staat kaal als omschrijving (het
     *    factuurnummer, bv. "20257588"). De betaling is de volledige
     *    bankomschrijving van de overschrijving, waarin dat factuurnummer
     *    ergens terugkomt — soms voluit, soms alleen de laatste cijfers,
     *    soms meerdere factuurnummers in één omschrijving. Nooit gegarandeerd
     *    sluitend; altijd de ruwe omschrijving erbij tonen zodat iemand het
     *    kan natrekken.
     * Niet elke negatieve boeking is een betaling — een creditnota of
     * afschrijving ("Free Jerseys 26/27", "Afboeken Dub. Debiteuren") hoort
     * er ook tussen en krijgt gewoon geen gekoppelde factuur.
     *
     * Rekening 400000 alleen al is tienduizenden boekingen breed door de
     * verzamelklant "B2C sales - …", dus dit haalt de hele rekening op voor
     * de gevraagde periode (net als transacties()) en bewaart die gegroepeerd
     * per klant in de cache, zodat elke volgende klant in dezelfde periode
     * gratis is.
     */
    public function klantBetalingen(string $contactID, string $van, string $tot, bool $forceer = false): array
    {
        $groep = $this->betalingenPerKlant($van, $tot, $forceer);
        return $this->koppelBetalingen($groep[$contactID] ?? []);
    }

    /**
     * Haalt (en cachet) alle boekingen op debiteurenrekeningen, gegroepeerd per
     * klant.
     *
     * Gebruikt GetTransactions (AccountingInfo), niet GLAccountTransactions
     * (Accounting/XML) zoals eerst: alleen GetTransactions kan de
     * ForeignCurrency-gegevensgroep meegeven (amountFC/rate/currency — Yuki's
     * eigen "Bedrag VV"), nodig voor de valutakolom op klant.php. Bevestigd op
     * 2026-09-16 tegen echte data (Osaka Hockey Americas heeft zowel EUR- als
     * USD-facturen dooreen) dat dit per regel verschilt, dus nooit aannemen.
     * `dataGroups` is een ongedocumenteerd, kommagescheiden veld — leeg geeft
     * geen van de subvelden terug, niet leeg met een fout, dus nooit weglaten
     * zonder expliciet te kiezen wat je nodig hebt.
     * `numberOfRecords`/`startRecord` zijn verplicht maar geen harde limiet
     * per call: 50.000 gaf op deze rekening 41.788 regels in 4,3s in één keer
     * terug, dus net zo goedkoop als de oude GLAccountTransactions-aanroep.
     */
    private function betalingenPerKlant(string $van, string $tot, bool $forceer): array
    {
        $sleutel = sprintf('%s/betalingen_%s_%s.json', $this->cacheDir, $van, $tot);
        if (!$forceer && is_readable($sleutel) && filemtime($sleutel) > time() - 900) {
            $d = json_decode((string) file_get_contents($sleutel), true);
            if (is_array($d)) return $d;
        }

        $rekeningen = (array) ($this->cfg['debiteuren_rekeningen'] ?? ['400000', '404000', '407000']);
        $perKlant = [];

        foreach ($rekeningen as $code) {
            $r = $this->metSessie('AccountingInfo', 'GetTransactions', [
                'administrationID' => $this->cfg['administration_id'],
                'glAccountCode'    => $code,
                'startDate'        => $van,
                'endDate'          => $tot,
                'financialMode'    => 0,
                'dataGroups'       => 'Contact,ForeignCurrency',
                'numberOfRecords'  => 50000,
                'startRecord'      => 0,
                'searchValues'     => [],
            ]);
            $lijst = $r->GetTransactionsResult->Transaction ?? [];
            if (is_object($lijst)) $lijst = [$lijst];

            foreach ($lijst as $t) {
                $cid = trim((string) ($t->contact->id ?? ''));
                if ($cid === '') continue; // geen klantkoppeling, niets aan te doen
                $fc = $t->foreignCurrency ?? null;
                $perKlant[$cid][] = [
                    'id'        => (string) ($t->id ?? ''),
                    'datum'     => substr((string) $t->transactionDate, 0, 10),
                    'contact'   => trim((string) ($t->contact->fullName ?? '')),
                    'oms'       => trim((string) ($t->description ?? '')),
                    'bedrag'    => round((float) (string) $t->amount, 2),
                    'rekening'  => $code,
                    // 'valuta' is null zolang Yuki geen ForeignCurrency teruggaf
                    // voor deze regel (bv. een banktransactie of correctie i.p.v.
                    // een echte verkoopfactuur) — nooit stilzwijgend EUR aannemen.
                    'valuta'    => $fc ? trim((string) ($fc->currency ?? '')) : null,
                    'bedrag_vv' => $fc ? round((float) (string) ($fc->amountFC ?? 0), 2) : null,
                ];
            }
        }

        @file_put_contents($sleutel, json_encode($perKlant));
        return $perKlant;
    }

    /**
     * Koppelt betalingen aan facturen binnen de regels van één klant.
     * Best-effort, zie de toelichting bij klantBetalingen(). Eén factuur mag
     * bij meerdere betalingen opduiken (een hoofdbetaling plus een kleine
     * correctie delen vaak hetzelfde factuurnummer in de omschrijving) — er
     * wordt dus niet "verbruikt", enkel bijgehouden of een factuur ooit
     * ergens aan gekoppeld werd, voor de nog-openstaand-lijst aan het einde.
     */
    private function koppelBetalingen(array $regels): array
    {
        usort($regels, fn($a, $b) => $a['datum'] <=> $b['datum']);

        $facturen = [];
        foreach ($regels as $r) {
            if ($r['bedrag'] > 0 && preg_match('/^\d+$/', $r['oms'])) {
                $facturen[$r['oms']] = $r + ['gekoppeld' => false];
            }
        }

        $betalingen = [];
        foreach ($regels as $r) {
            if ($r['bedrag'] >= 0) continue; // alleen negatieve regels zijn betaling/aanpassing

            $gevonden = [];

            if (preg_match('/^Betaling:\s*(\[.+\])$/u', $r['oms'], $m)) {
                // Webshopstijl: "Betaling: [ref]" hoort exact bij factuur "[ref]".
                if (isset($facturen[$m[1]])) {
                    $gevonden[$m[1]] = true;
                    $facturen[$m[1]]['gekoppeld'] = true;
                }
            } else {
                // Bankomschrijving: elk cijferreeksje van 4+ kan een (deel van
                // een) factuurnummer zijn — heel, of alleen de laatste cijfers.
                // array_keys() maakt numerieke stringsleutels weer tot int
                // (dezelfde valkuil als elders met GL-codes) — terug naar
                // string zetten voor de vergelijking.
                preg_match_all('/\d{4,}/', $r['oms'], $mm);
                foreach ($mm[0] as $kandidaat) {
                    foreach (array_keys($facturen) as $fnrRuw) {
                        $fnr = (string) $fnrRuw;
                        if ($fnr === $kandidaat || str_ends_with($fnr, $kandidaat)) {
                            $gevonden[$fnr] = true;
                            $facturen[$fnr]['gekoppeld'] = true;
                        }
                    }
                }
            }

            $betalingen[] = [
                'id'       => $r['id'],
                'datum'    => $r['datum'],
                'bedrag'   => $r['bedrag'],
                'oms'      => $r['oms'],
                'rekening' => $r['rekening'],
                'facturen' => array_map(fn($fnr) => [
                    'factuur' => $facturen[$fnr]['oms'],
                    'datum'   => $facturen[$fnr]['datum'],
                    'bedrag'  => $facturen[$fnr]['bedrag'],
                ], array_keys($gevonden)),
            ];
        }

        usort($betalingen, fn($a, $b) => $b['datum'] <=> $a['datum']);

        $nogOpen = [];
        foreach ($facturen as $f) {
            if (!$f['gekoppeld']) {
                $nogOpen[] = ['factuur' => $f['oms'], 'datum' => $f['datum'], 'bedrag' => $f['bedrag']];
            }
        }

        // Gemiddelde betaaltermijn: gewogen (naar factuurbedrag) gemiddelde
        // van betaaldatum minus factuurdatum, over elk gekoppeld paar in dit
        // venster. Zelfde gewogen-gemiddelde-aanpak als de "gewogen ouderdom"
        // voor de groep elders in dit bestand, en om dezelfde reden: één grote
        // factuur moet zwaarder wegen dan een reeks kleintjes, en een enkel
        // rare datumfout (betaling vóór factuurdatum, zou niet mogen voorkomen)
        // wordt overgeslagen in plaats van het gemiddelde te vervuilen.
        $termijnSom = 0.0; $termijnGewicht = 0.0;
        foreach ($betalingen as $b) {
            foreach ($b['facturen'] as $f) {
                $dagen = (strtotime($b['datum']) - strtotime($f['datum'])) / 86400;
                if ($dagen < 0 || $f['bedrag'] <= 0) continue;
                $termijnSom += $dagen * $f['bedrag'];
                $termijnGewicht += $f['bedrag'];
            }
        }
        $gemTermijn = $termijnGewicht > 0.005 ? round($termijnSom / $termijnGewicht, 1) : null;

        return [
            'aantal_facturen'   => count($facturen),
            'aantal_betalingen' => count($betalingen),
            'betalingen'        => $betalingen,
            'nog_open'          => $nogOpen,
            // Indicatief — gebaseerd op dezelfde best-effort koppeling als
            // hierboven, en alleen over facturen die in dít venster zowel
            // geboekt als (vermoedelijk) betaald werden.
            'gem_termijn'       => $gemTermijn,
            // Ruwe factuurregels uit dit venster, per factuurnummer — gebruikt
            // door klant.php om de valuta (Bedrag VV) van een openstaande
            // factuur op te zoeken. Een factuur buiten dit venster staat hier
            // niet in; dat is geen fout, gewoon buiten bereik.
            'facturen_info'     => array_map(fn($f) => [
                'datum'     => $f['datum'],
                'bedrag'    => $f['bedrag'],
                'valuta'    => $f['valuta'] ?? null,
                'bedrag_vv' => $f['bedrag_vv'] ?? null,
            ], $facturen),
        ];
    }

    // ------------------------------------------------------------- projecten --

    /** De projecten van deze administratie. Verandert zelden, dus een week cache. */
    public function projecten(bool $forceer = false): array
    {
        $f = $this->cacheDir . '/projecten.json';
        if (!$forceer && is_readable($f) && filemtime($f) > time() - 7 * 86400) {
            $d = json_decode((string) file_get_contents($f), true);
            if (is_array($d)) return $d;
        }

        $r = $this->metSessie('AccountingInfo', 'GetProjectsAndID', [
            'administrationID' => $this->cfg['administration_id'],
            'searchOption'     => 'All',
            'searchValue'      => '',
        ]);

        $uit = [];
        $lijst = $r->GetProjectsAndIDResult->Project ?? null;
        foreach (is_array($lijst) ? $lijst : ($lijst ? [$lijst] : []) as $p) {
            $code = trim((string) ($p->code ?? ''));
            if ($code === '') continue;
            $uit[$code] = trim((string) ($p->description ?? $code));
        }

        @file_put_contents($f, json_encode($uit));
        return $uit;
    }

    /** Namen van alle grootboekrekeningen. Nodig omdat GetProjectBalance er geen geeft. */
    public function rekeningnamen(bool $forceer = false): array
    {
        $f = $this->cacheDir . '/rekeningnamen.json';
        if (!$forceer && is_readable($f) && filemtime($f) > time() - 7 * 86400) {
            $d = json_decode((string) file_get_contents($f), true);
            if (is_array($d)) return $d;
        }

        $r = $this->metSessie('AccountingInfo', 'GetGLAccountScheme', [
            'administrationID' => $this->cfg['administration_id'],
        ]);

        $uit = [];
        $lijst = $r->GetGLAccountSchemeResult->GlAccount ?? null;
        foreach (is_array($lijst) ? $lijst : ($lijst ? [$lijst] : []) as $g) {
            $code = trim((string) ($g->code ?? ''));
            // Let op: Yuki schrijft 'descripton', met tikfout.
            if ($code !== '') $uit[$code] = trim((string) ($g->descripton ?? $g->description ?? ''));
        }

        @file_put_contents($f, json_encode($uit));
        return $uit;
    }

    /**
     * Maandmutaties van één project, in dezelfde vorm als maandmutaties(),
     * zodat bouw_pnl() er niets van hoeft te weten.
     *
     * GetProjectBalance neemt een datumbereik, dus hier is één call per maand
     * genoeg en hoeven we geen verschillen te berekenen.
     */
    public function maandmutatiesProject(string $projectCode, int $jaar, bool $forceer = false): array
    {
        $tot = ($jaar === (int) date('Y')) ? (int) date('n') : 12;
        $namen = $this->rekeningnamen($forceer);
        $mut = [];

        for ($m = 1; $m <= $tot; $m++) {
            $van = sprintf('%04d-%02d-01', $jaar, $m);
            $eind = self::maandeinde($jaar, $m);
            $f = sprintf('%s/prj_%s_%04d-%02d.json', $this->cacheDir, $projectCode, $jaar, $m);

            $ttl = (strtotime($eind) < strtotime('-100 days')) ? 7 * 86400 : 900;
            $rij = null;
            if (!$forceer && is_readable($f) && filemtime($f) > time() - $ttl) {
                $rij = json_decode((string) file_get_contents($f), true);
            }

            if (!is_array($rij)) {
                $r = $this->metSessie('AccountingInfo', 'GetProjectBalance', [
                    'administrationID' => $this->cfg['administration_id'],
                    'GLAccountCode'    => '',
                    'projectCode'      => $projectCode,
                    'StartDate'        => $van,
                    'EndDate'          => $eind,
                ]);
                $lijst = $r->GetProjectBalanceResult->ProjectBalance ?? null;
                $rij = [];
                foreach (is_array($lijst) ? $lijst : ($lijst ? [$lijst] : []) as $b) {
                    $code = trim((string) ($b->glAccountCode ?? ''));
                    if ($code === '') continue;
                    $rij[$code] = (float) ($b->amount ?? 0);
                }
                @file_put_contents($f, json_encode($rij));
            }

            foreach ($rij as $code => $ruw) {
                $c = (string) $code;
                // Alleen resultatenrekeningen, en met hetzelfde tekenomdraaien.
                if ($c === '' || ($c[0] !== '6' && $c[0] !== '7')) continue;
                $mut[$c]['naam'] = $namen[$c] ?? '';
                $mut[$c]['maanden'][$m] = -1 * (float) $ruw;
            }
        }

        // Maanden zonder boeking op een rekening moeten toch nul krijgen.
        foreach ($mut as $code => $info) {
            for ($m = 1; $m <= $tot; $m++) {
                if (!isset($mut[$code]['maanden'][$m])) $mut[$code]['maanden'][$m] = 0.0;
            }
            ksort($mut[$code]['maanden']);
        }

        return ['maanden' => $tot, 'rekeningen' => $mut, 'standen' => []];
    }
}

// ------------------------------------------------------------------ mapping --

/** Leest mapping.csv. Sleutels worden ints; dat is prima zolang we consequent zijn. */
function lees_mapping(): array
{
    $pad = __DIR__ . '/mapping.csv';
    if (!is_readable($pad)) throw new RuntimeException('mapping.csv ontbreekt.');
    $map = [];
    $fh = fopen($pad, 'r');
    fgetcsv($fh, 0, ';');
    while (($r = fgetcsv($fh, 0, ';')) !== false) {
        if (!isset($r[0], $r[2]) || trim((string) $r[0]) === '') continue;
        $map[trim((string) $r[0])] = trim((string) $r[2]);
    }
    fclose($fh);
    return $map;
}

// ---------------------------------------------------------------------- P&L --

/**
 * Bouwt de P&L: per categorie per maand een bedrag, plus de berekende subtotalen.
 * Rekeningen zonder categorie komen op een eigen regel terecht, nooit stilzwijgend weg.
 */
function bouw_pnl(array $mutaties, array $map): array
{
    $tot = $mutaties['maanden'];
    $rij = [];
    $zonderCategorie = [];

    foreach (PNL_LIJNEN as [$naam, $soort]) {
        $rij[$naam] = array_fill(1, $tot, 0.0);
    }
    $rij[NIET_TOEGEWEZEN] = array_fill(1, $tot, 0.0);
    $detail = [];

    foreach ($mutaties['rekeningen'] as $code => $info) {
        $cat = $map[(string) $code] ?? null;

        if ($cat === null || $cat === 'NIET IN P&L') {
            if ($cat === null) {
                $heeftBedrag = false;
                foreach ($info['maanden'] as $v) if (abs($v) > 0.005) $heeftBedrag = true;
                if ($heeftBedrag) {
                    $zonderCategorie[(string) $code] = $info['naam'];
                    foreach ($info['maanden'] as $m => $v) $rij[NIET_TOEGEWEZEN][$m] += $v;
                    $detail[NIET_TOEGEWEZEN][(string) $code] = $info;
                }
            }
            continue;
        }
        if (!isset($rij[$cat])) continue;   // categorie die niet in de P&L staat
        foreach ($info['maanden'] as $m => $v) $rij[$cat][$m] += $v;
        $detail[$cat][(string) $code] = $info;
    }

    // Binnen een categorie de grootste bedragen bovenaan.
    foreach ($detail as $cat => $rekeningen) {
        uasort($detail[$cat], function ($a, $b) {
            return abs(array_sum($b['maanden'])) <=> abs(array_sum($a['maanden']));
        });
    }

    // Subtotalen. Elke lijn is al zo ondertekend dat optellen volstaat.
    $som = function (array $namen, int $m) use ($rij): float {
        $t = 0.0;
        foreach ($namen as $n) $t += $rij[$n][$m] ?? 0.0;
        return $t;
    };

    $totMarge = ['Omzet', 'Aankopen handelsgoederen'];
    $totBedrijf = ['Bruto marge', 'Andere bedrijfsopbrengsten', 'Kosten gebouw', 'Kosten kantoor',
        'Reiskosten', 'Kosten publiciteit', 'Autokosten', 'Transportkosten en Bleckmann',
        'Overige diensten en diverse goederen', 'Managementvergoedingen en erelonen',
        'Bezoldigingen, RSZ & Pensioenen', 'Afschrijvingen', 'Overige bedrijfskosten',
        'Doorrekeningen', NIET_TOEGEWEZEN];
    $totJaar = ['Bedrijfsresultaat', 'Financiele opbrengsten', 'Financiele kosten',
        'Uitzonderlijke opbrengsten', 'Uitzonderlijke kosten', 'Belastingen op het resultaat',
        'Belastingen op het resultaat van vorige boekjaren'];

    for ($m = 1; $m <= $tot; $m++) {
        $rij['Bruto marge'][$m]                = $som($totMarge, $m);
        $rij['Bedrijfsresultaat'][$m]          = $som($totBedrijf, $m);
        $rij['Resultaat van het boekjaar'][$m] = $som($totJaar, $m);
    }

    return ['maanden' => $tot, 'rijen' => $rij, 'detail' => $detail,
            'zonder_categorie' => $zonderCategorie];
}

/**
 * Kerncijfers voor het dashboard, over een gekozen reeks maanden.
 * $van en $tot zijn maandnummers, inclusief.
 */
function kerncijfers(array $pnl, array $standen, int $van, int $tot, array $cfg = []): array
{
    $laatste = $standen[$tot] ?? [];

    $debRek  = $cfg['debiteuren_rekeningen'] ?? ['400000', '404000', '407000'];
    $vooruit = $cfg['vooruitbetaald_rekeningen'] ?? [];

    $bank = 0.0; $vorderingen = 0.0; $vooruitbetaald = 0.0;
    foreach ($laatste as $code => $r) {
        if ($r['type'] !== 'B') continue;
        $c = (string) $code;
        if (str_starts_with($c, '55') || str_starts_with($c, '57')) $bank += $r['bedrag'];
        if (in_array($c, $debRek, true))  $vorderingen += $r['bedrag'];
        if (in_array($c, $vooruit, true)) $vooruitbetaald += $r['bedrag'];
    }

    $optellen = function (string $naam) use ($pnl, $van, $tot): float {
        $t = 0.0;
        for ($m = $van; $m <= $tot; $m++) $t += $pnl['rijen'][$naam][$m] ?? 0.0;
        return $t;
    };

    $omzet     = $optellen('Omzet');
    $marge     = $optellen('Bruto marge');
    $resultaat = $optellen('Resultaat van het boekjaar');

    $opbrengsten = 0.0;
    foreach (['Omzet', 'Andere bedrijfsopbrengsten', 'Financiele opbrengsten',
              'Uitzonderlijke opbrengsten'] as $n) {
        $opbrengsten += $optellen($n);
    }

    // DSO: hoeveel dagen omzet staat er open?
    // Vorderingen zijn inclusief btw en omzet niet, dus dit valt iets hoog uit.
    $dagen = 0;
    for ($m = $van; $m <= $tot; $m++) {
        $dagen += (int) date('t', mktime(0, 0, 0, $m, 1, (int) date('Y')));
    }
    $dso = ($omzet > 0.005 && $dagen > 0) ? $vorderingen / $omzet * $dagen : null;

    return [
        'omzet'          => $omzet,
        'marge'          => $marge,
        'marge_pct'      => abs($omzet) > 0.005 ? $marge / $omzet * 100 : null,
        'opbrengsten'    => $opbrengsten,
        'kosten'         => $opbrengsten - $resultaat,
        'resultaat'      => $resultaat,
        'bank'           => $bank,
        'vorderingen'    => $vorderingen,
        'vooruitbetaald' => $vooruitbetaald,
        'dso'            => $dso,
        'dagen'          => $dagen,
    ];
}

/**
 * Splitst de openstaande posten in een aparte groep en de rest.
 * Matcht op een deel van de klantnaam, hoofdletterongevoelig.
 *
 * De gewogen ouderdom telt alleen positieve bedragen mee: een creditnota
 * met een oude datum zou het gemiddelde anders de verkeerde kant op trekken.
 */
function splits_debiteuren(array $d, array $fragmenten): array
{
    $vandaag = new DateTimeImmutable('today');
    $leeg = ['bedrag' => 0.0, 'aantal' => 0, 'te_laat' => 0.0,
             'gewicht' => 0.0, 'dagen_gewogen' => 0.0, 'namen' => []];
    $uit = ['groep' => $leeg, 'overig' => $leeg];

    foreach ($d['posten'] ?? [] as $p) {
        $naam = (string) $p['contact'];
        $inGroep = false;
        foreach ($fragmenten as $f) {
            if ($f !== '' && stripos($naam, (string) $f) !== false) { $inGroep = true; break; }
        }
        $vak = $inGroep ? 'groep' : 'overig';

        $uit[$vak]['bedrag'] += (float) $p['bedrag'];
        $uit[$vak]['aantal']++;
        if ($p['te_laat'] !== null && $p['te_laat'] > 0) $uit[$vak]['te_laat'] += (float) $p['bedrag'];
        if ($inGroep && $naam !== '') $uit['groep']['namen'][$naam] = true;

        if ((float) $p['bedrag'] > 0 && !empty($p['datum'])) {
            try {
                $dagen = (int) $vandaag->diff(new DateTimeImmutable($p['datum']))->format('%a');
                $uit[$vak]['gewicht'] += (float) $p['bedrag'];
                $uit[$vak]['dagen_gewogen'] += (float) $p['bedrag'] * $dagen;
            } catch (Throwable $e) { /* datum onbruikbaar, sla over */ }
        }
    }

    foreach (['groep', 'overig'] as $vak) {
        $g = $uit[$vak]['gewicht'];
        $uit[$vak]['ouderdom'] = $g > 0.005 ? $uit[$vak]['dagen_gewogen'] / $g : null;
        $uit[$vak]['bedrag']   = round($uit[$vak]['bedrag'], 2);
        $uit[$vak]['te_laat']  = round($uit[$vak]['te_laat'], 2);
        $uit[$vak]['namen']    = array_keys($uit[$vak]['namen']);
    }
    return $uit;
}
