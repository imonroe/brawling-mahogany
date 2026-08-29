# Shipped template packs

Every `*.json` file here is a template pack, and `TemplatePackSeeder` imports
all of them on every deploy — `ReferenceDataSeeder` calls it beside the
permission catalogue and the three deal types, because a pack is a catalogue
entry identical for everybody.

**There are no packs here yet, and that is deliberate.** #87 is blocked on #11:
Emily's and Heather's real lists, and the per-task metadata `task_templates`
needs. The Build Plan's instruction is *build the mechanism against a
placeholder, do not invent the content* — a pack ships to every install and is
copied on first use, so stages somebody made up would teach a process nobody
follows and would be in flight before anybody noticed. An empty templates
screen is honest; a plausible wrong one is not.

## The loop these files come out of

1. `php artisan packs:import <file> --team=<slug>` puts a draft in a team.
2. Somebody who does the job marks it up on the templates screen — who owns
   each task, when it is due, whether it gates an advance, and which stage
   completions a client should hear about.
3. `php artisan packs:export --template=<id> --output=database/packs/<slug>.json`
   takes back what they produced.
4. Read the diff, edit the `pack` stanza, **take out anything a pack may not
   carry**, and commit.

Step 4's second half is not a formality: an export writes whatever the team's
template holds, including an automation that sends an email and an
`action_completed` gate, and `packs:import --as-pack` refuses both. The export
does not warn — it is a faithful copy of a team's template, and only the pack
direction has those limits. The two bullets below say which.

`packs:export --pack=<slug>` exports a whole pack rather than one workflow.

## The format

One file is one pack, and a pack holds one or more workflow templates.

```json
{
  "formatVersion": 1,
  "pack": {
    "slug": "buyer-representation",
    "name": "Buyer Representation",
    "description": null,
    "isInstalledByDefault": false,
    "priceTier": null,
    "sortOrder": 0
  },
  "messageTemplates": [],
  "workflows": [
    {
      "name": "Buyer Under Contract",
      "description": null,
      "version": 1,
      "isActive": true,
      "dealTypes": [{ "name": "Buyer Representation", "isDefault": true }],
      "stages": [
        {
          "name": "Under Contract",
          "description": null,
          "expectedDurationDays": 30,
          "ownerRole": "Transaction coordinator",
          "isMilestone": true,
          "clientFacingLabel": "You are under contract.",
          "gates": [
            {
              "gateType": "date_reached",
              "label": "Inspection objection has passed",
              "isBlocking": true,
              "config": { "keyDateName": "Inspection objection" }
            }
          ],
          "tasks": [
            {
              "title": "Confirm loan application completed with lender",
              "description": null,
              "ownerRole": "Agent",
              "dueOffsetDays": 3,
              "isRequired": true
            }
          ],
          "automations": [
            {
              "trigger": "stage_start",
              "actionType": "create_task",
              "config": { "taskTitle": "Request the settlement statement" },
              "isActive": true,
              "executionMode": "manual",
              "messageTemplate": null
            }
          ]
        }
      ]
    }
  ]
}
```

### Things worth knowing before you hand-edit one

- **Order is array position.** There are no `sortOrder` numbers inside the
  arrays, so the file cannot disagree with itself. `template_packs.sort_order`
  is the exception, because it orders packs against each other.
- **A shipped pack cannot carry an automation that sends words.**
  `message_templates` is team-scoped and `action_definitions` is not, so a
  CHECK constraint refuses a shared row that names a team's private template.
  A pack may carry any action that supplies its own words — *create a task*,
  *prompt somebody to do it*, *create a calendar event*. Any action that needs
  a message template — email, push, an internal notification — is refused at
  import, **whether or not the file names one**: a `send_email` with a null
  template satisfies the constraint and would ship a permanently incomplete
  automation to every install. Importing the same file `--team=<slug>` carries
  the words fine.
- **An `action_completed` gate is refused on the way *in*.** Its configuration
  is an `actionDefinitionId` — an id from whichever database wrote the file —
  and every import rebuilds the automations with new ids, so the gate would
  arrive pointing at nothing. An export writes one out happily, because it is a
  faithful copy of what a team has; take it out of the file, and add the gate on
  the templates screen after importing.
- **A message template stanza is held to the same rules the Messages screen
  uses**: merge fields are checked, a subject may not contain a line break, the
  recipient rule has to be one the channel can carry, and `fromIdentity` has to
  be an address the mail parser accepts.
- **Importing into a team reuses a message template of the same name on the
  same channel** rather than creating a second one, because the database keeps
  a unique index over that pair. The import says when it did, because the words
  that will send are then the ones already in the team. Two stanzas in one file
  whose names fold together are refused for the same reason — one of them would
  silently become the other.
- **`isRequired` is what makes a task gate an advance.** It feeds the
  `required_tasks_complete` gate. Absent means `false`, which is the column's
  own default and the safe one: a stage where every task blocks is a stage
  nobody can leave.
- **`clientFacingLabel` is not decoration.** A milestone stage with no label is
  **omitted** from the client's status page rather than renamed, because the
  only alternative to omitting it is inventing words on a team's branded page.
- **`dueOffsetDays` is relative to the stage's start**, and signed. There is no
  key-date anchor on `task_templates` — #11 records the decision to narrow to
  stage-relative rather than put a migration on #87's critical path, so *"five
  days before closing"* belongs in the title until that changes.
- **`gateType` may be any type the registry knows**, not only the three the
  editor's picker offers — a file is written by somebody who can supply the
  `config` a `document_present` gate needs.
- **Deal types are matched by name.** A name this install has not got is
  reported at import and the association is left off; nothing invents a deal
  type.

## Re-importing

`packs:import --as-pack` upserts on the pack's slug and rebuilds each named
workflow's stages outright, so a corrected pack ships with the code that
corrected it. The slug is the **only** thing matched on, so importing a file
whose slug collides with an unrelated pack updates that pack — the import says
so when the slug already existed, and it is worth reading. It does **not** delete a workflow template the file stopped
naming: `workflows.workflow_template_id` points at it, and a running deal
losing that pointer to a re-seed is a cost no file edit should impose silently.
It says so instead, and `is_active` on the templates screen is the reversible
way to take one out of circulation.
