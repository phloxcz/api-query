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

use Composer\Autoload\ClassLoader;
use Nette\InvalidStateException;
use Phlox\ApiQuery\Attributes\Resource;

/**
 * Finds the #[Resource] classes of namespaces, when the container compiles, by
 * the autoloading of Composer and among classes loaded already.
 */
final class ResourceFinder
{
    /** @var list<string> Directories looked in, to recompile when a class is added */
    public array $directories = [];

    /**
     * @param list<string> $namespaces
     * @return array<class-string, class-string> The resource of each entity, by the entity's class
     * @throws InvalidStateException For two resources of one entity, neither of them `default: false`
     */
    public function find(array $namespaces): array
    {
        $namespaces = array_map(static fn(string $namespace): string => trim($namespace, '\\') . '\\', $namespaces);

        $classes = [];
        foreach ($namespaces as $namespace) {
            foreach ($this->listClasses($namespace) as $class) {
                $classes[$class] = true;
            }
        }
        foreach (get_declared_classes() as $class) {
            if (array_any($namespaces, static fn(string $namespace): bool => str_starts_with($class, $namespace))) {
                $classes[$class] = true;
            }
        }

        $found = [];
        foreach (array_keys($classes) as $class) {
            if (!class_exists($class)) {
                continue;
            }
            $attributes = (new \ReflectionClass($class))->getAttributes(Resource::class);
            if ($attributes === []) {
                continue;
            }

            $resource = $attributes[0]->newInstance();
            if (!$resource->default) {
                continue;
            }

            $entity = ltrim($resource->entity, '\\');
            if (isset($found[$entity])) {
                throw new InvalidStateException(
                    "Both {$found[$entity]} and {$class} are resources of {$entity}. One of them stands for it everywhere; "
                    . 'mark the others with #[Resource(..., default: false)], and name them by #[QueryOf] where they are used.',
                );
            }
            $found[$entity] = $class;
        }

        ksort($found);

        /** @var array<class-string, class-string> */
        return $found;
    }

    /**
     * @return list<string>
     */
    private function listClasses(string $namespace): array
    {
        $classes = [];

        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            foreach (array_keys($loader->getClassMap()) as $class) {
                if (str_starts_with($class, $namespace)) {
                    $classes[] = $class;
                }
            }

            foreach ($loader->getPrefixesPsr4() as $prefix => $directories) {
                // The namespace is under the prefix, or the prefix under the namespace
                if (str_starts_with($namespace, $prefix)) {
                    $sub = substr($namespace, strlen($prefix));
                    $base = $namespace;
                } elseif (str_starts_with($prefix, $namespace)) {
                    $sub = '';
                    $base = $prefix;
                } else {
                    continue;
                }

                foreach ($directories as $directory) {
                    $path = rtrim($directory, '/\\') . ($sub === '' ? '' : '/' . str_replace('\\', '/', rtrim($sub, '\\')));
                    if (is_dir($path)) {
                        array_push($classes, ...$this->scan($path, $base));
                    }
                }
            }
        }

        return $classes;
    }

    /**
     * Whether a file is worth loading: autoloading it runs it, which a file that isn't
     * the class of its name, such as a script, must not be.
     */
    private static function declaresResource(string $file, string $class): bool
    {
        $code = (string) file_get_contents($file);

        return str_contains($code, 'Resource')
            && preg_match('~^\s*(?:(?:final|abstract|readonly)\s+)*class\s+' . preg_quote($class, '~') . '\b~m', $code) === 1;
    }

    /**
     * @return list<string>
     */
    private function scan(string $directory, string $namespace): array
    {
        $this->directories[] = $directory;
        $classes = [];

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = "{$directory}/{$entry}";
            if (is_dir($path)) {
                array_push($classes, ...$this->scan($path, $namespace . $entry . '\\'));
            } elseif (str_ends_with($entry, '.php') && self::declaresResource($path, substr($entry, 0, -4))) {
                $classes[] = $namespace . substr($entry, 0, -4);
            }
        }

        return $classes;
    }
}
