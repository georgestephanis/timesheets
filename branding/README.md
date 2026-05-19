# timesheets — Brand Icon

The `{ time }` mark for **timesheets**, an open-source app for tracking
hours on client work. The icon reads time as a code literal — two braces
flanking a clock face — signaling _developer tool_ at a glance while
remaining warm enough for a desktop dock.

---

## Files

```
package/
├── svg/
│   ├── timesheets-icon-dark.svg         # full tile, dark colorway
│   ├── timesheets-icon-dark-glyph.svg   # glyph only, transparent
│   ├── timesheets-icon-light.svg        # full tile, light colorway
│   └── timesheets-icon-light-glyph.svg  # glyph only, transparent
├── png/
│   ├── dark/   icon-{16,32,48,64,128,256,512,1024}.png
│   │           glyph-{128,256,512,1024}.png   (transparent)
│   └── light/  icon-{16,32,48,64,128,256,512,1024}.png
│               glyph-{128,256,512,1024}.png   (transparent)
└── favicon/
    ├── favicon-dark-16.png      favicon-light-16.png
    ├── favicon-dark-32.png      favicon-light-32.png
    └── favicon-dark-180.png     favicon-light-180.png   # Apple touch
```

Source of truth is **SVG**. Rasters are rendered from it; regenerate them
if you change the vector.

---

## Concept

The two metaphors layered into the mark:

1. **`{ }` braces** — code blocks. Names the audience without a word.
   The icon belongs in a developer's dock alongside their terminal.
2. **Clock face** — what the app is _for._ The hands sit at 10-past so
   the dial reads as a friendly, complete shape rather than a brand-new
   stopwatch waiting to start.

The braces and the clock-face stroke are weighted equally (≈ 42–58 px on
the 1024 canvas) so neither metaphor dominates at small sizes — at
16 px the clock collapses to a circular accent dot, which is intentional.

## Construction

| Property       | Value                                       |
| -------------- | ------------------------------------------- |
| Canvas         | 1024 × 1024 px                              |
| Tile shape     | Rounded square, `rx = 228` (macOS squircle) |
| Brace stroke   | 58 px, round caps + joins                   |
| Clock outer    | r = 118 px, stroke 42 px                    |
| Clock hands    | 28 × 92 (minute) and 92 × 28 (hour), rx 14  |
| Optical center | Glyph centered at (512, 512)                |
| Safe area      | Keep all glyph elements within a 768 px box |

Live size in product:

- **Desktop dock / launcher** — 1024 export, OS handles masking
- **Browser favicon** — 16 / 32 png + svg
- **Apple touch icon** — 180 png
- **README hero** — 108–160 px svg
- **CLI banner / terminal title** — 16 / 32 png

---

## Colorways

### Dark (default — for light surfaces, dock, web app)

|            | Token   | Hex       | Role                       |
| ---------- | ------- | --------- | -------------------------- |
| Background | `ink`   | `#16130F` | Tile fill                  |
| Glyph      | `paper` | `#F6F2EA` | Braces, clock outline ring |
| Accent     | `amber` | `#F6B84A` | Clock face stroke + hands  |

```css
--ts-ink: #16130f;
--ts-paper: #f6f2ea;
--ts-amber: #f6b84a;
```

### Light (for dark surfaces, dark-mode docs, print)

|            | Token        | Hex       | Role                       |
| ---------- | ------------ | --------- | -------------------------- |
| Background | `paper`      | `#F6F2EA` | Tile fill                  |
| Glyph      | `ink`        | `#16130F` | Braces, clock outline ring |
| Accent     | `terracotta` | `#C25E2A` | Clock face stroke + hands  |

```css
--ts-paper: #f6f2ea;
--ts-ink: #16130f;
--ts-terracotta: #c25e2a;
```

> The accent shifts from **amber** on dark to **terracotta** on light to
> keep contrast against the paper background. Both sit in the same warm
> family so the brand still feels singular across the two modes.

### Contrast

| Pair                       | Ratio  | WCAG |
| -------------------------- | ------ | ---- |
| paper on ink (dark glyph)  | 16.9:1 | AAA  |
| ink on paper (light glyph) | 16.9:1 | AAA  |
| amber on ink               | 9.4:1  | AAA  |
| terracotta on paper        | 4.8:1  | AA   |

---

## Usage

### HTML — favicon

```html
<link rel="icon" href="/package/svg/timesheets-icon-dark.svg" />
<link rel="icon" sizes="32x32" href="/package/favicon/favicon-dark-32.png" />
<link rel="icon" sizes="16x16" href="/package/favicon/favicon-dark-16.png" />
<link rel="apple-touch-icon" href="/package/favicon/favicon-dark-180.png" />
```

To swap the colorway with the user's system theme:

```html
<link rel="icon" href="/package/svg/timesheets-icon-light.svg" media="(prefers-color-scheme: dark)" />
<link rel="icon" href="/package/svg/timesheets-icon-dark.svg" media="(prefers-color-scheme: light)" />
```

### Markdown — README hero

```md
<p align="center">
  <img src="package/svg/timesheets-icon-dark.svg#gh-light-mode-only"
       width="128" height="128" alt="timesheets" />
  <img src="package/svg/timesheets-icon-light.svg#gh-dark-mode-only"
       width="128" height="128" alt="timesheets" />
</p>
<h1 align="center">timesheets</h1>
```

### Wordmark lockup

The icon pairs with **JetBrains Mono Bold (700), lowercase** for the
wordmark. Spec:

- Cap-height of the wordmark = **60%** of icon height
- Gap between icon and wordmark = **18%** of icon height
- Vertical centering: optical, on the lowercase x-height

---

## Don'ts

- **Don't** recolor the braces independently of the clock face — they're
  one mark.
- **Don't** rotate the clock hands to other positions. 10:10 reads as
  "running"; 12:00 reads as "stopped"; arbitrary angles look broken.
- **Don't** add a stroke around the squircle tile — the OS draws shadow
  and edge treatment.
- **Don't** stretch, skew, or non-uniformly scale.
- **Don't** drop the squircle on backgrounds at sizes below 32 px; use
  the glyph-only SVG so the braces stay legible.

---

## License

This icon ships under the same license as the parent project (MIT). You
may use, modify, and redistribute it as part of timesheets or forks
thereof. For unrelated projects, please design your own mark — but feel
free to lift the construction conventions.
