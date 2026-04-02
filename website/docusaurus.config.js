// @ts-check

/** @type {import('@docusaurus/types').Config} */
const config = {
  title: 'Weaver ORM',
  tagline: 'PHP 8.4+ ORM for Symfony. Zero reflection, no proxies, no memory leaks.',
  favicon: 'img/logo.svg',

  url: 'https://unapartidamas.github.io',
  baseUrl: '/weaverORM/',

  organizationName: 'Unapartidamas',
  projectName: 'weaverORM',

  onBrokenLinks: 'warn',
  onBrokenMarkdownLinks: 'warn',

  i18n: {
    defaultLocale: 'en',
    locales: ['en', 'es', 'zh-Hans', 'hi', 'ar', 'pt', 'fr', 'ru', 'ja', 'de'],
  },

  presets: [
    [
      'classic',
      /** @type {import('@docusaurus/preset-classic').Options} */
      ({
        docs: {
          sidebarPath: require.resolve('./sidebars.js'),
          editUrl: 'https://github.com/Unapartidamas/weaverORM/tree/main/website/',
        },
        blog: false,
        theme: {
          customCss: require.resolve('./src/css/custom.css'),
        },
      }),
    ],
  ],

  themeConfig:
    /** @type {import('@docusaurus/preset-classic').ThemeConfig} */
    ({
      image: 'img/logo.svg',
      navbar: {
        title: 'Weaver ORM',
        logo: {
          alt: 'Weaver ORM Logo',
          src: 'img/logo.svg',
        },
        items: [
          {
            type: 'docSidebar',
            sidebarId: 'tutorialSidebar',
            position: 'left',
            label: 'Docs',
          },
          {
            to: '/docs/pyrosql/overview',
            label: 'PyroSQL',
            position: 'left',
          },
          {
            href: 'https://github.com/Unapartidamas/weaverORM',
            label: 'GitHub',
            position: 'right',
          },
          {
            type: 'localeDropdown',
            position: 'right',
          },
        ],
      },
      footer: {
        style: 'dark',
        links: [
          {
            title: 'Docs',
            items: [
              {
                label: 'Getting Started',
                to: '/docs/intro',
              },
              {
                label: 'Entity Mapping',
                to: '/docs/entity-mapping',
              },
              {
                label: 'PyroSQL',
                to: '/docs/pyrosql/overview',
              },
            ],
          },
          {
            title: 'Community',
            items: [
              {
                label: 'GitHub Discussions',
                href: 'https://github.com/Unapartidamas/weaverORM/discussions',
              },
              {
                label: 'GitHub Issues',
                href: 'https://github.com/Unapartidamas/weaverORM/issues',
              },
            ],
          },
          {
            title: 'More',
            items: [
              {
                label: 'GitHub',
                href: 'https://github.com/Unapartidamas/weaverORM',
              },
              {
                label: 'Packagist',
                href: 'https://packagist.org/packages/Unapartidamas/weaverORM',
              },
            ],
          },
        ],
        copyright: `Copyright © ${new Date().getFullYear()} Weaver ORM. Built with Docusaurus.`,
      },
      prism: {
        theme: require('prism-react-renderer').themes.github,
        darkTheme: require('prism-react-renderer').themes.dracula,
        additionalLanguages: ['php', 'bash', 'json', 'yaml'],
      },
      // Algolia search — fill in your own appId, apiKey, and indexName to enable
      // algolia: {
      //   appId: 'YOUR_APP_ID',
      //   apiKey: 'YOUR_SEARCH_API_KEY',
      //   indexName: 'weaver-orm',
      //   contextualSearch: true,
      //   searchParameters: {},
      //   searchPagePath: 'search',
      // },
    }),
};

module.exports = config;
