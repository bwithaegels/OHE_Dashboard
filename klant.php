<?php
/**
 * klant.php — overzicht van één klant: betaalgedrag (gemiddelde betaaltermijn)
 * en de facturen die nu nog openstaan.
 *
 * Eerdere versie toonde hier ook een uitklapbare betalingenlijst met
 * best-effort gekoppelde facturen; op verzoek vervangen door dit compactere
 * overzicht. De onderliggende koppeling (Yuki::koppelBetalingen(), nog steeds
 * best-effort — zie de toelichting daar) blijft wel bestaan: ze voedt nu de
 * "gemiddelde betaaltermijn"-meter en de valutakolom hieronder, alleen niet
 * meer als zichtbare lijst.
 *
 * Valuta: OutstandingDebtorItems (de bron van de openstaande-facturentabel)
 * heeft géén valutaveld — nagekeken op 1.676 echte posten, ook niet-EU-
 * klanten (2026-09-16). Die info ("Bedrag VV" in Yuki's eigen scherm) komt
 * uit een aparte operatie, GetTransactions met de ForeignCurrency-
 * gegevensgroep, en is dus alleen bekend voor facturen die binnen de
 * hieronder gekozen periode vielen. Buiten dat venster: valuta "—", maar het
 * (EUR-)oorspronkelijke bedrag van Yuki zelf blijft altijd zichtbaar.
 */

declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/yuki.php';

vereis_login($CFG);
header('X-Robots-Tag: noindex, nofollow');

$contactID = trim((string) ($_GET['id'] ?? ''));
$naamUitUrl = trim((string) ($_GET['naam'] ?? ''));
$forceer = isset($_GET['ververs']);

if ($contactID === '') {
    http_response_code(400);
    ?>
<!doctype html>
<html lang="nl">
<head><?php schrijf_head('Klant — Osaka Hockey Europe'); ?></head>
<body>
<div class="wrap breed">
  <div class="fout"><strong>Geen klant opgegeven.</strong><br>
    Kies een klant via <a href="klanten.php">de klantenlijst</a>.</div>
</div>
</body>
</html>
    <?php
    exit;
}

// Periode: bepaalt alleen het venster waarover de gemiddelde betaaltermijn en
// de valutakolom worden berekend — de openstaande-facturentabel zelf toont
// altijd alles wat nu écht openstaat, ongeacht deze keuze. Vaste knoppen ipv
// vrije datumvelden, zodat de cache (die per exacte van/tot-combinatie
// sleutelt) niet voor elke muisklik een nieuwe Yuki-call afdwingt.
$reeksen = [
    '3m'  => ['label' => 'Laatste 3 maanden', 'van' => date('Y-m-d', strtotime('-3 months'))],
    '6m'  => ['label' => 'Laatste 6 maanden', 'van' => date('Y-m-d', strtotime('-6 months'))],
    '12m' => ['label' => 'Laatste 12 maanden', 'van' => date('Y-m-d', strtotime('-12 months'))],
    'jaar' => ['label' => 'Dit jaar', 'van' => date('Y-01-01')],
];
$reeks = isset($_GET['periode'], $reeksen[$_GET['periode']]) ? (string) $_GET['periode'] : '12m';
$van = $reeksen[$reeks]['van'];
$tot = date('Y-m-d');

// Betaaltermijn-meter: lage waarde is goed, zoals de andere meters in deze
// app. Geen vaste Yuki-norm bekend, dus een redelijke aanname (30 dagen
// nettotermijn is gangbaar) — net als de te-laat-drempels elders aan te
// passen in config.php.
$termijnGroen = (float) ($CFG['betaaltermijn_groen'] ?? 30);
$termijnRood  = (float) ($CFG['betaaltermijn_rood'] ?? 60);

$fout = '';
$naam = $naamUitUrl;
$koppeling = null;
$eigenPosten = [];
$totaalOpenstaand = 0.0;
$oudsteOpenDagen = null;

try {
    $y = new Yuki($CFG);

    // Live en goedkoop (uit de bestaande cache): wat staat er NU echt nog
    // open volgens Yuki zelf, ongeacht de gekozen periode hierboven.
    $openstaand = $y->openstaandeDebiteuren();
    $eigenPosten = array_values(array_filter(
        $openstaand['posten'] ?? [],
        fn($p) => ($p['contactid'] ?? '') === $contactID
    ));
    // Oudste eerst is prettiger voor "wie moet ik het eerst bellen".
    usort($eigenPosten, fn($a, $b) => ($b['te_laat'] ?? PHP_INT_MIN) <=> ($a['te_laat'] ?? PHP_INT_MIN));

    foreach ($eigenPosten as $p) {
        $totaalOpenstaand += (float) $p['bedrag'];
        if ($p['te_laat'] !== null && (int) $p['bedrag'] > 0) {
            $oudsteOpenDagen = max($oudsteOpenDagen ?? PHP_INT_MIN, (int) $p['te_laat']);
        }
        if ($naam === '' && $p['contact'] !== '') $naam = $p['contact'];
    }

    $koppeling = $y->klantBetalingen($contactID, $van, $tot, $forceer);
} catch (Throwable $e) {
    $fout = $e->getMessage();
}

if ($naam === '') $naam = '(klant zonder naam)';

