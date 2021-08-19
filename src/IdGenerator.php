<?php
declare(strict_types = 1);

namespace LanguageServer;

/**
 * Generates unique, incremental IDs for use as request IDs
 */
class IdGenerator
{
    public int $counter = 1;

    /**
     * Returns a unique ID
     */
    public function generate()
    {
        return $this->counter++;
    }
}
