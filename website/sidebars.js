// @ts-check

/** @type {import('@docusaurus/plugin-content-docs').SidebarsConfig} */
const sidebars = {
  tutorialSidebar: [
    {
      type: 'category',
      label: 'Getting Started',
      collapsed: false,
      items: [
        'intro',
        'installation',
        'quick-start',
        'configuration',
      ],
    },
    {
      type: 'category',
      label: 'Entity Mapping',
      items: [
        'entity-mapping',
        'relations',
        'embeddable',
        'inheritance',
        'concerns',
      ],
    },
    {
      type: 'category',
      label: 'Entity Workspace',
      items: [
        'workspace',
        'repositories',
        'querying',
        'lifecycle',
        'transactions',
      ],
    },
    {
      type: 'category',
      label: 'Schema & Migrations',
      items: [
        'schema-migrations',
      ],
    },
    {
      type: 'category',
      label: 'Advanced',
      items: [
        'collections',
        'pagination',
        'testing',
        'doctrine-bridge',
        'mongodb',
      ],
    },
    {
      type: 'category',
      label: 'PyroSQL',
      items: [
        'pyrosql/overview',
        'pyrosql/time-travel',
        'pyrosql/branches',
        'pyrosql/cdc',
        'pyrosql/approximate',
        'pyrosql/vectors',
        'pyrosql/wasm-udfs',
      ],
    },
  ],
};

module.exports = sidebars;
