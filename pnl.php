<?php
/**
 * pnl.php — resultatenrekening per maand, als Tabulator-tabel.
 *
 * PHP rekent en levert JSON; Tabulator doet de weergave. Dat geeft
 * sorteren, filteren, kolommen aan- en uitzetten en export zonder dat
 * ik dat allemaal zelf moet bouwen.
 */

declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/yuki.php';

vereis_login($CFG);
header('X-Robots-Tag: noindex, nofollow');

$jaar    = (int) date('Y');
$vorig   = isset($_GET['vorig']);
$forceer = isset($_GET['ververs']);
$project = trim((string) ($_GET['project'] ?? ''));
$fout    = '';
$dit = $vor = null;
$projecten = [];

try {
    $y   = new Yuki($CFG);
    $map = lees_mapping();
    $projecten = $y->projecten();
    if ($project !== '' && !isset($projecten[$project])) $project = '';

    $haal = function (int $j) use ($y, $project, $forceer) {
        return $project === ''
            ? $y->maandmutaties($j, $forceer)
            : $y->maandmutatiesProject($project, $j, $forceer);
    };

    $dit = bouw_pnl($haal($jaar), $map);
    if ($vorig) $vor = bouw_pnl($haal($jaar - 1), $map);
} catch (Throwable $e) {
    $fout = $e->getMessage();
}

/** Bewaart het projectfilter bij het volgen van een link. */
function url(array $extra = []): string
{
    global $project, $vorig;
    $q = [];
    if ($project !== '') $q['project'] = $project;
    if ($vorig) $q['vorig'] = '1';
    foreach ($extra as $k => $v) {
        if ($v === null) unset($q[$k]); else $q[$k] = $v;
    }
    return 'pnl.php' . ($q ? '?' . http_build_query($q) : '');
}

/** Alle regels in vaste volgorde, met de percentageregel en de restpost erbij. */
function zichtbare_lijnen(array $pnl): array
{
    $uit = [];
    foreach (PNL_LIJNEN as [$naam, $soort]) {
        $uit[] = [$naam, $soort];
        if ($naam === 'Bruto marge') $uit[] = [MARGE_PCT, 'pct'];
        if ($naam === 'Doorrekeningen' && !empty($pnl['zonder_categorie'])) {
            $uit[] = [NIET_TOEGEWEZEN, 'post'];
        }
    }
    return $uit;
}

function marge_pct(array $pnl, int $m): ?float
{
    $omzet = $pnl['rijen']['Omzet'][$m] ?? 0.0;
    if (abs($omzet) < 0.005) return null;
    return ($pnl['rijen']['Bruto marge'][$m] ?? 0.0) / $omzet * 100;
}

function rijtotaal(array $pnl, string $naam): float
{
    $t = 0.0;
    for ($m = 1; $m <= $pnl['maanden']; $m++) $t += $pnl['rijen'][$naam][$m] ?? 0.0;
    return $t;
}

/** Het jaartotaal van een percentage is totale marge gedeeld door totale omzet. */
function marge_pct_totaal(array $pnl): ?float
{
    $omzet = rijtotaal($pnl, 'Omzet');
    if (abs($omzet) < 0.005) return null;
    return rijtotaal($pnl, 'Bruto marge') / $omzet * 100;
}

/** Rekeningnummers van een categorie, uit beide jaren samen, altijd als string. */
function rekeningen_van(string $cat, array $dit, ?array $vor): array
{
    $codes = array_map('strval', array_keys($dit['detail'][$cat] ?? []));
    if ($vor) {
        foreach (array_keys($vor['detail'][$cat] ?? []) as $c) {
            if (!in_array((string) $c, $codes, true)) $codes[] = (string) $c;
        }
    }
    return $codes;
}

// ------------------------------------------------------------------- data --

$rijen = [];
$nDit = $dit ? $dit['maanden'] : 0;
$nVor = $vor ? $vor['maanden'] : 0;

