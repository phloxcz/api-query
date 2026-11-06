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
use Phlox\ApiQuery\Fields\FieldMap;
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
            'entities' => Expect::arrayOf(Expect::structure([
                'relations' => Expect::listOf('string'),
                'computed' => Expect::arrayOf('string', 'string'),
                'resource' => Expect::string()->nullable(),
            ])->castTo('array'), 'string'),
            'dateTimeFormat' => Expect::string($defaults->dateTimeFormat),
            'dateFormat' => Expect::string($defaults->dateFormat),
            'timeFormat' => Expect::string($defaults->timeFormat),
            'timeIntervalFormat' => Expect::string($defaults->timeIntervalFormat),
        ]);
    }

    public function loadConfiguration(): void
    {
        $builder = $this->getContainerBuilder();

        // Documents are generated when the container compiles, from what's configured here
        FieldMap::configureEntities($this->config->entities);
        self::registerEntityDescriber();

        $config = $builder->addDefinition($this->prefix('config'))
            ->setFactory(QueryConfig::class, (array) $this->config)
            ->setAutowired(false);

        $builder->addDefinition($this->prefix('processor'))
            ->setFactory(Processor::class, ['config' => $config]);

        $builder->addDefinition($this->prefix('sqlProcessor'))
            ->setFactory(SqlProcessor::class, ['config' => $config]);
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
