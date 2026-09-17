<?php
/**
 * klanten.php — wie betaalt er op tijd en wie niet.
 * Gebaseerd op OutstandingDebtorItems: openstaande verkoopfacturen met vervaldatum.
 */

declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/yuki.php';

vereis_login($CFG);
header('X-Robots-Tag: noindex, nofollow');

$forceer = isset($_GET['ververs']);
$fout = '';
$d = null;

$groepFragmenten = (array) ($CFG['groep_klanten'] ?? []);
$b2cFragmenten   = (array) ($CFG['b2c_klanten'] ?? []);

try {
    $y = new Yuki($CFG);
    $d = $y->openstaandeDebiteuren($forceer);
} catch (Throwable $e) {
    $fout = $e->getMessage();
}

/** Hoort deze klantnaam bij de groep? */
function is_groep(string $naam, array $fragmenten): bool
{
    foreach ($fragmenten as $f) {
        if ($f !== '' && stripos($naam, (string) $f) !== false) return true;
    }
    return false;
}

if ($d) {
    foreach ($d['klanten'] as $i => $kl) {
        $d['klanten'][$i]['groep'] = is_groep((string) $kl['contact'], $groepFragmenten);
        $d['klanten'][$i]['b2c']   = is_groep((string) $kl['contact'], $b2cFragmenten);
    }
    foreach ($d['posten'] as $i => $po) {
        $d['posten'][$i]['groep'] = is_groep((string) $po['contact'], $groepFragmenten);
        $d['posten'][$i]['b2c']   = is_groep((string) $po['contact'], $b2cFragmenten);
    }
}

$grensGroen = (float) ($CFG['telaat_groen'] ?? 10);
$grensRood  = (float) ($CFG['telaat_rood'] ?? 30);
?>
<!doctype html>
<html lang="nl">
<head>
<?php schrijf_head('Betaalgedrag klanten — Osaka Hockey Europe'); ?>
<link href="https://cdnjs.cloudflare.com/ajax/libs/tabulator-tables/6.5.2/css/tabulator.min.css" rel="stylesheet">
</head>
<body>
<div class="wrap breed">

<header>
  <div>
    <h1>Betaalgedrag klanten</h1>
    <p class="sub">Openstaande verkoopfacturen, ouderdom gerekend vanaf de vervaldatum.</p>
  </div>
  <div class="knoppen">
    <a class="btn" href="index.php">Dashboard</a>
    <a class="btn" href="pnl.php">Resultatenrekening</a>
    <a class="btn prim" href="?ververs=1">Ververs</a>
    <?php themaknop(); ?>
  </div>
</header>

<?php if ($fout): ?>
  <div class="fout"><strong>Ophalen mislukt.</strong><br><?= h($fout) ?></div>
<?php endif; ?>

<?php if ($d): ?>

<div class="paneel meterpaneel">
  <div class="meterkant">
    <p class="lbl">Aandeel te laat</p>
    <?= meter_svg($d['pct_te_laat'], $grensGroen, $grensRood, '%') ?>
  </div>
  <div class="metertekst">
    <p><strong>€ <?= euro($d['te_laat']) ?></strong> van de € <?= euro($d['totaal']) ?>
       openstaand is over de vervaldatum
       <?php if ($d['pct_te_laat'] !== null): ?>
         — dat is <?= h(number_format($d['pct_te_laat'], 1, ',', '.')) ?>%.
       <?php endif; ?>
    </p>
    <p><?= (int) $d['aantal'] ?> openstaande facturen in totaal.</p>
    <p class="nb">Groen tot <?= (int) $grensGroen ?>% te laat, rood vanaf <?= (int) $grensRood ?>%.
       Aan te passen in config.php.</p>
  </div>
</div>

