# R42 Design Jury 1: Dot.Auction (independent, browser-only)

Screens: /private/tmp/claude-501/-Users-sakhilebhayi-Dot/e1920f4e-d237-4804-8adb-eb4bdf16c1c4/scratchpad/jury1-shots/ (file names cited below). Walked as stranger, Zanele (new), Lerato (buyer), Sipho (winner), Thandi (seller), Naledi (operator), at 1440x900 and 390x844, light and dark, plus live two-session bidding and a lot closing in real time.

## Scores (Awwwards weighting; Sites of the Day average 7.2 to 7.6)

| Category | Weight | Score | Evidence |
|---|---|---|---|
| Design | 40% | 7.4 | 12-lot5-live-d-light.png, 20-lot4-d-light.png, 80-lot4-d-dark.png |
| Usability | 30% | 6.8 | 42-sipho-settle1-d-light.png, 20-lot14 (entity), page=99 empty state |
| Creativity | 20% | 6.8 | 01-landing-d-light.png (bid ladder), 11-browse-d-light.png |
| Content | 10% | 7.5 | landing copy, lot-state copy, 56-create-bad-err-d-light.png |
| Developer view | n/a | 7.3 | no overflow at 390 on 12 screens, 2px focus rings, live updates work, dark mode complete; 36px tap targets |
| Overall | | 7.1 | Just under Site of the Day average. Fixing the P0/P1 list below puts it in the 7.5 to 7.8 range. |

## Central idea and signature (one sentence)
A plain-spoken auction where every lot states its own truth (price, next valid bid, time, whether you lead, what happens next), signed by the "bid ladder" rail with yellow current-bid slab; the design expresses it well on the lot page and weakly everywhere else.

## What is excellent
- The lot page. Price at 3,100 in a large numeral, countdown, progress rail with a mark for the original close, ladder (start, current, next, +2 steps), reserve met / not met, your own rung tagged "You". State panel changes by situation: "You were outbid ... Bid R1,300.00" with a one-tap button (20-lot4), "You are leading ... nothing to do" (17-bid-placed), "This is your lot" (62-own-lot), "Not open yet", "The seller is deciding". This answers "did my bid work, do I lead, what next" without reading anything else.
- Bid flow: empty ("Enter the amount you want to bid."), too low ("Your bid is too low. The next valid bid is R3,200.00."), non-number (shows an example), then a confirm dialog with the amount in the confirm button. Live: a second session outbid the first and the first page flipped to "You were outbid" with no reload within seconds. Countdown ticks to 0s.
- Dashboard "Needs you" strip, then bids, then watching; role-aware (seller gets "Awaiting your decision" card with reserve vs top bid). Truthful on reload; numbers stayed consistent after a bid.
- Honest copy: private reserve explained, late-bid extension explained, 403 and 404 pages are branded and helpful, empty states exist for every list (72-new-*).
- Dark mode is a real second theme, not an inversion (80-browse-d-dark.png). Mobile lot page puts price first, photo after; bottom tab bar.
- Create-lot form: live "how buyers will see it" card, per-field help, a summary error box plus inline errors (56-create-bad-err), photo upload with preview, publish confirm dialog.

## What feels generic or templated
- Browse is an equal 4-column card grid of identical cards, each with a coloured image block, badge row, title, "Current bid", price, "Closes in", "Watch". Nothing says which lot is hot; the 5-minute-left rug has the same weight as a 5-day lot (11-browse-d-light.png).
- Watch rows on the dashboard: two cards butt against each other with no gutter and leave a big blank right side (10-buyer-home-d-light.png).
- Profile and teams are untouched Jetstream defaults: Title Case headings, "Update your name and photo" with no photo field, Google Authenticator boilerplate, stacked "OS X - Chrome 127.0.0.1" session list, yellow "Enable" and "Log Out Other Browser Sessions" (64-profile-d-light.png). Looks like a different product.
- Photos are "DEMO IMAGE" placeholders with 3 identical diagonal-stripe tints; fine for seed data but the whole browse page then has no real visual hook.
- No fake numbers or gradients found; the only tell is badge-heavy cards.