if ($dit) {
    foreach (zichtbare_lijnen($dit) as [$naam, $soort]) {
        $r = ['naam' => label($naam), 'soort' => $soort, 'code' => null];

        for ($m = 1; $m <= $nDit; $m++) {
            $r['m' . $m] = $soort === 'pct'
                ? marge_pct($dit, $m)
                : round($dit['rijen'][$naam][$m] ?? 0.0, 2);
        }
        $r['tot'] = $soort === 'pct' ? marge_pct_totaal($dit) : round(rijtotaal($dit, $naam), 2);

        if ($vor) {
            for ($m = 1; $m <= $nVor; $m++) {
                $r['p' . $m] = $soort === 'pct'
                    ? marge_pct($vor, $m)
                    : round($vor['rijen'][$naam][$m] ?? 0.0, 2);
            }
            $r['ptot'] = $soort === 'pct' ? marge_pct_totaal($vor) : round(rijtotaal($vor, $naam), 2);
        }

        // Onderliggende grootboekrekeningen.
        if ($soort === 'post') {
            $kinderen = [];
            foreach (rekeningen_van($naam, $dit, $vor) as $code) {
                $dNaam = $dit['detail'][$naam][$code]['naam']
                      ?? ($vor['detail'][$naam][$code]['naam'] ?? '');
                $k = ['naam' => $code . ' — ' . $dNaam, 'soort' => 'detail', 'code' => $code];
                $t = 0.0;
                for ($m = 1; $m <= $nDit; $m++) {
                    $v = round($dit['detail'][$naam][$code]['maanden'][$m] ?? 0.0, 2);
                    $k['m' . $m] = $v;
                    $t += $v;
                }
                $k['tot'] = round($t, 2);
                if ($vor) {
                    $tv = 0.0;
                    for ($m = 1; $m <= $nVor; $m++) {
                        $v = round($vor['detail'][$naam][$code]['maanden'][$m] ?? 0.0, 2);
                        $k['p' . $m] = $v;
                        $tv += $v;
                    }
                    $k['ptot'] = round($tv, 2);
                }
                $kinderen[] = $k;
            }
            if ($kinderen) $r['_children'] = $kinderen;
        }

        $rijen[] = $r;
    }
}
?>
<!doctype html>
<html lang="nl">
<head>
<?php schrijf_head('Resultatenrekening — Osaka Hockey Europe'); ?>
<link href="https://cdnjs.cloudflare.com/ajax/libs/tabulator-tables/6.5.2/css/tabulator.min.css" rel="stylesheet">
</head>
<body>
<div class="wrap breed">

<header>
  <div>
    <h1>Resultatenrekening <?= $jaar ?><?= $vor ? ' en ' . ($jaar - 1) : '' ?><?= $project !== '' ? ' — ' . h($projecten[$project]) : '' ?></h1>
    <p class="sub">Bedragen in euro. Opbrengsten positief, kosten negatief.
      Klap een categorie open voor de rekeningen, klik daarna op een bedrag voor de boekingen.</p>
  </div>
  <div class="knoppen">
    <a class="btn" href="index.php">Dashboard</a>
    <a class="btn" href="klanten.php">Klanten</a>
    <?php if ($projecten): ?>
      <select class="btn" onchange="location.href=this.value">
        <option value="<?= h(url(['project' => null])) ?>"<?= $project === '' ? ' selected' : '' ?>>Alle projecten</option>
        <?php foreach ($projecten as $code => $naam): ?>
          <option value="<?= h(url(['project' => $code])) ?>"<?= $project === (string) $code ? ' selected' : '' ?>>
            <?= h($naam) ?>
          </option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
    <?php if ($vor): ?>
      <a class="btn" href="<?= h(url(['vorig' => null])) ?>">Vorig jaar verbergen</a>
    <?php else: ?>
      <a class="btn" href="<?= h(url(['vorig' => '1'])) ?>">Vorig jaar tonen</a>
    <?php endif; ?>
    <a class="btn prim" href="<?= h(url(['ververs' => '1'])) ?>">Ververs</a>
    <?php themaknop(); ?>
  </div>
</header>

<?php if ($fout): ?>
  <div class="fout"><strong>Ophalen mislukt.</strong><br><?= h($fout) ?></div>
<?php endif; ?>

