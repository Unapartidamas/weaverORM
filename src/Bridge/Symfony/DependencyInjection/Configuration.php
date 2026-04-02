<?php

declare(strict_types=1);

namespace Weaver\ORM\Bridge\Symfony\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('weaver');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->arrayNode('connections')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->enumNode('driver')
                                ->values(['pdo_mysql', 'pdo_pgsql', 'pdo_sqlite', 'pyrosql', 'mongodb'])
                                ->defaultValue('pdo_mysql')
                            ->end()
                            ->scalarNode('url')->defaultNull()->end()
                            ->scalarNode('host')->defaultValue('127.0.0.1')->end()
                            ->integerNode('port')->defaultNull()->end()
                            ->scalarNode('dbname')->defaultNull()->end()
                            ->scalarNode('user')->defaultNull()->end()
                            ->scalarNode('password')->defaultNull()->end()
                            ->scalarNode('charset')->defaultValue('utf8mb4')->end()
                            ->booleanNode('persistent')->defaultFalse()->end()

                            ->arrayNode('replica')
                                ->children()
                                    ->scalarNode('host')->isRequired()->end()
                                    ->integerNode('port')->defaultNull()->end()
                                    ->scalarNode('user')->defaultNull()->end()
                                    ->scalarNode('password')->defaultNull()->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->scalarNode('default_connection')->defaultValue('default')->end()
                ->integerNode('max_rows_safety_limit')->defaultValue(100_000)->end()
                ->booleanNode('debug')->defaultFalse()->end()
                ->booleanNode('n1_detector')->defaultValue('%kernel.debug%')->end()
                ->arrayNode('second_level_cache')
                    ->canBeEnabled()
                    ->children()
                        ->scalarNode('adapter')->defaultNull()->end()
                        ->integerNode('default_ttl')->defaultValue(3600)->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
