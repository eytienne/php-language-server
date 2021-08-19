<?php
declare(strict_types = 1);

namespace LanguageServer;

use LanguageServer\FilesFinder\FileSystemFilesFinder;
use LanguageServer\ContentRetriever\FileSystemContentRetriever;
use LanguageServer\Index\StubsIndex;
use Microsoft\PhpParser\Parser;
use phpDocumentor\Reflection\DocBlockFactory;
use Sabre\Uri;
use Webmozart\PathUtil\Path;
use function Sabre\Event\coroutine;

foreach ([__DIR__ . '/../../../autoload.php', __DIR__ . '/../autoload.php', __DIR__ . '/../vendor/autoload.php'] as $file) {
    if (file_exists($file)) {
        require_once $file;
        break;
    }
}

class ComposerScripts
{
    public static function parseStubs()
    {
        coroutine(function () {
            $stubsLocation = Path::canonicalize(__DIR__ . '/../vendor/jetbrains/phpstorm-stubs');
            if (!file_exists($stubsLocation)) {
                throw new \Exception('jetbrains/phpstorm-stubs package not found');
            }

            /** @var string[] */
            $uris = yield (new FileSystemFilesFinder())->find("$stubsLocation/**/*.php");
            $contentRetriever = new FileSystemContentRetriever();

            $index = new StubsIndex();

            $parser = new Parser();
            $docBlockFactory = DocBlockFactory::createInstance();
            $definitionResolver = new DefinitionResolver($index);

            foreach ($uris as $uri) {
                echo "Parsing stub $uri\n";
                /** @var string */
                $content = yield $contentRetriever->retrieve($uri);

                // Change URI to phpstubs://
                $parts = Uri\parse($uri);
                $parts['path'] = Path::makeRelative($parts['path'], $stubsLocation);
                $parts['scheme'] = 'phpstubs';
                $uri = Uri\build($parts);

                // Create a new document and add it to $index
                new PhpDocument($uri, $content, $index, $parser, $docBlockFactory, $definitionResolver);
            }

            $index->setComplete();
            echo "Saving Index\n";
            $index->save();
            echo "Finished\n";
        })->wait();
    }
}
