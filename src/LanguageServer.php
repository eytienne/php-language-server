<?php
declare(strict_types = 1);

namespace LanguageServer;

use AdvancedJsonRpc\Dispatcher;
use LanguageServerProtocol\{
    ServerCapabilities,
    ClientCapabilities,
    TextDocumentSyncKind,
    InitializeResult,
    CompletionOptions,
    SignatureHelpOptions
};
use LanguageServer\FilesFinder\{FilesFinder, ClientFilesFinder, FileSystemFilesFinder};
use LanguageServer\ContentRetriever\{ContentRetriever, ClientContentRetriever, FileSystemContentRetriever};
use LanguageServer\Index\{DependenciesIndex, GlobalIndex, Index, ProjectIndex, StubsIndex};
use LanguageServer\Cache\{FileSystemCache, ClientCache};
use AdvancedJsonRpc\Error as AdvancedJsonRpcError;
use AdvancedJsonRpc\ErrorCode;
use AdvancedJsonRpc\ErrorResponse;
use AdvancedJsonRpc\Request;
use AdvancedJsonRpc\Response;
use AdvancedJsonRpc\SuccessResponse;
use LanguageServer\Server\TextDocument;
use LanguageServer\Server\Workspace;
use Sabre\Event\Promise;
use stdClass;
use function LanguageServer\array_merge_recursive2;
use function Sabre\Event\coroutine;
use Throwable;
use Webmozart\PathUtil\Path;

class LanguageServer extends Dispatcher
{
    /**
     * Handles textDocument/* method calls
     */
    public ?TextDocument $textDocument = null;

    /**
     * Handles workspace/* method calls
     */
    public ?Workspace $workspace = null;

    public $telemetry;
    public $completionItem;
    public $codeLens;

    protected ProtocolReader $protocolReader;

    protected ProtocolWriter $protocolWriter;

    protected LanguageClient $client;

    protected FilesFinder $filesFinder;

    protected ContentRetriever $contentRetriever;

    protected PhpDocumentLoader $documentLoader;

    /**
     * The parsed composer.json file in the project, if any
     */
    protected ?stdClass $composerJson = null;

    /**
     * The parsed composer.lock file in the project, if any
     */
    protected ?stdClass $composerLock = null;

    protected GlobalIndex $globalIndex;

    protected ProjectIndex $projectIndex;

    protected DefinitionResolver $definitionResolver;

    public function __construct(ProtocolReader $reader, ProtocolWriter $writer)
    {
        parent::__construct($this, '/');
        $this->protocolReader = $reader;
        $this->protocolReader->on('close', function () {
            $this->shutdown();
            $this->exit();
        });
        $this->protocolReader->on('message', function (Message $msg) {
            coroutine(function () use ($msg) {
                // Ignore responses, this is the handler for requests and notifications
                if (Response::isResponse($msg->body)) {
                    return;
                }
                $result = null;
                $error = null;
                try {
                    // Invoke the method handler to get a result
                    $result = yield $this->dispatch($msg->body);
                } catch (AdvancedJsonRpcError $e) {
                    // If a ResponseError is thrown, send it back in the Response
                    $error = $e;
                } catch (Throwable $e) {
                    // If an unexpected error occurred, send back an INTERNAL_ERROR error response
                    $error = new AdvancedJsonRpcError(
                        (string)$e,
                        ErrorCode::INTERNAL_ERROR,
                        null,
                        $e
                    );
                }
                // Only send a Response for a Request
                // Notifications do not send Responses
                if (Request::isRequest($msg->body)) {
                    if ($error !== null) {
                        $responseBody = new ErrorResponse($msg->body->id, $error);
                    } else {
                        $responseBody = new SuccessResponse($msg->body->id, $result);
                    }
                    $this->protocolWriter->write(new Message($responseBody));
                }
            })->otherwise('\\LanguageServer\\crash');
        });
        $this->protocolWriter = $writer;
        $this->client = new LanguageClient($reader, $writer);
    }

