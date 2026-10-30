import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs';
import { dirname, join, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { type DefaultTheme, defineConfig } from 'vitepress';

// The documentation site (ADR-0029): docs/ as GitHub Pages, at hanzala-afzal-dev.github.io/laravel-dynamic-throttle.

const repository = 'https://github.com/hanzala-afzal-dev/laravel-dynamic-throttle';
const base = '/laravel-dynamic-throttle/';
const description =
    'Rate limits you change while your Laravel app runs: per IP, user, API token, header or tenant, with dry-run, exemptions, emergency switches and a dashboard.';

/** docs/, and the repository's root. */
const docs = fileURLToPath(new URL('..', import.meta.url));
const root = resolve(docs, '..');

/**
 * What stays on GitHub only: the ADRs, the specs and the roadmap are for contributors.
 * The site is for the package's users.
 */
const githubOnly = ['adr/', 'specs/', 'roadmap.md'];

/** Pages whose source has another path: the site's own pages, and each folder's README as its index. */
const rewrites: Record<string, string> = {
    'site/index.md': 'index.md',
    'site/getting-started.md': 'getting-started.md',
    'site/changelog.md': 'changelog.md',
    'guide/README.md': 'guide/index.md',
};

/** Files outside docs/ whose text a page shows, by the page. */
const shownOn: Record<string, string> = {
    'README.md': 'getting-started.md',
    'CHANGELOG.md': 'changelog.md',
};

const slash = (path: string): string => path.split(sep).join('/');

function isPage(fromDocs: string): boolean {
    return (
        fromDocs.endsWith('.md') &&
        !fromDocs.startsWith('../') &&
        !fromDocs.startsWith('.vitepress/') &&
        !fromDocs.startsWith('node_modules/') &&
        !githubOnly.some((path) => (path.endsWith('/') ? fromDocs.startsWith(path) : fromDocs === path))
    );
}

/**
 * Where a relative link of the docs goes on the site. The docs link as GitHub reads them,
 * relative to their file, so they work there too: a link to a page of the site stays on
 * the site, and one to anything else, such as a test, the config file or a spec, opens it
 * on GitHub. Text a page includes from the README or the changelog links from the
 * repository's root, so a link that does not resolve from the page tries the root.
 */
function siteHref(href: string, file: string): string {
    if (/^[a-z][a-z\d+.-]*:/i.test(href) || href.startsWith('#') || href.startsWith('/')) {
        return href;
    }

    const hashAt = href.indexOf('#');
    const path = decodeURI(hashAt < 0 ? href : href.slice(0, hashAt));
    const hash = hashAt < 0 ? '' : href.slice(hashAt);
    const target = [resolve(dirname(file), path), resolve(root, path)].find((candidate) => existsSync(candidate));

    if (target === undefined) {
        throw new Error(`${slash(relative(root, file))} links to ${href}, which does not exist.`);
    }

    const fromRoot = slash(relative(root, target));
    const fromDocs = slash(relative(docs, target));
    const page = shownOn[fromRoot] ?? (isPage(fromDocs) ? (rewrites[fromDocs] ?? fromDocs) : undefined);

    return page === undefined
        ? `${repository}/${statSync(target).isDirectory() ? 'tree' : 'blob'}/main/${fromRoot}${hash}`
        : `/${page}${hash}`;
}

/**
 * GitHub's heading ids (github-slugger), so the docs' anchors work on GitHub and on the
 * site alike: lowercase, without punctuation, and spaces as hyphens.
 */
const githubSlug = (heading: string): string =>
    heading
        .toLowerCase()
        .replace(/[^\p{L}\p{M}\p{N}\p{Pc} -]/gu, '')
        .replace(/ /g, '-');

/** A title from the docs' Markdown, as sidebar HTML: code spans as code. */
const inlineHtml = (text: string): string =>
    text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/`([^`]+)`/g, '<code>$1</code>');

function fail(message: string): never {
    throw new Error(message);
}

/** The guide's pages, in the order its index lists them. */
function guidePages(): DefaultTheme.SidebarItem[] {
    const index = readFileSync(join(docs, 'guide/README.md'), 'utf8');
    const list = index.split('\n## Pages\n')[1]?.split('\n## ')[0] ?? '';
    const pages = [...list.matchAll(/^- \[([^\]]+)\]\(([\w-]+)\.md\)/gm)].map(([, text, file]) => ({
        text: inlineHtml(text),
        link: `/guide/${file}`,
    }));

    return pages.length > 0 ? pages : fail('docs/guide/README.md lists no pages under "## Pages".');
}

/**
 * Fails the build on a link between pages of the site to a page or a heading that does
 * not exist, so a renamed heading cannot break a link quietly. VitePress checks the pages only.
 */
function checkLinks(outDir: string): void {
    const pages = new Map<string, string>();
    const ids = new Map<string, Set<string>>();
    const decode = (html: string): string => html.replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');

    const walk = (directory: string): void => {
        for (const entry of readdirSync(directory, { withFileTypes: true })) {
            const path = join(directory, entry.name);

            if (entry.isDirectory()) {
                walk(path);
            } else if (entry.name.endsWith('.html')) {
                pages.set(slash(relative(outDir, path)), readFileSync(path, 'utf8'));
            }
        }
    };
    walk(outDir);

    const idsOf = (page: string): Set<string> => {
        if (!ids.has(page)) {
            ids.set(page, new Set([...(pages.get(page) ?? '').matchAll(/\sid="([^"]+)"/g)].map(([, id]) => decode(id))));
        }

        return ids.get(page) ?? new Set();
    };

    const broken: string[] = [];

    for (const [page, html] of pages) {
        for (const [, attribute] of html.matchAll(/<a\s[^>]*?href="([^"]*)"/g)) {
            const href = decode(attribute);

            if (/^[a-z][a-z\d+.-]*:/i.test(href) || href.startsWith('//')) {
                continue;
            }

            const hashAt = href.indexOf('#');
            const path = hashAt < 0 ? href : href.slice(0, hashAt);
            const id = hashAt < 0 ? '' : decodeURIComponent(href.slice(hashAt + 1));
            let target = page;

            if (path !== '') {
                if (!path.startsWith(base)) {
                    broken.push(`${page}: ${href} is not under ${base}`);
                    continue;
                }

                const name = decodeURI(path.slice(base.length));
                target = name === '' || name.endsWith('/') ? `${name}index.html` : name.endsWith('.html') ? name : `${name}.html`;
            }

            if (!pages.has(target)) {
                broken.push(`${page}: ${href} is no page`);
            } else if (id !== '' && !idsOf(target).has(id)) {
                broken.push(`${page}: ${href} has no heading #${id}`);
            }
        }
    }

    if (broken.length > 0) {
        throw new Error(`Broken links on the site:\n${[...new Set(broken)].join('\n')}`);
    }
}

