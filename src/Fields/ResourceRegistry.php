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

namespace Phlox\ApiQuery\Fields;

/**
 * Which #[Resource] stands for each entity, and the FieldMaps built from them.
 *
 * Each Processor has its own, so two of them with other resources don't disturb
 * each other. Documentation, generated when the container compiles, uses the one
 * the extension sets, as attributes like #[QueryOf] can't get a service.
 */
final class ResourceRegistry
{
    private static ?self $documented = null;

    /** @var array<string, class-string> */
    private array $resources = [];

    /** @var array<string, FieldMap> */
    private array $maps = [];

    /**
     * @param array<string, class-string> $resources The #[Resource] of an entity, by the entity's class
     */
    public function __construct(array $resources = [])
    {
        foreach ($resources as $entity => $resource) {
            /** @var class-string $resource */
            $resource = ltrim($resource, '\\');
            $this->resources[ltrim($entity, '\\')] = $resource;
        }
    }

    /**
     * The one documentation uses: what the extension found when the container compiled.
     */
    public static function documented(): self
    {
        return self::$documented ??= new self();
    }

    public static function setDocumented(self $registry): void
    {
        self::$documented = $registry;
    }

    /**
     * @return class-string|null
     */
    public function getResourceOf(string $entity): ?string
    {
        return $this->resources[ltrim($entity, '\\')] ?? null;
    }

    /**
     * @param \Closure(): FieldMap $build
     */
    public function remember(string $class, \Closure $build): FieldMap
    {
        return $this->maps[ltrim($class, '\\')] ??= $build();
    }
}