    /**
     * The initialize request is sent as the first request from the client to the server.
     *
     * @param ClientCapabilities $capabilities The capabilities provided by the client (editor)
     * @param string $rootPath The rootPath of the workspace. Is null if no folder is open.
     * @return Promise<InitializeResult>
     *
     * @see https://microsoft.github.io/language-server-protocol/specifications/specification-3-17/#initializeParams
     */
    public function initialize(
        ClientCapabilities $capabilities,
        string $rootPath = null,
        string $rootUri = null,
        ?InitializationOptions $initializationOptions = null
    ): Promise {
        /** @var InitializationOptions */
        $initializationOptions = (object) array_merge_recursive2((array) new InitializationOptions(), (array) $initializationOptions);

        if ($rootPath === null && $rootUri !== null) {
            $rootPath = uriToPath($rootUri);
        }
        return coroutine(function () use ($capabilities, $rootPath, $initializationOptions) {

            if ($capabilities->xfilesProvider) {
                $this->filesFinder = new ClientFilesFinder($this->client);
            } else {
                $this->filesFinder = new FileSystemFilesFinder;
            }

            if ($capabilities->xcontentProvider) {
                $this->contentRetriever = new ClientContentRetriever($this->client);
            } else {
                $this->contentRetriever = new FileSystemContentRetriever;
            }

            $dependenciesIndex = new DependenciesIndex;
            $sourceIndex = new Index;
            $this->projectIndex = new ProjectIndex($sourceIndex, $dependenciesIndex, $this->composerJson);
            $stubsIndex = StubsIndex::read();
            $this->globalIndex = new GlobalIndex($stubsIndex, $this->projectIndex);

            // The DefinitionResolver should look in stubs, the project source and dependencies
            $this->definitionResolver = new DefinitionResolver($this->globalIndex);

            $this->documentLoader = new PhpDocumentLoader(
                $this->contentRetriever,
                $this->projectIndex,
                $this->definitionResolver
            );

            if ($rootPath !== null) {
                yield $this->beforeIndex($rootPath);

                // Find composer.json
                if ($this->composerJson === null) {
                    $composerJsonFiles = yield $this->filesFinder->find(Path::makeAbsolute('**/composer.json', $rootPath));
                    sortUrisLevelOrder($composerJsonFiles);

                    if (!empty($composerJsonFiles)) {
                        $this->composerJson = json_decode(yield $this->contentRetriever->retrieve($composerJsonFiles[0]));
                    }
                }

                // Find composer.lock
                if ($this->composerLock === null) {
                    $composerLockFiles = yield $this->filesFinder->find(Path::makeAbsolute('**/composer.lock', $rootPath));
                    sortUrisLevelOrder($composerLockFiles);

                    if (!empty($composerLockFiles)) {
                        $this->composerLock = json_decode(yield $this->contentRetriever->retrieve($composerLockFiles[0]));
                    }
                }

                $cache = $capabilities->xcacheProvider ? new ClientCache($this->client) : new FileSystemCache;

                // Index in background
                $indexer = new Indexer(
                    $this->filesFinder,
                    $rootPath,
                    $initializationOptions->exclude,
                    $this->client,
                    $cache,
                    $dependenciesIndex,
                    $sourceIndex,
                    $this->documentLoader,
                    $this->composerLock,
                    $this->composerJson
                );
                $indexer->index()->otherwise('\\LanguageServer\\crash');
            }


            if ($this->textDocument === null) {
                $this->textDocument = new TextDocument(
                    $this->documentLoader,
                    $this->definitionResolver,
                    $this->client,
                    $this->globalIndex,
                    $this->composerJson,
                    $this->composerLock
                );
            }
            if ($this->workspace === null) {
                $this->workspace = new Workspace(
                    $this->client,
                    $this->projectIndex,
                    $dependenciesIndex,
                    $sourceIndex,
                    $this->documentLoader,
                    $this->composerJson,
                    $this->composerLock
                );
            }

            $serverCapabilities = new ServerCapabilities();
            // Ask the client to return always full documents (because we need to rebuild the AST from scratch)
            $serverCapabilities->textDocumentSync = TextDocumentSyncKind::FULL;
            // Support "Find all symbols"
            $serverCapabilities->documentSymbolProvider = true;
            // Support "Find all symbols in workspace"
            $serverCapabilities->workspaceSymbolProvider = true;
            // Support "Go to definition"
            $serverCapabilities->definitionProvider = true;
            // Support "Find all references"
            $serverCapabilities->referencesProvider = true;
            // Support "Hover"
            $serverCapabilities->hoverProvider = true;
            // Support "Completion"
            $serverCapabilities->completionProvider = new CompletionOptions;
            $serverCapabilities->completionProvider->resolveProvider = false;
            $serverCapabilities->completionProvider->triggerCharacters = ['$', '>'];

            $serverCapabilities->signatureHelpProvider = new SignatureHelpOptions();
            $serverCapabilities->signatureHelpProvider->triggerCharacters = ['(', ','];

            // Support global references
            $serverCapabilities->xworkspaceReferencesProvider = true;
            $serverCapabilities->xdefinitionProvider = true;
            $serverCapabilities->xdependenciesProvider = true;

            return new InitializeResult($serverCapabilities);
        });
    }

    /**
     * The shutdown request is sent from the client to the server. It asks the server to shut down, but to not exit
     * (otherwise the response might not be delivered correctly to the client). There is a separate exit notification that
     * asks the server to exit.
     *
     * @return void
     */
    public function shutdown()
    {
        unset($this->project);
    }

    /**
     * A notification to ask the server to exit its process.
     *
     * @return void
     */
    public function exit()
    {
        exit(0);
    }

    /**
     * Called before indexing, can return a Promise
     *
     * @param string $rootPath
     */
    protected function beforeIndex(string $rootPath)
    {
    }
}
