# Weaver ORM — Documentation Site

This directory contains the [Docusaurus v3](https://docusaurus.io/) documentation site for Weaver ORM.

## Running locally with Docker

From the **repository root**, run:

```bash
docker run --rm -v $(pwd):/app -w /app/website node:20-alpine sh -c "npm install && npm run start -- --host 0.0.0.0"
```

Then open [http://localhost:3000/weaver/](http://localhost:3000/weaver/) in your browser.

## Structure

```
website/
  docs/               # Markdown documentation pages
  src/
    pages/index.js    # Landing page
    css/custom.css    # Brand / theme overrides
  static/
    img/logo.svg      # Site logo
  docusaurus.config.js
  sidebars.js
  package.json
```

## Building for production

```bash
docker run --rm -v $(pwd):/app -w /app/website node:20-alpine sh -c "npm install && npm run build"
```

The static output is written to `website/build/`.

## Deployment

Pushing to `main` (with changes under `website/` or `docs/`) automatically triggers the
[Deploy Documentation](.github/workflows/deploy-docs.yml) GitHub Actions workflow, which
publishes the built site to GitHub Pages at `https://weaver-orm.github.io/weaver/`.
