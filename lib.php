<?php
/**
 * lib.php — gedeelde basis voor alle pagina's.
 * Regelt configuratie, inloggen, opmaak en de <head>.
 */

declare(strict_types=1);
error_reporting(E_ALL);

$CFG = require __DIR__ . '/config.php';
date_default_timezone_set($CFG['timezone'] ?? 'Europe/Brussels');

// Een fatale fout mag niet stilletjes een halve pagina opleveren.
// Standaard komt alles in cache/fouten.log; zet 'debug' => true in config.php
// om de melding ook op het scherm te krijgen.
if (!is_dir(__DIR__ . '/cache')) @mkdir(__DIR__ . '/cache', 0755, true);
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/cache/fouten.log');
ini_set('display_errors', !empty($CFG['debug']) ? '1' : '0');

/** Categorieën in vaste volgorde. [calc]-regels worden berekend, niet gemapt. */
const PNL_LIJNEN = [
    ['Omzet',                                            'post'],
    ['Aankopen handelsgoederen',                         'post'],
    ['Bruto marge',                                      'calc'],
    ['Andere bedrijfsopbrengsten',                       'post'],
    ['Kosten gebouw',                                    'post'],
    ['Kosten kantoor',                                   'post'],
    ['Reiskosten',                                       'post'],
    ['Kosten publiciteit',                               'post'],
    ['Autokosten',                                       'post'],
    ['Transportkosten en Bleckmann',                     'post'],
    ['Overige diensten en diverse goederen',             'post'],
    ['Managementvergoedingen en erelonen',               'post'],
    ['Bezoldigingen, RSZ & Pensioenen',                  'post'],
    ['Afschrijvingen',                                   'post'],
    ['Overige bedrijfskosten',                           'post'],
    ['Doorrekeningen',                                   'post'],
    ['Bedrijfsresultaat',                                'calc'],
    ['Financiele opbrengsten',                           'post'],
    ['Financiele kosten',                                'post'],
    ['Uitzonderlijke opbrengsten',                       'post'],
    ['Uitzonderlijke kosten',                            'post'],
    ['Belastingen op het resultaat',                     'post'],
    ['Belastingen op het resultaat van vorige boekjaren', 'post'],
    ['Resultaat van het boekjaar',                       'calc'],
];

/** Weergavenamen met accenten; de sleutels moeten exact op mapping.csv aansluiten. */
const LABELS = [
    'Financiele opbrengsten' => 'Financiële opbrengsten',
    'Financiele kosten'      => 'Financiële kosten',
];

const NIET_TOEGEWEZEN = 'Niet toegewezen';
const MARGE_PCT       = 'Brutomarge %';

function label(string $cat): string
{
    return LABELS[$cat] ?? $cat;
}

// ------------------------------------------------------------------- head --

/**
 * Schrijft de <head>. Het themascript staat er bewust inline en vóór de CSS:
 * anders licht de pagina eerst wit op voor hij donker wordt.
 */
function schrijf_head(string $titel): void
{
    ?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= htmlspecialchars($titel, ENT_QUOTES, 'UTF-8') ?></title>
<script>
(function () {
  var t = null;
  try { t = localStorage.getItem('ohe-thema'); } catch (e) {}
  if (!t) {
    t = (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)
        ? 'donker' : 'licht';
  }
  document.documentElement.setAttribute('data-thema', t);
})();
</script>
<link rel="stylesheet" href="stijl.css?v=<?= bestandsversie('stijl.css') ?>">
<?php
}

/** Tijdstempel van een bestand, zodat de browser een nieuwe versie ophaalt. */
function bestandsversie(string $naam): string
{
    $t = @filemtime(__DIR__ . '/' . $naam);
    return (string) ($t ?: time());
}

/** De knop rechtsboven die tussen licht en donker wisselt. */
function themaknop(): void
{
    echo '<button class="btn icoon" id="themaknop" type="button" onclick="themaWissel()">☾</button>';
}

// ---------------------------------------------------------------- inloggen --

function start_sessie(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Strict',
        'secure'   => !empty($_SERVER['HTTPS']),
        'path'     => '/OHE/Reporting/',
    ]);
    session_start();
}

