import React from 'react';
import ComponentCreator from '@docusaurus/ComponentCreator';

export default [
  {
    path: '/weaver/de/docs',
    component: ComponentCreator('/weaver/de/docs', '6be'),
    routes: [
      {
        path: '/weaver/de/docs',
        component: ComponentCreator('/weaver/de/docs', '798'),
        routes: [
          {
            path: '/weaver/de/docs',
            component: ComponentCreator('/weaver/de/docs', '533'),
            routes: [
              {
                path: '/weaver/de/docs/',
                component: ComponentCreator('/weaver/de/docs/', 'f94'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/weaver/de/docs/collections',
                component: ComponentCreator('/weaver/de/docs/collections', 'bd5'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/weaver/de/docs/concerns',
                component: ComponentCreator('/weaver/de/docs/concerns', '2ae'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/weaver/de/docs/configuration',
                component: ComponentCreator('/weaver/de/docs/configuration', '90f'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/weaver/de/docs/doctrine-bridge',
                component: ComponentCreator('/weaver/de/docs/doctrine-bridge', '673'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/weaver/de/docs/embeddable',
                component: ComponentCreator('/weaver/de/docs/embeddable', '4ee'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/weaver/de/docs/entity-mapping',
                component: ComponentCreator('/weaver/de/docs/entity-mapping', '0dc'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/weaver/de/docs/inheritance',
                component: ComponentCreator('/weaver/de/docs/inheritance', '4d9'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/weaver/de/docs/installation',
                component: ComponentCreator('/weaver/de/docs/installation', 'df3'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/weaver/de/docs/lifecycle',
                component: ComponentCreator('/weaver/de/docs/lifecycle', '750'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/weaver/de/docs/mongodb',
                component: ComponentCreator('/weaver/de/docs/mongodb', 'c13'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/weaver/de/docs/pagination',
                component: ComponentCreator('/weaver/de/docs/pagination', '484'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/weaver/de/docs/querying',
                component: ComponentCreator('/weaver/de/docs/querying', '6ff'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/weaver/de/docs/quick-start',
                component: ComponentCreator('/weaver/de/docs/quick-start', '6f7'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/weaver/de/docs/relations',
                component: ComponentCreator('/weaver/de/docs/relations', '330'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/weaver/de/docs/repositories',
                component: ComponentCreator('/weaver/de/docs/repositories', 'e48'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/weaver/de/docs/schema-migrations',
                component: ComponentCreator('/weaver/de/docs/schema-migrations', '41a'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/weaver/de/docs/testing',
                component: ComponentCreator('/weaver/de/docs/testing', 'a7b'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/weaver/de/docs/transactions',
                component: ComponentCreator('/weaver/de/docs/transactions', '25b'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/weaver/de/docs/valkarnsql/approximate',
                component: ComponentCreator('/weaver/de/docs/valkarnsql/approximate', '5df'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/weaver/de/docs/valkarnsql/branches',
                component: ComponentCreator('/weaver/de/docs/valkarnsql/branches', '297'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/weaver/de/docs/valkarnsql/cdc',
                component: ComponentCreator('/weaver/de/docs/valkarnsql/cdc', '56c'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/weaver/de/docs/valkarnsql/overview',
                component: ComponentCreator('/weaver/de/docs/valkarnsql/overview', '24d'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/weaver/de/docs/valkarnsql/time-travel',
                component: ComponentCreator('/weaver/de/docs/valkarnsql/time-travel', '798'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/weaver/de/docs/valkarnsql/vectors',
                component: ComponentCreator('/weaver/de/docs/valkarnsql/vectors', '8a6'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/weaver/de/docs/valkarnsql/wasm-udfs',
                component: ComponentCreator('/weaver/de/docs/valkarnsql/wasm-udfs', '957'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/weaver/de/docs/workspace',
                component: ComponentCreator('/weaver/de/docs/workspace', 'c1b'),
                exact: true,
                sidebar: "tutorialSidebar"
              }
            ]
          }
        ]
      }
    ]
  },
  {
    path: '/weaver/de/',
    component: ComponentCreator('/weaver/de/', '330'),
    exact: true
  },
  {
    path: '*',
    component: ComponentCreator('*'),
  },
];
