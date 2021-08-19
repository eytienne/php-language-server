<?php
declare(strict_types = 1);

namespace LanguageServer\Index;

class DependenciesIndex extends AbstractAggregateIndex
{
    /**
     * Map from package name to index
     *
     * @var Index[]
     */
    protected array $indexes = [];

    protected function getIndexes()
    {
        return $this->indexes;
    }

    public function getDependencyIndex(string $packageName): Index
    {
        if (!isset($this->indexes[$packageName])) {
            $index = new Index;
            $this->indexes[$packageName] = $index;
            $this->registerIndex($index);
        }
        return $this->indexes[$packageName];
    }

    public function setDependencyIndex(string $packageName, Index $index)
    {
        $this->indexes[$packageName] = $index;
        $this->registerIndex($index);
    }

    public function removeDependencyIndex(string $packageName)
    {
        unset($this->indexes[$packageName]);
    }

    public function hasDependencyIndex(string $packageName): bool
    {
        return isset($this->indexes[$packageName]);
    }
}
