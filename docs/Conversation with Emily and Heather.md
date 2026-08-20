---
created: 2026-08-20
modified: 2026-08-20
---

# Conversation with Emily and Heather

The discussion focused on the functional requirements, market validation, and technical scoping for a specialized real estate workflow application. Ian and Emily analyzed a competitor’s product demo to identify high-value features for their own "internal tool" build, specifically emphasizing AI-driven document extraction and task automation.

### Competitor Analysis & Feature Validation

The team reviewed a demo from a husband-and-wife developer team. While the competitor's product is priced at $200/month, Emily noted the presentation lacked professional credibility and a mobile app.

Key validated features identified from the competitor:

- **Automated Date Extraction:** The system reads uploaded PDF contracts to extract calendar dates and "additional provisions."
- **Inspection Task Lists:** Uploaded inspection reports are processed by AI to automatically generate a corresponding task list.
- **Task List Customization:** Unlike standard CRMs, the competitor allows users to customize milestone-based task lists (e.g., pre-listing, under-contract, inspection).
- **Social/Event Scraping:** A "genius" feature noted was the ability for the system to prompt agents with local neighborhood events (e.g., food trucks) or social media life events (e.g., births) to facilitate authentic client touchpoints.

### Product Scoping (V1)

Ian and Emily defined the initial boundaries for the first version of their tool:

- **Target Market:** Explicitly scoped to **Residential** deals. Commercial workflows and rental property management are deferred as future "template packs."
- **Dashboard Capacity:** The dashboard will be designed to handle up to **25 concurrent deals** to support high-volume agents and small teams.
- **Tenant Placement:** While ongoing rental management is excluded, a template for tenant placement may be included in the initial residential scope.
- **CRM Lite:** The tool will include basic CRM functionality to maintain relationships after a contract closes, including "happy home" reminders.

### Security & Compliance Concerns

The group identified a critical risk regarding the "upload anything" document feature:

- **PII Risks:** Real estate contracts and earnest money checks contain personally identifiable financial information (account numbers, lending details).
- **Storage Requirements:** Uploading these documents triggers stringent security and data storage compliance requirements.
- **Mitigation Strategy:** Ian suggested implementing a prominent warning system to prevent users from uploading sensitive financial data, while Emily highlighted the need to limit what the AI analyzes to ensure privacy.

## Full transcript

Ian • 0:02

So the first question is, is the SaaS thesis validated at all?

Ian • 0:10

Which means can we find five additional real estate agents who might be interested in subscribing to it for like 40 bucks a month?

Emily • 0:23

Yeah, well, they are charging two hundred a month for the one that we just went to, and they've got people signed up.

Ian • 0:29

Okay.

Emily • 0:30

So we got that yet.

Ian • 0:33

Uh, the second question is, what does Emily's actual process look like, milestone by milestone?

Emily • 0:40

I sent you two lists. But I didn't, what I didn't send you was like the date. Per contract list, all the dates, so like I could send you what could be in a contract. So what was interesting about the the way they did it is the way they do it is they don't have to sync with CTM. They sync with they you once you go under contract, you also get whoa.

Speaker • 1:08

I knew that was going to happen in one of these days.

Emily • 1:10

Why? Why did that happen? It's not locked in the thing, maybe the bottom break. I think it just split off. Set right. I can see it. Okay, well, that's not where I'm gonna sit. That's what I know. Okay. Okay, well. No, no, I'm gonna sit right here. No, it's fine. All right, sit here. Okay, so what we do is so when so you have to do a little maintenance to get them going, and one of the things you have to do is when you go under contract, you have to upload your contract. So you take a PDF, upload it. It reads your contract. It pulls out all your dates and makes those calendar dates. And it also pulls out like additional provisions and says just makes notes of that. I didn't take screenshots because I think I'll get a recording of it maybe. She didn't do a very good job presenting it, in my opinion, at all. I could have crushed that. And then her husband.

Emily • 2:32

Yeah, and like it looks good and stuff, but like there's no app for it yet. It doesn't do so it doesn't do any push notifications is their next move is a client interface, and I actually think that part doesn't matter at all. I'm not in agreement with her on that. So some of the things that did that was cool was that like, so say

Speaker • 2:53

you

Emily • 2:55

Okay, it's time to schedule inspection. It auto populates the email, it's the inspections, and the stuff is sent. So everything happens. It's really a working portal. So but you have to populate your client information and it doesn't pull from your CRM or for Google or from like contacts, which it should, in my opinion, and you have to populate

Speaker • 3:20

your

Emily • 3:21

