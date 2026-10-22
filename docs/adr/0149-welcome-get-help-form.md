# ADR 0149: Welcome gets a Get help form that opens a ticket with the account's AI key, and a link to the service page

Date: 2026-10-22
Category: domain

## Status
Accepted (operator-directed, 2026-10-22)

## Context

The operator asked for a support form in the panel: "build a open support ticket form, which is, it looks like normal
... it goes to pikkumies, which checks it all ... and if pikkumies cannot solve it completely, it opens a ticket", and
then "just get this released". He also asked for a link from Welcome to the billing service page: "probably should
have also billing link to the service details page, it has many buttons now".

What existed on 2026-10-22:

- The Help card on Welcome (ADR 0147) had links only: getting started, knowledge base, wiki, Discord, two email
  addresses. Opening a ticket meant leaving the panel and signing in to the client area.
- The billing site has a ticket route for AI keys: `POST https://pulsedmedia.com/remote/ticket.php` with
  `Authorization: Bearer vsk-...` and JSON `{email, subject, message, priority}`. It answers 201 `{"ticket": "..."}`,
  401 for any key or email it does not accept, 429 when an account opens too many, 413 and 400 for bad input, 503 when
  switched off. It spends no AI credits. The email must be the billing account's own, or a linked user's who
  may open tickets.
- The account's AI key is `~/.virsilipasKey` (ADR 0090); ADR 0148 already reads it for the credits card through the
  same gate.
- Pikkumies itself runs only where the host has Eternals switched on, so "Pikkumies checks first" cannot be the only
  path.

## Decision

1. **Step one ships now: a plain form.** The Help card gets a folded "Get help" form: what is wrong (up to 8,000
   characters), a topic (Not working, Slow, Server down, Billing / account) and a reply email, prefilled from
   `~/.notifyEmail` when that is a regular file with a valid address.
2. **Where it goes.** With a key that passes the ADR 0148 gate, `welcome.php?support=1` (POST with
   `X-Requested-With`, ADR 0133) sends the ticket through the key route. The subject is the topic label and the first
   line (80 characters); the message ends with a line saying it came from the panel's form. Common password and key
   forms (`password: ...`, `token=...`, `pwd is <value with a digit>`, `vsk-...`) are replaced before sending. The key
   never appears in a page or an answer. Billing questions, and accounts without a key, get a link to the client-area
   form instead, because the key carries no account authority.
3. **What the customer sees.** One line under the form: "Ticket #N opened" on success, or what went wrong and a link
   to the client-area form. The text stays in the box on failure.
4. **Not in this change: Pikkumies first.** Letting Pikkumies look at the problem before a ticket opens, with its
   latest activity line shown, needs Eternals on the host and its own end-to-end checks on an Eternals testing machine.
5. **Service link.** When the account has a billing service id, the Help links start with "Your service in the client
   area", the billing service details page.

## Consequences

- A customer with an AI key opens a ticket in two clicks without signing in elsewhere; one without a key is one click
  from the client-area form.
- The billing site's own limits (5 tickets an hour, 20 a day per account) apply; the panel adds none.
- `welcome.php` reaches existing accounts through the panel heal.

## Alternatives considered

- Sending the ticket by mail from the server: the billing site would have no proof of which account sent it.
- Waiting for step two: the operator asked for the release now, and most hosts do not run Eternals yet.
