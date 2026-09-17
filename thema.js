/* thema.js — licht of donker, onthouden per browser.
   De initiële keuze wordt al in de <head> gezet (zie lib.php), zodat de
   pagina niet eerst wit oplicht voor hij donker wordt. */

(function () {
  const SLEUTEL = 'ohe-thema';

  function huidig() {
    return document.documentElement.getAttribute('data-thema') || 'licht';
  }

  function zet(thema) {
    document.documentElement.setAttribute('data-thema', thema);
    try { localStorage.setItem(SLEUTEL, thema); } catch (e) { /* privémodus */ }
    knopBijwerken();
    if (window.grafiekKleurenBijwerken) window.grafiekKleurenBijwerken();
  }

  function knopBijwerken() {
    const k = document.getElementById('themaknop');
    if (!k) return;
    const donker = huidig() === 'donker';
    k.textContent = donker ? '☀' : '☾';
    k.setAttribute('aria-label', donker ? 'Naar lichte weergave' : 'Naar donkere weergave');
    k.title = k.getAttribute('aria-label');
  }

  window.themaWissel = function () {
    zet(huidig() === 'donker' ? 'licht' : 'donker');
  };

  document.addEventListener('DOMContentLoaded', knopBijwerken);

  // Volgt het systeem zolang er nog niet handmatig gekozen is.
  if (window.matchMedia) {
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function (e) {
      let gekozen = null;
      try { gekozen = localStorage.getItem(SLEUTEL); } catch (err) { /* niets */ }
      if (!gekozen) {
        document.documentElement.setAttribute('data-thema', e.matches ? 'donker' : 'licht');
        knopBijwerken();
        if (window.grafiekKleurenBijwerken) window.grafiekKleurenBijwerken();
      }
    });
  }
})();

/** Leest een kleur uit de CSS-variabelen, zodat de grafiek het thema volgt. */
function cssKleur(naam) {
  return getComputedStyle(document.documentElement).getPropertyValue(naam).trim();
}