function vereis_login(array $CFG): void
{
    start_sessie();

    if (isset($_GET['uitloggen'])) {
        $_SESSION = [];
        session_destroy();
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }

    if (!empty($_SESSION['ingelogd'])) return;

    $fout = '';
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['wachtwoord'])) {
        // Kleine vertraging: maakt het raden van wachtwoorden onaantrekkelijk.
        usleep(400000);
        if (hash_equals((string) $CFG['wachtwoord'], (string) $_POST['wachtwoord'])) {
            session_regenerate_id(true);
            $_SESSION['ingelogd'] = true;
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
            exit;
        }
        $fout = 'Onjuist wachtwoord.';
    }

    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    ?>
<!doctype html>
<html lang="nl">
<head><?php schrijf_head('Rapportage'); ?></head>
<body class="inlog">
  <form method="post">
    <h1>Osaka Hockey Europe — rapportage</h1>
    <input type="password" name="wachtwoord" placeholder="Wachtwoord" autofocus autocomplete="current-password">
    <button type="submit">Aanmelden</button>
    <?php if ($fout): ?><p class="fout"><?= htmlspecialchars($fout) ?></p><?php endif; ?>
  </form>
  <script src="thema.js"></script>
</body>
</html>
    <?php
    exit;
}

// ------------------------------------------------------------------ opmaak --

/** Bedrag met punt als duizendtal en komma als decimaal, Belgische stijl. */
function euro(float $v, bool $decimalen = false): string
{
    $s = number_format($v, $decimalen ? 2 : 0, ',', '.');
    return $s === '-0' ? '0' : $s;
}

/** Percentage met één decimaal; null wordt een streepje. */
function pct(?float $v): string
{
    return $v === null ? '—' : number_format($v, 1, ',', '.') . '%';
}

/** Voor kopiëren naar Excel: komma als decimaal, geen duizendtallen. */
function excel_getal(float $v): string
{
    return str_replace('.', ',', number_format($v, 2, '.', ''));
}

function maandnaam(int $m): string
{
    return ['', 'jan', 'feb', 'mrt', 'apr', 'mei', 'jun',
            'jul', 'aug', 'sep', 'okt', 'nov', 'dec'][$m];
}

function maandnaam_lang(int $m): string
{
    return ['', 'januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli',
            'augustus', 'september', 'oktober', 'november', 'december'][$m];
}

// ------------------------------------------------------------- periodefilter --

/** Keuzelijst voor het dashboard: jaar tot nu, de verstreken kwartalen, de maanden. */
function periode_opties(int $tot): array
{
    $o = ['ytd' => 'Jaar tot nu'];
    for ($q = 1; $q <= 4; $q++) {
        if (($q - 1) * 3 + 1 <= $tot) $o['q' . $q] = 'Kwartaal ' . $q;
    }
    for ($m = 1; $m <= $tot; $m++) $o['m' . $m] = ucfirst(maandnaam_lang($m));
    return $o;
}

/** Zet een keuze om in [eerste maand, laatste maand]. Onbekend wordt jaar tot nu. */
function periode_bereik(string $keuze, int $tot): array
{
    if (preg_match('/^q([1-4])$/', $keuze, $m)) {
        $q = (int) $m[1];
        return [($q - 1) * 3 + 1, min($q * 3, $tot)];
    }
    if (preg_match('/^m(\d{1,2})$/', $keuze, $m)) {
        $mm = max(1, min((int) $m[1], $tot));
        return [$mm, $mm];
    }
    return [1, $tot];
}

// ------------------------------------------------------------------ meter --

/** Punt op de wijzerplaat. 180 graden is links, 270 boven, 360 rechts. */
function meter_punt(float $cx, float $cy, float $r, float $hoek): array
{
    $rad = deg2rad($hoek);
    return [$cx + $r * cos($rad), $cy + $r * sin($rad)];
}

function meter_boog(float $cx, float $cy, float $r, float $van, float $tot): string
{
    [$x1, $y1] = meter_punt($cx, $cy, $r, $van);
    [$x2, $y2] = meter_punt($cx, $cy, $r, $tot);
    $groot = ($tot - $van) > 180 ? 1 : 0;
    return sprintf('M %.2f %.2f A %.2f %.2f 0 %d 1 %.2f %.2f', $x1, $y1, $r, $r, $groot, $x2, $y2);
}

/**
 * Halve meter van groen naar rood, met een naald. Lage waarde is goed.
 *
 * Alle afmetingen, lettergroottes en kleuren staan in het element zelf.
 * De stylesheet kan uit de cache komen en dan moet dit nog steeds kloppen;
 * var() met een terugvalwaarde geeft het thema als het wél geladen is.
 */
