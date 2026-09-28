<?php
/**
 * index.php — het dashboard, met een filter op periode.
 */

declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/yuki.php';

vereis_login($CFG);
header('X-Robots-Tag: noindex, nofollow');

$jaar    = (int) date('Y');
$forceer = isset($_GET['ververs']);
$keuze   = isset($_GET['periode']) ? (string) $_GET['periode'] : 'ytd';
$fout    = '';
$pnl = $k = $mut = $y = null;

$splits = null;

try {
    $y   = new Yuki($CFG);
    $mut = $y->maandmutaties($jaar, $forceer);
    $pnl = bouw_pnl($mut, lees_mapping());
} catch (Throwable $e) {
    $fout = $e->getMessage();
}

// De openstaande posten zijn nodig om de groep apart te kunnen zetten.
// Mislukt dat, dan valt het dashboard terug op één ongesplitste meter.
try {
    if ($pnl) {
        $splits = splits_debiteuren(
            $y->openstaandeDebiteuren($forceer),
            (array) ($CFG['groep_klanten'] ?? [])
        );
    }
} catch (Throwable $e) {
    $splits = null;
}

$opties = $van = $tot = null;
if ($pnl) {
    $opties = periode_opties($pnl['maanden']);
    if (!isset($opties[$keuze])) $keuze = 'ytd';
    [$van, $tot] = periode_bereik($keuze, $pnl['maanden']);
    $k = kerncijfers($pnl, $mut['standen'], $van, $tot, $CFG);
}

// Vorig boekjaar kost twaalf extra maandeinden en wordt daarom alleen op
// verzoek geladen — zelfde afspraak als in de resultatenrekening. Eenmaal
// opgehaald zijn die maanden ouder dan 100 dagen, dus houdt de cache ze een
// week vast en is een volgende klik gratis.
$toonVorig = isset($_GET['vorigjaar']);
$pnlVorig = null;
$foutVorig = '';
if ($toonVorig && $pnl) {
    try {
        $pnlVorig = bouw_pnl($y->maandmutaties($jaar - 1, $forceer), lees_mapping());
    } catch (Throwable $e) {
        $foutVorig = $e->getMessage();
    }
}

$r = $k ? ratios($k, $pnl, $mut['standen'], $van, $tot, $CFG, $pnlVorig) : null;

/** Houdt de huidige keuzes vast in elke link op deze pagina. */
function url_met(array $extra = []): string
{
    $p = array_merge(array_intersect_key($_GET, array_flip(['periode', 'vorigjaar'])), $extra);
    $p = array_filter($p, fn($v) => $v !== null);
    return '?' . http_build_query($p);
}

/** Verhouding als "1,8×"; null wordt een streepje. */
function maal(?float $v): string
{
    return $v === null ? '—' : number_format($v, 1, ',', '.') . '×';
}

/** Aantal dagen, afgerond; null wordt een streepje. */
function dagen_tekst(?float $v): string
{
    return $v === null ? '—' : number_format($v, 0, ',', '.') . ' d';
}

// Grafiek: altijd het hele jaar, maar de gekozen maanden vol en de rest gedempt.
$labels = $opbrengsten = $kosten = $margeReeks = $inBereik = [];
if ($pnl) {
    $inkomsten = ['Omzet', 'Andere bedrijfsopbrengsten', 'Financiele opbrengsten', 'Uitzonderlijke opbrengsten'];
    for ($m = 1; $m <= $pnl['maanden']; $m++) {
        $in = 0.0;
        foreach ($inkomsten as $n) $in += $pnl['rijen'][$n][$m] ?? 0.0;
        $res   = $pnl['rijen']['Resultaat van het boekjaar'][$m] ?? 0.0;
        $omz   = $pnl['rijen']['Omzet'][$m] ?? 0.0;
        $marge = $pnl['rijen']['Bruto marge'][$m] ?? 0.0;

        $labels[]      = maandnaam($m);
        $opbrengsten[] = round($in, 2);
        $kosten[]      = round($in - $res, 2);
        $margeReeks[]  = abs($omz) > 0.005 ? round($marge / $omz * 100, 1) : null;
        $inBereik[]    = ($m >= $van && $m <= $tot);
    }
}

