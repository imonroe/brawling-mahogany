---
title: Automated messages
summary: Client updates that send themselves, and the approval step in front of them.
section: coming-later
order: 3
arrives_with: A later release
---

Messages to clients that send themselves when something happens — a stage
completing, a deadline approaching — so the update that keeps somebody calm
does not depend on remembering to send it.

## What exists today

Nothing automated. Notes and logged contacts are the record of what you sent
by hand.

## What it will do

**Templates with merge fields**, so a message reads as though it was written
for the person receiving it.

**Triggers**, so a message goes when a stage completes or a date approaches
rather than when somebody remembers.

**A test send**, so you see exactly what the client will see, with real data
in it, before anybody else does.

## The approval queue, which is the point

Automated messages will land in a **review queue** before they send. You see
what is about to go, to whom, and can edit or stop it.

An email to the wrong client cannot be recalled. This is the highest-risk
thing the app will ever do on your behalf, and the safety rails are being
treated as part of the feature rather than as a setting to be added later:
a rate limit, a switch that stops all sending immediately, and a sandbox mode
where nothing reaches a real address.

Until every one of those works, this does not ship.
