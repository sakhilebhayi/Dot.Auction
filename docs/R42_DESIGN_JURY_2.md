# Dot.Auction design jury, round 2 (independent, browser-only)

Screenshots: /private/tmp/claude-501/-Users-sakhilebhayi-Dot/e1920f4e-d237-4804-8adb-eb4bdf16c1c4/scratchpad/jury2-shots/ (file names cited below). Walked as stranger, Zanele (8), Lerato (3), Sipho (4), Aisha (5), Thandi (1), Naledi (7); 1440x900 and 390x844, light and dark. No console errors and no horizontal overflow on any page tested.

## Scores

| Category | Score | Evidence |
|---|---|---|
| Design (40%) | 7.4 | One idea carried everywhere: the bid ladder, big tabular rand figures, a single yellow, closing rails in red. Calm, rigorous, dark mode is genuinely designed (113-d-lot). Held back by placeholder "DEMO IMAGE" art, a boilerplate team page, and a heavy phone browse. |
| Usability (30%) | 6.9 | Bid flow is excellent (13/14/16/17). Dead ends are after the hammer: winner has nothing to do, no seller contact or collection step; contradictions between pages; stranger cannot see a single lot. |
| Creativity (20%) | 6.5 | The ladder is a real signature, countdown rails with extension tick are good. Everything else is a restrained admin vocabulary; nothing surprising in the lot photography or browse. |
| Content (10%) | 8.0 | Plain, specific, honest copy ("The seller is deciding", "Your last bid was R1,000.00... R300.00 more"). A few robotic lines. |
| Developer view | 7.5 | Skip link, visible focus, labelled controls, live countdowns, dialog closes on Esc, graceful bad-query handling, no overflow. Phone browse is very long; scroll position of bid form on phone. |
| **Overall** | **7.1** | Solid, trustworthy product at about the Site-of-the-Day average floor; a few trust contradictions and the lack of public browse keep it below 7.5. |

## Reading
Central idea: an auction where every number that matters (price, next valid bid, time left, whether you lead) is stated in plain words and a ladder you can click. The design expresses it well on the lot page and landing hero; it stops expressing it after the auction closes and in the stranger's first view (no lots).

## Slop tells and what is excellent
Generic: equal four-column card grid with identical placeholder art (05-browse); "DEMO IMAGE" tiles; the Jetstream-default Team settings page (79-team); Laravel phrasing "The title field is required." (57); icon plus label sidebar; eyebrow-free headings are otherwise good (no gradients or glass).
Excellent: bid ladder with "Next bid" and "You" markers (09-lot4); outbid panel with one-tap "Bid R1,300.00"; confirm dialog stating binding nature (16-bid-confirm); too-low message naming the exact next bid (14); lot states each explained (22-lot10 awaiting, 28-lot18 cancelled sale, 117-lot7-after closed pending); seller decision card inline in My lots (51); honest operator page (77); list-a-lot form with live card preview and good empty-submit summary (57).

## The auction journey as a stranger
What is sold, price, time left, step: all clear on a lot. How to bid: clear. Did it work: yes, immediate "You are leading", reserve status stated (17). Outbid: red panel plus notification. End: "Closed, result pending... within a few minutes" then result. Next step after winning: weak (payment "opens soon" with no date, nothing about collection). A stranger, however, cannot see any lot before sign-in (/auctions redirects to /login), so the landing page sells a process with no stock.

