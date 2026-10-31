# Branding

Logo, favicon, colour scheme, colours and custom CSS of the guest pages and the admin UI. Admin role only; every
change is written to the audit log (`settings.branding`, `site.branding`; changed keys only, no file
contents or CSS text). Decisions: CONCEPT.md §9 ("Branding"), §10.3, §12.

## 1. Where to configure

| What | Where | Applies to |
|---|---|---|
| Global defaults | Admin UI → Settings → Appearance | Guest pages of all sites, admin UI, signed approval pages |
| Site overrides | Admin UI → Sites → site → "Appearance of this site" | Guest pages of that site only |

Resolution order for each item (logo, favicon, colour scheme, accent, background, text colour, custom CSS):
**site override → global setting → built-in default.** Each item of a site is either inherited
(checkbox off) or overridden (checkbox on). A site's custom CSS **replaces** the global custom CSS
(copy the global CSS into the site field to extend it).

Guest pages use the site of the current portal session (the site the router redirect named); the
status page after login uses the site of the login. Pages without a site (e.g. "not connected")
use the global branding. The admin UI always uses the global branding.

## 2. Logo and favicon

- Logo: PNG, JPEG, WebP or SVG, max. 512 KB and 4096 × 4096 px. Shown in the guest header
  (height 2 rem) and in the admin top bar (1.5 rem); the width follows the aspect ratio (max. 10 rem).
- Favicon: PNG, ICO or SVG, max. 100 KB.
- The type is detected from the content, not from the file name. Raster images are stored as
  uploaded (not re-encoded): remove EXIF/location metadata yourself if needed.
- Replace: upload a new file. Remove (global): "Remove logo" / "Remove favicon" brings back the
  built-in default. Remove (site): uncheck "Override" to inherit again.
- Files are stored as `<sha256>.<ext>` in `/data/branding` and included in backups. URLs contain
  the content hash (`/branding/files/<hash>.<ext>`), so browsers cache them for a year and a new
  upload is picked up at once. Files no longer referenced are deleted on a later save (after 5 minutes).

### Built-in default

Without an upload the portal shows a built-in logo (shield with Wi-Fi arcs, inline SVG in
`currentColor`) that follows the accent colour (`--gp-accent`), and the favicons
`/favicon.svg`, `/favicon.png` (32 px) and `/favicon.ico`. The settings page says "Default logo in use".

- Override it: upload a logo (globally or per site).
- Hide it (or any logo): custom CSS `.gp-logo { display: none; }`.
- Recolour only the default logo: `.gp-logo-default { color: #b91c1c; }`.

### SVG rules

SVG files must be UTF-8; they are parsed and re-serialized. **Rejected** (error message, nothing stored): other encodings (UTF-16, UTF-7, ...), DOCTYPE or
entity declarations, `script`, `foreignObject`, `iframe`, `embed`, `object` and similar
elements, any `on*` event attribute, `href`/`xlink:href` other than `#id` or an embedded
`data:image/(png|jpeg|gif|webp);base64,...`, `javascript:` anywhere, `@import` or `url()` to anything
but `#id`/embedded images in `style`. **Removed silently:** comments, processing instructions,
editor metadata (Inkscape/Sodipodi elements and attributes), animation (`animate`, `set`) and other
unknown elements. Text in SVG uses the fonts of the viewer's device; convert text to paths for a
stable look. Uploaded files are served with
`Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox` and
`X-Content-Type-Options: nosniff`, so even an SVG opened directly cannot run scripts or load anything.

## 3. Colours

Colour scheme (v0.11.0): **Dark** (default; also when not set) or **Light** (the look before v0.11.0).
A fixed choice for all visitors, it does not follow the device setting (`prefers-color-scheme`). It
sets the defaults of the variables below; custom colours override them in both schemes. The admin UI
always uses the global scheme. The printable voucher sheet stays light.

Hex values (`#1d4ed8` or `#14f`); empty = default of the scheme. The settings page has a live preview card.