Actual contracted and then she also uploaded an MLS sheet instead of having that link with MLS Okay, okay, so those are the things I know about that Your dates and deadlines would come from like anything that had a date and deadline in it from CTM all the other tasks come from your task list templates They had it split into different things. So you have like a prelisting task list, like a signed listing task list, like under contract, during inspection. One of my favorite things that it did is when you do an inspection, you upload your inspection, it AIs it into a task list.

Heather • 4:05

Yeah,

Emily • 4:06

I loved that.

Heather • 4:07

Which is how do you write your inspections?

Emily • 4:14

Mm-hmm.

Speaker • 4:16

Okay. Um.

Speaker • 4:20

The next question is, what does Emily's brokerage require?

Speaker • 4:23

If the contracts must live in the brokerage platform, the document feature narrows considerably and the compliance framing changes.

Speaker • 4:31

Now, it sounds to me like based on what you just said, Said that one of the nice things about their system was that you could upload documents and it would extract information from them and use it to populate things further down in the system

Emily • 4:46

Yes, I have concerns about it though. my concerns are that we have to have like in our listing agreements now we have a clause that has to be in there that says people have access to this information that you know who has access to it. I have a slight security concern with people's privacy information. It's not that who cares? Like you're going to know this person bought that house in public records, but you have you I think you would want to be like I don't know that you can upload any doc you want to this thing because what if you uploaded their lending information or I don't know what I'm just

Speaker • 5:25

yeah that'd be like

Speaker • 5:26

um

Speaker • 5:27

That would bring into effect certain document storage requirements and security and stuff.

Speaker • 5:36

Exactly because that's personally identifiable financial information.

Emily • 5:40

Yeah, kind of. That's what I worried about with it. Like if you uploaded their earnest money check, it's got their checking account information on a copy of the check on a picture. We can keep that in CTM because CTM has security. Which is crappy, by the way, but like, suppose it is not yours. But it's also not ours.

Heather • 5:56

because you're not responsible.

Emily • 5:57

Yeah, so I would want to be limit what information like I would want it to at least be like, does this look like a check? We can't upload it. This looks like private data. You know what something about it? I don't know about that, you know?

Speaker • 6:12

Well, I mean, in order to figure out that it looks like a check, not only would you have to upload it, you'd also have to analyze if you did some kind of thing.

Speaker • 6:19

This

Emily • 6:19

isn't necessarily a key. All of your No, that's what I'm saying. But if you can upload anything, then it is.

Speaker • 6:25

Well, what if we put a big red warning sign on the upload thing that says do not upload personally identifiable or financial information?

Heather • 6:33

If you're doing this, you're marketing to other people. Sorry. I'm sorry. I was like, well,

Speaker • 6:38

we're building it.

Speaker • 6:39

We're going

Speaker • 6:39

to

Speaker • 6:39

build it as an internal tool.

Emily • 6:42

We were going to build one just like what they're talking about, because I don't. I mean, I'm minimizing how hard it is. I know I am, Ian, but I don't think it's that hard.

Speaker • 6:51

Well, it's relatively big and relatively complicated because it's got a pretty in-depth data model that's got to be associated with it.

Speaker • 7:02

Yeah, but I

Emily • 7:02

don't think they're doing anything genius other than what we had already thought about doing. Like we already have a outline of Go on.

Speaker • 7:12

commercial deals, in or out.

Emily • 7:16

I think I don't know enough about what the commercial timeline looks like and their processes work, but I don't know why it wouldn't be completely transferable to them. Like it's -- so one of the things that they do is they onboard, and so you sit down with them and upload your task list. So there could be like a customizable version and a not customizable version in my mind, I guess. But like if it's all custom if your task list is always customizable, it'll be the same for commercial versus residential.

Speaker • 7:45

So why

Emily • 7:46

not?

Speaker • 7:49

Well, the the system recommended explicitly scoping V one to residential and treating commercial as a later template pack, which might be interesting because if you're doing commercial deals, then you might have different requirements of the software.

Speaker • 8:04

Or might want to start with different templates and if that's the

Emily • 8:07

case stuff works different. I don't know that you can extract the same. I just don't. I don't know commercial well enough.

Speaker • 8:12

Well, maybe that's something that we can add down the road, and we'll just keep it residential for the the first version.

Speaker • 8:20

Rentals.

Speaker • 8:23

The Canvas lists two rental workflows.

Speaker • 8:25

Property management.

Speaker • 8:26

It is an adjacent product with.

Speaker • 8:28

Recurring rent, maintenance requests, and tenant screening.

Speaker • 8:32

Recommend V1 covers tenant placement only, not ongoing management.