function meter_svg(?float $waarde, float $groen, float $rood, string $eenheid = 'd'): string
{
    $max = max($rood * 1.6, $groen * 3, 30.0);
    $cx = 100.0; $cy = 96.0; $r = 74.0; $dikte = 15.0;

    $hoekVan = fn(float $v): float => 180.0 + max(0.0, min(1.0, $v / $max)) * 180.0;

    $k = [
        'groen'  => 'var(--meter-groen, #2f9e63)',
        'oranje' => 'var(--meter-oranje, #e0a33a)',
        'rood'   => 'var(--meter-rood, #cf4b4b)',
        'tekst'  => 'var(--tekst, #1c1c1a)',
        'zacht'  => 'var(--tekst-zacht, #78766f)',
        'vlak'   => 'var(--vlak, #ffffff)',
        'flauw'  => 'var(--tekst-flauw, #c3c1ba)',
    ];

    $svg  = '<svg viewBox="0 0 200 112" width="100%" style="display:block;max-width:230px;margin:0 auto"'
          . ' role="img" aria-label="Meter">';

    $svg .= '<path d="' . meter_boog($cx, $cy, $r, 180, $hoekVan($groen)) . '" fill="none" stroke="'
          . $k['groen'] . '" stroke-width="' . $dikte . '"/>';
    $svg .= '<path d="' . meter_boog($cx, $cy, $r, $hoekVan($groen), $hoekVan($rood)) . '" fill="none" stroke="'
          . $k['oranje'] . '" stroke-width="' . $dikte . '"/>';
    $svg .= '<path d="' . meter_boog($cx, $cy, $r, $hoekVan($rood), 360) . '" fill="none" stroke="'
          . $k['rood'] . '" stroke-width="' . $dikte . '"/>';

    foreach ([$groen, $rood] as $grens) {
        [$x1, $y1] = meter_punt($cx, $cy, $r - $dikte / 2, $hoekVan($grens));
        [$x2, $y2] = meter_punt($cx, $cy, $r + $dikte / 2, $hoekVan($grens));
        $svg .= sprintf('<line x1="%.2f" y1="%.2f" x2="%.2f" y2="%.2f" stroke="%s" stroke-width="2"/>',
                        $x1, $y1, $x2, $y2, $k['vlak']);
    }

    $lettertype = 'system-ui, -apple-system, sans-serif';

    if ($waarde !== null) {
        [$nx, $ny] = meter_punt($cx, $cy, $r - $dikte - 5, $hoekVan($waarde));
        $svg .= sprintf('<line x1="%.2f" y1="%.2f" x2="%.2f" y2="%.2f" stroke="%s" stroke-width="3" '
                      . 'stroke-linecap="round"/>', $cx, $cy, $nx, $ny, $k['tekst']);
        $svg .= sprintf('<circle cx="%.2f" cy="%.2f" r="4.5" fill="%s"/>', $cx, $cy, $k['tekst']);
        $svg .= '<text x="' . $cx . '" y="' . ($cy - 20) . '" text-anchor="middle" font-family="'
              . $lettertype . '" font-size="24" font-weight="600" fill="' . $k['tekst'] . '">'
              . h(number_format($waarde, 0, ',', '.')) . h($eenheid) . '</text>';
    } else {
        $svg .= sprintf('<circle cx="%.2f" cy="%.2f" r="4.5" fill="%s"/>', $cx, $cy, $k['flauw']);
        $svg .= '<text x="' . $cx . '" y="' . ($cy - 20) . '" text-anchor="middle" font-family="'
              . $lettertype . '" font-size="24" font-weight="600" fill="' . $k['flauw'] . '">—</text>';
    }

    $svg .= '<text x="14" y="110" font-family="' . $lettertype . '" font-size="10" fill="'
          . $k['zacht'] . '">0</text>';
    $svg .= '<text x="186" y="110" text-anchor="end" font-family="' . $lettertype . '" font-size="10" fill="'
          . $k['zacht'] . '">' . h(number_format($max, 0, ',', '.')) . h($eenheid) . '</text>';
    $svg .= '</svg>';
    return $svg;
}

/**
 * Ontsnapt tekst voor HTML.
 * Neemt ook getallen aan: een rekeningnummer als array-sleutel is een int
 * geworden, en een string-typehint zou hier onder strict_types afbreken.
 */
function h(int|float|string|null $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}
