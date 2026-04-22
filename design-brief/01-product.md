# 01 — Product

## What Doctor Subs is

A WordPress plugin that lives inside the WooCommerce admin. It detects and fixes three specific kinds of broken WooCommerce Subscriptions:

1. **Ghost subscription** — subscription is marked active but WordPress forgot to schedule its next payment. Will never renew.
2. **Stuck on-hold** — the payment actually succeeded in Stripe, but the subscription's status never flipped back to active. Customer got charged, store shows them as delinquent.
3. **Repeated failed payment actions** — something has been trying to renew this subscription and failing for a while.

Each detected problem gets a one-click fix with preview + undo. The plugin journals every fix so nothing it does is silent or irreversible.

## Who uses it

**Primary user: the solo store owner.** Non-technical. Runs a subscription business of some kind (monthly box, membership, coaching, digital download, small SaaS). Has 20 to 500 active subscribers. Does not know what Action Scheduler is. Cannot read logs. Does not run WP-CLI.

**Their emotional state when they open the plugin:**
- First install = panic mode. Revenue dipped this month, a customer emailed saying "my subscription didn't renew," they searched the WP plugin directory, installed Doctor Subs, and are now holding their breath.
- Return visits (Monday morning) = peace-of-mind mode. They want a 5-second glance that says "you're fine" or "3 things need you."

## The five-second test

When the owner lands on the main page, they should answer three questions in five seconds:

1. Is my store OK right now? (yes / no / mixed)
2. If not, how many things need me?
3. Where do I click to start?

## Emotional arc (panic → calm)

```
  Moment                        What they feel          What the design does
  ────────────────────────────  ──────────────────────  ──────────────────────────
  Just installed                Anxious, skeptical      First-run is calm, not
                                                        hyped. "Let's take a look."

  Hits 'Scan' button            Hopeful, worried        Progress is honest, not
                                                        performatively branded.

  Sees results                  Relief or alarm         Counts are big and clear.
                                                        Zero broken = immediate
                                                        calm. Three broken = clear
                                                        next step, not red panic.

  Clicks into a broken sub      Focused, cautious       Plain-English reason.
                                                        No jargon. One obvious
                                                        button.

  Fix preview modal             Trust-checking          Shows exactly what will
                                                        change. Two buttons:
                                                        Cancel and Fix. No tricks.

  Fix applied                   Relief + small pride    Success state is quiet.
                                                        No confetti, no emoji.
                                                        Just done.

  Reviews Undo log              Reassured               Every action listed.
                                                        Revert is always available.
```

## The product promise

"Never surprise the merchant. Never hide work. Never claim to have done something you haven't."

## What differentiates it from support

The merchant's other options when something breaks are:
- Email their host's support (slow, generic answers).
- Post on WooCommerce forums (wait for a stranger).
- Hire a dev on Codeable (expensive for a 10-minute fix).

Doctor Subs wins by being instant, specific, and trustworthy at the pixel level.
