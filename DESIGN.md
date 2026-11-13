---
version: alpha
name: Rostrum
description: The Dot.Auction design system. A price climbs a ladder while the clock runs down a rail. Chalk and Graphite grounds, Gavel gold, Hammer red, one family with a width axis for figures.
colors:
  primary: "#f1c62e"
  secondary: "#1c2120"
  tertiary: "#d71016"
  neutral: "#eef0ef"
  chalk-ground: "#eef0ef"
  chalk-surface: "#f7f8f7"
  chalk-raised: "#ffffff"
  chalk-sunken: "#e2e6e4"
  chalk-ink: "#1c2120"
  chalk-ink-2: "#363e3b"
  chalk-ink-3: "#525d58"
  chalk-line: "#cfd5d2"
  chalk-line-strong: "#6b7772"
  graphite-ground: "#1c2120"
  graphite-surface: "#262c2b"
  graphite-raised: "#2e3532"
  graphite-sunken: "#131716"
  graphite-ink: "#eef0ef"
  graphite-ink-2: "#cfd5d2"
  graphite-ink-3: "#a7b0ac"
  graphite-line: "#363e3b"
  graphite-line-strong: "#7f8c86"
  gavel: "#f1c62e"
  gavel-hover: "#f6d95c"
  gavel-edge-chalk: "#7c5d00"
  hammer: "#d71016"
  hammer-text-chalk: "#b80d12"
  hammer-text-graphite: "#ff9b97"
  ring-chalk: "#0b6b5c"
  ring-graphite: "#5ed3bb"
  dusk-chalk: "#2b5a86"
  dusk-graphite: "#93bde6"
typography:
  display:
    fontFamily: Archivo
    fontSize: 2.125rem
    fontWeight: 700
    lineHeight: 1.15
    letterSpacing: -0.012em
    fontVariation: "'wdth' 112"
  headline:
    fontFamily: Archivo
    fontSize: 1.4375rem
    fontWeight: 700
    lineHeight: 1.15
    letterSpacing: -0.012em
    fontVariation: "'wdth' 108"
  body:
    fontFamily: Archivo
    fontSize: 1rem
    fontWeight: 400
    lineHeight: 1.55
  label:
    fontFamily: Archivo
    fontSize: 0.875rem
    fontWeight: 650
    lineHeight: 1.3
  money:
    fontFamily: Archivo
    fontSize: 2.75rem
    fontWeight: 700
    lineHeight: 1
    letterSpacing: -0.03em
    fontVariation: "'wdth' 106"
    fontFeature: "'tnum' 1, 'lnum' 1"
  clock:
    fontFamily: Chivo Mono
    fontSize: 1.1875rem
    fontWeight: 600
    lineHeight: 1.1
    letterSpacing: -0.02em
rounded:
  none: 0
  xs: 2px
  sm: 4px
  md: 6px
  pill: 999px
spacing:
  xs: 4px
  sm: 8px
  md: 16px
  lg: 24px
  xl: 40px
  xxl: 64px
components:
  button-primary:
    backgroundColor: "{colors.gavel}"
    textColor: "{colors.chalk-ink}"
    rounded: "{rounded.sm}"
    height: 44px
    padding: 16px
  button-primary-hover:
    backgroundColor: "{colors.gavel-hover}"
    textColor: "{colors.chalk-ink}"
  button-quiet:
    backgroundColor: "{colors.chalk-ground}"
    textColor: "{colors.chalk-ink}"
    rounded: "{rounded.sm}"
    height: 44px
  button-danger:
    backgroundColor: "{colors.chalk-ground}"
    textColor: "{colors.hammer-text-chalk}"
    rounded: "{rounded.sm}"
    height: 44px
  input:
    backgroundColor: "{colors.chalk-raised}"
    textColor: "{colors.chalk-ink}"
    rounded: "{rounded.sm}"
    height: 44px
  status-live:
    backgroundColor: "#d8eee9"
    textColor: "{colors.ring-chalk}"
    rounded: "{rounded.xs}"
  status-closing-soon:
    backgroundColor: "#fce6e5"
    textColor: "{colors.hammer-text-chalk}"
    rounded: "{rounded.xs}"
  status-sold:
    backgroundColor: "{colors.chalk-ink}"
    textColor: "{colors.chalk-ground}"
    rounded: "{rounded.xs}"
  lot-card:
    backgroundColor: "{colors.chalk-surface}"
    textColor: "{colors.chalk-ink}"
    rounded: "{rounded.none}"
  ladder-current:
    backgroundColor: "{colors.gavel}"
    textColor: "{colors.chalk-ink}"
    rounded: "{rounded.xs}"
  dialog:
    backgroundColor: "{colors.chalk-raised}"
    textColor: "{colors.chalk-ink}"
    rounded: "{rounded.md}"