<?php if ($dit): ?>
<div class="werkbalk">
  <input type="search" id="zoek" placeholder="Zoek categorie of rekening…">
  <button class="btn" type="button" onclick="tabel.getRows().forEach(r => r.treeExpand())">Alles uitklappen</button>
  <button class="btn" type="button" onclick="tabel.getRows().forEach(r => r.treeCollapse())">Inklappen</button>
  <button class="btn" type="button" onclick="tabel.clearSort()">Herstel volgorde</button>
  <span class="spacer"></span>
  <button class="btn" type="button" onclick="tabel.copyToClipboard('active')">Kopieer</button>
  <button class="btn" type="button" onclick="tabel.download('csv','resultatenrekening.csv',{delimiter:';'})">CSV</button>
  <button class="btn" type="button" onclick="tabel.download('xlsx','resultatenrekening.xlsx',{sheetName:'P&L'})">Excel</button>
</div>

<div id="tabel"></div>
<div id="boekingen"></div>

<p class="melding">
  <?php if ($project !== ''): ?>
    Je kijkt naar één project. Alleen boekingen met projectcode
    <?= h($project) ?> tellen mee, dus de totalen wijken af van de volledige administratie.
  <?php endif; ?>
  Rechtsklik of gebruik het menuutje in een kolomkop om kolommen te verbergen.
  Sorteren mag, maar dan staat de resultatenrekening niet meer in de gebruikelijke
  volgorde — met &ldquo;Herstel volgorde&rdquo; zet je dat terug.
  <?php if (!$vor): ?>Vorig jaar wordt pas opgehaald als je erom vraagt, om API-calls te sparen.<?php endif; ?>
</p>
<?php endif; ?>

</div>

