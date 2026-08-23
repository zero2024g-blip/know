# Ember — ZERO panel design system

Direction A, built out across all panel screens. Not yet wired into the
CodeIgniter views; this is the reference implementation.

## Files

| File | What it is |
|---|---|
| `ember.css` | The design system. Single stylesheet, no framework, no build step. |
| `extras.css` | Styles for the seven proposed additions (toasts, palette, QR sheet, bulk bar, sparkline, skeletons, light mode). |
| `build.mjs` | Generates the 10 screen mockups from shared partials. |
| `build-extras.mjs` | Generates the proposed-additions page. |
| `verify.mjs` | Drives headless Chromium over every page at 1440px and 390px and fails on overflow, contrast, tap-target, collapsed-box or JS problems. |
| `preview/` | Rendered JPEGs, desktop and mobile, for every screen. |

## Running it

```bash
node build.mjs && node build-extras.mjs   # writes *.html next to the CSS
node verify.mjs                            # renders to shots/ and reports problems
```

`verify.mjs` prints `CLEAN` when nothing is wrong. Last run: clean across
11 pages x 2 viewports.

## Tokens

Everything derives from custom properties at the top of `ember.css`:
ground, line, ink, brand, semantic, type, shape, space, elevation.
Change `--accent` and the whole panel re-skins.

Semantic colour is deliberately separate from the brand coral:
`--ok` green, `--warn` amber, `--bad` red, `--info` blue. Red means one
thing only. This is the main departure from the current panel, where
Roles, Saldo, Login Time and Auto Logout all rendered as `text-danger`.

## Responsive

One breakpoint does the heavy lifting: at 820px the key table stops being
a table and each row becomes a card, and the top nav links move into a
bottom tab bar. Nothing scrolls sideways at any width.

## Not done yet

Wiring into `Layout/Starter.php`, `Auth/`, `Keys/`, `User/` and `Admin/`.
