<?php
declare(strict_types = 1);

namespace LanguageServer;

use AdvancedJsonRpc\Message as MessageBody;

class Message
{
    public ?MessageBody $body;

    /**
     * @var string[]
     */
    public array $headers;

    /**
     * Parses a message
     *
     * @param string $msg
     * @return Message
     */
    public static function parse(string $msg): Message
    {
        $obj = new self;
        $parts = explode("\r\n", $msg);
        $obj->body = MessageBody::parse(array_pop($parts));
        foreach ($parts as $line) {
            if ($line) {
                $pair = explode(': ', $line);
                $obj->headers[$pair[0]] = $pair[1];
            }
        }
        return $obj;
    }

    /**
     * @param MessageBody $body
     * @param string[] $headers
     */
    public function __construct(MessageBody $body = null, array $headers = [])
    {
        $this->body = $body;
        if (!isset($headers['Content-Type'])) {
            $headers['Content-Type'] = 'application/vscode-jsonrpc; charset=utf8';
        }
        $this->headers = $headers;
    }

    public function __toString(): string
    {
        $body = (string)$this->body;
        $contentLength = strlen($body);
        $this->headers['Content-Length'] = $contentLength;
        $headers = '';
        foreach ($this->headers as $name => $value) {
            $headers .= "$name: $value\r\n";
        }
        return $headers . "\r\n" . $body;
    }
}
