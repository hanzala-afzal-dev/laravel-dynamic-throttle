# ADR-0029: A documentation site on GitHub Pages, built from docs/

- Status: Accepted
- Date: 2026-10-25
- Roadmap phase: 6

## Context

- The docs are Markdown in `docs/`: the guide, the runbook and the ADRs. People read them on GitHub, which has no search across pages, no navigation between them, and no home page but the README.
- The README and the guide are written to read well on GitHub, with links relative to each file. They must keep working there: most people find the package on GitHub or Packagist.
- The repository is public since 2026-10-18, so GitHub Pages and its Actions minutes are free.

## Decision

1. **VitePress builds `docs/` into a static site, and GitHub Pages serves it** at <https://hanzala-afzal-dev.github.io/laravel-dynamic-throttle/>. `.github/workflows/docs.yml` builds it for every pull request that changes the docs, and deploys it from `main`.
2. **The Markdown stays the source, as GitHub reads it.** The site shows the guide, the runbook and the ADRs as they are. The specs and the roadmap stay on GitHub only: they are for contributors.
   - Links keep their GitHub form. The site sends a link to one of its pages to that page, and a link to anything else, such as a test, the config file or a spec, to the file on GitHub.
   - Headings get GitHub's ids, so the docs' anchors work in both places.
3. **No text is copied.** The home page and Getting started include sections of `README.md`, marked with `<!-- #region -->` comments, which GitHub does not show; the changelog page includes `CHANGELOG.md`. The site's own pages are in `docs/site/`, and the sidebar is read from the guide's and the ADRs' indexes.
4. **Its toolchain is apart from the dashboard's** ([ADR-0028](0028-dashboard-react-shadcn.md)): `docs/package.json`, with VitePress pinned to an exact version and its own lock file. Nothing of it ships: `docs/` is export-ignored.

## Reasoning

- VitePress needs only Markdown and Node, which the project already uses for the dashboard. It brings search, navigation, dark mode and good mobile layouts without a theme to maintain.
- Keeping the Markdown as the source means one set of docs, which reviewers already read in pull requests, and nothing to keep in step.
- The build fails on a link to a page or a heading that does not exist, so the site also checks the docs' links on every change.

## Alternatives considered

- **GitHub's built-in Jekyll build:** no build step to own, but relative links between Markdown files, search and navigation need plugins and a theme, and GitHub's Jekyll offers only a fixed set of them.
- **MkDocs with Material:** an excellent site, but a Python toolchain next to PHP and Node.
- **Copying the docs into a site folder:** free to restructure, but two copies drift apart.

## Fallback

- If VitePress becomes a burden, the Markdown moves as it is to another static generator that reads relative links; only `docs/.vitepress/` and `docs/site/` change.
- Revisit when the docs need versions per release, such as after 1.0, or a custom domain.

## Consequences

- Good: a searchable site with a home page, built from the docs as they are, and a check of every link between pages on each docs change.
- Good: GitHub keeps showing the same Markdown, unchanged except for the README's invisible region comments.
- Bad: a second `package.json` and lock file to keep current.
- Bad: the site shows `main`, which may document what is not yet released. The changelog's **Unreleased** section says what is new.

## Verification

- The Docs workflow builds the site for every pull request that changes `docs/`, the README, the changelog or `art/`. The build fails on a broken link between pages, on a link to a missing file, and on a link to a heading the target page lacks.
