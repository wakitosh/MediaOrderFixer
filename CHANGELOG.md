# Changelog

All notable changes to this project are documented in this file.

## 0.1.1 (2026-07-29)

- Fixed: applying a reorder left the item's `modified` timestamp untouched, so
  nothing downstream could tell that the media order or the primary media had
  changed. Most visibly, the IIIF manifest cache
  (`files/iiif/<version>/<itemId>.manifest.json`, written when
  `iiifserver_manifest_cache_derivativemedia` is enabled) kept serving the
  previous canvas order, so viewers such as Mirador showed the old page order
  and the old thumbnail order indefinitely.
- The apply action writes the entities directly instead of going through the
  API, so `AbstractEntityAdapter::updateTimestamps()` — the only thing that
  maintains `modified` in Omeka S — never ran. The timestamp is now set
  explicitly when a reorder is applied.
- Note: the direct-entity path still does not emit `api.update.post`, so the
  background job of module Derivative Media is not dispatched on apply. The
  manifest is instead rebuilt on the next request for it, which can be slow for
  items with a very large number of media.

## 0.1.0 (2026-02-18)

- Initial release of MediaOrderFixer.
- Added admin UI for review-before-apply media reordering workflow.
- Added identifier resolution via text input and file upload.
- Added reorder strategy selection:
  - `padded_first`
  - `plain_first`
  - `interleave`
- Added preview table with:
  - eligibility / exclusion status
  - Before / After media samples
  - item edit link and public page link
  - site slug selector for public URL preference
- Added bulk selection support (header toggle button).
- Added apply actions for selected/all eligible rows.
- Added primary media update rule on apply:
  - choose the first public media in reordered result
  - fallback to the first media if no public media exists
