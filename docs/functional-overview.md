# Functional overview — nobrainersbot

**What this system is, in product terms.** For the technical counterpart see [technical-overview.md](technical-overview.md).

## One sentence

A crypto trading bot that finds good entry moments on volatile coins, filters out the bad ones, buys, manages the stop-loss until a good exit, and (as a SaaS) lets a customer run it by just connecting an exchange and setting an amount.

## The problem it solves

The owner ran a legacy bot (`bot_signals`) that found trades on volatile coins using hand-tuned indicator rules. It worked, but: too many bad trades slipped through, the rules drifted over time, the good-vs-bad judgement was partly manual, and selling was hand-managed. nobrainersbot rebuilds this into a system that is **faithful to the proven rules first**, then **measurably better**, then **self-learning** — and finally a product other people can use.

## How it works (the trade lifecycle)

1. **Watch coins.** Per coin we ingest 5 base indicators (`vzo`, `phobos`, `obv-x-value`, `mfi`, `volumeud`) from TradingView. `volumeud` also carries price.
2. **Find a candidate entry.** Buy rules (currently 20, 21, 22, 23) evaluate the indicator series at each moment. A rule is a flat AND of subrules; each subrule computes one value over a lookback window and checks it against a min/max band.
3. **Decide whether to take it (precision).** Not every rule-fire is a good trade. A precision layer (coin volatility gating + an ML meta-filter) decides whether to actually buy — the goal is "drop the bad trades without losing the good ones".
4. **Buy.** On a real exchange (MEXC first) for the configured amount.
5. **Manage the exit.** A stop-loss is the **maximum of three mechanisms**, and is **never lowered**:
   - a hard floor ~1% below the buy price (max 1% loss),
   - a time-based rising stop (the longer in trade with no profit, the tighter),
   - business-rule exits (rule 101) that tighten the stop when an indicator drops.
   The stop trails up from the market/peak; when price breaches it, we sell.
6. **Record everything.** Every computed value, every entry, every exit, per datetime — so the system can learn.

## Two ideas that drive the design

- **Recall vs precision are separate jobs.** Rules are tuned for *recall* (catch every good trade, even at the cost of some bad ones). A separate precision layer drops the bad ones. We never tighten a rule to hit "max bad ≤ good" — that loses good trades. Precision is the filter's job.
- **Good entries are data, not opinion.** A "good trade" is defined from the price path after entry (upside reached within a horizon, without a disqualifying drop first, on a clean non-whippy path) — the triple-barrier / MFE method. This lets us auto-label and find good moments **even when no current rule catches them**.

## What we are building, in order

| Now | Foundation | Find all good trade periods (independent of rules) + store every computed value + run the sell-engine per datetime → know the potential result at every moment. Screens + graph to explore it. |
| Next | Precision | Coin volatility gating + ML meta-filter to drop bad trades. Discover new rules from the stored data. |
| Later | Execution | MEXC live execution, position sizing, the multi-tenant customer UI ("connect exchange + set amount = done"). |

## The customer vision (later)

A WorkMyAgent-style SaaS: a customer signs up, connects their exchange via API key, sets how much to trade, and the bot runs autonomously — finding volatile coins, trading them, managing risk. Multi-tenant, with a dashboard of trades, results, and live status. The hard part (the edge) is what we build first; the product wrapper comes last.

## Hard constraints

- **`bot_signals` is a read-only source.** We validate every rebuilt calculation against it (it is the oracle of historical truth) but never write to it.
- **No look-ahead.** Any value used to decide an entry uses only data from before that moment. "Future" quantities (realised profit, selling price, future price) are labels, never entry features.
- **Faithful before better.** Every rebuilt rule is validated to match legacy (currently 98.8–99.96% agreement) before we improve anything.
