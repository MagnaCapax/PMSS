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
4. **Pikkumies first, where it runs.** When the Pikkumies ask bar is shown (ADR 0148: Eternals on the host, Pikkumies
   installed, billing id) and the account has an AI key, the form says that for Not working and Slow, Pikkumies looks
   first on the customer's own AI credits (a check like this uses about 10,000 to 60,000) and opens the ticket with what it found when it
   cannot fix it. Send then posts `welcome.php?support=pikkumies`, which hands the text, inside a fixed frame with the
   customer's consent to that ticket and the reply email, to `pikkumies ask --nonce=<random>` run as the account (at
   most 4,000 characters, the same redaction). The ticket itself goes through Pikkumies' own ticket tool. Server down
   and Billing / account never go to Pikkumies: a fault is never charged to the customer's credits. "Skip Pikkumies,
   send to support now" sends the plain ticket of item 2. A refusal from `ask` (setup unfinished, busy, unsent text,
   rate cap, starting) is told in plain words with the skip offered. Below 40,000 credits the form says they are too
   low and does not start Pikkumies: the credit service refuses a request it cannot cover up front, and a QA check was
   refused with 39,504 left.
5. **Activity line.** While Pikkumies works, the form shows its state and latest activity line from
   `welcome.php?support=activity` (`pikkumies eternal activity --json`, polled every 4 seconds while the page is in
   view, at most 15 minutes), cleaned of control characters and cut to 200 characters, and an "Open Pikkumies" button.
   A Pikkumies without that command shows no line. When 15 answered polls (about a minute) show neither work nor a
   line from this question, the form says Pikkumies has not started on it, with Open Pikkumies and the skip.
6. **Service link.** When the account has a billing service id, the Help links start with "Your service in the client
   area", the billing service details page.

## Consequences

- A customer with an AI key opens a ticket in two clicks without signing in elsewhere; one without a key is one click
  from the client-area form.
- The billing site's own limits (5 tickets an hour, 20 a day per account) apply; the panel adds none.
- `welcome.php` reaches existing accounts through the panel heal.

## Alternatives considered

- Sending the ticket by mail from the server: the billing site would have no proof of which account sent it.
- Waiting for step two: the operator asked for the release now, and most hosts do not run Eternals yet.

## Amendment 2026-10-23: a standard Pikkumies answer, no commands on the page, a support log

Operator, 2026-10-23: "the interface sucks, is over complicated, non user friend, with shell commands shown etc. the
promtp should always say no ticket needed if all is fine ... always stadnard, and deterministic log additions".

- The prompt asks Pikkumies to start its answer with one fixed line: "Result: All fine, no ticket needed.", "Result:
  Fixed, no ticket needed: ...", "Result: Not fixed, I opened ticket #...", or "Result: Not fixed, ticket not sent:
  ...", then at most five plain lines, with no commands, file paths, code or logs in anything it writes. It opens a
  ticket only when it cannot fix the problem.
- Item 5 changes: the form never shows Pikkumies' activity line, which is often a command. It shows Pikkumies' own newest
  words (`said` from `pikkumies eternal activity --json`, mcxPikkumies ADR-0075 amendment 2026-10-23), else "checking
  your seedbox" with the seconds elapsed. When Pikkumies is done, the form shows its Result line.
- The form is shorter: topic chips, the email field only when the account has none on file, one note, Send and a
  visible "Send to support instead", and four links.
- `~/.pmss/support-log.jsonl` in the account gets one row per send (route, topic, outcome, length of the text, never
  the text) and one per Pikkumies answer (its Result line), written by the panel, not by the AI. It keeps the newest 500
  rows.
- The form and the log take only the Result sentence of Pikkumies' answer; the rest of it is in the Pikkumies tab.
- Item 4's credit floor is 60,000, not 40,000: the credit service reserves for the call's prompt, which grows with
  Pikkumies' session, and a QA check was refused with 44,602 left (2026-10-23).
