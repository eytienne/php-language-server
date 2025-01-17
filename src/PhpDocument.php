<?php
declare(strict_types = 1);

namespace LanguageServer;

use LanguageServer\Index\Index;
use LanguageServerProtocol\{
    Diagnostic, Position, Range
};
use Microsoft\PhpParser\Node;
use Microsoft\PhpParser\Node\SourceFileNode;
use Microsoft\PhpParser\Parser;
use phpDocumentor\Reflection\DocBlockFactory;

class PhpDocument
{
    private Parser $parser;

    /**
     * The DocBlockFactory instance to parse docblocks
     */
    private DocBlockFactory $docBlockFactory;

    /**
     * The DefinitionResolver instance to resolve reference nodes to definitions
     */
    private DefinitionResolver $definitionResolver;

    private Index $index;

    private string $uri;

    /**
     * The AST of the document
     */
    private SourceFileNode $sourceFileNode;

    /**
     * Map from fully qualified name (FQN) to Definition
     *
     * @var Definition[]
     */
    private array $definitions;

    /**
     * Map from fully qualified name (FQN) to Node
     *
     * @var Node[]
     */
    private array $definitionNodes;

    /**
     * Map from fully qualified name (FQN) to array of nodes that reference the symbol
     *
     * @var array<string, Node[]>
     */
    private array $referenceNodes;

    /**
     * Diagnostics for this document that were collected while parsing
     *
     * @var Diagnostic[]
     */
    private $diagnostics;

    /**
     * @param string $uri The URI of the document
     * @param string $content The content of the document
     * @param Index $index The Index to register definitions and references to
     * @param Parser $parser The PhpParser instance
     * @param DocBlockFactory $docBlockFactory The DocBlockFactory instance to parse docblocks
     * @param DefinitionResolver $definitionResolver The DefinitionResolver to resolve definitions to symbols in the workspace
     */
    public function __construct(
        string $uri,
        string $content,
        Index $index,
        $parser,
        DocBlockFactory $docBlockFactory,
        DefinitionResolver $definitionResolver
    ) {
        $this->uri = $uri;
        $this->index = $index;
        $this->parser = $parser;
        $this->docBlockFactory = $docBlockFactory;
        $this->definitionResolver = $definitionResolver;
        $this->updateContent($content);
    }

    /**
     * Get all references of a fully qualified name
     *
     * @param string $fqn The fully qualified name of the symbol
     */
    public function getReferenceNodesByFqn(string $fqn)
    {
        return isset($this->referenceNodes) && isset($this->referenceNodes[$fqn]) ? $this->referenceNodes[$fqn] : null;
    }

    /**
     * Updates the content on this document.
     * Re-parses a source file, updates symbols and reports parsing errors
     * that may have occurred as diagnostics.
     *
     * @param string $content
     * @return void
     */
    public function updateContent(string $content)
    {
        // Unregister old definitions
        if (isset($this->definitions)) {
            foreach ($this->definitions as $fqn => $definition) {
                $this->index->removeDefinition($fqn);
            }
        }

        // Unregister old references
        if (isset($this->referenceNodes)) {
            foreach ($this->referenceNodes as $fqn => $node) {
                $this->index->removeReferenceUri($fqn, $this->uri);
            }
        }

        $treeAnalyzer = new TreeAnalyzer($this->parser, $content, $this->docBlockFactory, $this->definitionResolver, $this->uri);

        $this->diagnostics = $treeAnalyzer->getDiagnostics();

        $this->definitions = $treeAnalyzer->getDefinitions();

        $this->definitionNodes = $treeAnalyzer->getDefinitionNodes();

        $this->referenceNodes = $treeAnalyzer->getReferenceNodes();

        foreach ($this->definitions as $fqn => $definition) {
            $this->index->setDefinition($fqn, $definition);
        }

        // Register this document on the project for references
        foreach ($this->referenceNodes as $fqn => $_nodes) {
            // Cast the key to string. If (string)'2' is set as an array index, it will read out as (int)2. We must
            // deal with incorrect code, so this is a valid scenario.
            $this->index->addReferenceUri((string)$fqn, $this->uri);
        }

        $this->sourceFileNode = $treeAnalyzer->getSourceFileNode();
    }

    /**
     * Returns this document's text content.
     */
    public function getContent()
    {
        return $this->sourceFileNode->fileContents;
    }

    /**
     * Returns this document's diagnostics
     */
    public function getDiagnostics()
    {
        return $this->diagnostics;
    }

    /**
     * Returns the URI of the document
     */
    public function getUri()
    {
        return $this->uri;
    }

    /**
     * Returns the AST of the document
     */
    public function getSourceFileNode()
    {
        return $this->sourceFileNode;
    }

    public function getNodeAtPosition(Position $position)
    {
        if ($this->sourceFileNode === null) {
            return null;
        }

        $offset = $position->toOffset($this->sourceFileNode->getFileContents());
        $node = $this->sourceFileNode->getDescendantNodeAtPosition($offset);
        if ($node !== null && $node->getStartPosition() > $offset) {
            return null;
        }
        return $node;
    }

    /**
     * Returns a range of the content
     */
    public function getRange(Range $range)
    {
        $content = $this->getContent();
        $start = $range->start->toOffset($content);
        $length = $range->end->toOffset($content) - $start;
        return substr($content, $start, $length);
    }

    /**
     * Returns the definition node for a fully qualified name
     *
     * @param string $fqn
     * @return Node|null
     */
    public function getDefinitionNodeByFqn(string $fqn)
    {
        return $this->definitionNodes[$fqn] ?? null;
    }

    /**
     * Returns a map from fully qualified name (FQN) to Nodes defined in this document
     *
     * @return Node[]
     */
    public function getDefinitionNodes()
    {
        return $this->definitionNodes;
    }

    /**
     * Returns a map from fully qualified name (FQN) to Definition defined in this document
     *
     * @return Definition[]
     */
    public function getDefinitions()
    {
        return $this->definitions ?? [];
    }

    /**
     * Returns true if the given FQN is defined in this document
     *
     * @param string $fqn The fully qualified name of the symbol
     * @return bool
     */
    public function isDefined(string $fqn): bool
    {
        return isset($this->definitions[$fqn]);
    }
}