Emily • 8:38

We are we

Speaker • 8:39

exclude resident or rentals completely?

Emily • 8:42

We don't manage rentals. A lot of us aren't allowed to, so we can place a renter, but we can't manage rental property management.

Speaker • 8:51

Do you do any rental placements at all?

Emily • 8:53

I personally do, but no. But other people in our company do.

Speaker • 8:59

People

Emily • 8:59

do. I think you would just have a you'd have the same kind of thing. You'd have a rental template, like a workflow template.

Speaker • 9:06

Yeah, maybe that's the idea is that we we make it so that there are template packs that cover different kinds of workflows and then use those as add-ons.

Emily • 9:19

I think somebody would want a rental pack. It's the same as a buyer's pack. Like some people, like the buyer's pack is way less than the listing. The listing is the most important part, I would say.

Speaker • 9:33

Do clients ever need to upload?

Speaker • 9:35

A seller emailing a disclosure back is common.

Speaker • 9:38

Portal upload is not in the current scope and might belong in slice.

Speaker • 9:42

So

Emily • 9:42

that's actually not common. Most clients do all their documentation through CTM. We don't use DocuSign or anything like that in Colorado. Now, if we're looking out of state, there's different requirements for that. But I would say most clients sign through a DocuSign or through a CTM kind of interface. Actually sending

Speaker • 10:04

us.

Emily • 10:09

Oh.

Speaker • 10:12

How many concurrent deals does Emily actively run?

Speaker • 10:15

Five and fifty imply very different dashboard designs.

Emily • 10:21

I think that Grace had twenty-three going in her dashboard right now. I think Kelly has seventeen right now. I think I am usually running two to ten. I've had as many as ten at a time. Like ten signs on the street, I know, but I like right now I've got three-ish running.

Speaker • 10:42

Okay, well, let's let's shoot for a dozen as a reasonable sort of middle ground, and I think you

Emily • 10:50

should shoot for twenty-five.

Speaker • 10:52

I mean, I shoot for twenty-five.

Emily • 10:54

I think you should shoot for twenty-five because I think that there's teams. If you're like or yeah, if you're out of prefer, you know, like there's something just really high well and if you think about right now, if I counted Brittany, I have six, seven. So and you might have a bunch of active clients that are like in progress as buyers. Like you could have ten buyers and five listings, and then if you had a team, you could each

Speaker • 11:23

have

Emily • 11:24

a buyer can be in a very long process. Also, I think what's interesting is one of the things that happened in this is that you'd have a closed contract. So when the contract closes, they still had activities. So your active isn't necessarily the end of the game because what it does is it became a CRN. So like, You could have a hundred clients in it that were getting happy health reminders and other things that happen automatically.

Speaker • 11:59

Yeah, so a little bit of CRM functionality is probably nice to have.

Emily • 12:02

Yeah, one of the cool things that it did that I thought was actually smart is it it like combed the news and events in town, and so it would say, hey. This person lives in this neighborhood and there's a food truck park pop up this weekend. You should tell them about it, and it would tell you to tell them about it, which is like a great touch point without. That's

Heather • 12:24

a that was that would I thought that was probably the most important

Emily • 12:28

thing. I thought that was genius. I think the other thing that would be genius is if it combed Facebook for life changing events. Like, did you notice that so-and-so had a baby? Yeah, which I just saw that feature from another company marketed to me, and that's all it did was comb your social for life events so you could reach out to people. I thought that was cool. I almost suckered in for that. Hey, I like our penguins, baby. Where do we get penguins?

Speaker • 13:00

And where do you see penguins?

Emily • 13:02

On the TV.

Speaker • 13:04

Oh, that's just like the login screen for the computer there.

Emily • 13:12

They look like hot penguins. They look like they're in the wrong place, really, when I think about it.

Speaker • 13:18

Well, a large

Heather • 13:19

quantity of penguins live not at the Arctic. They have arctic. They're they're tropical.

Speaker • 13:26

South America, presumably.

Speaker • 13:29

Maybe some in Australia

Heather • 13:31

like off South of South Africa.

Emily • 13:34

I guess I forgot that. You never see them like that. You usually see them on the snow.

Speaker • 13:40

Okay, and then the message that you sent me yesterday has like the step by step stuff that you're

Speaker • 13:47

talking

Speaker • 13:47

about.

Speaker • 13:47

I had to do a

Emily • 13:48

couple lists of things. I haven't combed them yet, but I was just giving you examples of what we kind of have and use. I think I would want to refine those into one.

Speaker • 13:58

Because I

Emily • 13:58

