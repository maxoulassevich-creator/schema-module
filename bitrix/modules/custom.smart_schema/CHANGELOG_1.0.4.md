# Smart Schema Enterprise 1.0.4

- Added microdata-aware audit for product category pages.
- Fixed mismatch where AI/audit logs reported category schema issues but no actionable proposals appeared in "Что внести".
- Added replacement proposals for `ProductCollection` -> `CollectionPage` and microdata `ItemList` -> JSON-LD `ItemList`.
- Reopens previously auto-skipped proposals when they become relevant again after a new scan.
- Verification now checks remaining same-type microdata after replacement mode.
