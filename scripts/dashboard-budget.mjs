// The dashboard's bundle budget (ADR-0028 point 5): the JavaScript the first page
// loads stays under 300 KB gzipped: the entry, the Overview, which it loads on demand
// as the first page (SPEC-021 decision 6), and the chunks they import statically.
// The other pages are not counted. Run after `npm run build`.
import { readFileSync } from 'node:fs';
import { gzipSync } from 'node:zlib';

const BUDGET = 300 * 1024;
const FIRST_PAGE = 'pages/overview/index.tsx';
const manifest = JSON.parse(readFileSync(new URL('../dist/.vite/manifest.json', import.meta.url), 'utf8'));
const entry = Object.keys(manifest).find((key) => manifest[key].isEntry);

if (entry === undefined) {
    console.error('dist/.vite/manifest.json has no entry: run npm run build.');
    process.exit(1);
}

if (manifest[FIRST_PAGE] === undefined) {
    console.error(`dist/.vite/manifest.json has no ${FIRST_PAGE}: the budget cannot count the first page.`);
    process.exit(1);
}

const seen = new Set();
const visit = (key) => {
    if (seen.has(key)) {
        return;
    }

    seen.add(key);
    (manifest[key].imports ?? []).forEach(visit);
};
visit(entry);
visit(FIRST_PAGE);

let total = 0;

for (const key of seen) {
    const file = manifest[key].file;
    const size = gzipSync(readFileSync(new URL(`../dist/${file}`, import.meta.url))).length;
    total += size;
    console.log(`${file}: ${(size / 1024).toFixed(1)} KB gzipped`);
}

console.log(`First page: ${(total / 1024).toFixed(1)} KB gzipped, budget ${BUDGET / 1024} KB.`);

if (total > BUDGET) {
    console.error('The first page\'s JavaScript is over budget (ADR-0028 point 5): load more of it on demand.');
    process.exit(1);
}
