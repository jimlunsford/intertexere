# Bundled third-party notices

Intertexere itself remains licensed under `AGPL-3.0-or-later`; the complete GNU Affero General Public License version 3 text is in [LICENSE](LICENSE).

## WordPress link icon

The compiled editor bundle, `build/editor/index.js`, incorporates the `link` icon from **`@wordpress/icons@15.5.0`**, imported by `src/editor/index.js` and used for the Intertexere editor sidebar. Its icon source is used without edits; the normal production build bundles and minifies its generated JavaScript.

The exact published package supplies these attributions:

- `package.json` author: **The WordPress Contributors**.
- `package.json` SPDX license declaration: **`GPL-2.0-or-later`**.
- `LICENSE.md` Gutenberg copyright notice, verbatim: **Copyright 2016-2026 by the contributors**.
- Upstream repository: [WordPress/gutenberg](https://github.com/WordPress/gutenberg), package directory `packages/icons`.

The complete, unmodified `LICENSE.md` from the 15.5.0 npm artifact is preserved in [LICENSES/wordpress-icons-15.5.0.LICENSE.md](LICENSES/wordpress-icons-15.5.0.LICENSE.md), including its project/contribution licensing explanation, copyright notices, warranty disclaimers, and referenced license texts. Its discussion of MPL contributions does not change the package's declared `GPL-2.0-or-later` project license used here. These upstream attributions are not claims that Jim Lunsford authored the WordPress component.

## GPLv3 and AGPLv3 combined distribution

For distribution with Intertexere, the WordPress component's GPL "or later" permission is exercised under **GNU GPL version 3**. The complete, unmodified [GNU GPLv3 text](https://www.gnu.org/licenses/gpl-3.0.txt) is supplied separately in [LICENSES/GPL-3.0.txt](LICENSES/GPL-3.0.txt).

Section 13 of GPLv3 and section 13 of AGPLv3 permit linking or combining their covered works and conveying the resulting combination. The WordPress portion remains governed by GPLv3, and Intertexere's portion remains governed by AGPLv3. AGPLv3's section 13 network-interaction requirements apply to the combination, including corresponding source for the incorporated GPLv3 work where required. This does not relicense the WordPress component as AGPL or remove its original `GPL-2.0-or-later` provenance and permissions.

## Exact corresponding source and rebuilding

The committed [package-lock.json](package-lock.json), entry `packages["node_modules/@wordpress/icons"]`, is the dependency lock authority. It pins version **15.5.0**, this version-specific npm artifact, and its content integrity:

- Artifact: [icons-15.5.0.tgz](https://registry.npmjs.org/@wordpress/icons/-/icons-15.5.0.tgz).
- SHA-512 integrity: `sha512-ES/PmxhDyBvx5Z+SqMXJCO1hLJ6XLs4L6FZNQLfqhFDn/xppjkFtFw6B0EnfumpdcZX/tSYDU3PfcicFilIGKQ==`.

Use the Intertexere source checkout and lockfile corresponding to the distributed bundle. With **Node.js 22.13.0** and npm satisfying [package.json](package.json), run from the repository root:

```sh
npm ci
npm run build
```

`npm ci` retrieves the locked dependencies, including the exact icon artifact, and verifies their recorded integrity. Do not omit development dependencies: the editor build tools and icon package are declared there. `npm run build` runs the committed command `wp-scripts build src/editor/index.js --output-path=build/editor` with locked `@wordpress/scripts@34.2.0` and produces `build/editor/index.js` and its companion assets.

The installed package contains the preferred editable SVG source, the generated TSX component, and the published module used by the Intertexere build:

| Role | Path from the Intertexere repository root |
| --- | --- |
| Preferred icon source | `node_modules/@wordpress/icons/src/library/link.svg` |
| Icon manifest entry (`slug: link`, `filePath: library/link.svg`) | `node_modules/@wordpress/icons/src/manifest.json` |
| Generated JSX/TypeScript component | `node_modules/@wordpress/icons/src/library/link.tsx` |
| Source exports | `node_modules/@wordpress/icons/src/index.ts` and `node_modules/@wordpress/icons/src/library/index.ts` |
| Published module incorporated by the build | `node_modules/@wordpress/icons/build-module/library/link.mjs` |
| Published source map, including the TSX source | `node_modules/@wordpress/icons/build-module/library/link.mjs.map` |
| Intertexere import and sidebar use | `src/editor/index.js`, `import { link } from '@wordpress/icons';` |

The package's `exports.import` resolves to `build-module/index.mjs`, which re-exports `build-module/library/index.mjs`; that file exports `link` from `build-module/library/link.mjs`. The locked WordPress build tooling explicitly bundles `@wordpress/icons`. Webpack includes the referenced icon module, removes unused exports, and minifies the result into Intertexere's editor bundle. Its `@wordpress/primitives` and React JSX runtime imports are external WordPress-provided runtime dependencies.

The package README identifies SVG files as the source used to generate the TSX components. The npm artifact includes those source forms and the compiled module, but does not ship the upstream `lib/generate-library.cjs` or `lib/generate-manifest-php.cjs` scripts mentioned by its own package build command. Rebuilding Intertexere uses the already-published module through the root commands above; it does not run the icon package's separate generation command. The versioned tarball and locked integrity identify the exact supplied source independently of changes to upstream repository HEAD.

When distributing the compiled bundle, retain these notices, the accompanying license files, and access to the matching Intertexere source and locked component source described here.
