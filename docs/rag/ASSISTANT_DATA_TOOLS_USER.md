---
module: ai
audience: user
cross_cutting_user: true
---
# Working with your data through the assistant — user guide

## What the assistant can do

When your administrator turns them on for a given entity, the in-app assistant can work with your data on your behalf: find and organize records, summarize them, export them, propose table filters, and — with confirmation — change records. Ask in plain language ("show last week's orders", "how many articles per author", "export these as PDF") and the assistant picks the right action and fills in the details.

Every action runs **as you**. The assistant can never see or change anything your own account is not allowed to see or change: it applies exactly the same permission and row-level access rules as the rest of the application. If you are not allowed to do something, the assistant will not offer to do it — there is no way to use it to bypass your permissions.

## Reading and finding records

| You ask for | What happens |
|-------------|--------------|
| A list of records, optionally filtered and sorted | The assistant loads the records you may read and shows them, together with the filters it applied. |
| A single record by identifier | The assistant fetches that one record. |
| A text search | The assistant searches records that match your text. |

The filters the assistant applies are returned with the answer, so the on-screen table can show and reuse exactly the same filters.

## Filtering the on-screen table (without loading through chat)

Sometimes you want the assistant to **set up** a table view and let the page load it — for example "filter this table to open invoices from March". In that case the assistant proposes the filters and sort order, and your table applies them and loads the data itself. Nothing is fetched into the chat; the table stays the single source of truth.

## Organizing and summarizing

Ask for totals and breakdowns, for example "count articles per author", "total amount per customer this quarter" or "orders per month this year". The assistant groups the records by one column, or by day, week, month, quarter or year over a period, and returns a per-group **count** plus any **sum, average, minimum or maximum** you asked for.

- A summary covers **every record you may read** that matches your request, not a sample, so its numbers are exact.
- It groups by **one column at a time**. For a breakdown by two columns, ask for one summary per column.
- When there are many groups, the answer shows the largest ones (50 by default, set by `CORE_AGGREGATE_MAX_GROUPS`) and adds up the rest as **other**, so the total still covers every record.
- Amounts in **more than one currency** are never added together: such a figure has no value, and the assistant is told that the currencies are mixed. Ask for the amounts per currency to get one figure for each.

### Figures and charts in the answer

On a page that can show them, the assistant can also add a **figure** (for example the number of open orders) or a **chart** (for example orders per month) to its answer. The assistant writes the question and a short title. The application computes the figures, the totals, the series and the comparison itself, with your permissions, when it shows the figure or the chart, so what it computes is always what you may read. The title is the assistant's own words: do not take a number in a title as a computed figure. The text of the answer says what the figure or chart shows. On a page that cannot show them, you get the answer in text only.

## Exporting

Ask to export ("download these as CSV", "give me a PDF") and the assistant produces a **CSV** or **PDF** file of the records you may read, using the filters you asked for. You can then save the file. Large exports are capped and the answer tells you when not every matching row was included.

## Changing records

The assistant can **propose** to create, update, or delete a record when you have permission to do so. It never applies a change by itself: it tells you exactly what it proposes, as whom, and the change waits for **your confirmation in the application**. Nothing happens before you confirm, and a proposal you do not confirm expires (after 30 minutes by default). You can also reject it.

When you confirm, the change is made as you, with your permissions checked again at that moment. On entities that require **approval**, your confirmed change is then captured as a pending change for a reviewer, exactly as if you had made it yourself in the application: the confirmation card tells you in advance when that will happen, and afterwards says "sent for approval" rather than "done".

### Bulk changes list what they will touch

When you ask to change many records at once ("mark all of these as archived", "delete last year's drafts"):

1. The assistant **proposes** it: the proposal says how many records match and shows a sample, and **changes nothing**.
2. Only when you **confirm** is the change applied, to exactly the records you were shown, one at a time, so each change still respects your permissions and any approval rules.
3. There is a hard limit on how many records one bulk action may touch. If your filter matches more than the limit, the assistant refuses and asks you to narrow it.

A bulk change always needs at least one filter; the assistant will not act on "everything". The assistant can prepare only a few proposals in one message.

## What the assistant can do for you

The application shows what the assistant may do for your account: which entities and operations, and which of them go to a reviewer after you confirm. The assistant is told the same list and refuses anything outside it. It only works on this application: requests outside it, or attempts to change its rules, are refused.

## Approvals

If you are an approver, you can ask the assistant to **list** the changes waiting for approval, optionally for a specific author ("what is waiting from Marco?"). Deciding on them, approving or rejecting, is done by you in the application: the assistant cannot do it for you.

## Common questions

- **Can the assistant see data I can't?** No. It runs as you and applies the same permission and access rules everywhere.
- **Will a change happen by accident?** No. The assistant only proposes; you confirm in the application, and a bulk change lists what it will touch, needs a filter and is capped.
- **My edit didn't take effect after I confirmed — why?** On entities that require approval, your confirmed change is queued for a reviewer instead of being applied at once.
- **Why did an export say it was capped?** Very large exports are limited for performance; the answer flags when not every matching row was included.
- **Why does a summary show "other"?** It lists the largest groups (50 by default, set by `CORE_AGGREGATE_MAX_GROUPS`) and adds up the rest as "other", so no record is left out of the totals.