<div class="kaarten">
  <?php foreach ($d['bakken'] as $b):
    if ($b['aantal'] === 0) continue; ?>
    <div class="kaart">
      <p class="lbl"><?= h($b['label']) ?></p>
      <p class="val <?= $b['bedrag'] < 0 ? 'neg' : '' ?>">€ <?= euro((float) $b['bedrag']) ?></p>
      <p class="nb"><?= (int) $b['aantal'] ?> facturen</p>
    </div>
  <?php endforeach; ?>
</div>

<div class="werkbalk">
  <input type="search" id="zoek" placeholder="Zoek klant of factuurnummer…">
  <select class="btn" id="klantkeuze">
    <option value="">Alle klanten</option>
  </select>
  <a class="btn prim" id="klant-detail-knop" href="#" style="display:none">Bekijk klant →</a>
  <button class="btn" type="button" id="b2cknop" onclick="b2cWissel()">B2C verbergen</button>
  <button class="btn" type="button" id="groepknop" onclick="groepWissel()">Groep verbergen</button>
  <button class="btn" type="button" onclick="toon('klanten')" id="tab-klanten">Per klant</button>
  <button class="btn" type="button" onclick="toon('posten')" id="tab-posten">Per factuur</button>
  <span class="spacer"></span>
  <button class="btn" type="button" onclick="huidige().download('csv','openstaand.csv',{delimiter:';'})">CSV</button>
  <button class="btn" type="button" onclick="huidige().download('xlsx','openstaand.xlsx',{sheetName:'Openstaand'})">Excel</button>
</div>

<div id="tabel-klanten"></div>
<div id="tabel-posten" style="display:none"></div>

<p class="melding">
  Een negatief bedrag is een creditnota of een vooruitbetaling die nog niet is afgeletterd.
  De lijst komt rechtstreeks uit Yuki en wordt een kwartier bewaard.
</p>
<?php endif; ?>

</div>

<script src="thema.js"></script>
<?php if ($d): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/tabulator-tables/6.5.2/js/tabulator.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script>
const KLANTEN = <?= json_encode($d['klanten'], JSON_UNESCAPED_UNICODE) ?>;
const POSTEN  = <?= json_encode($d['posten'], JSON_UNESCAPED_UNICODE) ?>;