$periodeNaam = $opties[$keuze] ?? 'Jaar tot nu';

// DSO zonder de groepsvorderingen. De omzet blijft het totaal, want
// groepsverkopen staan op dezelfde omzetrekening als al het andere.
$dsoExcl = null;
$groepBedrag = 0.0;
if ($k && $splits && $k['omzet'] > 0.005 && $k['dagen'] > 0) {
    $groepBedrag = (float) $splits['groep']['bedrag'];
    $dsoExcl = ($k['vorderingen'] - $groepBedrag) / $k['omzet'] * $k['dagen'];
}
?>
<!doctype html>
<html lang="nl">
<head><?php schrijf_head('Osaka Hockey Europe — rapportage'); ?></head>
<body>
<div class="wrap">

<header>
  <div>
    <h1>Osaka Hockey Europe</h1>
    <p class="sub">Bijgewerkt <?= h(date('d/m/Y H:i')) ?><?= $y && $mut ? ' · ' . (int) $y->calls . ' API-calls' : '' ?></p>
  </div>
  <div class="knoppen">
    <?php if ($opties): ?>
    <form method="get" style="display:inline">
      <?php if ($toonVorig): ?><input type="hidden" name="vorigjaar" value="1"><?php endif; ?>
      <select name="periode" class="btn" onchange="this.form.submit()">
        <?php foreach ($opties as $w => $l): ?>
          <option value="<?= h($w) ?>"<?= $w === $keuze ? ' selected' : '' ?>><?= h($l) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php endif; ?>
    <a class="btn" href="pnl.php">Resultatenrekening</a>
    <a class="btn" href="klanten.php">Klanten</a>
    <a class="btn prim" href="<?= h(url_met(['ververs' => 1, 'periode' => $keuze])) ?>">Ververs</a>
    <?php themaknop(); ?>
    <a class="btn" href="?uitloggen=1">Afmelden</a>
  </div>
</header>

<?php if ($fout): ?>
  <div class="fout"><strong>Ophalen mislukt.</strong><br><?= h($fout) ?></div>
<?php endif; ?>

<?php if (!empty($pnl['zonder_categorie'])): ?>
  <div class="waarschuwing">
    <strong>Nieuwe grootboekrekeningen zonder categorie.</strong>
    Ze staan in de resultatenrekening op de regel &ldquo;<?= h(NIET_TOEGEWEZEN) ?>&rdquo;, dus de totalen
    kloppen, maar ze horen thuis in een van je categorieën. Vul ze aan in <code>mapping.csv</code>.
    <ul>
      <?php foreach ($pnl['zonder_categorie'] as $code => $naam): ?>
        <li><?= h((string) $code) ?> — <?= h($naam) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if ($k): ?>
<p class="sub" style="margin:-.4rem 0 .9rem">
  <?= h($periodeNaam) ?><?= $van === $tot ? '' : ' · ' . h(maandnaam($van)) . '–' . h(maandnaam($tot)) ?>
</p>

