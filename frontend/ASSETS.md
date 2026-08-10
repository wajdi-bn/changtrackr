# Public assets

All frontend runtime assets live under `public/assets` and use stable,
descriptive paths. Do not add generated filenames or duplicate source images.

## Structure

- `avatars`: bundled demonstration avatars only.
- `branding`: ChargeTrackr and authentication-provider marks.
- `charging`: media used by the live charging workflow.
- `fonts`: self-hosted fonts and their licenses.
- `landing`: editorial images used by public and authentication pages.
- `payments/providers`: payment-provider marks.
- `stations/models`: charging-station catalog images.

## Rules

- Use lowercase kebab-case filenames.
- Prefer WebP for photographic media and PNG/SVG for marks that require it.
- Resize media to its largest rendered size before committing it.
- Keep optimized raster images below 250 KB unless a documented exception is
  required.
- Declare intrinsic `width` and `height` in React and lazy-load images that are
  not visible in the first viewport.
- Update stored database paths through a reversible migration when renaming an
  asset referenced by persisted data.

The bundled Inter variable font is distributed under the SIL Open Font License;
its license is stored beside the font file.