<script src="thema.js"></script>
<?php if ($dit): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/tabulator-tables/6.5.2/js/tabulator.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script>
const DATA   = <?= json_encode($rijen, JSON_UNESCAPED_UNICODE) ?>;
const JAAR   = <?= $jaar ?>;
const N_DIT  = <?= $nDit ?>;
const N_VOR  = <?= $nVor ?>;
const MND    = ['', 'jan', 'feb', 'mrt', 'apr', 'mei', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec'];
const MND_L  = ['', 'januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli',
                'augustus', 'september', 'oktober', 'november', 'december'];

function euroJS(v, decimalen) {
  return v.toLocaleString('nl-BE', {
    minimumFractionDigits: decimalen ? 2 : 0,
    maximumFractionDigits: decimalen ? 2 : 0
  });
}

/** Bedrag of percentage, afhankelijk van het soort regel. */
function getalOpmaak(cell) {
  const v = cell.getValue();
  const d = cell.getRow().getData();
  if (v === null || v === undefined || v === '') return '';
  const el = cell.getElement();
  if (v < 0) el.classList.add('neg');
  else if (v === 0) el.classList.add('nul');
  if (d.soort === 'pct') return euroJS(v, true) + '%';
  return euroJS(v, false);
}

/** Menu in elke kolomkop om kolommen te verbergen. */
function kolomMenu() {
  const menu = [];
  for (const kolom of this.getColumns()) {
    if (!kolom.getDefinition().title) continue;
    menu.push({
      label: '<span class="kolomvink">' + (kolom.isVisible() ? '☑' : '☐') + ' ' +
             kolom.getDefinition().title + '</span>',
      action: function (e) {
        e.stopPropagation();
        kolom.toggle();
      }
    });
  }
  return menu;
}

/** Klik op een bedrag van een rekeningregel: boekingen van die maand ophalen. */
function celKlik(e, cell, jaar, maand) {
  const d = cell.getRow().getData();
  if (!d.code) return;
  if (!cell.getValue()) return;
  toonBoekingen(d.code, d.naam, jaar, maand);
}

function maandKolommen(prefix, jaar, aantal) {
  const kolommen = [];
  for (let m = 1; m <= aantal; m++) {
    kolommen.push({
      title: MND[m],
      field: prefix + m,
      hozAlign: 'right',
      width: 96,
      headerSortStartingDir: 'desc',
      formatter: getalOpmaak,
      cellClick: function (e, cell) { celKlik(e, cell, jaar, m); }
    });
  }
  kolommen.push({
    title: 'Totaal', field: prefix === 'm' ? 'tot' : 'ptot',
    hozAlign: 'right', width: 110, cssClass: 'kolom-totaal', formatter: getalOpmaak
  });
  return kolommen;
}

const kolommen = [
  {
    title: 'Categorie', field: 'naam', frozen: true, minWidth: 260, widthGrow: 2,
    headerSort: false, headerFilter: false
  },
  { title: String(JAAR), columns: maandKolommen('m', JAAR, N_DIT) }
];
if (N_VOR > 0) {
  kolommen.push({ title: String(JAAR - 1), columns: maandKolommen('p', JAAR - 1, N_VOR) });
}

const tabel = new Tabulator('#tabel', {
  data: DATA,
  columns: kolommen,
  columnDefaults: { headerMenu: kolomMenu, headerHozAlign: 'right' },
  layout: 'fitDataStretch',
  height: '72vh',
  dataTree: true,
  dataTreeStartExpanded: false,
  dataTreeChildIndent: 18,
  dataTreeElementColumn: 'naam',
  clipboard: true,
  clipboardCopyRowRange: 'active',
  rowFormatter: function (row) {
    const d = row.getData();
    const el = row.getElement();
    if (d.soort === 'calc')   el.classList.add('rij-calc');
    if (d.soort === 'pct')    el.classList.add('rij-pct');
    if (d.soort === 'detail') el.classList.add('rij-detail');
  }
});

// Zoeken over categorie- en rekeningnamen tegelijk.
document.getElementById('zoek').addEventListener('input', function () {
  const t = this.value.trim();
  if (t === '') tabel.clearFilter();
  else tabel.setFilter('naam', 'like', t);
});

// ------------------------------------------------------------- boekingen ---

async function toonBoekingen(code, naam, jaar, maand) {
  const doel = document.getElementById('boekingen');
  doel.innerHTML = '<div class="bk"><div class="bk-laden">Boekingen ophalen…</div></div>';
  doel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

  let d;
  try {
    const res = await fetch('transacties.php?rekening=' + encodeURIComponent(code) +
                            '&jaar=' + jaar + '&maand=' + maand);
    d = await res.json();
    if (d.fout) throw new Error(d.fout);
  } catch (e) {
    doel.innerHTML = '<div class="bk"><div class="bk-fout">Ophalen mislukt: ' +
                     ontsnap(e.message || String(e)) + '</div></div>';
    return;
  }

  let html = '<div class="bk">';
  html += '<div class="bk-kop"><strong>' + ontsnap(naam) + '</strong> · ' +
          MND_L[maand] + ' ' + jaar + ' · ' + d.aantal + ' boekingen · totaal € ' +
          euroJS(d.totaal, true) +
          '<button class="bk-sluit" onclick="document.getElementById(\'boekingen\').innerHTML=\'\'">sluiten</button></div>';

  if (!d.groepen.length) {
    html += '<div class="bk-leeg">Geen boekingen gevonden.</div>';
  } else {
    html += '<table class="bk-tabel"><thead><tr><th>Omschrijving</th><th>Aantal</th><th>Bedrag</th></tr></thead><tbody>';
    d.groepen.forEach(function (g) {
      html += '<tr><td>' + ontsnap(g.oms) + '</td><td>' + g.aantal + '</td><td class="' +
              (g.bedrag < 0 ? 'neg' : '') + '">€ ' + euroJS(g.bedrag, true) + '</td></tr>';
    });
    if (d.rest_aantal > 0) {
      html += '<tr class="bk-rest"><td>Overige omschrijvingen</td><td>' + d.rest_aantal +
              '</td><td class="' + (d.rest_bedrag < 0 ? 'neg' : '') + '">€ ' +
              euroJS(d.rest_bedrag, true) + '</td></tr>';
    }
    html += '</tbody></table>';

    if (d.grootste && d.grootste.length) {
      html += '<details class="bk-los"><summary>Grootste losse boekingen</summary>' +
              '<table class="bk-tabel"><tbody>';
      d.grootste.forEach(function (l) {
        html += '<tr><td>' + l.datum + '</td><td>' + ontsnap(l.oms) + '</td><td class="' +
                (l.bedrag < 0 ? 'neg' : '') + '">€ ' + euroJS(l.bedrag, true) + '</td></tr>';
      });
      html += '</tbody></table></details>';
    }
  }
  doel.innerHTML = html + '</div>';
}

function ontsnap(s) {
  const d = document.createElement('div');
  d.textContent = s;
  return d.innerHTML;
}
</script>
<?php endif; ?>
</body>
</html>
