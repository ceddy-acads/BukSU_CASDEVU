# Vendor libraries

Third-party browser code, self-hosted so the site needs no CDN. Copied
byte-for-byte from the npm registry tarballs; nothing was built, patched or
run from the packages. `.gitattributes` marks this folder `-text` so git never
rewrites line endings in these files.

| Folder | Package | Version | Files | Licence |
|---|---|---|---|---|
| `pdfjs/` | [pdfjs-dist](https://www.npmjs.com/package/pdfjs-dist) (Mozilla PDF.js) | 6.4.299 | `legacy/build/pdf.min.mjs`, `legacy/build/pdf.worker.min.mjs` | Apache-2.0, `pdfjs/LICENSE` |
| `page-flip/` | [page-flip](https://www.npmjs.com/package/page-flip) (StPageFlip, Nodlik) | 2.0.7 | `dist/js/page-flip.browser.js` | MIT, `page-flip/LICENSE` |

Sources:

- https://registry.npmjs.org/pdfjs-dist/-/pdfjs-dist-6.4.299.tgz
  (sha256 `86269b40170eb41740ea05ad2102d71d33de4f122b34eb2f478be0ea7779c415`)
- https://registry.npmjs.org/page-flip/-/page-flip-2.0.7.tgz
  (sha256 `871f33a759f927f83e92556beeebfe264aa78c2ccb2686092c1d43a19f5a0cf2`)

Both licence files come from their packages unchanged.

PDF.js is the **legacy** build, which supports older phone browsers. Only the
library and its worker are shipped: no CMaps, standard fonts or WASM decoders,
so a PDF that relies on a non-embedded CJK font or a JPEG 2000 image may draw
imperfectly. Reports exported from Canva, Word or PowerPoint embed their fonts
and are unaffected.

To upgrade: download the new tarball into an empty folder outside the repo,
extract it with `tar`, copy the same files over these, and update this table.
