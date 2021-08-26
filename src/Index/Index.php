<?php
declare(strict_types = 1);

namespace LanguageServer\Index;

use LanguageServer\Definition;
use Sabre\Event\EmitterTrait;

use function LanguageServer\FqnUtilities\nameConcat;
use function LanguageServer\FqnUtilities\normalize;

/**
 * Represents the index of a project or dependency
 * Serializable for caching
 */
class Index implements ReadableIndex, \Serializable
{
    use EmitterTrait;

    const MEMBER_REGEX = '/((?:->|::).+)$/';
    /**
     * An associative array that maps splitted fully qualified symbol names
     * to definitions, eg :
     * [
     *     'Psr' => [
     *         '\Log' => [
     *             '\LoggerInterface' => [
     *                 ''        => $def1, // definition for 'Psr\Log\LoggerInterface' which is non-member
     *                 '->log()' => $def2, // definition for 'Psr\Log\LoggerInterface->log()' which is a member
     *             ],
     *         ],
     *     ],
     * ]
     *
     * @var array<string, Definition|Definition[]>
     */
    private array $definitions = [];

    /**
     * An associative array that maps fully qualified symbol names
     * to arrays of document URIs that reference the symbol
     *
     * @var string[][]
     */
    private array $references = [];

    private bool $complete = false;

    private bool $staticComplete = false;

    /**
     * Marks this index as complete
     */
    public function setComplete()
    {
        if (!$this->isStaticComplete()) {
            $this->setStaticComplete();
        }
        $this->complete = true;
        $this->emit('complete');
    }

    /**
     * Marks this index as complete for static definitions and references
     */
    public function setStaticComplete()
    {
        $this->staticComplete = true;
        $this->emit('static-complete');
    }

    public function isComplete(): bool
    {
        return $this->complete;
    }

    public function isStaticComplete(): bool
    {
        return $this->staticComplete;
    }

    /**
     * Returns a Generator providing an associative array [string => Definition]
     * that maps fully qualified symbol names to Definitions (global or not)
     *
     * @return \Generator|Definition[]
     */
    public function getDefinitions(): \Generator
    {
        yield from $this->yieldDefinitionsRecursively($this->definitions);
    }

    /**
     * Returns a Generator that yields all the direct child Definitions of a given FQN
     *
     * @return \Generator|Definition[]
     */
    public function getChildDefinitionsForFqn(string $fqn): \Generator
    {
        $parts = $this->splitFqn(normalize($fqn));
        if ('' === end($parts)) {
            // we want to return all the definitions in the given FQN, not only
            // the one (non member) matching exactly the FQN.
            array_pop($parts);
        }

        $result = $this->getIndexValue($parts);
        if (!$result) {
            return;
        }
        foreach ($result as $name => $item) {
            // Don't yield the parent
            if ($name === '') {
                continue;
            }
            if ($item instanceof Definition) {
                $yielded = $item;
            } elseif (is_array($item) && isset($item[''])) {
                $yielded = $item[''];
            }
            if (isset($yielded)) {
                yield (preg_match(self::MEMBER_REGEX, $name) ? $fqn.$name : nameConcat($fqn, $name)) => $yielded;
            }
        }
    }

    /**
     * Returns the Definition object by a specific FQN
     *
     * @param string $fqn
     * @param bool $globalFallback Whether to fallback to global if the namespaced FQN was not found
     */
    public function getDefinition(string $fqn, bool $globalFallback = false)
    {
        $parts = $this->splitFqn($fqn);
        $definition = $this->getIndexValue($parts);

        if ($definition instanceof Definition) {
            return $definition;
        }

        if ($globalFallback) {
            $parts = explode('\\', $fqn);
            $nonNamespacedPortion = end($parts);
            return $this->getDefinition($nonNamespacedPortion);
        }
    }

    /**
     * Registers a definition
     *
     * @param string $fqn The fully qualified name of the symbol
     * @param Definition $definition The Definition object
     */
    public function setDefinition(string $fqn, Definition $definition)
    {
        $parts = $this->splitFqn($fqn);

        $storage =& $this->definitions;
        foreach ($parts as $part) {
            if($part === end($parts)) {
                $storage[$part] = $definition;
                break;
            }
            $storage[$part] ??= [];
            $storage =& $storage[$part];
        }

        $this->emit('definition-added');
    }

    /**
     * Unsets the Definition for a specific symbol
     * and removes all references pointing to that symbol
     *
     * @param string $fqn The fully qualified name of the symbol
     */
    public function removeDefinition(string $fqn)
    {
        $parts = $this->splitFqn($fqn);
        $this->removeIndexedDefinition(0, $parts, $this->definitions, $this->definitions);

        unset($this->references[$fqn]);
    }

    /**
     * Returns a Generator providing all URIs in this index that reference a symbol
     *
     * @param string $fqn The fully qualified name of the symbol
     * @return \Generator|string[]
     */
    public function getReferenceUris(string $fqn): \Generator
    {
        foreach ($this->references[$fqn] ?? [] as $uri) {
            yield $uri;
        }
    }

