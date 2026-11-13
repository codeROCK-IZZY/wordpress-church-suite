# 2. Answer requests through signed links, without logging in

## Context

Most volunteers never log in to the church website. A request they have to log in to answer goes unanswered, and
the coordinator ends up chasing people by text on Saturday night.

## Decision

- Each request email carries two links, Accept and Decline. Each holds the place's id and an HMAC (SHA-256, keyed
  with the site's salts) of the place and the person it was sent to.
- The link is checked against whoever holds the place **now**. If the place has gone to someone else, the old link
  stops working, so a forwarded email cannot confirm or release a place it no longer refers to.
- A decline immediately asks the best other eligible person, using the scheduler's own rules, so the replacement
  respects their limits and spacing. If nobody can, the office is emailed.
- Accepting after declining is refused with a polite message, because the place may already be with someone else.
- Answers for dates in the past are ignored.

## Consequences

- Answering takes one tap from a phone. The integration test opens both links over HTTP.
- Anyone holding the link can answer for that person. That is the usual trade-off for emailed links, and it is
  limited to one place. The links contain no personal data.
- Links do not expire on a timer, only when the place changes hands or the date passes.
