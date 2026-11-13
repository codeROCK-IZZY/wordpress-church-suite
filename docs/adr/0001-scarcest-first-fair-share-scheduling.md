# 1. Fill the scarcest places first, and share by what people offered

## Context

A church rota must fill every place while respecting what volunteers said: the roles they are trained for, the dates
they are away, and how often they are happy to serve. Within that, it should not wear out the reliable few, put
someone on two Sundays in a row, split a couple who want to serve together, or put people at a service time they
asked to avoid.

An exact optimiser (integer programming) could do this, but it means a solver dependency on shared WordPress hosting,
results that are hard to explain to a rota coordinator, and slow re-plans when one person drops out.

## Decision

A greedy scheduler with a repair pass:

1. **Hard rules** decide who is eligible at all: trained, not away, not already in a role at that service, and under
   their monthly limit.
2. **Scarcest first.** Places are ordered by how many people could take them that day. A role with one trained
   person is filled before the welcome team.
3. **Score** each eligible person and take the lowest: the share of their offered capacity already used, plus
   penalties for the week before or after (1.0), a household split when they asked to serve together (1.0), a time
   they did not prefer (0.3, and a 0.15 bonus for the time they did prefer), and two services on one day (0.6).
4. **Repair.** For each empty place, look for someone who could take it but is blocked by another place made in this
   plan (at their monthly limit, or in another role at that service). If someone else can take that other place,
   move them. Places that existed before the plan are never moved, because people may have confirmed them.

The weights were set on 15 tuning churches. Raising spacing and household from 0.5 to 1.0 cut back-to-back Sundays
from 13.7% to 11.1% and household splits from 1.6% to 1.0%. The fill rate stayed the same, and load spread rose
slightly (0.188 to 0.198). The repair pass added 0.7 points of filled places.

**A change after the held-out run.** A unit test written after the first held-out run showed that preferences only
penalised a mismatch. Someone who asked for 09:00 kept losing ties for 09:00 to people with no preference, and was
pushed to 11:00 every time. A matching preference now earns half the penalty as a bonus. All three banks were run
again. On held-out churches, back-to-back Sundays went from 11.4% to 11.1%, household splits from 1.4% to 1.1%,
and filled-after-declines from 88.4% to 88.5%.

## Consequences

- Held-out churches (after the change above): 90.9% filled with no rule broken, against 88.5% for a careful first-come coordinator and a
  rule-breaking 93.2% from team spreadsheets. Only 0.1% of volunteers were never asked, against 26.2%.
- It does not prove the rota is optimal. The share of places that no method can fill (about 9%) suggests most of the
  gap is real scarcity, but the distance from the true optimum was not measured.
- Every choice can be explained in one sentence ("Ann had served least relative to what she offered"), which matters
  when a coordinator has to defend the rota on a Sunday morning.