| Setting | Variable | Default light / dark | Used for |
|---|---|---|---|
| Accent colour | `--gp-accent` | `#0f172a` / `#e2e8f0` | Primary buttons, active language, links (`.gp-link`), default logo, admin top bar (light; dark: only a custom accent) |
| (computed) | `--gp-accent-contrast` | `#fff` / `#000` | Text on accent backgrounds: black or white, whichever has the higher contrast to the accent |
| Background colour | `--gp-bg` | `#f1f5f9` / `#0f172a` | Page background |
| Text colour | `--gp-text` | `#0f172a` / `#e2e8f0` | Page text |
| (CSS only) | `--gp-surface` | `#fff` / `#1e293b` | Card background (`.gp-card`), secondary buttons |
| (CSS only) | `--gp-border` | `#cbd5e1` / `#475569` | Border of inputs and secondary buttons |
| (CSS only) | `--gp-divider` | `rgb(0 0 0 / .1)` / `rgb(255 255 255 / .12)` | Separator lines in guest cards |
| (CSS only) | `--gp-input-bg` | `#fff` / `#0f172a` | Input background |
| (CSS only) | `--gp-input-text` | `#0f172a` / `#f1f5f9` | Input and secondary button text |
| (CSS only) | `--gp-header-bg` | `var(--gp-accent)` / `var(--gp-surface)` | Admin top bar (a custom accent sets it to the accent in both schemes) |
| (CSS only) | `--gp-header-text` | `var(--gp-accent-contrast)` / `var(--gp-text)` | Admin top bar text |
| (CSS only) | `--gp-danger` | red-700 / `#b91c1c` | Delete buttons |
| (CSS only) | `--gp-card-shadow` | shadow / thin outline | Card shadow |
| (derived, v0.11.1) | `--gp-surface`, `--gp-input-bg`, `--gp-input-text`, `--gp-border`, `--gp-divider` | see above | With a custom background or text colour: derived from `--gp-bg` / `--gp-text` via `color-mix()` (surface 92 % bg + 8 % text, border 60 % / 40 %, input = bg / text, divider 15 % text), so cards stay readable in both schemes; custom CSS still overrides them |

The colour variables are written to the theme stylesheet only when set; otherwise the defaults of the
built-in stylesheet apply, so the look without branding is unchanged.

## 4. Custom CSS

Served as a separate stylesheet (`/branding/theme/<global|site id>.css?v=<hash>`, `text/css`) that is
loaded **after** the built-in stylesheet, so plain selectors win over the built-in (layered) Tailwind
styles without `!important`. Max. 64 KB.

Rules (checked on save, after removing comments and decoding CSS escapes):

- `@import` is not allowed.
- `url()`, `src()` and `image-set()` may only reference `data:` URLs or paths on the portal host
  (`/...` or relative). Absolute URLs (`https://...`) and protocol-relative URLs (`//host/...`) are
  rejected: guest devices are behind the hotspot walled garden (only the portal host is reachable),
  and guest pages must not leak visits to third parties.
- Strings must not contain URLs to other hosts either: after decoding escapes and line continuations
  and removing whitespace/control characters, a string (other than a `data:` URL) must not contain
  `//` or start with `http:`, `https:`, `ftp:`, `ws:`, `wss:` or `file:` (backslashes count as `/`).
- `expression()`, `behavior:`, `-moz-binding` and `javascript:` are rejected.

Broken custom CSS in the admin UI: open any admin page with `?plain=1` (e.g.
`/admin/settings?plain=1`); the theme stylesheet is skipped and the CSS can be fixed.

The page's `Content-Security-Policy` is not changed by branding: there is no inline CSS, the theme
stylesheet and files come from the same origin.

## 5. Stable hooks

`gp-*` classes and `--gp-*` variables are a **stable API**: they keep their meaning across updates
(changes only with a note in the release). Tailwind utility classes (`rounded`, `bg-slate-100`, ...)
and the HTML structure around them are **not** stable; do not target them.

### Body classes

| Class | Marks |
|---|---|
| `gp-page` | Every page with branding |
| `gp-theme-dark` / `gp-theme-light` | Colour scheme of the page (also on `<html>`, where the scheme defaults of the `--gp-*` variables are set, so `:root { ... }` in custom CSS overrides them) |
| `gp-guest` / `gp-admin` | Guest port pages / admin UI (incl. login and signed approval link page) |
| `gp-site-<slug>` | Guest page of a site (slug lower-case, other characters replaced by `-`), e.g. `gp-site-site-a` |
| `gp-step-<step>` | Guest step: `landing`, `portal` (login form), `sms` (SMS code entry), `approval` (waiting for approval), `handoff` (login to the router), `waiting` (router confirmation), `status` (online), `error`; admin: `approval-link` |
| `gp-step-error-<kind>` | Error page kind: `no_hotspot`, `misconfigured`, `expired`, `revoked`, `locked`, `router_failed`, `approval_denied`, `approval_expired`, `approval_busy`, `sms_busy`, `sms_failed` |

