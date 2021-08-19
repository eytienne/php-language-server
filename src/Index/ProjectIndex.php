<?php
declare(strict_types = 1);

namespace LanguageServer\Index;

use function LanguageServer\getPackageName;

/**
 * A project index manages the source and dependency indexes
 */
class ProjectIndex extends AbstractAggregateIndex
{
    private DependenciesIndex $dependenciesIndex;

    private Index $sourceIndex;

    public function __construct(Index $sourceIndex, DependenciesIndex $dependenciesIndex, \stdClass $composerJson = null)
    {
        $this->sourceIndex = $sourceIndex;
        $this->dependenciesIndex = $dependenciesIndex;
        $this->composerJson = $composerJson;
        parent::__construct();
    }

    protected function getIndexes()
    {
        return [$this->sourceIndex, $this->dependenciesIndex];
    }

    public function getIndexForUri(string $uri): Index
    {
        $packageName = getPackageName($uri, $this->composerJson);
        if ($packageName) {
            return $this->dependenciesIndex->getDependencyIndex($packageName);
        }
        return $this->sourceIndex;
    }
}