/** Kleurklasse voor dagen te laat, zelfde drempels als klanten.php's dagenOpmaak(). */
function klant_laat_klasse(?int $dagen): string
{
    if ($dagen === null || $dagen <= 0) return '';
    if ($dagen > 90) return 'laat-3';
    if ($dagen > 30) return 'laat-2';
    return 'laat-1';
}
?>
<!doctype html>
<html lang="nl">
<head>
<?php schrijf_head('Klant: ' . $naam . ' — Osaka Hockey Europe'); ?>
</head>
<body>
<div class="wrap breed">

<header>
  <div>
    <h1><?= h($naam) ?></h1>
    <p class="sub">Betaalgedrag en openstaande facturen.</p>
  </div>
  <div class="knoppen">
    <a class="btn" href="klanten.php">← Klanten</a>
    <a class="btn prim" href="?id=<?= urlencode($contactID) ?>&naam=<?= urlencode($naam) ?>&periode=<?= h($reeks) ?>&ververs=1">Ververs</a>
    <?php themaknop(); ?>
  </div>
</header>

<?php if ($fout): ?>
  <div class="fout"><strong>Ophalen mislukt.</strong><br><?= h($fout) ?></div>
<?php endif; ?>

<?php if (!$fout && $koppeling): ?>

<div class="werkbalk">
  <span class="nb" style="margin-right:.3rem">Betaaltermijn berekend over:</span>
  <?php foreach ($reeksen as $sleutel => $info): ?>
    <a class="btn<?= $sleutel === $reeks ? ' prim' : '' ?>"
       href="?id=<?= urlencode($contactID) ?>&naam=<?= urlencode($naam) ?>&periode=<?= h($sleutel) ?>"><?= h($info['label']) ?></a>
  <?php endforeach; ?>
</div>

<div class="kaarten">
  <div class="kaart">
    <p class="lbl">Nu openstaand</p>
    <p class="val <?= $totaalOpenstaand < 0 ? 'neg' : '' ?>">€ <?= euro($totaalOpenstaand, true) ?></p>
    <p class="nb"><?= count($eigenPosten) ?> openstaande factu(u)r(en)</p>
  </div>
  <div class="kaart">
    <p class="lbl">Langst openstaand</p>
    <p class="val"><?= $oudsteOpenDagen !== null && $oudsteOpenDagen > 0 ? h((string) $oudsteOpenDagen) . ' d' : '—' ?></p>
    <p class="nb">dagen over de vervaldatum</p>
  </div>
  <div class="kaart kaart-meter">
    <p class="lbl">Gemiddelde betaaltermijn</p>
    <?= meter_svg($koppeling['gem_termijn'], $termijnGroen, $termijnRood, 'd') ?>
    <p class="nb">
      <?php if ($koppeling['gem_termijn'] !== null): ?>
        dagen tussen factuur- en betaaldatum, <?= h(strtolower($reeksen[$reeks]['label'])) ?>
      <?php else: ?>
        onvoldoende gekoppelde betalingen in dit venster
      <?php endif; ?>
    </p>
  </div>
</div>

<h2>Openstaande facturen</h2>
<p class="melding">
  Bedrag VV (valuta en oorspronkelijk bedrag in die valuta) is alleen bekend voor
  facturen die binnen de hierboven gekozen periode geboekt zijn — Yuki's
  openstaande-postenlijst zelf houdt geen valuta bij. Buiten dat venster staat
  hier een streepje in plaats van een gegokte "EUR".
</p>

<?php if (!$eigenPosten): ?>
  <p class="nb">Geen openstaande facturen.</p>
<?php else: ?>
  <table class="tabel-eenvoudig">
    <thead>
      <tr>
        <th>Factuur</th>
        <th>Datum</th>
        <th>Vervaldatum</th>
        <th>Valuta</th>
        <th>Origineel bedrag</th>
        <th>Openstaand bedrag</th>
        <th>Dagen te laat</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($eigenPosten as $p):
        $info      = $koppeling['facturen_info'][$p['referentie']] ?? null;
        $valuta    = $info['valuta'] ?? null;
        // "Origineel bedrag" toont het bedrag in de eigen valuta van de
        // factuur (Bedrag VV) als die bekend is en niet EUR; anders het
        // gewone (EUR-)oorspronkelijke bedrag dat Yuki altijd teruggeeft.
        $origWeergave = ($valuta && $valuta !== 'EUR' && $info['bedrag_vv'] !== null)
            ? $info['bedrag_vv'] : $p['origineel'];
        $dagen = $p['te_laat'];
        $klasse = klant_laat_klasse($dagen);
    ?>
      <tr>
        <td><?= h($p['referentie'] !== '' ? $p['referentie'] : '—') ?></td>
        <td><?= h($p['datum']) ?></td>
        <td><?= h($p['verval'] !== '' ? $p['verval'] : '—') ?></td>
        <td><?= h($valuta ?: '—') ?></td>
        <td><?= euro((float) $origWeergave, true) ?></td>
        <td>€ <?= euro((float) $p['bedrag'], true) ?></td>
        <td><?= $dagen === null ? '—'
              : ($dagen > 0
                  ? '<span class="' . h($klasse) . '">' . h((string) $dagen) . ' d</span>'
                  : '<span class="nb">' . h((string) (-$dagen)) . ' d te gaan</span>') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<?php endif; ?>

</div>
<script src="thema.js"></script>
</body>
</html>