---

## Overview

**Name:** Rostrum, the auctioneer's podium. The one idea: **an auction is a price climbing a ladder while a clock runs down a rail.** Everything in the system is built from those two things, and both are drawn from real data only.

### Concept card

| | |
|---|---|
| **Idea** | A price climbs a ladder while the clock runs down a rail. Time is the protagonist; prices are the data. |
| **Metaphor** | The sale room: lot numbers on paddles, the catalogue sheet, the bid ladder of increments, the hammer at the end of the rail. |
| **Signature** | The **bid ladder** (`ds.bid-ladder`): the real valid bids of one lot as rungs, the current price as the gold rung, the viewer's own bid marked "You" (a marker inside its row, between the label and the figure, never across the border; every figure shares one right edge, pressable rung or not), a private reserve shown only as met or not met. Its partner is the **closing rail** (`ds.rail`) and the sale-room **clock** (`ds.countdown`, in Chivo Mono so the digits never move). |
| **Emotion** | Calm certainty, the moment before the hammer falls: you can see exactly where the price is and how much time is left. |
| **Motion verb** | **Strike.** Short, decisive, no bounce: a press travels 1px and stops, a dialog rises 8px in 240ms and stops, the rail advances in 1s linear steps. Nothing wobbles at rest. |
| **Screenshot** | A lot page: the gold current rung at R1,250.00, the next bid R1,300.00 under it, the rail 90% spent and red, the clock reading 37m 58s. Captured set: `scratchpad/shell-shots/` (see the R-42 shell report for the path). |