function euroJS(v) {
  return v.toLocaleString('nl-BE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function bedragOpmaak(cell) {
  const v = cell.getValue();
  if (v === null || v === undefined || v === '') return '';
  if (v < 0) cell.getElement().classList.add('neg');
  return euroJS(v);
}
/** Hoe later, hoe roder. */
function dagenOpmaak(cell) {
  const v = cell.getValue();
  const el = cell.getElement();
  if (v === null || v === undefined) return '—';
  if (v > 90)      el.classList.add('laat-3');
  else if (v > 30) el.classList.add('laat-2');
  else if (v > 0)  el.classList.add('laat-1');
  return v > 0 ? v + ' te laat' : (-v) + ' te gaan';
}

/**
 * Tabulator bouwt asynchroon op. Vroeger riep deze pagina meteen na de
 * constructor al .redraw() en .setFilter() aan ("Table Not Initialized" in
 * de console, en op de verborgen #tabel-posten zelfs een echte crash:
 * "Cannot read properties of null (reading 'offsetWidth')", omdat Tabulator
 * dan kolombreedtes probeert te meten op een element dat nog display:none
 * staat). Alles wat de tabellen aanraakt wacht daarom op het tableBuilt-
 * event van allebei.
 *
 * Dat event wordt hier via .on() gebonden, meteen na de constructor — niet
 * via een `tableBuilt`-sleutel in de configuratie, die in deze versie van
 * Tabulator stil genegeerd bleek te worden (geen fout, maar de functie werd
 * ook nooit aangeroepen, waardoor filteren voorgoed uitstond). Een
 * setTimeout-vangnet start alsnog op als het event onverhoopt tóch nooit
 * komt, zodat de pagina nooit permanent op slot blijft staan.
 */
let gereedTellet = 0;
let opgestart = false;
function opstarten() {
  if (opgestart) return;
  opgestart = true;
  toon('klanten');
  filtersToepassen();
}
function tabelGereed() {
  gereedTellet++;
  if (gereedTellet >= 2) opstarten();
}

const tabelKlanten = new Tabulator('#tabel-klanten', {
  data: KLANTEN,
  layout: 'fitColumns',
  height: '58vh',
  initialSort: [{ column: 'te_laat', dir: 'desc' }],
  columns: [
    { title: 'Klant', field: 'contact', minWidth: 240, widthGrow: 3,
      formatter: c => (c.getRow().getData().groep ? '<span class="groepmerk">groep</span> ' : '') +
                      ontsnap(c.getValue()) },
    { title: 'Facturen', field: 'aantal', hozAlign: 'right', width: 100 },
    { title: 'Openstaand', field: 'bedrag', hozAlign: 'right', width: 140, formatter: bedragOpmaak },
    { title: 'Waarvan te laat', field: 'te_laat', hozAlign: 'right', width: 150, formatter: bedragOpmaak },
    { title: 'Langst te laat', field: 'oudste', hozAlign: 'right', width: 140,
      formatter: c => c.getValue() === null ? '—' : c.getValue() + ' d' },
    { title: '', field: 'contactid', width: 90, hozAlign: 'center', headerSort: false,
      formatter: () => '<a class="btn">Details</a>',
      cellClick: (e, cell) => klantDetailLink(cell.getRow().getData(), true) }
  ]
});

const tabelPosten = new Tabulator('#tabel-posten', {
  data: POSTEN,
  layout: 'fitColumns',
  height: '58vh',
  initialSort: [{ column: 'te_laat', dir: 'desc' }],
  columns: [
    { title: 'Klant', field: 'contact', minWidth: 200, widthGrow: 3 },
    { title: 'Referentie', field: 'referentie', width: 130 },
    { title: 'Datum', field: 'datum', width: 110 },
    { title: 'Vervaldatum', field: 'verval', width: 120 },
    { title: 'Dagen', field: 'te_laat', hozAlign: 'right', width: 120, formatter: dagenOpmaak },
    { title: 'Openstaand', field: 'bedrag', hozAlign: 'right', width: 130, formatter: bedragOpmaak }
  ]
});

tabelKlanten.on('tableBuilt', tabelGereed);
tabelPosten.on('tableBuilt', tabelGereed);

// Vangnet: als het tableBuilt-event onverwacht nooit afgaat, toch starten na
// een paar seconden in plaats van de pagina voorgoed niet-interactief te laten.
setTimeout(function () {
  if (!opgestart) {
    console.warn('tableBuilt is niet binnen 3s afgegaan voor beide tabellen — noodstart.');
    opstarten();
  }
}, 3000);

function ontsnap(s) {
  const e = document.createElement('div');
  e.textContent = s === null || s === undefined ? '' : s;
  return e.innerHTML;
}

let actief = 'klanten';
function huidige() { return actief === 'klanten' ? tabelKlanten : tabelPosten; }

function toon(welke) {
  actief = welke;
  document.getElementById('tabel-klanten').style.display = welke === 'klanten' ? '' : 'none';
  document.getElementById('tabel-posten').style.display  = welke === 'posten'  ? '' : 'none';
  document.getElementById('tab-klanten').classList.toggle('prim', welke === 'klanten');
  document.getElementById('tab-posten').classList.toggle('prim', welke === 'posten');
  if (opgestart) huidige().redraw(true);
}

/**
 * Alle filters (zoekveld, klantkeuze, groep, B2C) worden hier samen opgebouwd
 * en in één keer met setFilter() toegepast. setFilter vervangt de hele stapel;
 * addFilter/removeFilter ernaast gebruiken zorgt ervoor dat het zoekveld de
 * andere filters ongemerkt wist. Daarom loopt alles door deze ene functie.
 */
const klantSelect = document.getElementById('klantkeuze');
Array.from(new Set(KLANTEN.map(k => k.contact)))
  .sort((a, b) => a.localeCompare(b, 'nl'))
  .forEach(naam => {
    const optie = document.createElement('option');
    optie.value = naam;
    optie.textContent = naam;
    klantSelect.appendChild(optie);
  });

/**
 * Verwijst naar de detailpagina van één klant. contactid komt rechtstreeks
 * uit Yuki's ContactID, dus die is stabiel ook als een klant hernoemd wordt —
 * op naam matchen zou daar stuklopen.
 */
function klantDetailUrl(data) {
  if (!data || !data.contactid) return null;
  return 'klant.php?id=' + encodeURIComponent(data.contactid) + '&naam=' + encodeURIComponent(data.contact);
}
function klantDetailLink(data, navigeer) {
  const url = klantDetailUrl(data);
  if (url && navigeer) window.location.href = url;
  return url;
}
function detailKnopBijwerken() {
  const knop = document.getElementById('klant-detail-knop');
  const naam = klantSelect.value;
  const rij = KLANTEN.find(k => k.contact === naam);
  const url = naam !== '' ? klantDetailUrl(rij) : null;
  if (url) {
    knop.href = url;
    knop.style.display = '';
  } else {
    knop.style.display = 'none';
  }
}

let groepVerborgen = false;
let b2cVerborgen = true; // standaard op B2B gericht; B2C staat één klik verderop.

function filtersToepassen() {
  if (!opgestart) return; // tabellen zijn nog niet klaar om gefilterd te worden.
  const zoekTekst = document.getElementById('zoek').value.trim();
  const gekozenKlant = klantSelect.value;

  const gemeenschappelijk = [];
  if (gekozenKlant !== '') gemeenschappelijk.push({ field: 'contact', type: '=', value: gekozenKlant });
  if (groepVerborgen) gemeenschappelijk.push({ field: 'groep', type: '!=', value: true });
  if (b2cVerborgen) gemeenschappelijk.push({ field: 'b2c', type: '!=', value: true });

  const klantenFilters = [...gemeenschappelijk];
  const postenFilters = [...gemeenschappelijk];

  // Het zoekveld wordt genegeerd zodra er één specifieke klant gekozen is.
  if (gekozenKlant === '' && zoekTekst !== '') {
    klantenFilters.push({ field: 'contact', type: 'like', value: zoekTekst });
    postenFilters.push([
      { field: 'contact', type: 'like', value: zoekTekst },
      { field: 'referentie', type: 'like', value: zoekTekst }
    ]);
  }

  tabelKlanten.setFilter(klantenFilters);
  tabelPosten.setFilter(postenFilters);
}

function groepWissel() {
  groepVerborgen = !groepVerborgen;
  const knop = document.getElementById('groepknop');
  if (knop) knop.textContent = groepVerborgen ? 'Groep tonen' : 'Groep verbergen';
  filtersToepassen();
}

function b2cWissel() {
  b2cVerborgen = !b2cVerborgen;
  document.getElementById('b2cknop').textContent = b2cVerborgen ? 'B2C tonen' : 'B2C verbergen';
  filtersToepassen();
}

document.getElementById('b2cknop').textContent = b2cVerborgen ? 'B2C tonen' : 'B2C verbergen';
document.getElementById('groepknop').textContent = groepVerborgen ? 'Groep tonen' : 'Groep verbergen';

klantSelect.addEventListener('change', function () {
  document.getElementById('zoek').value = '';
  filtersToepassen();
  detailKnopBijwerken();
});

document.getElementById('zoek').addEventListener('input', function () {
  if (this.value.trim() !== '') klantSelect.value = '';
  filtersToepassen();
  detailKnopBijwerken();
});
</script>
<?php endif; ?>
</body>
</html>
