# Gallery Release Runbook (DOC-011)

The real, exact steps to take this release from "prepared" (where it
stands today) to actually published on GitHub and submitted to the PKP
Plugin Gallery. Everything up through step 3 has already been done for
`2.0.0.0` — see `docs/v2/GALLERY_RELEASE_METADATA.md` for the real,
current package MD5/size. Steps 4 onward are real publication actions,
gated by this project's standing PUBLIC RELEASE GATE — an explicit owner
decision, not something an agent takes autonomously.

## What's already done (repeatable for a future release)

1. **Version bump**: update `<release>`/`<date>` in `version.xml`.
2. **Build the package**: from the repo root, on the exact commit being
   released —
   ```
   git archive --format=tar --prefix=chatwootIntegration/ HEAD | gzip > chatwootIntegration-<version>.tar.gz
   ```
   Verify: `tar tzf` shows every entry under one `chatwootIntegration/`
   prefix, a fresh extraction is clean, `version.xml`/`LICENSE`/`index.php`
   are present, and no stale/dev-only file made it in (see `docs/v2/
   TASKLIST.md` RELS-006 for what was found and removed this pass).
3. **Compute the MD5**: `md5 -q <tarball>` (macOS) or `md5sum <tarball>`
   (Linux). Record it alongside the package's exact byte size.
4. **Draft metadata**: title/summary/description/homepage/maintainer and
   a draft Gallery XML `<release>` snippet — see `docs/v2/
   GALLERY_RELEASE_METADATA.md` for the real template this release used.

## What remains — real publication actions (owner-gated)

5. **Make the repository public** (RELS-001) — this repo's own
   description names GitHub as the actual publication target, distinct
   from this Forgejo dev repo. Requires an explicit owner decision on
   which GitHub repository/organization to publish to.
6. **Push the release commit to the public GitHub repo and create an
   immutable GitHub release/tag** (RELS-009) — attach the real
   `chatwootIntegration-<version>.tar.gz` from step 2 as a release asset,
   giving it a real, permanent download URL. This URL replaces the
   placeholder in `docs/v2/GALLERY_RELEASE_METADATA.md`'s `<package>`
   element.
7. **Validate the final XML** (RELS-013): re-run the MD5 check against
   the actual uploaded asset (not just the local build — confirm nothing
   changed in transit), and confirm the package URL is publicly
   reachable.
8. **Open the PR to `pkp/plugin-gallery`** (RELS-014) adding this
   plugin's entry (a first-time listing needs a new `<plugin>` block; a
   version bump on an already-listed plugin needs only a new `<release>`
   entry appended to the existing block) with the validated XML from
   step 7.
9. **Address PKP's automated checks and any maintainer code review**
   (RELS-015) on that PR.
10. Once merged and live, **never edit that specific published
    `<release>` entry** (RELS-016) — a correction requires a new release
    version, never rewriting history under an already-published one.

## For the next release after this one

Re-run this entire runbook from step 1, and also re-run `docs/v2/
VERIFICATION_MATRIX.md`'s "Compatibility-update process for a future OJS
release" (RELS-017) first if the OJS compatibility target is changing —
never assume a prior release's verification still holds.
