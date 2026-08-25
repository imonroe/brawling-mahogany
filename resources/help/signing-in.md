---
title: Signing in and your account
summary: Passwords, two-factor, passkeys, and what to do when you are locked out.
section: getting-started
order: 2
---

## Signing in

Your account is your email address and a password. If somebody invited you to
a team, the invitation is what created the account — accepting it is what sets
your password.

## Two-factor authentication

**Team owners must have it**, and so must whoever administers the install.
The requirement follows the **Team Owner role** itself rather than any
particular permission — hold that role in any team and you must enrol.

It is not a lock on particular screens. Until you have enrolled, the app sends
you to the enrolment screen from everywhere except your own account settings
and this manual, which stays readable while you are held there.

Everybody else may turn it on and should. A role your team composed for itself
does not carry the requirement, even when it grants the same abilities an owner
has — so if you want somebody held to two-factor, give them the Team Owner
role rather than a copy of its permissions. Whether that is the right rule is
being looked at.

You will find it under **Settings → Security**, along with your recovery
codes. Keep those somewhere that is not your phone — they are what gets you
back in when the phone is the thing you have lost.

## Passkeys

If your device supports them, you can add a passkey and sign in with your
face, fingerprint or device PIN instead of typing a password. It is under
**Settings → Security** beside two-factor.

A passkey does not replace your password; it sits alongside it.

## When you cannot get in

**Forgotten password.** Use the link on the sign-in screen. If your install
has no mail set up yet — which is the case on a fresh development
environment — an administrator can generate a reset link for you directly.

**Lost your second factor.** Use a recovery code. Each one works once.

**Out of recovery codes and no phone.** This one needs a person: an
administrator has to help you. There is deliberately no self-service screen
that mints a way past two-factor for somebody else's account, because a screen
like that is an account-takeover button however carefully it is worded.

## Your profile

**Settings → Profile** holds your name and contact details. One thing that
surprises people: these are held **per team**. If you work in two teams, each
one holds its own record of your name and number, and changing it in one does
not change it in the other. That is deliberate — a brokerage and a side
venture may know you differently — but it does mean checking both if you
change your number.