    /**
     * For test use.
     * Returns all references, keyed by fqn.
     *
     * @return string[][]
     */
    public function getReferences(): array
    {
        return $this->references;
    }

    /**
     * Adds a document URI as a referencee of a specific symbol
     *
     * @param string $fqn The fully qualified name of the symbol
     */
    public function addReferenceUri(string $fqn, string $uri)
    {
        if (!isset($this->references[$fqn])) {
            $this->references[$fqn] = [];
        }
        // TODO: use DS\Set instead of searching array
        if (array_search($uri, $this->references[$fqn], true) === false) {
            $this->references[$fqn][] = $uri;
        }
    }

    /**
     * Removes a document URI as the container for a specific symbol
     *
     * @param string $fqn The fully qualified name of the symbol
     * @param string $uri The URI
     */
    public function removeReferenceUri(string $fqn, string $uri)
    {
        if (!isset($this->references[$fqn])) {
            return;
        }
        $index = array_search($fqn, $this->references[$fqn], true);
        if ($index === false) {
            return;
        }
        array_splice($this->references[$fqn], $index, 1);
    }

    public function unserialize($serialized)
    {
        $data = unserialize($serialized);

        if (isset($data['definitions'])) {
            foreach ($data['definitions'] as $fqn => $definition) {
                $this->setDefinition($fqn, $definition);
            }

            unset($data['definitions']);
        }

        foreach ($data as $prop => $val) {
            $this->$prop = $val;
        }
    }

    public function serialize()
    {
        return serialize([
            'definitions' => iterator_to_array($this->getDefinitions()),
            'references' => $this->references,
            'complete' => $this->complete,
            'staticComplete' => $this->staticComplete
        ]);
    }

    /**
     * Returns a Generator that yields all the Definitions in the given $storage recursively.
     * The generator yields key => value pairs, e.g.
     * `'Psr\Log\LoggerInterface->log()' => $definition`
     */
    private function yieldDefinitionsRecursively(array &$storage, string $prefix = ''): \Generator
    {
        foreach ($storage as $key => $value) {
            if (!is_array($value)) {
                yield $prefix.$key => $value;
            } else {
                yield from $this->yieldDefinitionsRecursively($value, $prefix.$key);
            }
        }
    }

    /**
     * Splits the given FQN into an array, eg :
     * - `'\\Psr\\Log\\LoggerInterface'` will be `['Psr', 'Log', 'LoggerInterface', '']` with a terminal empty string for the leaf without member
     * - `'\\Psr\\Log\\LoggerInterface->log()'` will be `['Psr', 'Log', 'LoggerInterface', '->log()']` with a terminal string for the leaf member
     * - `'PHP_VERSION'` will be `['PHP_VERSION']`
     *
     * @return string[]
     */
    private function splitFqn(string $fqn): array
    {
        // array_filter avoid [''] when fqn is global namespace
        $parts = array_filter(explode('\\', normalize($fqn)));
        if ($parts) { // empty when "global namespace"
            $lastPart = array_pop($parts);
            // split the last part in 2 parts at the operator
            if(preg_match(self::MEMBER_REGEX, $lastPart, $matches)) {
                $parts[] = str_replace($matches[0], '', $lastPart);
                $parts[] = $matches[0];
            } else {
                $parts[] = $lastPart;
                $parts[] = '';
            }
        }

        return $parts;
    }

    /**
     * Return the values stored in this index under the given $parts array.
     * It can be an index node or a Definition if the $parts are precise
     * enough. Returns null when nothing is found.
     *
     * @param string[] $parts              The splitted FQN
     */
    private function getIndexValue(array $parts)
    {
        $return = $storage = $this->definitions;
        foreach ($parts as $part) {
            /** @var Definition|Definition[] */
            $stored = $storage[$part] ?? null;
            if($stored === null) {
                return null;
            } elseif (is_array($stored)) {
                $return = $storage = $stored;
            } else {
                $return = $stored;
            }
        }
        return $return;
    }

    /**
     * Recursive function that removes the definition matching the given $parts from the given
     * $storage array. The function also looks up recursively to remove the parents of the
     * definition which no longer has children to avoid to let empty arrays in the index.
     *
     * @param int $level              The current level of FQN part
     * @param string[] $parts         The splitted FQN
     * @param array &$storage         The current array in which to remove data
     * @param array &$rootStorage     The root storage array
     */
    private function removeIndexedDefinition(int $level, array $parts, array &$storage, array &$rootStorage)
    {
        $part = $parts[$level];

        if ($level + 1 === count($parts)) {
            if (isset($storage[$part])) {
                unset($storage[$part]);

                if (0 === count($storage)) {
                    // parse again the definition tree to remove the parent
                    // when it has no more children
                    $this->removeIndexedDefinition(0, array_slice($parts, 0, $level), $rootStorage, $rootStorage);
                }
            }
        } else {
            $this->removeIndexedDefinition($level + 1, $parts, $storage[$part], $rootStorage);
        }
    }
}
