---
created: 2026-08-19
modified: 2026-08-19
project: Brawling Mahogany
---

# The basic idea

## Summary

> [!abstract] At a glance
> A voice brain dump (~6.5 min) in which Ian lays out the founding concept for **Brawling Mahogany**: a workflow-driven CRM for small real estate teams, prompted by a collaboration request from Emily, a working real estate agent. The recording sets the product vision, sketches the user and permission model, names a preferred tech stack, and defines the immediate next step as writing a PRD and tightening the data model.

### Origin and context

The project starts with a request. Emily, a real estate agent, wants Ian to build something with her. She runs a small operation: herself plus a personal assistant. Depending on the deal, she acts as either a buyer's agent or a seller's agent, and her clients are buying or selling houses, condos, apartments, and sometimes commercial buildings.

Every one of those transaction types carries its own process. Certain tasks have to happen every single time for the deal to move smoothly, for clients to feel taken care of, and for Emily's team to know where things stand and what comes next. Right now that knowledge lives in people's heads. That gap is the problem the software solves.

### The core idea

Build something shaped like a CRM, but shift the center of gravity. A conventional CRM tracks contacts, their details, and follow-ups. This application instead attaches **workflows to each kind of deal**, and each workflow is built out of **milestones**. The contact record still matters, but the process is what the software actually manages.

A milestone is both a state and a trigger. Reaching one advances the deal and fires off whatever needs to happen at that point.

Two examples Ian gives:

- **Property listed in the MLS.** Hitting this milestone should kick off client communication, for instance an automated congratulations email that includes the MLS link.
- **Pre-sale improvements to a property.** This milestone carries real substructure: improvements get decided on, supplies get purchased, contractors get hired, and an expected completion date gets tracked.

The second example matters because it shows milestones are not just checkboxes. They hold data, dependencies, and dates.

### Product scope and business model

The deliverable is a web application. It must handle many clients at once, and critically, it must support **many teams**, not just Emily and her assistant. That multi-tenancy is deliberate and commercial: Ian wants to sell subscriptions to other small, independent real estate professionals at a modest monthly fee, so they can run the same playbook in their own practices.

Each team needs a **control panel** for managing its own projects and its own people.

### Users, roles, and permissions

Ian wants role-based security built on a single underlying `user` object, extended by **role decorators**. Each decorator grants a permission set and can also add role-specific fields. Concrete cases he walks through:

- **Contractor.** Probably needs no login at all. Does need stored attributes: what work they are good at, notes on past jobs, and similar history.
- **Buyer.** Wants a **client portal** to log into and check status, see their position on the timeline, and read what happens next.
- **Team owner (Emily).** Can add users, change roles, define new permission sets, and manage templates. All of it **strictly scoped to that owner's own team**.
- **Super administrator.** Can see and act across the entire application, for maintenance and troubleshooting.

### Technology and infrastructure

These are stated as leanings rather than final decisions:

