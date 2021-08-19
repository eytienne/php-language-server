<?php
declare(strict_types = 1);

namespace LanguageServer\Index;

/**
 * Aggregates definitions of the project and stubs
 */
class GlobalIndex extends AbstractAggregateIndex
{
    private Index $stubsIndex;

    private ProjectIndex $projectIndex;

    public function __construct(StubsIndex $stubsIndex, ProjectIndex $projectIndex)
    {
        $this->stubsIndex = $stubsIndex;
        $this->projectIndex = $projectIndex;
        parent::__construct();
    }

    protected function getIndexes()
    {
        return [$this->stubsIndex, $this->projectIndex];
    }
}
