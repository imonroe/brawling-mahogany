---
title: Roles and permissions
summary: The five that ship, composing your own, and what each permission opens.
section: setup
order: 2
---

What somebody can do is decided by the **roles** they hold. A role is a set of
permissions; a person can hold more than one, and holding two means holding
everything in either.

## The five that ship

| Role                    | For                                                              |
| ----------------------- | ---------------------------------------------------------------- |
| **Team Owner**          | Runs the team. Everything, including settings, members and roles |
| **Team Member**         | Works deals day to day                                           |
| **Status Viewer**       | A client, reading their own status page                          |
| **Contact**             | Somebody in the directory with no access at all                  |
| **Super Administrator** | Platform operations, across teams                                |

**Shipped roles cannot be edited**, and that is on purpose. If one team
renamed Team Member, that word would mean something different in their audit
log to everybody else's, six months later when somebody is trying to read it.

To differ, compose your own beside them.

## Composing a role

**Settings → Roles → New role**. Name it, tick the permissions, save.

The permissions on offer are the ones that apply to your team. Platform
operations are not among them.

Two things the app will not let you do, both for the same reason. You cannot
name a role after one that ships — a second _"Team Owner"_ would be
indistinguishable from the real one wherever permissions are checked, and the
consequences of that reach as far as being able to remove your own team's last
genuine owner. And you cannot give two roles the same name, because a
permissions matrix nobody can read is worse than one that is slightly
inconvenient.

## Archiving

Roles are **archived, never deleted**. A role appears in every audit entry and
every membership that ever held it, and deleting it would make those
unreadable.

Archiving is reversible, and the screen shows you how many people hold a role
**before** you choose — because archiving one held by four people takes four
people's access with it.

## What the permissions open

| Permission                              | Opens                                        |
| --------------------------------------- | -------------------------------------------- |
| `deals.view` / `deals.manage`           | Seeing and editing deals                     |
| `workflow.advance`                      | Advancing a stage, and ticking a manual gate |
| `workflow.override`                     | Overriding an unmet gate, with a reason      |
| `stage.skip`                            | Marking a stage not applicable               |
| `people.view` / `people.manage`         | The directory                                |
| `people.import`                         | Importing contacts from a file or Google     |
| `properties.view` / `properties.manage` | Properties                                   |
| `templates.manage`                      | Templates                                    |
| `settings.manage`                       | Team settings                                |
| `team.members.manage`                   | Inviting and removing people                 |
| `team.roles.manage`                     | This screen                                  |
| `team.audit.view`                       | The audit log                                |
| `team.export`                           | Exporting the team's data                    |

The screen lists a few more than this — the ones belonging to features that
have not arrived yet, like the calendar and Keep in Touch. They tick and save
like any other; they simply have nothing to open until the feature does.

The screen says **gate** where a deal says _requirement_ — same thing, and the
word changes with the context: here you are handing out an ability, and there
somebody is looking at what is in their way.

`workflow.advance` and `workflow.override` are deliberately separate. Somebody
should be able to move deals along all day without being able to decide a
survey was unnecessary.

## The last owner

The app will not let you remove the last person who can administer a team.
There would be no way back in without an operator.