### Layout

| Class | Marks |
|---|---|
| `gp-container` | Guest page column (max. width, padding) |
| `gp-header` | Header (guest: logo, title, language switch; admin: top bar) |
| `gp-logo` | The logo (`img` for an upload, inline `svg` for the default) |
| `gp-logo-default` | Additionally on the built-in default logo |
| `gp-title` | Title text next to the logo ("Guest Wi-Fi"; admin: "Guest Portal" link) |
| `gp-lang-switch` | Language switcher (`nav`) |
| `gp-lang-link` | One language link; `gp-lang-active` on the current language |
| `gp-main` | Main content area |
| `gp-nav` | Admin navigation |
| `gp-user-menu` | Admin user name + logout |
| `gp-footer` | Admin footer (version) |

### Guest content

| Class | Marks |
|---|---|
| `gp-card` | Content card (`--gp-surface` box) |
| `gp-card-title` | Card heading (`h1`) |
| `gp-intro` | Introductory text below the heading |
| `gp-hint` | Secondary/help text |
| `gp-notice` | Warning box (previous router login failed; `gp-limited-mode`: federation limited mode, SMS login only) |
| `gp-error` | Validation error list |
| `gp-success` | Success message (SMS resent; heading "You are online") |
| `gp-remaining` | Remaining time line on the status page |
| `gp-form` | Login form |
| `gp-name-fields` | First/last name row |
| `gp-label` | Field label (wraps the input) |
| `gp-input` | Text input |
| `gp-code-input` | Access code / SMS code input (also `gp-input`) |
| `gp-checkbox` | Terms checkbox |
| `gp-terms` | Collapsible terms of use (`details`); `gp-terms-text` its text |
| `gp-terms-accept` | Label of the terms checkbox |
| `gp-button` | Every button and button-like link |
| `gp-button-secondary` | Secondary buttons (also `gp-button`): SMS request, approval request, resend |
| `gp-link` | Text link (e.g. "change number") |
| `gp-alt-login` | Alternative login block below the form; `gp-alt-sms` / `gp-alt-approval` |

The admin content pages (tables, forms) have no hooks beyond the layout; style them through
`.gp-admin` and the layout classes only.

## 6. Examples

Darker variant of the dark scheme (set background `#0b1120` and text `#e2e8f0` in the colour fields, then):

```css
:root { --gp-surface: #111827; }
.gp-guest .gp-card { box-shadow: none; border: 1px solid #1f2937; }
.gp-guest .gp-input { background: #0b1120; color: #e2e8f0; border-color: #374151; }
.gp-guest .gp-button-secondary { background: transparent; color: inherit; border-color: #374151; }
```

Rounded buttons and cards:

```css
.gp-button { border-radius: 9999px; }
.gp-card { border-radius: 1.25rem; }
```

Hide the title next to the logo (logo only), or the logo:

```css
.gp-guest .gp-title { display: none; }
.gp-guest .gp-logo { height: 3rem; }
/* .gp-logo { display: none; } */
```

Background image (embedded as `data:` URL; keep it small, every guest page loads it):

```css
.gp-guest {
    background-image: url("data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCA0MCA0MCI+PGNpcmNsZSBjeD0iMjAiIGN5PSIyMCIgcj0iMiIgZmlsbD0iI2NiZDVlMSIvPjwvc3ZnPg==");
    background-size: 40px 40px;
}
```

Only one site, only one step:

```css
.gp-site-site-a .gp-card-title { color: #15803d; }
.gp-step-status .gp-card { text-align: center; }
```

## 7. Limits and notes

| Item | Limit |
|---|---|
| Logo | 512 KB, 4096 × 4096 px, PNG/JPEG/WebP/SVG |
| Favicon | 100 KB, PNG/ICO/SVG |
| Custom CSS | 64 KB, rules in section 4 |

- Multiple instances (federation): colours, CSS and the file references are replicated with the
  settings and sites; the files themselves are stored per instance in `/data/branding`. An instance
  without a referenced file fetches it by its hash from the instance that set it (or the hub) and checks
  it like an upload (size, type, SVG rules, hash); until then it shows the built-in default (the settings
  page warns). Files not available yet are tried again every 5 minutes.
- The router's `login.html` only redirects to the portal (nothing visible), so it has no logo.
- Captive browsers (OS mini browsers) may cache aggressively and support less CSS; test changes on
  real devices.
- Restore: `branding/` is part of the backup archive and is extracted into `/data` with the rest.