## Usability problems found
- Seller list says "Sold, Payment overdue" for Six leather dining chairs (51) while the sale page (53) and the buyer's page (54) say payment is not open and nothing is due; lot 18 and a notification say a sale was cancelled "because payment was overdue" though payment cannot be made. Operator page (77) shows "Payment started" and "Payment failed, card declined" for Dell Latitude while the buyer sees "Payment opens soon" and the page header says Paystack is off.
- Winners have no seller contact, collection point or delivery information anywhere (43-sale-laptop). Won four days ago, still "Payment opens soon" with no date.
- "Awaiting seller decision" says "There is no deadline for the decision" (22): buyers bidding on reserve lots can be stuck indefinitely.
- Closed-pending lot vanishes from Aisha's dashboard "open lots" (121) with no "result pending" line; it only shows on its own page.
- "Watch" button on closed, sold, cancelled and pending lots (117, 05-browse cards 0020, 0010).
- Lot 18 shows "Sale cancelled" twice (alert and panel) plus a link "Open the payment page for this sale" to a cancelled sale (28).
- "Your part: You won this lot" label (43) is odd; sale page lacks a stepper (won, pay, collect).
- Browse: "22 lots" header vs "Showing 1 to 12 of 20 other lots"; "Other lots" heading when no featured exists; ended lots mixed in page 1 under "Ending soonest" after live ones; closing-soon counts overlap Live count (8 + 2 are the same lots).
- Ladder "You" chip overlaps the row border (09, 113); price figures in the ladder are not aligned to the same right edge as the current-bid row.
- Lot page: "What is included" repeats "Good, Cape Town" per item; category "Books & Music" for a guitar.
- Out-of-range page quietly shows page 2 (31): fine, but says nothing.
- Once leading, the bid form is removed, so a leader cannot raise their bid ahead of time.
- Team settings (79) is generic boilerplate, role picker looks disabled, and "Administrator users can perform any action" has no meaning in an auction product.
- Notification timestamps are all "Sun 8 Nov, 10:48" (seed artefact, but looks fake).
- Phone browse is one 600px card per lot (93-m-browse), 12 per page, roughly 7,400px; filters chips scroll sideways with no cue.

## Fix list
P0
1. Reconcile payment truth: seller "Payment overdue" (51), overdue cancellation notices, and operator "started/failed" (77) must match buyer "payment opens soon" (54), or hide overdue and cancellation language while payments are off.
2. Show winners what happens next: seller display name and a message or contact route, collection or delivery note, a 3-step status (won, pay, collect) and an expected date or "we will email you".
3. Public browse: let strangers see lots (read-only, "Sign in to bid") at /auctions and lot pages, and put 3 live lots on the landing hero under the ladder.

P1
4. Give "awaiting seller decision" a stated service window and show it on the buyer's lot, bids list and dashboard (for example "seller has 48 hours"; reminder to seller).
5. Keep closed-pending and won lots on the dashboard "Needs you" strip until the result lands.
6. Remove Watch/Stop watching on closed, sold, unsold and cancelled lots.
7. Replace placeholder art with real or neutral generated imagery (for the demo) and drop the "DEMO IMAGE" text; use lot-specific aspect crops.
8. Phone browse: switch to a compact horizontal row (96px thumbnail, title, price, time) or a 2-column grid; sticky filter button.
9. Fix the lot 18 duplicate "Sale cancelled" blocks and the dead payment link on cancelled sales.
10. Replace Team settings with a product-appropriate page (or hide teams); rename roles or remove.
11. Split browse results: live first, then ended in a separate "Recently ended" section; make the counters agree (22 vs 20 other).
12. On phone, put photo before the bid panel or add a sticky bid bar with price, time and "Bid".

P2
13. Allow a leader to raise a bid ("Raise my bid") and add a max-bid note.
14. Dialog: repeat the time left and whether the bid will extend the close ("adds 5 minutes").
15. Rewrite framework validation text ("The starting price field is required") to "Enter a starting price".
16. Align ladder figures and fix the "You" chip overlap.
17. Vary the browse grid (feature the closing-soon lot at large size, 2-column density, emphasis on price).
18. "What is included" rows: show condition once and location once on the lot.
19. Give the out-of-range page note ("Showing the last page") and keep position on filter changes.
20. Spread seed timestamps and add dates to the "Payment opens soon" copy so demos do not look fake.
