# Changelog

All notable changes to this project are documented in this file.

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
