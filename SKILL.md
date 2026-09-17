---
name: yuki-reporting-dashboard
description: Build and maintain the Osaka Hockey Europe reporting dashboard on the Yuki accounting API — architecture, GL-account mapping rules, P&L table spec, and a running log of problems already solved. Use this skill whenever the work touches Yuki, the reporting dashboard, the P&L data sheet, GL-account mapping, cost categories, or Osaka Hockey Europe figures — including small requests like "why is this account unmapped", "add a metric", "the refresh is slow", or "fix the margin calculation". Also use it before writing any Yuki API call, and update the Lessons learned section whenever something breaks or a decision changes.
---

# Yuki reporting dashboard

A hosted reporting dashboard over the Yuki bookkeeping API for **Osaka Hockey Europe**.

Not Exzellence. Tessa runs two things; this project is the hockey one. If a request
mentions clinic treatments, mesoestetic, or Instagram, it is the other side of her work
and this skill does not apply.

## Working via GitHub

Two private repos, split by app: **`OHE_Dashboard`** (this one — dashboard + P&L, matches
this skill) and **`OHE_Closing`** (a separate month-end closing checklist app, shares the
same Yuki key/administration but is a different tool with its own `check.php`/`todo.php`/
`checks/`). Don't conflate the two when a request mentions "the closing tool" — this skill
covers the dashboard only.

No live sync exists between this repo and a Claude session — each conversation starts
with an empty container. The working pattern:

1. User pastes a fine-grained PAT (read/contents scope, sometimes write) + repo name(s) at
   the start of a session.
2. Claude `git clone`s fresh into the container and reads only the files relevant to the
   task at hand, not the whole repo by default.
3. The token is used only for that session's clone/push — never written to a project file,
   memory, or anything that persists past the conversation. User re-pastes it next time.
4. Neither repo ships a `.gitignore`; `cache/` (session IDs, balance/debtor JSON) and
   `config.php` are committed as-is. Not currently treated as a live secret leak since
   `config.php`'s `yuki_api_key` is left blank in the repo and the committed password is a
   placeholder — but if a real key or password is ever put in `config.php` for testing,
   it must be scrubbed from history before pushing, not just removed in a later commit.

## What it is

Two surfaces on one app:

1. **Dashboard** — revenue and costs over time, gross margin, outstanding invoices,
   cash and bank balance. Manual refresh button, usable by others without Tessa present.
2. **P&L data sheet** — month-by-month table for the current year. Prior year is loaded
   alongside but hidden until requested. Must be copyable into Excel with formatting intact.

## Architecture