sent multiples and I didn't send you a buyer one. Emily Bosart Heather sent me her list. I saw that. Yeah, and so I thought we might take a look at those and refine our list based on it. Okay. What did you think of the demo today? Well, I

Heather • 14:16

missed the

Emily • 14:17

beginning part. It was kind of the same, like you kind of get the structure, though. Yeah, it looks a

Heather • 14:22

lot like rechat. You know,

Emily • 14:24

it yeah, it does look like a lot like rechat um which I can show you. I have that. It I

Heather • 14:35

I mean to be fair, I don't know that it's particularly different than rechat other than the fact that you own your crm

Emily • 14:43

well you can customize your dates and deadlines. That's the big thing that's different

Speaker • 14:47

is

Emily • 14:47

You can customize your task list, and that's what we don't have anywhere.

Heather • 14:51

In your

Emily • 14:52

CRM. Yeah, there are CRMs who do that, I think, but I don't know where they are.

Heather • 14:57

I mean, I, yeah, so yes, because all it does is CTM states the deadline. So it doesn't give you the other like check. Yeah, send this now.

Emily • 15:06

Yeah, it doesn't give you your customized

Heather • 15:08

deadline. They don't check your lockbox. They don't. I thought the and I. Other than that, I thought, so it's all in one place instead of going through your notes and having to do your checklist or proper. I, yeah, that's great. I thought the other thing, the only other thing that I thought was really interesting was this subject, I, which I thought was brilliant. super smart. But still a little bit bad when we steal that thing.

Emily • 15:36

I'm gonna steal the whole thing from them because it. We could sell it better.

Heather • 15:41

You could sell it better.

Speaker • 15:42

Now, am I understanding this correctly?

Speaker • 15:44

The demo that you saw today was by a husband and wife team.

Heather • 15:47

Yeah, yeah.

Speaker • 15:48

Was the husband the sole developer and the wife was the salesperson, sort of?

Emily • 15:53

She's a

Heather • 15:53

realtor. She's a realtor, and so she it's essentially the same situation. But the developer guy was like a Chad. So the way he was

Emily • 16:03

talking was very Chad like. He didn't say much. But when he said he

Heather • 16:08

was talking, he sounded

Emily • 16:10

like

Heather • 16:12

I'm not sure that he actually knows what he's doing.

Emily • 16:15

I don't know how he did it.

Heather • 16:16

Yeah, I I think he may have had a friend's help or whatever, you know, and that might just be the I don't know. He did not strike me as an engineer. That's what I'm going to tell you because

Emily • 16:26

he did not.

Heather • 16:27

He sounded like sales guy.

Emily • 16:32

Yeah, he felt like a sales guy. He sounded, like a sales guy. I'm not sure why they're having her sound, except that she has credibility because she's a realtor, right? But he sounded like a broy. But she really told us that on how she was selling solving her mom's problem, her mom problems. And you know what I don't care about? Her fucking mom problems. Nor does any busy agent.

Emily • 16:58

I felt very trod white. She is not. She was not the she's one of the top ages. But she's pretty. She's great.

Heather • 17:04

Oh yeah. She he seemed like a abolishing sales dude.

Emily • 17:10

Like he was my brother. Wait, but not good.

Heather • 17:14

No. He's not good. No, but he's like his jargon was like broy sales dude. Right, more than it wasn't the like I know. I listened to John's golf. You know when you're talking when the growie sales dude is talking, you know when the engineers are talking, right? Sure. There's totally different. Yeah, he golfs. Yeah, he golfs, and he doesn't know what he's actually doing, right? There's not there's no user interface happening. There's no technical jargon going on whatsoever. He is. Just throwing out two throat jargon. So I don't know how that happened. There's got to be a better like that. It did not come off well.

Emily • 17:57

It looked nice, though.

Heather • 18:00

The product looks good. The people presenting the product, it was

Emily • 18:05

weird. Their next phase was client view, and that made no sense to me because I think app. It's the way to go.

Heather • 18:13

Yeah, I won't ask. I want patients with their clients to check things off, which is, I guess, okay, but I think more professional is just for you to do, is for you to contact your client and say, you know, whatever, instead of having the client here to check off. Yeah, yeah, I don't want my client checking off. To do list, you know?

Speaker • 18:45

well, that gives me enough to continue moving forward.

Speaker • 18:50

No, I mean, really, like the lists that you sent me are probably the most important thing because that's got the actual workflow.

Emily • 18:57

If you give me another day, I can get it revised into like a good solid list between the lists. I just didn't do that yet last night.

Emily • 19:06

Right now I can charge my computer.
