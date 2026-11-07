<?php
/**
 * This file is part of phloxcz/api-query.
 *
 * (c) Phlox <https://github.com/phloxcz>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Phlox\ApiQuery\DI;

use Nette;
use Nette\Schema\Expect;
use Phlox\ApiCore\Documentation\SchemaBuilder;
use Phlox\ApiQuery\Events\QueryListener;
use Phlox\ApiQuery\Fields\FieldMap;
use Phlox\ApiQuery\Fields\ResourceRegistry;
use Phlox\ApiQuery\Processor;
use Phlox\ApiQuery\QueryConfig;
use Phlox\ApiQuery\SqlProcessor;
use Phlox\EntityDatabase\Entity\Entity;

/**
 * Registers the services carrying out queries.
 *
 * @property-read \stdClass $config
 */
final class Extension extends Nette\DI\CompilerExtension
{
    public function getConfigSchema(): Nette\Schema\Schema
    {
        $defaults = new QueryConfig();

        return Expect::structure([
            'maxPageSize' => Expect::int($defaults->maxPageSize)->min(1),
            'maxDepth' => Expect::int($defaults->maxDepth)->min(0),
            'maxRows' => Expect::int($defaults->maxRows)->min(1),
            'ignoreDiacritics' => Expect::bool($defaults->ignoreDiacritics),
            'resources' => Expect::anyOf(Expect::string(), Expect::listOf('string'))->default([]),
            'entities' => Expect::mixed(), // gone, refused with what replaces it
            'dateTimeFormat' => Expect::string($defaults->dateTimeFormat),
            'dateFormat' => Expect::string($defaults->dateFormat),
            'timeFormat' => Expect::string($defaults->timeFormat),
            'timeIntervalFormat' => Expect::string($defaults->timeIntervalFormat),
        ]);
    }

    public function loadConfiguration(): void
    {
        $builder = $this->getContainerBuilder();

        if ($this->config->entities !== null) {
            throw new Nette\DI\InvalidConfigurationException(
                "apiQuery.entities is gone. Give an entity a #[Resource], with its relations and computed fields, and name "
                . "the namespace of resources in apiQuery.resources. An entity without one is shown with all its columns and no relations.",
            );
        }

        $finder = new ResourceFinder();
        $resources = $finder->find(array_values(array_map(strval(...), (array) $this->config->resources)));
        foreach ($resources as $resource) {
            $builder->addDependency(new \ReflectionClass($resource));
        }
        foreach (array_unique($finder->directories) as $directory) {
            $builder->addDependency($directory); // a resource added there
        }

        // Documents are generated when the container compiles, from what's found here
        ResourceRegistry::setDocumented(new ResourceRegistry($resources));
        self::registerEntityDescriber();

        $settings = (array) $this->config;
        unset($settings['entities']);
        $settings['resources'] = $resources;

        $config = $builder->addDefinition($this->prefix('config'))
            ->setFactory(QueryConfig::class, $settings)
            ->setAutowired(false);

        $builder->addDefinition($this->prefix('processor'))
            ->setFactory(Processor::class, ['config' => $config]);

        $builder->addDefinition($this->prefix('sqlProcessor'))
            ->setFactory(SqlProcessor::class, ['config' => $config]);
    }

    /**
     * Listeners are services, found here and registered once, so they can't pile up
     * on the shared processors as they would when added on every call.
     */
    public function beforeCompile(): void
    {
        $builder = $this->getContainerBuilder();
        $targets = [
            'processor' => ['onSelection', 'onEntity', 'onProcessedEntity', 'onSearch'],
            'sqlProcessor' => ['onProcessedRow'],
        ];

        foreach ($builder->findByType(QueryListener::class) as $name => $definition) {
            $class = (string) $definition->getType();
            foreach ($targets as $service => $events) {
                $processor = $builder->getDefinition($this->prefix($service));
                assert($processor instanceof Nette\DI\Definitions\ServiceDefinition);
                foreach ($events as $event) {
                    if (method_exists($class, $event)) {
                        $processor->addSetup('$' . $event . '[]', [[new Nette\DI\Definitions\Reference((string) $name), $event]]);
                    }
                }
            }
        }
    }

    /**
     * An entity or a resource named in documentation, such as in #[Response], is described
     * by what a query can reach of it, which is what comes out of it.
     */
    private static function registerEntityDescriber(): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;

        SchemaBuilder::addClassDescriber(static function (string $class, SchemaBuilder $builder): ?array {
            if (is_a($class, Entity::class, true)) {
                return FieldMap::forEntity($class)->toSchema($builder);
            }
            if (class_exists($class) && FieldMap::isResource($class)) {
                return FieldMap::canBeBuilt($class) ? FieldMap::forResource($class)->toSchema($builder) : ['type' => 'object'];
            }

            return null;
        });
    }
}
