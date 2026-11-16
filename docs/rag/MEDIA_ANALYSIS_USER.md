---
module: ai
audience: user
cross_cutting_user: true
locale: en
canonical_source: ai/media-analysis
safe_source_label: "AI · Media analysis"
version: '1.0'
policy_classification: user_safe
policy_classification_version: in-app-docs-v1
required_permissions: []
tenant_scope: global
---
# Media analysis: user guide

With media analysis switched on (Filament > Settings, `features.media_analysis.enabled`), the files you
attach to contents and tickets become searchable by what they show or say:

- images get a caption, keywords, the idea and intent they convey, and any text they contain;
- audio and video get a transcript;
- PDFs get their text.

The caption and keywords fill the media's description and keywords only when you left them empty; what
you write is never overwritten.

A content or ticket also becomes findable by what its media show (for example an article with a photo of
a lighthouse is found by "lighthouse"), but not by the full transcript of a video it contains.

You only find a media if you can see the content or ticket it belongs to. An administrator can change
this with the setting `media.search_visibility` (`open` shows every media regardless of its owner).

The same file attached twice is analysed once. When the last copy of a file is deleted for good, its
analysis is deleted too.

On a media's view (in the media gallery, or on its content or ticket) an "AI analysis" panel shows the
idea, intent, entities and any transcript or extracted text, with a "Re-analyze" button to run the
analysis again. The panel and the button appear only while media analysis is switched on.