| Layer | Direction |
|---|---|
| Backend | Laravel / PHP (Ian's personal preference, and he thinks it fits well) |
| Frontend | Vue.js components for interactivity |
| Hosting | Dockerized / containerized on a server, likely DigitalOcean |
| Email | Amazon SES |
| SMS | Wanted eventually, explicitly deferred |

### Current state

Ian has already roughed out a starting data model. He is candid that it is imprecise and that things are missing or need adjusting. See [[Rough data model.canvas]].

### Next steps

1. Turn this basic idea plus the rough data model into a proper **product requirements document**.
2. **Tighten up the data model** so it is precise enough to build against.
3. **Lock in the technology choices** rather than leaving them as preferences.

Ian closes by saying he will drop the brain dump into Obsidian and start feeding it to AI to see what comes out. That is what this note is.

---

## Transcript

Ian • 0:01

Okay, I'm gonna battle here about this project that Emily wants me to do with her.

Ian • 0:09

Um, so Emily is a real estate agent, and Emily has a personal assistant that helps her in the real estate business.

Ian • 0:18

Sometimes she works as a buyer's agent, sometimes she works as a seller's agent.

Ian • 0:26

In any case, she has clients, and those clients either want to buy a house or sell a house or a condo or an apartment or whatever, maybe a commercial building.

Ian • 0:39

And for each of those kinds of transactions, there's going to be a process that is involved.

Ian • 0:49

There's going to be certain workloads. which are involved that have to be executed every time in order for the deal to move smoothly, for the clients to feel comfortable, and for Emily and her team to have some idea of where things stand, what next steps are, and so forth.

Ian • 1:12

So the idea here is that we

Ian • 1:15

have

Ian • 1:17

Something like a customer relationship management software, but instead of just tracking customers and the information about them and their contacts and so forth and follow-ups, what we want to do is we want to have workflows that are associated with each kind of deal, and each of those workflows will have milestones.

Ian • 1:42

So, for instance, when a property is listed for sale in the MLS, that is a particular kind of milestone, and certain things have to happen.

Ian • 1:54

So, for instance, maybe at that point, you want to send the client an email that says, congratulations, your property is listed along with the MLS link, and so on and so forth.

Ian • 2:06

Likewise.

Ian • 2:07

If, for instance, there are improvements needed to a property before it can be sold, that's another milestone, right?

Ian • 2:19

The improvements have been decided on, uh, the supplies have been purchased, the contractors have been employed, and there is some expected done date.

Ian • 2:32

For that to be happening.

Ian • 2:37

So we want to make all of this into a web application, and we want to be able to keep track of a number of different clients, and we want to be able to support a number of different teams, not just Emily and her assistant, but perhaps we can sell subscriptions to it for

Ian • 2:55

other

Ian • 2:57

small, independent real estate professionals to use in their own practices for a modest monthly subscription fee.

Ian • 3:08

I have built out the basics of what I think is a reasonable start for a data model, but it is not very precise.

Ian • 3:17

And I'm sure that there's things that I have not thought about and

Ian • 3:20

that

Ian • 3:22

Need to be adjusted or improved.

Ian • 3:26

So the next step I think that I'd like to do is to take this basic idea and the basic data model and build out a product requirements document for the software.

Ian • 3:43

I'm anticipating that the software is going to run in some kind of

Ian • 3:46

Dockerized containerized system on a server, perhaps in Digital Oceans Cloud or something like that.

Ian • 3:56

I anticipate that we'll be using Amazon SES for email.

Ian • 4:02

Uh, we may want to support text messaging at some point in time, but that is a down the road kind of thing.

Ian • 4:10

So we need a product report Requirements document, and we need to tighten up what the data model looks like.

Ian • 4:17

We need to decide on the technologies that we're going to use.

Ian • 4:20

My personal feeling is that this is a good project for an application like Laravel and PHP with Vue. js front-end components for interactivity.

Ian • 4:34

We anticipate that.

Ian • 4:36

We'll probably need a, uh, control panel interface for each team so that they can control their projects and the people on their team.

Ian • 4:51

We anticipate that there's going to need to be some sort of roles-based security for different kinds of users.

Ian • 4:58

Um.

Ian • 5:00

We anticipate that the different kinds of users will all be based on a single sort of user object, along with decorators for the roles that they're assigned that give them certain permissions and also give them certain additional fields.

Ian • 5:17

So, like, for instance, uh, if there is a contractor user, that contractor Probably doesn't need to have login permissions, but that contractor probably does need to have some information about what kind of stuff that they're good at, and some notes about when they've been used before, and so on and so forth.

Ian • 5:37

A buyer may want to have a portal page to log into so that they can see the status of the project that they are involved in and see where they are in the timeline and see what

Ian • 5:50

the

Ian • 5:51

Next steps are, and that sort of thing.

Ian • 5:54

Likewise, Emily or a team owner should have the ability to add new users, to change their roles, to set up new kinds of permissions, to add templates, and so on and so forth.

Ian • 6:09

But that should be strictly scoped to that team owner's team.

Ian • 6:16

There should probably be a super administrator user who can see and work on any part of the application for things like maintenance, troubleshooting, et cetera.

Ian • 6:31

Okay, that's enough of a brain dump for right now.

Ian • 6:33

I'm going to drop this into an obsidian note, and I'm going to start feeding into some AI, and I'm going to see what we can come up with.