## Usability problems found
1. Winner cannot pay. Dashboard "Payment is overdue ... You can still pay until the seller cancels" and "Pay now" buttons, Won page "You owe R5,900.00 in total", then the sale page says "Nothing is due yet. Payment is not available yet" with no button (42-sipho-settle1). Overdue plus nothing due contradict each other; "Payment opened for the buyer" in the history also contradicts. Dead end on the most important post-win step.
2. Lot 14 title renders literally as "Brass ship&#039;s lantern" in the H1 on the lot page (21-lot14-d-light.png). Same lot reads correctly on browse and in the tab title. Double-escaping bug.
3. /auctions?page=99 shows "No lots yet. Nothing has been published." while 22 lots exist (and the tab counts show them).
4. Closed lot with a leading bidder shows badge "Not sold" next to "You held the highest bid... The result is being recorded" (lot 7 after it closed; 91-lot7-closed-live). Badge states a result the page says is not decided. It stayed this way for more than 30 s.
5. Notification "You've been outbid on Nikon D3500 ... price now R5,200" when the lot was bought with Buy Now; and 8 near-identical outbid notices, several for already-ended lots (30-notif-d-light.png). Noisy, one wrong.
6. Stranger cannot see any lot. Landing shows only an example ladder and "Sign in to bid"; browse is behind login. A stranger cannot tell what is being sold.
7. Two search boxes on Browse (top bar and "Search by title"), plus Sort needs the Search button. Sort changes do not apply on change.
8. Bid input is pre-filled with the next valid amount ("3200.00") with trailing ".00"; ladder rungs are labelled just "Bid" with a hidden "Set my bid to" and clicking one silently changes the input.
9. After you lead, the bid form disappears; you cannot raise your own maximum, and the long paragraph ("There is nothing to do: bidding higher against yourself would only raise your price") is heavy for a success state.
10. "In this lot" list ("Mountain bike / Used, Cape Town") is unlabelled: unclear whether it is a bundle of items or a condition line.
11. "Closing soon" lot header: the Watch button wraps below the badges and the price block shifts (20-lot7-d-light.png).
12. Seller list: "Garden tool set" cancelled lot shows "10 Nov 2026, 09:59 Closed", a future date next to Closed. "Reject the bid" is red-outlined and a dialog confirms (good), but the Accept button is the loudest thing on the page and irreversible; the dialog for Accept was not tested.
13. Reserve revealed to seller as "Your reserve R4,000.00 / Highest R3,000.00" is good, but bidders' "Awaiting seller decision" lots in browse give no hint of what happens next beyond opening the lot.
14. Mobile: tap targets are 36px high for chips, "Bid again", "Open lot", "Watch" (guideline 44px). Lot links in tables are 17px high text. Centre bottom tab is "List a lot" in primary yellow, promoting selling above buying for a bidder.
15. Operator page shows "(demo data)" strings and the "Show only these" link looks like plain bold text inside the red banner (73-operator-pay).
16. Notifications and profile pages use a narrower centred column (x=408) while every other page left-aligns at x=272; heading jumps between pages.
17. Time format inconsistency: "8 Nov, 09:59", "Sun 8 Nov, 10:39 SAST", "8 Nov 2026, 00:59 SAST", and "1d 5h" countdowns with tiny unit letters.
18. Full-page dialog confirm lacks the lot name ("Place a bid of R3,200.00?").

## Fix list

### P0 (breaks trust or usability)
1. Payment sale page and "Pay now" (42-sipho-settle1): either hide "Pay now" and the "overdue" wording until payment is switched on, or replace with one honest state: "Payment opens soon. You owe R3,700.00 once it does. We will tell you." Remove "Payment opened for the buyer" from history and do not mark sales overdue while payment cannot be made. Dashboard Needs-you row becomes "Waiting for payment to open" with no button.
2. Lot 14 H1 shows `&#039;`: render the title once-escaped everywhere (lot page H1, my-lots, notifications, dialogs). Add a test with an apostrophe title.
3. /auctions?page=N past the last page must redirect to the last page or show "That page does not exist. 23 lots match." never "No lots yet".

### P1 (clearly hurts the score)
4. Closed-but-unresolved lot (rug): show badge "Closed, result pending" (neutral) instead of "Not sold"; copy "You held the highest bid. The result will appear here within a minute."
5. Make browse hierarchical: promote "Closing soon" as a wide first row (countdown as the headline number, red rail), then a 3 or 4 column grid with real density controls; show bid count and leader state as one line, not a badge row.
6. Let strangers browse: make /auctions public read-only with a "Sign in to bid" button on the lot panel; add 4 live lots to the landing hero beside the ladder example.
7. Outbid notifications: collapse per lot ("Outbid on Yamaha, price now R1,250"), mark read when the lot is opened, never send "outbid" for a Buy Now sale (send "Sold to another bidder by Buy Now").
8. One search: remove the top-bar box on Browse or remove the page one; make Sort apply on change and keep Search for text only.
9. Bid panel: keep a compact "Raise your bid" control when leading (collapsed under the success message); shorten success copy to "You lead at R3,200.00. We will tell you if you are outbid."
10. Restyle Profile, Teams, 2FA to the app's language: sentence-case headings, remove photo mention, group sessions by device with a single "Sign out other devices" secondary button, 2FA enable as secondary button.
11. Mobile tap targets to 44px (chips, table actions, watch, ladder rungs); make whole table rows links on phones; replace centre "List a lot" tab with "Home" or put List a lot under More.
12. Label the ladder rungs ("Raise to R3,400") and mark them as buttons with hover/focus states; label "In this lot" as "What is included".

### P2 (polish)
13. Dashboard watching cards: add a grid gap and a 3 column track, or switch to a compact list on the dashboard.
14. Normalise date format: one pattern "Mon 9 Nov, 15:59" for this year; show year only when different; keep "SAST" in a single footer note instead of every row.
15. Countdown units: raise unit letters from the tiny subscript size to at least 12px; add `aria-live="polite"` only at thresholds (5 min, 1 min) so screen readers are not flooded.
16. Closing-soon header: keep Watch fixed top-right and let badges wrap beside it to avoid layout shift (20-lot7).
17. Cancelled lots in My lots: show the cancel date, not the planned close.
18. Add the lot name to the bid confirm dialog and the buy-now dialog ("Place a bid of R3,200.00 on Mountain bike?").
19. Align Notifications and Profile to the same left edge and width as the other pages.
20. Operator page: remove "(demo data)" from user-visible strings in any non-demo build; style "Show only these" as an underlined link.

## Developer view
- Responsive: no horizontal overflow at 390 on dashboard, browse, 3 lot states, bids, create, my lots, settlement, notifications, profile; filter chips scroll sideways on phone; mobile browse puts roughly 550px of filters above the first lot.
- Accessibility seen: skip link first, 2px solid focus outline on tab, labelled inputs, errors with icons plus text (not colour only), dialogs with Close, `aria-live` bid result. Gaps: 36px targets, 17px text links in tables, tiny unit subscripts.
- Motion: countdown and live price update work; dialog fades; no excess animation. Reduced-motion not exercised.
- Performance: pages load in under 100 ms locally, lazy images, no console errors on any normal screen (only expected 404s on missing ids).
