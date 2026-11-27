---
module: ai
audience: user
cross_cutting_user: true
locale: en
canonical_source: ai/assistant-proposals
safe_source_label: "AI · Suggestions from the assistant"
version: '1.0'
policy_classification: user_safe
policy_classification_version: in-app-docs-v1
required_permissions: []
tenant_scope: global
---
# Suggestions from the assistant: user guide

## What a suggestion is

Besides answering, the assistant can suggest a change to the way the application looks or works for you:
for example opening your lists as cards, or showing only the closed orders in a list. A suggestion appears
with the answer as a card that says what would change, from what to what, and why.

A suggestion is **only a proposal**:

- Nothing changes until you press **Apply**.
- You can dismiss it, or ask not to be offered that kind of suggestion again. The application keeps that
  choice in your own preferences, where you can see it and delete it.
- Applying a suggestion works like changing the setting yourself. You can always change it back.
- The assistant cannot apply it for you, cannot change a record, and cannot give you access to anything you
  are not already allowed to open. A suggestion can only touch your own preferences and your own views.

The assistant tells you that it is suggesting a change. It must never say that a change was made when you did
not press Apply; if it does, nothing was changed.

## When you do not see suggestions

The assistant offers suggestions only when the application shows them and the administrator has the
assistant enabled. If the assistant is off, or the application does not show suggestion cards, you simply
get answers.

## The title of a conversation

A conversation gets a short title by itself after the first answer, taken from your first question and the
answer, so the list of your conversations is readable. The title is written in the language you used. If a
title cannot be written, the first words of your question are used. A title you set yourself is never
changed. Deleting the conversation deletes its title.

## What the assistant remembers

When your administrator turns it on, the assistant reads the last few messages of the same conversation
before answering, so you can say "and the other one?" without repeating yourself. It reads only what you
wrote and what it answered, never the data it looked up for you, and it treats them as a record of what was
said, not as instructions. You can switch this off for one conversation from the conversation itself.

The assistant does **not** remember you from one conversation to the next: a new conversation starts from
nothing, and nothing about you is kept outside your conversations.

## What is kept

The assistant keeps your conversations, so you can come back to them, and nothing about how you use the
application. When you delete a conversation, its messages, the suggestions in them, its summaries and its
title are removed. When your account is deleted, your conversations and the contextual suggestions held
about you are removed too.

## Questions

- **Can the assistant change my settings without asking?** No. A suggestion changes nothing until you apply it.
- **Does the assistant learn from what I click?** No. The application does not record usage.
- **Does the assistant remember me in a new conversation?** No. At most it reads the recent messages of the
  conversation you are in, when that is turned on.
- **Why did a suggestion disappear?** Dismissed suggestions are not shown again, and a contextual suggestion
  nobody opened is removed after seven days.
