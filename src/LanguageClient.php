<?php
declare(strict_types = 1);

namespace LanguageServer;

use JsonMapper;
use LanguageServer\Client\TextDocument;
use LanguageServer\Client\Window;
use LanguageServer\Client\Workspace;
use LanguageServer\Client\XCache;

class LanguageClient
{
    /**
     * Handles textDocument/* methods
     */
    public TextDocument $textDocument;

    /**
     * Handles window/* methods
     */
    public Window $window;

    /**
     * Handles workspace/* methods
     */
    public Workspace $workspace;

    /**
     * Handles xcache/* methods
     */
    public XCache $xcache;

    public function __construct(ProtocolReader $reader, ProtocolWriter $writer)
    {
        $handler = new ClientHandler($reader, $writer);
        $mapper = new JsonMapper();

        $this->textDocument = new TextDocument($handler, $mapper);
        $this->window = new Window($handler);
        $this->workspace = new Workspace($handler, $mapper);
        $this->xcache = new XCache($handler);
    }
}
