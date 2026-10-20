# Docket

A report centre for Flarum 2, built on [Flags](https://github.com/flarum/flags).

Flags tells your moderators that a post was reported. Docket turns each report into a case your team can work: sort it into a queue, give it to someone, write notes on it, close it as resolved or rejected, and tell the people who reported it how it went. Every report is kept, so the history of a post or a member is one click away.

Flags keeps working exactly as before. Docket adds to it and changes nothing it does; disable Docket and your flags are where you left them.

![The report centre](screenshots/report-centre.png)

## What it does

- **One report per flagged post.** Every flag on the post joins it, whatever raised it: the flag form, a post waiting for approval, or an extension that flags posts. A flag after the report is closed opens a new one.
- **Report centre** at `/reports`: needs attention, unassigned, assigned to me, resolved, rejected and all, by queue, with counts.
- **Queues** that admins define, each with a colour, and optionally kept to groups. A queue kept to groups is seen only by moderators in them, together with the flags on its posts. Admins see everything.
- **Auto-assignment rules**, tried top to bottom, drag to reorder. Match on tags, reason, flag type (e.g. `approval`) or the author's groups, then file the report in a queue and assign it to a moderator, or to whichever member of a group has the fewest reports in hand.
- **Claim, release, take over and assign.** A report someone holds is in progress; one nobody holds is open. Assigning to someone else needs the *Assign reports to other moderators* permission, and is refused if they can't see the report.
- **Resolve or reject.** The post's flags are dismissed with it. Optionally tell the people who reported it, with a message of your own; your name is never shown to them.
- **Staff notes and a timeline** on every report: who claimed it, who it was assigned to, the rule that filed it, and what happened to the post (hidden, restored, deleted, approved) or its author (warned, suspended).
- **History**: earlier reports on the same post and about the same member on every report, and a *Reports* tab on each member's profile for staff.
- **My reports** at `/my-reports` for everyone: the posts you reported and how each went, as far as staff chose to tell you.
- **Notifications**: *a report was assigned to me* (web and email) and *a report I made was reviewed* (web, email optional).
- Dismissing flags the Flarum way, from the post or the flags dropdown, closes the report too: resolved if something was done to the post, rejected if not.

![A report: the post, who reported it, the timeline and staff notes, and who holds it](screenshots/report-in-progress.png)

![Resolving a report and telling the people who reported it](screenshots/resolve-dialog.png)

## Works with

- **flarum/tags**: rules by tag, and *View flags* per tag decides which reports a moderator sees.
- **flarum/approval**: posts awaiting approval arrive as reports of type `approval`; approving one is logged.
- **fof/moderator-warnings**: warn the author from the report, see their warning count, and the warning is logged on the report.
- **flarum/suspend**: suspending the author is logged on their open reports.
- **fof/byobu** and every other extension that limits who sees a post: a report is seen only by someone who can see its post.

## Who sees what

A report is seen by exactly the people who see the flags on its post: the post must be visible to them (private discussions, restricted tags, hidden and unapproved posts all count), and they need *View flags* for its tags. A queue kept to groups narrows that further. A report whose post has been deleted is kept for staff who hold *View flags* forum-wide.

The people who reported a post see only their own report: its status, and the outcome message when staff chose to send one. They never see staff notes, other reporters, or who handled it.

![What a member sees under My reports](screenshots/my-reports.png)

## Install

```bash
composer require ernestdefoe/docket
php flarum extension:enable ernestdefoe-docket
```

Flags raised before Docket was installed, or while it was disabled, are filed the next time someone opens the report centre.

## Update

```bash
composer update ernestdefoe/docket
php flarum migrate
php flarum cache:clear
```

## Settings

*Admin → Docket*: queues, rules, and whether the *tell the reporters* box starts ticked. The *Assign reports to other moderators* permission is in the Moderate section of the permission grid (Moderators by default).

![Queues and auto-assignment rules](screenshots/admin-queues-and-rules.png)

## Support

[Support discussion](https://ernestdefoe.online) · [Issues](https://github.com/ernestdefoe/docket/issues)

☕ If you like the things I have built, consider [buying me a coffee](https://buymeacoffee.com/ernestdefoe).