export default defineConfig({
    title: 'Dynamic Throttle',
    description,
    lang: 'en-US',
    base,
    cleanUrls: true,
    srcExclude: githubOnly.map((path) => (path.endsWith('/') ? `${path}**` : path)),
    rewrites,

    head: [
        ['meta', { property: 'og:type', content: 'website' }],
        ['meta', { property: 'og:title', content: 'Dynamic Throttle for Laravel' }],
        ['meta', { property: 'og:description', content: description }],
        ['meta', { property: 'og:image', content: `${repository.replace('github.com', 'raw.githubusercontent.com')}/main/art/social-preview.png` }],
        ['meta', { name: 'twitter:card', content: 'summary_large_image' }],
    ],

    markdown: {
        anchor: { slugify: githubSlug },
        config(md) {
            // After VitePress's own link handling is set up, so this runs first and VitePress then adds the base.
            const render = md.renderer.rules.link_open ?? ((tokens, idx, options, _env, self) => self.renderToken(tokens, idx, options));

            md.renderer.rules.link_open = (tokens, idx, options, env, self) => {
                const href = tokens[idx].attrGet('href');

                if (href !== null) {
                    tokens[idx].attrSet('href', siteHref(href, env.realPath ?? env.path));
                }

                return render(tokens, idx, options, env, self);
            };

            // The changelog keeps an "Unreleased" section on top (Keep a Changelog). Right after a
            // release it is empty, which on the site reads as a mistake, so the site leaves it out
            // until a change waits for the next release. Before the anchors and the outline read
            // the headings, so neither lists it.
            md.core.ruler.after('inline', 'empty-unreleased', (state) => {
                const tokens = state.tokens;

                for (let i = 0; i + 2 < tokens.length; i++) {
                    const unreleased =
                        tokens[i].type === 'heading_open' && tokens[i].tag === 'h2' && tokens[i + 1].content.replace(/[[\]]/g, '').trim() === 'Unreleased';
                    const next = tokens[i + 3];

                    if (unreleased && (next === undefined || (next.type === 'heading_open' && next.tag === 'h2'))) {
                        tokens.splice(i, 3);

                        return;
                    }
                }
            });
        },
    },

    // The home page shows the README's images, from art/ at the repository's root. Pages
    // import them as @art/…: a relative path breaks in the dev server on a page that
    // `rewrites` moves, where it resolves from the page's new place.
    vite: {
        resolve: { alias: { '@art': join(root, 'art') } },
        server: { fs: { allow: [root] } },
    },

    themeConfig: {
        nav: [
            { text: 'Getting started', link: '/getting-started' },
            { text: 'Guide', link: '/guide/', activeMatch: '^/guide/' },
            { text: 'Runbook', link: '/runbook' },
            { text: 'Changelog', link: '/changelog' },
        ],

        sidebar: [
            {
                text: 'Introduction',
                items: [
                    { text: 'Getting started', link: '/getting-started' },
                    { text: 'How it works', link: '/guide/' },
                ],
            },
            { text: 'Guide', items: guidePages() },
            { text: 'Operations', items: [{ text: 'Runbook', link: '/runbook' }] },
            { text: 'Reference', items: [{ text: 'Changelog', link: '/changelog' }] },
        ],

        outline: { level: [2, 3] },
        search: { provider: 'local' },
        socialLinks: [{ icon: 'github', link: repository }],
        footer: {
            message: 'Released under the MIT License. An independent package, not affiliated with or endorsed by Laravel.',
            copyright: '© 2026 Hanzala Afzal',
        },
    },

    buildEnd: (site) => checkLinks(site.outDir),
});