<div class="kaarten">
  <div class="kaart">
    <p class="lbl">Omzet</p>
    <p class="val <?= $k['omzet'] < 0 ? 'neg' : '' ?>">€ <?= euro($k['omzet']) ?></p>
  </div>
  <div class="kaart">
    <p class="lbl">Brutomarge</p>
    <p class="val <?= $k['marge'] < 0 ? 'neg' : '' ?>">€ <?= euro($k['marge']) ?></p>
    <p class="nb"><?= pct($k['marge_pct']) ?> van de omzet</p>
  </div>
  <div class="kaart">
    <p class="lbl">Kosten</p>
    <p class="val">€ <?= euro($k['kosten']) ?></p>
  </div>
  <div class="kaart">
    <p class="lbl">Resultaat</p>
    <p class="val <?= $k['resultaat'] < 0 ? 'neg' : '' ?>">€ <?= euro($k['resultaat']) ?></p>
  </div>
  <div class="kaart">
    <p class="lbl">Bank en kas</p>
    <p class="val <?= $k['bank'] < 0 ? 'neg' : '' ?>">€ <?= euro($k['bank']) ?></p>
    <p class="nb">stand eind <?= h(maandnaam($tot)) ?></p>
  </div>
  <div class="kaart">
    <p class="lbl">Openstaand bij klanten</p>
    <p class="val">€ <?= euro($k['vorderingen']) ?></p>
    <p class="nb">stand eind <?= h(maandnaam($tot)) ?><?php if (abs($k['vooruitbetaald']) > 0.5): ?>
      · € <?= euro(abs($k['vooruitbetaald'])) ?> vooruitbetaald<?php endif; ?></p>
  </div>
  <div class="kaart kaart-meter">
    <p class="lbl">Betaaltermijn (DSO)<?= $splits ? ' — excl. groep' : '' ?></p>
    <?= meter_svg($splits ? $dsoExcl : $k['dso'],
                  (float) ($CFG['dso_groen'] ?? 30), (float) ($CFG['dso_rood'] ?? 60)) ?>
    <p class="nb">
      <?php if (($splits ? $dsoExcl : $k['dso']) === null): ?>
        Geen omzet in deze periode.
      <?php else: ?>
        Gemiddeld tussen factureren en betaald krijgen.
        <a href="klanten.php">Wie te laat is →</a>
      <?php endif; ?>
    </p>
  </div>

  <?php if ($splits && $splits['groep']['aantal'] > 0): ?>
  <div class="kaart kaart-meter">
    <p class="lbl"><?= h((string) ($CFG['groep_label'] ?? 'Groep')) ?></p>
    <?= meter_svg($splits['groep']['ouderdom'],
                  (float) ($CFG['groep_groen'] ?? 60), (float) ($CFG['groep_rood'] ?? 120)) ?>
    <p class="nb">
      € <?= euro((float) $splits['groep']['bedrag']) ?> open over
      <?= (int) $splits['groep']['aantal'] ?> facturen.
      Gewogen ouderdom, geen DSO — zie hieronder.
    </p>
  </div>
  <?php endif; ?>
</div>

<?php if ($splits && $splits['groep']['aantal'] > 0): ?>
<p class="sub uitleg">
  De tweede meter toont de <strong>gewogen ouderdom</strong> van de openstaande facturen van
  <?= h(implode(', ', $splits['groep']['namen'])) ?>: hoelang dat geld gemiddeld al openstaat,
  gewogen naar bedrag. Het is geen DSO, want groepsverkopen worden op dezelfde omzetrekening
  geboekt als de rest, dus hun omzet is niet apart te nemen.
  De DSO links is berekend zonder die € <?= euro($groepBedrag) ?>.
</p>
<?php endif; ?>

<?php if ($r): ?>
<h2 class="ratiokop">Ratio's</h2>

<?php if (!$r['balans']['sluit']): ?>
  <div class="waarschuwing">
    <strong>De proefbalans sluit niet.</strong>
    De som van alle balansrekeningen wijkt € <?= euro(abs($r['balans']['verschil'])) ?> af van het
    resultaat op de resultatenrekeningen. De balansratio's hieronder (current ratio, quick ratio,
    solvabiliteit, werkkapitaal) staan daarom op een streepje: liever niets dan een kengetal dat
    er goed uitziet en niet klopt. De rentabiliteitsratio's komen uit de resultatenrekening zelf
    en zijn niet geraakt.
  </div>
<?php endif; ?>

