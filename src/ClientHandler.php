<?php
declare(strict_types = 1);

namespace LanguageServer;

use AdvancedJsonRpc;
use Sabre\Event\Promise;

class ClientHandler
{
    public ProtocolReader $protocolReader;

    public ProtocolWriter $protocolWriter;

    public IdGenerator $idGenerator;

    public function __construct(ProtocolReader $protocolReader, ProtocolWriter $protocolWriter)
    {
        $this->protocolReader = $protocolReader;
        $this->protocolWriter = $protocolWriter;
        $this->idGenerator = new IdGenerator;
    }

    /**
     * Sends a request to the client and returns a promise that is resolved with the result or rejected with the error
     *
     * @param string $method The method to call
     * @param array|object $params The method parameters
     * @return Promise <mixed> Resolved with the result of the request or rejected with an error
     */
    public function request(string $method, $params): Promise
    {
        $id = $this->idGenerator->generate();
        return $this->protocolWriter->write(
            new Message(
                new AdvancedJsonRpc\Request($id, $method, (object)$params)
            )
        )->then(function () use ($id) {
            $promise = new Promise;
            $listener = function (Message $msg) use ($id, $promise, &$listener) {
                if (AdvancedJsonRpc\Response::isResponse($msg->body) && $msg->body->id === $id) {
                    // Received a response
                    $this->protocolReader->removeListener('message', $listener);
                    if (AdvancedJsonRpc\SuccessResponse::isSuccessResponse($msg->body)) {
                        $promise->fulfill($msg->body->result);
                    } else {
                        $promise->reject($msg->body->error);
                    }
                }
            };
            $this->protocolReader->on('message', $listener);
            return $promise;
        });
    }

    /**
     * Sends a notification to the client
     *
     * @param string $method The method to call
     * @param array|object $params The method parameters
     * @return Promise <null> Will be resolved as soon as the notification has been sent
     */
    public function notify(string $method, $params): Promise
    {
        return $this->protocolWriter->write(
            new Message(
                new AdvancedJsonRpc\Notification($method, (object)$params)
            )
        );
    }
}