- Hosted on a subdomain of **exzellence.be** (Tessa's own domain), even though the figures
  are Osaka Hockey Europe's. Stack to be confirmed — see Open questions.
- Small backend. It holds the Yuki API key in environment variables and is the only thing
  that talks to Yuki. Never put the key in frontend code.
- **Config and `mapping.csv` must sit outside the web root**, or be denied by the web
  server. On shared hosting a file dropped next to `index.php` is world-readable over HTTPS,
  and that is the likeliest way this project leaks.
- Password-protected page, `noindex`, HTTPS only. This is company financial data on the
  public internet, on a domain that also hosts a public business site.
- Server-side cache, roughly 15 minutes, refreshed by the button. The cache exists to
  protect the daily call cap, not for speed.
- Key rotation lives in the Yuki portal. If anything looks wrong, revoke and regenerate
  rather than debugging in place.

## Yuki API essentials

Base URL `https://api.yukiworks.be/ws/` (serves both BE and NL). Namespace
`http://www.theyukicompany.com/`. Confirmed against the live Osaka administration on
2026-09-10 unless marked otherwise.

- **SOAP only.** PHP's built-in `SoapClient` against `<Service>.asmx?WSDL` works fine.
- **Session flow**: `Authenticate(accessKey)` → `AuthenticateResult` is the session ID.
  Cache it; it lasts about 24h. Re-authenticate and retry once on a stale-session fault.
- **Parameter casing differs per service**: `sessionID` (capital D) on Accounting,
  AccountingInfo, Contact and Archive; `sessionId` on Sales and Purchase.
- **Two services, two response shapes.** This is the trap:
  - `GetGLAccountScheme` (AccountingInfo) returns **typed PHP objects**, not XML:
    `$r->GetGLAccountSchemeResult->GlAccount` is an array of stdClass with lowercase
    fields `code`, `type`, `subtype`, `isEnabled`, `descripton`, `isVATApplicable`.
    **`descripton` is misspelled in Yuki's own API** — read that exact name.
  - `GLAccountBalance` (Accounting) returns **an XML string** in
    `$r->GLAccountBalanceResult->any`, shaped
    `<GLAccountBalance><GLAccount Code="700000" BalanceType="W"><Description/><Amount/></GLAccount>…`.
    `Code` and `BalanceType` are **attributes**; `Description` and `Amount` are children.
  - A single result comes back as one object, several as an array. Always normalise.
- **Credit is negative.** Yuki reports 100000 Geplaatst kapitaal as a negative amount, so
  revenue arrives negative and costs positive. **Multiply by −1 at read time** to reach the
  house convention (income positive, costs negative). Never patch the sign in the display
  layer; two flips in different places is how a P&L silently inverts.
- **Administration ID** is required alongside the key. The domain holds exactly one
  administration, Osaka Hockey Europe, and its ID matches config. Still never default to
  "the first one" — supply it explicitly. Key in `YUKI_API_KEY`, ID in
  `YUKI_ADMINISTRATION_ID`; neither in code, in the repo, or in this file.
- **Rate limit**: 1,000 SOAP calls per day per domain. Paid upgrades exist (5,000 for about
  €10.50/month, 10,000 for about €105/month) but the design should not need them.
- **Useful operations**: `Administrations`, `GLAccountBalance(sessionID,
  administrationID, transactionDate)`, `GLAccountBalanceFiscal` (same plus fiscal
  corrections), `GLAccountTransactions(…, GLAccountCode, StartDate, EndDate)`,
  `GetGLAccountScheme`, `GetPeriodDateTable`, `GetStartBalanceByGlAccount`,
  `GetTransactions`, `GetTransactionDetails`.

**Fetch strategy — confirmed 2026-09-10.** `GLAccountBalance` is a trial balance *at a
date*, and P&L accounts accumulate within the fiscal year, so a month's movement is the
difference between two month-end balances. Roughly 26 calls for two years, versus 191 if
transactions were pulled per account. Rows carry `BalanceType`: `B` for balance sheet,
`W` for P&L — 112 B-rows and about 110 W-rows in this administration. Filtering on
`BalanceType="W"` is cleaner than testing whether the code starts with 6 or 7.

At the **fiscal year boundary the cumulative resets**, so the first month of a fiscal year
takes its balance as-is instead of subtracting the previous month. Getting the boundary
wrong produces one absurd month and eleven correct ones — the kind of error that looks
like a data problem rather than a code problem.

`GetPeriodDateTable` takes `yearID`, not `year`; the wrong name fails with
`SOAP-ERROR: Encoding: object has no 'yearID' property`.

## GL-account mapping

Tessa owns the mapping. The confirmed table lives in `mapping.csv` (gl_code; gl_naam;
categorie), built from her reviewed MAR export on 2026-09-10. 191 P&L accounts, all
categorised. Three accounts — 693000, 693100, 793000 — are marked `NIET IN P&L`
(resultaatverwerking); including them would double-count the year's result.

The app never invents a category.

**A category may hold both class 6 and class 7 accounts.** Deliberate, not a mistake:

- `Bezoldigingen, RSZ & Pensioenen` contains 743000 and 743001, so recuperated staff
  costs net against the wage line.
- `Doorrekeningen` contains 616902 (cost) and 741902 (income).

Never infer sign from the account class. Always take the signed balance as Yuki reports
it and sum. Code that assumes "6 = cost, 7 = income" will produce wrong subtotals here.

**The unmapped-account protocol is non-negotiable:**

1. On every refresh, pull the full GL account scheme and compare it against `mapping.csv`.
2. Any account carrying a balance with no category is flagged to the user as a mapping request.
3. Flagged accounts still appear in the P&L under a visible "Niet toegewezen" line, so the
   totals always reconcile. Never silently drop an unmapped account — a P&L that quietly
   loses money is worse than an ugly one.

Expect this to fire every few months. Bookkeepers add accounts; that is normal, not a bug.

Gross margin = Omzet + Aankopen handelsgoederen, per the mapping. Note that Tessa moved
603000 (Algemene onderaanneming) out of Aankopen handelsgoederen into Overige diensten,
so subcontracting sits below the margin line, not inside it.

## P&L structure

Fixed line order, exactly as below. Lines marked **[calc]** are computed subtotals — they
have no GL accounts and must never be mapped.

```
Omzet
Aankopen handelsgoederen
Bruto marge                                          [calc]
Andere bedrijfsopbrengsten
Kosten gebouw
Kosten kantoor
Reiskosten
Kosten publiciteit
Autokosten
Transportkosten en Bleckmann
Overige diensten en diverse goederen
Managementvergoedingen en erelonen
Bezoldigingen, RSZ & Pensioenen
Afschrijvingen
Overige bedrijfskosten
Doorrekeningen                                       (616902 + 741902 only)
Bedrijfsresultaat                                    [calc]
Financiële opbrengsten
Financiële kosten
Uitzonderlijke opbrengsten
Uitzonderlijke kosten
Belastingen op het resultaat
Belastingen op het resultaat van vorige boekjaren
Resultaat van het boekjaar                           [calc]
```

Subtotal formulas:

- Bruto marge = Omzet + Aankopen handelsgoederen
- Bedrijfsresultaat = Bruto marge + Andere bedrijfsopbrengsten + all lines from
  Kosten gebouw through Doorrekeningen
- Resultaat van het boekjaar = Bedrijfsresultaat + every line below it

**Sign convention**: store every amount signed so that a plain sum produces the subtotal —
income positive, costs negative. Do not store costs as positive and subtract them in the
formula; that is where sign bugs breed. Display can still drop the minus sign if preferred.

`Doorrekeningen` is a netted line built from exactly two accounts, 616902 (cost) and
741902 (income), and it sits **inside** Bedrijfsresultaat. Because those two accounts are
pulled out of the categories they would otherwise fall into, the mapping check must treat
them as mapped, or they will flag as unmapped forever.

## P&L table spec

- Rows: the lines above, in that order. Columns: months.
- Current year by default. Prior year loads with it but stays hidden behind a toggle.
- Excel-friendly copy: real tab-separated values with plain numbers — no currency symbols
  baked into the cell, no thousands separators that break on paste, no merged cells.
- Test the copy path by actually pasting into a spreadsheet. It is the feature most likely
  to look fine on screen and arrive broken.

## Open questions

Fill these in as they get answered; delete them once settled.

- Per-invoice aging (which customer owes what, how long overdue) still needs the Sales or
  Contact service. The dashboard currently shows only the 40x balance total.
- Whether the accountant needs a login.

## Receivables and projects

Found by reading the WSDL with `__getFunctions()` / `__getTypes()` rather than guessing —
that costs no API calls and is the fastest way to settle a question about this API.

- **`OutstandingDebtorItems(sessionID, administrationID, includeBankTransactions,
  sortOrder)`** returns open customer items as anyXML. `sortOrder` values:
  `ContactAsc/Desc`, `AmountAsc/Desc`, `DateAsc/Desc`. Each `<Item ID>` carries `Date`,
  `Description`, `Contact`, `ContactID`, `OpenAmount`, `OriginalAmount`, `Type`,
  `Reference`, **`DueDate`**, `DocumentID`, `PaymentMethod` — plus full address, VAT
  number and several email fields. About 1.6 MB for this administration.
  **Only name, reference, dates and amount are kept in the cache.** The address and email
  data is dropped at parse time: what is not stored cannot leak.
  Variants: `…ByDate(startDate, endDate)`, `…ByDateOutstanding(dateOutstanding)` for a
  historical position, `…WithLanguage`, `…WithPaymentReference`, plus creditor equivalents.
- **`GetProjectBalance(sessionID, administrationID, GLAccountCode, projectCode, StartDate,
  EndDate)`** returns typed `ProjectBalance` objects (`glAccountCode`, `project`,
  `projectCode`, `amount`). An **empty `GLAccountCode` returns every account** for that
  project, and the call takes a date range directly — so a project P&L is one call per
  month, with no difference arithmetic. All six parameters are required.
  It returns balance-sheet accounts too; the project P&L filters to 6/7 and applies the
  same ×−1.
- **`GetProjectsAndID(sessionID, administrationID, searchOption, searchValue)`**;
  `searchOption` values: `All`, `Company`, `Code`, `Description`. One project exists:
  `2601001` "Yibbi Expenses", started 2026-01-01, no revenue booked on it.
- `GetProjectBalance` gives no account names, so `rekeningnamen()` caches
  `GetGLAccountScheme` for a week and supplies them.
- Nothing useful on the Contact service; Sales only creates invoices.

Enums are flattened to `string` by PHP's `__getTypes()`. Read the allowed values from the
WSDL's `<s:simpleType>` with a regex instead of guessing — `enumWaarden()` in the probe.

**Two different payment measures, and they answer different questions.** DSO on the
dashboard is receivables ÷ revenue × days: an average over the whole book, computed from
the trial balance with no extra calls. The gauge on `klanten.php` is the share of open
money that is past its due date, from real invoice data. Overdue share is the actionable
one; DSO is the trend one.

**Receivables are not "everything starting with 40".** 406001 (Shopify prepayments) sits
around −2M and was cancelling out most of the 400000 balance, so the dashboard understated
open receivables by roughly two thirds. The accounts that count are listed explicitly in
`config.php` under `debiteuren_rekeningen`, with prepayments shown separately.

## P&L table

Built on **Tabulator 6.5.2** (cdnjs), not hand-rolled HTML. PHP computes and emits JSON;
Tabulator renders. That buys sorting, filtering, column hide/show, tree expand and export
to clipboard, CSV and XLSX (the last needs SheetJS, also from cdnjs) for free.

- Rows carry a `soort` field — `post`, `calc`, `pct`, `detail` — and `rowFormatter` turns
  that into a CSS class. Formatting decisions belong in the data, not in the markup.
- Tabulator's base CSS assumes a light background with fixed colours, so `stijl.css`
  overrides it onto the theme variables. Any new Tabulator feature probably needs a
  matching override, or it will look fine in light mode and broken in dark.
- **Sorting reorders the P&L**, which destroys the reading order subtotals depend on.
  Sorting is allowed but there is a "Herstel volgorde" button; the first column is not
  sortable at all.
- The tree is two levels (category → GL account). Transactions are the third level but are
  *not* tree children — they load on demand into a panel below the table, because one
  account-month can be thousands of rows.
- No count badge next to category names. It read as a figure among figures and confused
  more than it helped.

## Files

Everything lives in `/OHE_Reporting/` on exzellence.be.

| Bestand | Rol |
|---|---|
| `config.php` | key, administration ID, password. Never served — returns a PHP array |
| `.htaccess` | denies config/csv/json, no directory listing, noindex |
| `mapping.csv` | GL code → categorie, Tessa's confirmed table |
| `lib.php` | login gate, `<head>`, category order, number formatting |
| `stijl.css` | all styling; light and dark themes as CSS variables |
| `thema.js` | theme toggle, remembered in localStorage, follows the OS until overridden |
| `yuki.php` | SOAP calls, session + balance cache, monthly movements, P&L assembly |
| `index.php` | dashboard: KPIs, income-vs-cost chart with margin % line, unmapped warning |
| `pnl.php` | month-by-month P&L, `Brutomarge %` row, optional prior year, Excel copy |
| `cache/` | session and month-end balances as JSON |

A percentage row is never a column sum: the year total for `Brutomarge %` is total margin
divided by total revenue, not the average of the months. Same rule for the Excel copy.
No colour codes outside `stijl.css` — the chart reads its colours from the CSS variables
via `cssKleur()` so it follows the theme.

Cache policy: month-ends older than 100 days are kept a week, recent ones 15 minutes.
`?ververs=1` forces a full refetch. Prior year is fetched only when asked for, since it
costs twelve more calls.

## Lessons learned

The point of this section. Every time something breaks, surprises us, or turns out to
work differently than assumed, add an entry here rather than fixing it silently. Newest
at the top. Keep entries short enough that they stay readable a year from now.

Format:

```
### YYYY-MM-DD — short title
What we expected. What actually happened. What to do instead.
```

### 2026-09-17 — dark-mode row hover made text vanish: text was never themed at all
Bjorge reported that hovering a row in `klanten.php`'s customer table turned the
background dark but the text also "stayed dark" — poor contrast, dark mode only.
Assumption going in was a bad hover-background choice; actual cause was upstream of
hover entirely, found by checking computed styles in a real reproduction rather than
guessing: Tabulator's own shipped CSS sets
`.tabulator .tabulator-tableholder .tabulator-table { color: #333; background-color: #fff; }`
— three chained classes, specificity (0,3,0) — which beats our `.tabulator { color:
var(--tekst); }` override (0,1,0) regardless of load order. **Every cell's text has
always rendered as a hardcoded `#333`, in both themes, hover or not** — light mode never
showed it because `#333` on a near-white background still has enough contrast; dark mode
masked it too, in the *normal* row state, because `#333` sits far enough from the very
dark row background (`--vlak`) to still read as legible. Hovering swaps the row
background to `--vlak-rustig`, which is much closer in luminance to that same fixed
`#333`, collapsing the contrast to near-zero.
Fix: added `.tabulator .tabulator-tableholder .tabulator-table { background-color:
var(--vlak); color: var(--tekst); }`, matching Tabulator's own selector so ours actually
wins. Confirmed via computed-style inspection before and after (text color literally
changed from `rgb(51,51,51)` to the theme's `--tekst` value) and via screenshot in both
themes, hovered and not, rather than trusting that a smaller CSS diff was correct.
**Lesson for any future Tabulator override**: matching or beating the *exact* selector
Tabulator itself uses matters more than which rule "should" win — a single-class
override reads as more specific than it is when the library's own rule chains several
classes on the same property. When a themed value silently doesn't apply, check computed
styles for the real winning rule before assuming the override is at fault.

### 2026-09-17 — GitHub access workflow established
User wants to stop re-pasting files every session for `OHE_Dashboard` and `OHE_Closing`.
No GitHub MCP connector exists in the directory (checked, none found), so there's no
persistent auto-sync into Claude's memory or project files. Landed on: user pastes a
fine-grained PAT scoped to just these two repos at the start of a session, Claude clones
into the container and reads what's relevant, token is never stored anywhere persistent.
First clone attempt failed with `Write access to repository not granted` on a plain
`git clone` (read-only operation) — retrying the identical command with the same token
succeeded. Root cause not fully confirmed, but it reads like the token's fine-grained
permissions hadn't finished propagating on GitHub's side yet right after creation/edit —
worth waiting a minute and retrying before concluding the scopes are wrong.
Confirmed while doing this: neither repo has a `.gitignore`, so `cache/` and `config.php`
are committed. The committed `OHE_Closing/config.php` password and administration ID are
not live secrets (password is a placeholder; admin ID alone is useless without the API
key, which is blank in the file) — so no rotation needed this time, but the pattern is
worth a `.gitignore` before anything real ever lands in a committed `config.php`.

### 2026-09-16 — average payment term + per-invoice currency: OutstandingDebtorItems has no currency field, "Bedrag VV" comes from a different call entirely
Bjorge asked for a KPI gauge (average payment term) on `klant.php`, plus a redesign
replacing the expandable payment list with a flat outstanding-invoices table showing
currency, original amount, outstanding amount and days overdue per invoice.

Currency turned out not to exist where expected. `OutstandingDebtorItems` — the only
source `klant.php` had for open invoices — was checked field-by-field against 1,676 real
records (via `probe_velden.php`, including US and Chinese customers who obviously invoice
in non-EUR) and confirmed to carry no currency field whatsoever: `Date, Description,
Contact, ContactID, OpenAmount, OriginalAmount, Type, Reference, DueDate, DocumentID,
PaymentMethod`, plus address/VAT/email fields, full stop. `OriginalAmount` is always in
EUR (base currency) — useful, but not what Bjorge meant.

Bjorge named the missing concept directly: "Bedrag VV" (Bedrag Vreemde Valuta / Amount
Foreign Currency), a real field visible in Yuki's own invoice-table UI. Probed via
`probe_valuta.php` and confirmed it lives on `GetTransactions` (AccountingInfo service),
as a `ForeignCurrency` sub-struct (`amountFC`, `rate`, `currency`) only populated when
`dataGroups` includes `"ForeignCurrency"`. Confirmed genuine and per-invoice, not a
constant, from live data: Osaka Hockey Americas has EUR- and USD-currency invoices
interleaved on the same account.

Consequence: currency/Bedrag VV is only knowable for invoices whose transaction line
falls inside whatever date window is being queried — `OutstandingDebtorItems` can never
supply it. So `betalingenPerKlant()` was switched from `GLAccountTransactions` (no
foreign-currency data) to `GetTransactions` with `dataGroups: 'Contact,ForeignCurrency'`
(same "1 call per account per window" cost), now surfacing `contact.id`/`contact.fullName`
and `foreignCurrency.{currency,amountFC}` per line, plus a new `facturen_info` key
(`datum`, `bedrag`, `valuta`, `bedrag_vv` per invoice reference) and a weighted-average
`gem_termijn` (days between invoice date and matched payment date, weighted by amount,
matched pairs only, non-positive amounts/negative day-counts skipped).

`klant.php`'s outstanding-invoices table shows the real `bedrag_vv` when currency is known
and non-EUR, falls back to `OutstandingDebtorItems`'s EUR `OriginalAmount` otherwise, and
shows an honest "—" (not a guessed "EUR") when no `facturen_info` entry exists because the
invoice falls outside the lookback window — disclosed on-page via a `.melding` note, not a
bug. Added `betaaltermijn_groen`/`betaaltermijn_rood` config keys (30/60 day thresholds,
same "reasonable assumption, override in config.php" pattern as existing thresholds).

Old code removed, not superseded: the `<details>`-based payment list and its
`nog_open`/`echtOpen` cross-reference are gone from `klant.php` — replaced, not
supplemented. `koppelBetalingen()` itself is unchanged, just no longer rendered as a list.

Recurring gotcha hit again: `array_keys()` on an invoice-number-keyed array returns ints,
and `str_ends_with()` threw a TypeError until cast back with `(string)` — same trap as
GL codes, documented below.

Still to clean up: `probe_velden.php`/`probe_valuta.php` are read-only diagnostics, not
meant to stay on the live server, same as the probe scripts below.

### 2026-09-16 — chased Yuki's "yellow highlight" match for a full afternoon: it isn't in the public API
Bjorge noticed Yuki's own UI highlights the matched invoice in yellow when clicking a
payment line, and assumed a real relational field must be behind it. Five rounds of
read-only probing (WSDL introspection first, then real calls) ran this to ground:

- `GetTransactionDetails` exists — on **AccountingInfo**, not Accounting as first assumed
  — needs an extra undocumented `financialMode` int (`0` works), but `documentReference`
  is either the invoice's own number restated or empty on payment lines. Dead end.
- `GetTransactions` (AccountingInfo) has `document`/`documentProcessed`/`documentMatched`
  sub-objects, but they're only populated via an undocumented `dataGroups` string param —
  absent from the object entirely otherwise, not null. `numberOfRecords: 50000` returned
  a full 41,788-row account window in one 4.3s call, so paging isn't mandatory; same
  "1 call per account per window" cost as `GLAccountTransactions`.
- `documentMatched` came back empty on every row tested (every invoice, payment, and the
  −€0.01 correction) — zero usable signal, tested exhaustively rather than assumed.
- `document` looked promising and isn't: for a payment line it identifies the **imported
  bank statement batch**, not the settled invoice — proven by four unrelated invoices
  sharing one `document.id` because they were imported in the same bank statement. Using
  it as a match key would wrongly link unrelated invoices paid on the same statement day.
  The one case where invoice and payment shared a `document.id` was a B2C webshop order,
  most likely because that integration marks the invoice itself paid rather than
  importing a separate line — a tempting false positive if generalised.
- Conclusion: the public Yuki SOAP API does not expose whatever drives that yellow
  highlight; it's most plausibly computed inside Yuki's own web app. Told Bjorge this
  plainly rather than continuing past the point of diminishing returns.
- No code change resulted — the existing best-effort `koppelBetalingen()` matcher was
  re-tested against fresh real B2B multi-invoice examples surfaced during this chase and
  matched correctly on the first try, now with stronger evidence behind the same design.
- If this comes up again: don't re-probe `GetTransactions`/`documentMatched` from
  scratch — read this entry first. Unexplored: a non-SOAP Yuki REST API, if one exists.

### 2026-09-16 — customer payment drill-down: shipped, matching is best-effort by design
Built `klant.php?id=<contactID>` — bank payments per customer, expandable to the invoices
each one settled. `OutstandingDebtorItems` only covers still-open items with no payment
history, so this needed different operations, confirmed via two read-only probe scripts:

- `GLAccountTransactions` already returns `Contact`/`ContactID` per line for 400000/404000/
  407000 with no separate call, but has no per-contact filter — isolating one customer
  means fetching the whole account for the window and filtering client-side.
- Account 400000 had 41,776 transactions in a 3.5-month window, almost all under one
  aggregate "B2C sales - België" pseudo-contact (every Shopify order lands there) — this
  drill-down is only meaningful for real (non-B2C) customers, consistent with hiding B2C
  by default in `klanten.php`.
- Three real description shapes, all handled by one matcher (`koppelBetalingen()`):
  B2C bracket references, B2B single-invoice SEPA narratives, and B2B multi-invoice
  payments with several invoice numbers comma/hyphen-separated in one description. The
  matcher extracts every `\d{4,}` run from a description and links it to any open invoice
  whose number equals or ends with that run — covers all shapes without detecting which
  shape it is. A stray rounding-correction line attaches to the same invoice as the main
  payment because matching isn't exclusive.
- Non-invoice adjustment lines correctly produce no match rather than a false one (e.g. a
  same-account credit-note-style sales document that looked like an unmatched payment but
  wasn't a payment at all) — "geen koppeling gevonden" with the raw description shown is
  the correct, honest outcome either way.
- This is a best-effort text match, not guaranteed reconciliation, and is documented as
  such on the page itself: every payment always shows its full raw bank description next
  to whatever it matched, so a wrong or missing match is checkable by eye.

Recurring gotcha: `array_keys()` on an invoice-number-keyed array returns ints;
`str_ends_with()` threw a TypeError until cast back to `(string)` at point of use.

`stijl.css`: added `.betalingen`/`.betaling`/`.betaling-detail` rules for the `<details>`-
based expandable list (deliberately not Tabulator, given the lifecycle bugs below) and
`.tabel-eenvoudig`, overriding its inherited `position: sticky` from the P&L table rules —
without a scrolling ancestor a sticky header there would float loose on scroll. Checked
against real `stijl.css` in both themes via headless-Chromium screenshot before delivery.

### 2026-09-16 — klanten.php "Details" button did nothing: stale cache, not a code bug
Bjorge reported the new "Details" link did nothing after it shipped. Root cause:
`openstaandeDebiteuren()` caches its whole result for an hour; the cache in place was
written by the *previous* version of `yuki.php`, before `contactid` was added to each row,
so every cached row was missing that field. `klantDetailUrl()` correctly returned `null`
for a missing `contactid` and the click handler correctly did nothing — code was right,
silently stale data made it look broken. `?ververs=1` fixed it immediately.

General lesson: any time a cached shape gains a new field, an in-flight cache written by
the old code silently lacks it until the TTL expires or a forced refresh happens — same
class of bug as the mapping/GL-account cache elsewhere in this app, and will keep
recurring whenever a cache's *shape*, not just its data, changes. Hardened
`klantDetailLink()` to fail loudly (an `alert()` pointing at "Ververs") instead of doing
nothing silently, and the Details column now renders a plain `—` instead of a button when
`contactid` is absent, so a stale-cache row looks visibly different rather than a button
that looks live but isn't. Worth a small cache-schema version stamp (`'_v' => 2` checked
on read) next time this bites, instead of relying on someone remembering to hit Ververs.

### 2026-09-16 — filtering klanten.php crashed, then silently did nothing, because of Tabulator's build timing
Added a customer picker and B2C/groep hide-toggles to `klanten.php`. Two rounds of bugs,
same root cause: touching a Tabulator instance before it has finished building.

Round 1 — crash: calling `.setFilter()`/`.redraw()` right after `new Tabulator(...)`, in
the same synchronous block, threw `Cannot read properties of null (reading 'offsetWidth')`
— fatal, killed the rest of the `<script>` block, so every button and the search box
stopped responding. Hit hardest on `#tabel-posten`, which starts `display:none`:
`layout: 'fitColumns'` tried to measure column widths on a container with no rendered box.

Round 2 — silently inert: fixed round 1 by passing `tableBuilt: fn` as a table option in
the constructor config, gating every table-touching call behind a ready counter. No more
crash — but the filters, dropdown and buttons did nothing at all, ever. Cause: in this
Tabulator build, a `tableBuilt` key inside the options object is silently ignored — no
error, the callback is simply never invoked, so the ready gate never opened.

Fix that worked: bind the event explicitly with `.on('tableBuilt', fn)` immediately after
the constructor call (the documented Event System API), not via an options-object key.
Also added a ~3s `setTimeout` fallback that force-starts filtering and logs a console
warning if `tableBuilt` still hasn't fired, so a wrong event-name assumption degrades to
"works after a short delay, with a warning" instead of "silently broken forever."

Takeaway: don't trust an options-object callback key for Tabulator lifecycle events
without checking this specific build's actual behaviour — bind via `.on()` instead, and
always pair a "wait for readiness" gate with a timeout escape hatch. `pnl.php` only calls
`setFilter` from inside a user-triggered input listener, which is safe by the time a human
can actually type — but any code that calls table methods automatically at page load
needs this guard. Worth checking `index.php` too if it ever gets an immediate
`.redraw()`/`.setFilter()` call on page load.

### 2026-09-11 — the CSS was right, the browser was wrong
The gauge rendered as a needle on a blank field: no coloured arcs, text far too large, SVG
filling the whole panel. Everything it needed lived in `stijl.css` — which the browser was
still serving from cache. Nothing in the PHP was broken.
Two fixes. `stijl.css` and `thema.js` now carry `?v=<filemtime>`, so a changed file is a
changed URL. And the gauge no longer depends on the stylesheet at all: dimensions, font
sizes and colours are attributes on the SVG, with `var(--x, #fallback)` so the theme still
applies when the stylesheet *is* loaded. **`var()` in an SVG presentation attribute
silently yields nothing when the variable is undefined** — an arc with an unresolvable
stroke just does not draw. Always give these a fallback.

### 2026-09-11 — half a table and no error message
The P&L rendered one category row and then stopped dead. Cause: `h($code)` where `$code`
came from `array_keys()` and was therefore an int — the same numeric-key trap as before,
this time fatal under `strict_types` because `h()` declared `string`. With
`display_errors` off the page just truncated, which reads like a CSS or JavaScript problem
and is not.
Two fixes, both worth keeping: `h()` now accepts `int|float|string|null` and casts, and
account codes are turned back into strings with `strval` the moment they leave
`array_keys()`. Errors now go to `cache/fouten.log`, and `'debug' => true` in config puts
them on screen. **A page that stops mid-render is a PHP fatal until proven otherwise** —
read the log before touching the front end.

### 2026-09-10 — one account, one month, 2.6 MB of XML
`GLAccountTransactions(sessionID, administrationID, GLAccountCode, StartDate, EndDate)`
works and returns XML in `->GLAccountTransactionsResult->any`, shaped
`<GLAccountTransaction ID="guid"><Date/><Description/><Amount/><Project Code=""/><GLAccountCode/></GLAccountTransaction>`.
But 604000 for a single month is 2.6 MB — thousands of "COGS" lines of a few euro each,
one per order. A flat list of transactions is unusable at this volume, so the drill-down
groups by description with a count and a total, keeps the twenty largest individual
bookings, and throws the rest away before caching. Drill on one account **and one month**,
never a whole row.
There is no invoice number in this response — only `Description`. Real invoice references
would need `GetTransactionDetails`, which takes an extra `financialMode` and has not been
probed yet.
Parameter casing differs between the two: `GLAccountTransactions` wants `GLAccountCode` /
`StartDate` / `EndDate`; `GetTransactions` wants `glAccountCode` / `startDate` / `endDate`
plus `numberOfRecords` and `startRecord` for paging. `__getTypes()` on the SoapClient
prints the exact struct — faster than guessing.

### 2026-09-10 — fiscal year confirmed as the calendar year
`GetPeriodDateTable(yearID)` returns 2026-01-01 to 2026-12-31, and the empirical check
agrees: cumulative income stands at 13.7M on 31/12 and resets to near zero on 31/01. So
January's movement is its balance as-is; every other month is the difference. Note that
January 2026 income is *negative* (−343k), presumably returns or year-end corrections — a
negative month is not evidence of a broken sign.

### 2026-09-10 — GL codes stop being strings the moment they become array keys
Keyed lookups by account code (`$balance['700000']`) look like string keys but PHP casts
numeric strings to int, so a `string` typehint under `declare(strict_types=1)` throws a
TypeError. Type any parameter that receives a GL code as `int|string` and cast inside.
Applies to every helper, CSV read and array_filter callback that touches a code.

### 2026-09-10 — guessed XML element names, got silence
Assumed the SOAP results parsed with `//GLAccount` and `Code`/`Amount` children, taken from
example code rather than a real response. GetGLAccountScheme parsed to nothing at all;
GLAccountBalance returned rows but every amount read as empty, so the whole trial balance
looked like zeroes. A parser that finds rows but no values is guessing element names — dump
the raw response before writing any parsing code.

### 2026-09-10 — server facts confirmed (one.com)
PHP 8.4.25, `soap` / `curl` / `openssl` / `simplexml` / `dom` present, outbound HTTPS to
api.yukiworks.be works, max_execution_time 300s. The directory above the web root is NOT
writable, so config and mapping live inside `/OHE_Reporting/` behind `.htaccess`; config is
a `.php` file returning an array so it can never be served as plain text. **Server clock is
UTC** — always `date_default_timezone_set('Europe/Brussels')`, or month boundaries drift.
Authenticate works; the domain holds exactly one administration and its ID matches config.

### 2026-09-10 — income accounts live inside cost categories
Assumed each category would hold accounts of one class, so sign could be derived from the
account number. Tessa's mapping puts 743000/743001 under Bezoldigingen and 741902 under
Doorrekeningen. Take the signed balance from Yuki and sum; never branch on 6-vs-7.

### 2026-09-10 — a proposed mapping is a draft, not a shortcut
Twelve accounts were flagged as ambiguous; Tessa changed 23. Eleven of the changes were
accounts nobody had flagged, including all four Waardeverminderingen moving out of
Afschrijvingen. Account names do not determine the category — always have her confirm.

### 2026-09-09 — project started
Nothing broken yet. Baseline assumptions recorded above are unverified against a live
administration; the first real API call is likely to correct several of them.