<div class="kaarten">
  <div class="kaart">
    <p class="lbl">Bedrijfsresultaat-marge</p>
    <p class="val <?= ($r['ebit_pct'] ?? 0) < 0 ? 'neg' : '' ?>"><?= pct($r['ebit_pct']) ?></p>
    <p class="nb">€ <?= euro($r['ebit']) ?> op € <?= euro($k['omzet']) ?> omzet</p>
  </div>
  <div class="kaart">
    <p class="lbl">Nettomarge</p>
    <p class="val <?= ($r['netto_pct'] ?? 0) < 0 ? 'neg' : '' ?>"><?= pct($r['netto_pct']) ?></p>
    <p class="nb">resultaat van het boekjaar op de omzet</p>
  </div>
  <div class="kaart">
    <p class="lbl">Kostenratio</p>
    <p class="val"><?= pct($r['kosten_ratio']) ?></p>
    <p class="nb">kosten tegenover alle opbrengsten samen</p>
  </div>
  <div class="kaart">
    <p class="lbl">Personeelskosten</p>
    <p class="val"><?= pct($r['lonen_pct']) ?></p>
    <p class="nb">€ <?= euro($r['lonen']) ?> van de omzet<?php if ($r['management_pct'] !== null): ?>
      · management en erelonen apart <?= pct($r['management_pct']) ?><?php endif; ?></p>
  </div>

  <div class="kaart">
    <p class="lbl">Voorraaddagen (DIO)</p>
    <p class="val"><?= dagen_tekst($r['dio']) ?></p>
    <p class="nb">voorraad € <?= euro($r['balans']['voorraad']) ?> op aankopen € <?= euro($r['aankopen']) ?></p>
  </div>
  <div class="kaart">
    <p class="lbl">Betaaltermijn leveranciers (DPO)</p>
    <p class="val"><?= dagen_tekst($r['dpo']) ?></p>
    <p class="nb">crediteuren € <?= euro($r['balans']['crediteuren']) ?> op inkopen € <?= euro($r['inkoop']) ?></p>
  </div>
  <div class="kaart">
    <p class="lbl">Cashcyclus</p>
    <p class="val <?= ($r['ccc'] !== null && $r['ccc'] <= 0) ? 'pos' : '' ?>"><?= dagen_tekst($r['ccc']) ?></p>
    <p class="nb">voorraad + klanten − leveranciers; lager is beter</p>
  </div>

  <div class="kaart">
    <p class="lbl">Current ratio</p>
    <p class="val"><?= maal($r['balans']['sluit'] ? $r['current'] : null) ?></p>
    <p class="nb">vlottende activa tegenover schulden op korte termijn</p>
  </div>
  <div class="kaart">
    <p class="lbl">Quick ratio</p>
    <p class="val"><?= maal($r['balans']['sluit'] ? $r['quick'] : null) ?></p>
    <p class="nb">zelfde, maar zonder de voorraad mee te rekenen</p>
  </div>
  <div class="kaart">
    <p class="lbl">Solvabiliteit</p>
    <p class="val"><?= pct($r['balans']['sluit'] ? $r['solvabiliteit'] : null) ?></p>
    <p class="nb">eigen vermogen op het balanstotaal</p>
  </div>
  <div class="kaart">
    <p class="lbl">Werkkapitaal</p>
    <p class="val <?= $r['werkkapitaal'] < 0 ? 'neg' : '' ?>">
      <?= $r['balans']['sluit'] ? '€ ' . euro($r['werkkapitaal']) : '—' ?></p>
    <p class="nb">vlottende activa min de korte schulden, stand eind <?= h(maandnaam($tot)) ?></p>
  </div>

  <div class="kaart">
    <p class="lbl">Omzet op jaarbasis</p>
    <p class="val">€ <?= euro($r['run_rate']) ?></p>
    <p class="nb"><?= h((string) $r['maanden']) ?> maand(en) doorgerekend naar twaalf</p>
  </div>
  <?php if ($r['groei'] === null): ?>
  <div class="kaart">
    <p class="lbl">Groei tegenover <?= h((string) ($jaar - 1)) ?></p>
    <p class="val">—</p>
    <p class="nb">
      <?php if ($foutVorig): ?>
        Vorig jaar ophalen mislukt: <?= h($foutVorig) ?>
      <?php else: ?>
        <a href="<?= h(url_met(['vorigjaar' => 1, 'periode' => $keuze])) ?>">Vorig jaar laden →</a>
        (twaalf extra Yuki-calls, daarna een week uit de cache)
      <?php endif; ?>
    </p>
  </div>
  <?php elseif (!$r['groei']['volledig']): ?>
  <div class="kaart">
    <p class="lbl">Groei tegenover <?= h((string) ($jaar - 1)) ?></p>
    <p class="val">—</p>
    <p class="nb">vorig boekjaar loopt niet tot <?= h(maandnaam($tot)) ?>, dus niet te vergelijken</p>
  </div>
  <?php else: ?>
  <div class="kaart">
    <p class="lbl">Omzetgroei tegenover <?= h((string) ($jaar - 1)) ?></p>
    <p class="val <?= ($r['groei']['omzet_pct'] ?? 0) < 0 ? 'neg' : '' ?>"><?= pct($r['groei']['omzet_pct']) ?></p>
    <p class="nb">€ <?= euro($k['omzet']) ?> nu tegenover € <?= euro($r['groei']['omzet']) ?> toen,
      zelfde maanden</p>
  </div>
  <div class="kaart">
    <p class="lbl">Brutomarge tegenover <?= h((string) ($jaar - 1)) ?></p>
    <p class="val"><?= pct($k['marge_pct']) ?></p>
    <p class="nb">was <?= pct($r['groei']['marge_pct_vorig']) ?><?php
      if ($k['marge_pct'] !== null && $r['groei']['marge_pct_vorig'] !== null):
        $vp = $k['marge_pct'] - $r['groei']['marge_pct_vorig']; ?>
      · <?= h(($vp >= 0 ? '+' : '') . number_format($vp, 1, ',', '.')) ?> procentpunt<?php endif; ?></p>
  </div>
  <?php endif; ?>