Three territories were weighed before choosing: (1) **the ladder and the rail** (the sale room's mechanics, chosen), (2) the printed catalogue (ruled sheets, lot numbers; kept as the *catalogue grid* of lot cards, not as a serif-and-cream broadsheet), (3) the paddle (a number held up; kept as the lot number tab and the "You" marker, not as a concept because paddle numbers are not in the data). The brand logo drives the hues: its yellow disc is **Gavel**, its red chevron is **Hammer**.

### Mode

Operate with a Persuade edge on the welcome page. Scanability, plain words and numerals that cannot be misread outrank expression. Brand lives in the ladder, the rail, the width axis of the type and the two logo hues.

### What the system refuses

The three AI-default looks (warm cream with a high-contrast serif and terracotta; near-black with one acid accent; broadsheet hairlines with zero radius and dense columns), Inter, Space Grotesk, Geist and Plus Jakarta Sans, four equal metric tiles, rounded cards floating in gutters, a thick coloured border on one side, radial blooms, gradient text, glass, tinted icon squares, and any fake activity or invented count.

## Colors

Two grounds, one fixed neutral ramp, four hue families. Every colour in the app is a token in `resources/css/tokens.css` (the only place a colour is written by value in CSS). Roles are `light-dark(Chalk, Graphite)` pairs, so the system follows the visitor's choice (`data-theme` on `<html>`, stored in `localStorage` as `dot-theme`) and falls back to the system preference.

- **Chalk** (light) is a cool plaster grey with a green undertone, not cream: ground `#eef0ef`, surface `#f7f8f7`, raised `#ffffff` (menus, dialogs, inputs), sunken `#e2e6e4`.
- **Graphite** (dark) is a charcoal with the same undertone, not near-black: ground `#1c2120`, surface `#262c2b`, raised `#2e3532`, sunken `#131716`. It is a true neutral family, not a navy.
- **Gavel** `#f1c62e` (the logo disc): the primary action and the current price. A fill, always with ink text (9.98:1). It is never body text on Chalk.
- **Hammer** `#d71016` (the logo chevron): time running out and destructive things. As text it is `#b80d12` on Chalk and `#ff9b97` on Graphite.
- **Ring** teal (`#0b6b5c` / `#5ed3bb`): the floor is open. Live, leading, paid, reserve met.
- **Dusk** blue (`#2b5a86` / `#93bde6`): not open yet, links.

Tailwind's `gray`, `indigo`, `red`, `green` and `yellow` scales are mapped to these ramps (`tailwind.config.js`), with gray 400 to 600 mapped to the theme-aware muted ink, so Jetstream's stock classes land on the same colours.

### AA verification (WCAG 2.2, ratio on the surface each role is used on; text needs 4.5, UI parts 3)

| Role | Chalk | Graphite | Used on |
|---|---|---|---|
| ink | 14.3 to 16.3 | 11.0 to 15.8 | ground, surface, raised, sunken |
| ink-2 | 8.7 to 11.0 | 8.4 to 12.1 | all four |
| ink-3 (muted text) | 5.4 to 6.9 | 5.7 to 8.1 | all four |
| ink on Gavel / Gavel hover | 10.0 / 11.7 | 10.0 / 11.7 | primary button, current rung |
| line-strong (control borders, 3 needed) | 3.7 to 4.7 | 3.6 to 5.2 | all four |
| focus ring (3 needed) | 14.3 to 16.3 | 11.0 to 15.8 | all four |
| link (Dusk) | 6.3 to 7.2 | 6.4 to 8.3 | ground, surface, raised |
| live / urgent / notice / scheduled text on its own tint | 5.3 to 5.9 | 7.4 to 9.4 | status chips |
| the same on ground and surface | 5.4 to 6.4 | 7.0 to 11.7 | inline status text |
| danger text | 5.7 to 6.8 | 6.2 to 8.1 | ground, surface, raised, tint |
| white on Hammer fill | 6.76 | 6.76 | count badge, solid danger button |

The script that produced this table checks 100+ pairs on every run (see `docs/R42_DECISIONS.md`, shell section); there are no failures. The detector and axe agree on the rendered pages.

## Typography

One family with a width axis, plus one mono used for exactly two jobs.

- **Archivo** (variable, weight 100 to 900, width 62 to 125, Latin subset, SIL OFL, self-hosted at `public/fonts/archivo-var-latin.woff2`). UI, display and money. Headings run at width 108 to 112; body at 100. Tabular lining numerals (`tnum`, `lnum`) are switched on for every price. Its zero is narrower than its O, so `R100 000` and `ROO` cannot be mistaken (checked in the specimen). Width is the signature: a lot number or a price can stand wide, a dense table can stand narrow, without a second family.
- **Chivo Mono** (variable weight, SIL OFL, `public/fonts/chivo-mono-var-latin.woff2`). Only the **sale-room clock** and **lot numbers** (the paddle tab, the error code): fixed-width digits so a ticking clock never shifts its neighbours.
- Fallbacks are metric-matched (`size-adjust`, `ascent-override`) so the swap does not move the layout. `font-display: swap`. No Google Fonts, no icon font, no CDN.

Scale (ratio about 1.2, tokens `--t-*`): 12 / 14 / 16 / 19 / 23 / 28 / 34 / 44 (money only). Body is 16px, line height 1.55, measure 66ch. Labels, table heads, tags and nav are the body face in sentence case, never tracked capitals. Figures that align are right-aligned and tabular. Display never exceeds 6rem (welcome headline is a clamp to 4.25rem).

## Layout

- **Shell:** from 64rem a 15rem **rail** (brand, then Home, Buying: Browse lots, My bids, Watching, Won; Selling: My lots, Payments; Operations for operators), a sticky top bar (search, test or live badge, bell, theme, account) and a page column of 80rem (`default`), 54rem (`narrow`, forms and reading), 96rem (`wide`) or full. Below 64rem the rail goes away: a top bar with the logo mark and the bell, and a **bottom bar** of five items (Home, Browse, My bids, Watching, More). More opens a bottom sheet with every other destination (**List a lot** first among the selling ones), the account links, the theme switch and log out. *Decision (design jury 1, polish round):* the bar used to carry **List a lot** in gold in the centre, which put selling above buying for a person who mostly bids. Buying is the core of the bar now; listing stays one tap away in More, on Home (the landing block's gold button for a seller, a link beside Browse live lots for a buyer) and on My lots, where a seller is already looking. The bar has no primary action of its own: the page owns it (the bid, Place bid, Pay now). Narrow reading and form pages (`narrow`) share the left edge of every other page from 64rem; they are not centred.
- **Grid:** one page container, a 4px base spacing scale (`--s-*`), a 1rem gutter (2rem from 64rem). Sections are bands divided by a 1px strong rule on top, not floating cards. Lots sit on a **ruled catalogue sheet** (`.ds-catalogue`: collapsed 1px borders), 17rem minimum cell. **Below 40rem a lot is a row, not a card**: a photo column, then status, title, price with its label, time left and bids, and the watch control at the right of the last line (twelve lots are about 1,700px, not 7,400). The card layout returns from 40rem.
- **Home's landing block** (`dashboard._landing`, the layout's `hero` slot): the logo lockup and the way back (InfoDot, Platforms) on one line, then the welcome h1 with one sentence and the page's one gold action (`data-primary-action`) beside one real lot: its clock, its rail, its price. The role is read from the person's own rows (`DashboardController::landing`): a seller's action is List a lot, a buyer's is Browse live lots; the other is a link. From 64rem the rail's lockup stands for the block's. With nothing open the block says so and shows no figure. Its entrance (the lead rises 10px once, transform only) is Home's one authored motion.
- **Stats:** never four equal tiles. Use one headline `ds.stat` (money size) beside a `ds.dl` ledger, or at most three figures of different weight, or a ledger only. A figure always names its baseline ("since 8 Nov, 09:00").
- **Touch:** every control is 44px on coarse pointers (48px for inputs on phones); inputs are 16px so iOS never zooms.
- **Landmarks:** skip link first, `header` (top bar), `nav` (rail or bar), one `main#main`, one `h1` from the layout `header` slot, a unique `<title>` per page from the layout `title` prop.

## Elevation & Depth

No shadow at rest. Surfaces separate by a tone step (ground, surface, raised, sunken) and a 1px rule. Only what floats gets `--shadow-float`: menus, dialogs, toasts. In Graphite a float also gets a 1px ring so it reads without light. No glow, no blur, no gradient, no glass.

## Shapes

Radius is a rule, not a taste: **panels, cards, lot cells and table rows are square (0)**; chips and status labels 2px; buttons, inputs and menus items 4px; dialogs 6px; toggles and count badges pill; avatars are square-cornered 4px (the paddle shape). Focus is a 2px ink ring (ink on Chalk, chalk on Graphite) with a 2px offset, visible on every surface, appearing instantly.

## Components

All anonymous Blade components live in `resources/views/components/ds/`; **the prop list is the comment block at the top of each file**. Jetstream's stock components (`x-button`, `x-input`, `x-label`, `x-modal`, `x-form-section` and the rest) are re-skinned to the same classes. Behaviour is progressive enhancement in `resources/js/ds.js` (data attributes, delegated listeners; the page works without it). `/dev/kit` (local and testing only) shows every component in both themes side by side.

| Component | Purpose |
|---|---|
| `ds.button` | primary (gavel), quiet, danger, link; sm 36, md 44, lg 52; icon, iconRight, loading, block. Submit buttons lock after one press. |
| `ds.icon-button` | 44px icon button, always named; optional count badge whose number is part of the name |
| `ds.icon` | one inline SVG from `App\Support\Icons` (24 grid, 1.75 stroke, square caps, `currentColor`) |
| `ds.brand` | the logo from `public/images`, cropped to its drawing: `lockup` or `mark`; light and dark files swap by theme |
| `ds.input`, `ds.textarea`, `ds.select` | label, control, hint and error with `aria-describedby`/`aria-invalid` wired; `money` prefixes the configured symbol and sets tabular figures |
| `ds.checkbox`, `ds.radio-group`, `ds.toggle` | choices; the toggle writes "On" and "Off" |
| `ds.field` | label, hint and error around a custom control |
| `ds.money` | amount in the configured currency, tabular lining numerals, symbol and cents set smaller; sizes sm, md, lg, xl, money |
| `ds.status` | the status vocabulary below; pass `state=` or `auction=` |
| `ds.countdown` | server time first, JS ticks it; Chivo Mono digits; `role=timer`, never announced per second; `ds:countdown-end` event |
| `ds.rail` | the closing rail: share of the sale already gone, a tick for the original close when late bids extended it |
| `ds.lot-card` | a lot as a catalogue entry in `.ds-catalogue`; a compact row below 40rem. A lot with no photo shows the designed "No photo yet" tile (a sunken frame with a dashed inner rule), the same words in the gallery. Watch is offered only while a lot is scheduled or taking bids (`Display::isWatchable`); a closed or cancelled lot has none |
| `ds.lot-row` | a lot that is closing as a ruled strip: the countdown is the headline figure (xl on Browse, lg on Home), the rail under it, price and watch beside. A different family from the catalogue on purpose: the Closing soon row on Browse and the 24-hour lists on Home. A list of one fills the width |
| `ds.bid-ladder` | the signature element; rungs are buttons that fill `[data-ds-bid-input]` |
| `ds.stat`, `ds.dl` | a headline figure; a ledger / stacked / inline definition list |
| `ds.page-header`, `ds.section` | the h1 block (the layout renders it); a ruled region with its own h2 |
| `ds.table` | stacked rows below 40rem (`td[data-label]`), right-aligned tabular figures, optional focusable scroll region |
| `ds.empty-state`, `ds.skeleton` | what belongs here and the one action; loading in the shape of what is coming |
| `ds.alert`, `ds.toasts`, `window.dsToast()` | inline messages; toast region and API; tones info, success, notice, danger |
| `ds.dialog`, `ds.confirm-dialog`, `ds.confirm` | native `<dialog>` (focus trap, Escape, inert background, bottom sheet on phones); confirm replaces `confirm()` and holds the real form |
| `ds.dropdown`, `ds.dropdown-item` | menu button with arrow keys, Home, End, Escape |
| `ds.tabs`, `ds.tabset`, `ds.tab-panel` | link tabs (state in the URL, preferred) and in-page tabs |
| `ds.chip` | filter or sort option as a link |
| `ds.pagination` and `vendor/pagination/*` | the pager and its summary |
| `ds.avatar`, `ds.badge` | initials block; small label or count |
| `ds.nav-item`, `ds.theme-toggle` | navigation entries; light/dark switch |

### Icons (`App\Support\Icons`, 24 grid, 1.75 stroke, square caps)

play, hourglass, calendar, gavel, no-sale, pause, ban, extend, pencil (the status silhouettes); home, browse, bids, bookmark, trophy, tag, receipt, shield, ladder (navigation); bell, bell-off, sun, moon, menu, close, user, users, logout, sliders, search, lock, key, mail (chrome); plus, minus, check, check-circle, x-circle, alert, info, clock, arrow-left, arrow-right, arrow-up, arrow-down, chevron-down, chevron-up, chevron-left, chevron-right, external, trash, image, filter, sort, eye, eye-off, copy, upload, download, loader, dots (actions and signals). Icons never sit in tinted squares and never stand alone for a meaning without a word beside or inside the control.

### Status vocabulary

A word, a silhouette and a hue for each state; colour is never the only signal. `Display::lotState($auction)` derives the lot state; `Display::statusVocabulary()` is the single table.

| State | Word | Silhouette | Hue |
|---|---|---|---|
| live | Live | play triangle | Ring |
| ending | Closing soon (inside `ending_soon_window_minutes`) | hourglass | Hammer |
| scheduled | Scheduled | calendar | Dusk |
| sold | Sold | gavel, filled ink chip | ink |
| closing | Closed, result pending (time is up, the settler has not recorded a result) | clock | outline only |
| unsold | Not sold | square with a bar | outline only |
| pending | Awaiting seller decision | pause bars | Gavel text |
| cancelled | Cancelled | circle with a slash | outline only |
| extended | Extended (n times) | arrow to an end bar | Ring |
| draft | Draft | pencil | outline only |
| awaiting_payment, initiated | Awaiting payment, Attempt started | clock | Gavel text |
| paid | Paid | check in a circle | Ring |
| payment_overdue, failed, unmatched | Payment overdue, Attempt failed, Needs a person | warning triangle or x | Hammer |
| expired | Attempt expired | circle with a slash | outline only |

### Words that name a sale or a payment

A sale is described by `App\Support\SettlementPosition` and nothing else: lists, dashboards and pages call `listLabel()`, `headline()` or `badge()`, never the stored settlement status. While payment is off no page says overdue, due, Pay now or cancelled-for-lateness for a sale that cannot be paid. A payment *attempt* (operator page) is history and is labelled so: "Attempt started", "Attempt failed", "Attempt expired", with "Payment attempt, not completed" under it. Form errors are sentences a person can act on ("Enter a title for the lot."), never the framework's ("The title field is required."): every field has a plain name in `lang/en/validation.php`, and a sweep test submits every form empty.

### Shell, navigation and routes

Role-aware items: everyone sees buyer and seller items; the operator sees Payment operations. Targets that do not exist yet go through `auction_route('name')` (`Display::route()`), which returns `#` only while the route is not registered; such an item is marked `data-pending-route` and does nothing when pressed. **UI agents register these route names** (they are linked from the rail, the phone bar and the menu): `buyer.bids` (My bids), `buyer.watchlist` (Watching), `buyer.won` (Won). Registered routes light up with no change to the shell. Layout API: `<x-app-layout title="..." description="..." width="default|narrow|wide|full" back="url" :title-visible="false">` with slots `header` (the h1), `meta`, `actions`, `topbar`; `<x-guest-layout title="..." variant="card|document">`.

Money, times: `Display::money()` / `<x-ds.money>` (config `auction.currency_symbol`); a date with a time is one pattern everywhere, **"Mon 9 Nov, 15:59"**, with the year only when it is not this year (`Display::time` maps the older spellings `j M Y, H:i`, `j M, H:i`, `D j M, H:i` to it). The zone is named once in the shell note ("Times shown in South African time") and on the big clocks and deadlines, not on every list row. `Display::time($at, $format, $withZone)` delegates to the backend's `Auction::localTime()` when it exists, otherwise converts to `config('auction.display_timezone')` (Africa/Johannesburg). Swap point: `App\Support\Display::time()`.

### Email

Mail clients cannot read CSS variables or web fonts, so `resources/views/vendor/mail/html/themes/default.css` repeats the tokens by value (Chalk ground, ink, line, link, Gavel button with ink text, 4px radius, system sans) and the header shows the logo on a white band. It is the one place besides `tokens.css` where colours are written as literals.

## Motion language

Verb: **strike.** Ease-out for entering and responding (`cubic-bezier(0.23, 1, 0.32, 1)`), ease-in-out for things already on screen; never ease-in. Durations: micro 120ms (press, hover colour), short 180ms (menus, toasts), mid 240ms (dialogs, sheets). A press moves 1px and scales 0.985. Only `transform`, `opacity` and colour are animated; never width, height, top or left (the rail is a `scaleX`). Keyboard-initiated actions do not animate. The clock ticks once a second and is paused in hidden tabs; the rail eases between ticks. Under `prefers-reduced-motion` every duration collapses to 0.01ms, and no content is ever hidden behind a scroll animation. The only idle motion is the clock itself.

## Voice

Short, confident, plain. Sentence case everywhere. No exclamation marks. No em or en dashes in visible text. A control names its action ("Place bid", "Delete draft", never "OK" or "Submit"). An error names the problem and the way out ("Your bid was too low. The next valid bid is R1,300.00."). A status is a word before it is a colour. Say what happened to the lot ("Ended below the reserve, the seller is deciding"), not what the system did. Numbers name their baseline. Times name the zone.

## Do's and Don'ts

Do
- Put the price, the step and the closing time in the first screen of a lot.
- Use `ds.money` for every amount and `Display::time()` for every time.
- Give every region four states: loading (skeleton), empty (what and the one action), error (what broke, what to do), full.
- Keep state in the URL (filters, tabs, sort, page).
- Use `ds.confirm` for anything destructive, with the verb on the button.
- Let a utility class override a component rule (Tailwind's utilities load last), and add a new token before adding a new colour.

Don't
- Write a hex, an rgb, a font name or a shadow outside `tokens.css` (and the email theme).
- Show four equal stat tiles, a card inside a card, a rounded card floating in a gutter, or a thick coloured border on one side.
- Use colour alone for a state, an icon alone for a meaning, a placeholder as a label, or a hover-only control.
- Invent activity: no fake "watching now", no sample counts, no live badges that are not live.
- Print the reserve amount to a buyer, anywhere, ever.
- Say "Pay now" or "overdue" for a sale that cannot be paid. `App\Support\SettlementPosition` is the one answer (waiting, due, overdue, paid, cancelled) for Home, Won, My bids, Payments, the sale page and the lot page; while online payment is closed every page says payment opens soon and what will be owed.
- Escape a title twice. A header slot arrives escaped; the layout decodes it once and the page header escapes it once. Prefer the layout's `title` prop (no header slot) for anything a user typed.
- Load a CDN, a web font service or an icon font.
