# 3. Giving in whole cents, and privacy erasure that keeps the books

## Context

Givers need an accurate statement every year, often for tax relief. Floating-point money drifts, and refunds and
gifts dated around the new year are where statements usually go wrong. A person may also ask to be forgotten, but a
charity usually has to keep its financial records for several years.

## Decision

- Store amounts as whole cents (`BIGINT`). Parse typed amounts strictly ("50", "49.99", "1,234.5"), and reject
  anything with more than two decimals.
- A statement covers one calendar year by the date the gift was given. It lists every gift. A refunded gift is shown,
  marked and struck through, but not counted. Totals are by fund and overall, summed in cents.
- A refund is a flag on the gift, not a deletion, so the record of what happened stays.
- Privacy erasure removes the person's name, email, phone, roles and dates away, and releases their places from today
  on so the rota can be planned again. Gift dates and amounts stay, linked to an anonymous record ("Former member
  #12"), and the eraser tells the administrator so.
- The statement footer is the church's own text, for its charity number and whatever wording its tax rules require.

## Consequences

- The unit tests cover refunds, gifts in other years and money parsing. The integration test builds a real statement
  and checks it survives erasure with its totals intact.
- There is no online giving: gifts are recorded by staff or imported. Taking payments is a different job, better done
  by a payment provider's plugin.
- Statements are printable HTML (print styles hide the admin chrome), not PDFs.