</div>

<details class="ratio-uitleg">
  <summary>Waar deze cijfers vandaan komen</summary>
  <p>
    Alles hierboven komt uit de resultatenrekening en de proefbalans die het dashboard toch al
    ophaalt; alleen de vergelijking met vorig jaar kost extra Yuki-calls. Kosten staan in de hele
    app negatief; voor een ratio worden ze één keer omgedraaid, in <code>ratios()</code>.
  </p>
  <p>
    <strong>Klanten- en leveranciersposten worden op het teken ingedeeld, niet op het
    rekeningnummer.</strong> Een 40-rekening met een creditsaldo — zoals de
    Shopify-vooruitbetalingen — is een schuld en telt ook als schuld, want meetellen als vordering
    zou de current ratio veel te hoog zetten.
  </p>
  <p>
    <strong>DPO</strong> rekent met de ingekochte goederen én diensten
    (€ <?= euro($r['inkoop']) ?> over deze periode): lonen, afschrijvingen, belastingen en
    financiële lasten zitten er niet in, want die lopen niet via een leveranciersfactuur.
    <strong>DIO</strong> rekent alleen met de aankopen handelsgoederen.
  </p>
  <p>Voorraadrekeningen in deze berekening
    <?= $CFG['voorraad_rekeningen'] ?? null ? '(uit config.php)' : '(alles in klasse 3 — stel <code>voorraad_rekeningen</code> in config.php in om dit vast te leggen)' ?>:</p>
  <ul>
    <?php foreach ($r['balans']['gebruikt']['voorraad'] as $code => [$naam, $bedrag]): ?>
      <li><?= h((string) $code) ?> <?= h($naam) ?> — € <?= euro($bedrag) ?></li>
    <?php endforeach; ?>
    <?php if (!$r['balans']['gebruikt']['voorraad']): ?><li>geen enkele met een saldo</li><?php endif; ?>
  </ul>
  <p>Crediteurenrekeningen
    <?= $CFG['crediteuren_rekeningen'] ?? null ? '(uit config.php)' : '(alles in 44 — stel <code>crediteuren_rekeningen</code> in config.php in om dit vast te leggen)' ?>:</p>
  <ul>
    <?php foreach ($r['balans']['gebruikt']['crediteuren'] as $code => [$naam, $bedrag]): ?>
      <li><?= h((string) $code) ?> <?= h($naam) ?> — € <?= euro($bedrag) ?></li>
    <?php endforeach; ?>
    <?php if (!$r['balans']['gebruikt']['crediteuren']): ?><li>geen enkele met een saldo</li><?php endif; ?>
  </ul>
  <p>
    Balans eind <?= h(maandnaam($tot)) ?>: vast € <?= euro($r['balans']['vast']) ?> ·
    voorraad € <?= euro($r['balans']['voorraad']) ?> ·
    vorderingen € <?= euro($r['balans']['vorderingen']) ?> ·
    liquide € <?= euro($r['balans']['liquide']) ?> ·
    korte schulden € <?= euro($r['balans']['kort_vreemd']) ?> ·
    eigen vermogen € <?= euro($r['balans']['eigen_vermogen']) ?>.
    Controle: balansrekeningen minus resultatenrekeningen = € <?= euro($r['balans']['verschil']) ?>
    (<?= $r['balans']['sluit'] ? 'sluit' : 'sluit niet' ?>).
  </p>
</details>
<?php endif; ?>

</div>

<div class="paneel">
  <div class="legenda">
    <span><span class="dot" style="background:var(--reeks-op)"></span>Opbrengsten</span>
    <span><span class="dot" style="background:var(--reeks-kost)"></span>Kosten</span>
    <span><span class="dot" style="background:var(--accent)"></span>Brutomarge %</span>
  </div>
  <div style="position:relative;height:280px"><canvas id="grafiek"></canvas></div>
</div>
<?php endif; ?>

<footer>
  Bank- en klantensaldi zijn standen op een moment, geen optelling over de periode.
  Ze volgen daarom de laatste maand van je selectie.
</footer>

</div>

<script src="thema.js"></script>
<?php if ($k): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.js"></script>
<script>
const IN_BEREIK = <?= json_encode($inBereik) ?>;

const grafiek = new Chart(document.getElementById('grafiek'), {
  data: {
    labels: <?= json_encode($labels) ?>,
    datasets: [
      { type: 'bar', label: 'Opbrengsten', data: <?= json_encode($opbrengsten) ?>,
        borderRadius: 4, maxBarThickness: 26, order: 2, yAxisID: 'y' },
      { type: 'bar', label: 'Kosten', data: <?= json_encode($kosten) ?>,
        borderRadius: 4, maxBarThickness: 26, order: 2, yAxisID: 'y' },
      { type: 'line', label: 'Brutomarge %', data: <?= json_encode($margeReeks) ?>,
        yAxisID: 'y2', order: 1, tension: .3, borderWidth: 2,
        pointRadius: 3, pointHoverRadius: 5, spanGaps: true }
    ]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    plugins: {
      legend: { display: false },
      tooltip: { callbacks: { label: function (c) {
        if (c.dataset.yAxisID === 'y2') {
          return c.parsed.y === null ? '' : 'Brutomarge: ' + c.parsed.y.toLocaleString('nl-BE') + '%';
        }
        return c.dataset.label + ': € ' + c.parsed.y.toLocaleString('nl-BE', { maximumFractionDigits: 0 });
      } } }
    },
    scales: {
      x:  { grid: { display: false } },
      y:  { position: 'left',  ticks: { callback: v => '€' + Math.round(v / 1000) + 'k' } },
      y2: { position: 'right', grid: { display: false }, ticks: { callback: v => v + '%' } }
    }
  }
});

/** Maanden buiten de selectie krijgen een doorzichtige versie van dezelfde kleur. */
function gedempt(kleur, aan) {
  if (aan) return kleur;
  const d = document.createElement('canvas').getContext('2d');
  d.fillStyle = kleur;
  const hex = d.fillStyle;   // altijd genormaliseerd naar #rrggbb
  const r = parseInt(hex.slice(1, 3), 16),
        g = parseInt(hex.slice(3, 5), 16),
        b = parseInt(hex.slice(5, 7), 16);
  return 'rgba(' + r + ',' + g + ',' + b + ',0.22)';
}

window.grafiekKleurenBijwerken = function () {
  const tekst  = cssKleur('--tekst-zacht');
  const raster = cssKleur('--raster');
  const op     = cssKleur('--reeks-op');
  const kost   = cssKleur('--reeks-kost');

  grafiek.data.datasets[0].backgroundColor = IN_BEREIK.map(b => gedempt(op, b));
  grafiek.data.datasets[1].backgroundColor = IN_BEREIK.map(b => gedempt(kost, b));
  grafiek.data.datasets[2].borderColor     = cssKleur('--accent');
  grafiek.data.datasets[2].backgroundColor = cssKleur('--accent');

  ['x', 'y', 'y2'].forEach(function (as) {
    grafiek.options.scales[as].ticks.color = tekst;
    grafiek.options.scales[as].ticks.font  = { size: 11 };
  });
  grafiek.options.scales.y.grid.color = raster;
  grafiek.update('none');
};
window.grafiekKleurenBijwerken();
</script>
<?php endif; ?>
</body>
</html>
