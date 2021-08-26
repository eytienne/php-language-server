<?php

namespace LanguageServer\FqnUtilities;

use phpDocumentor\Reflection\{Type, Types};

/**
 * Returns all possible FQNs in a type
 *
 * @param Type|null $type
 * @return string[]
 */
function getFqnsFromType($type): array
{
    $fqns = [];
    if ($type instanceof Types\Object_) {
        $fqsen = $type->getFqsen();
        if ($fqsen !== null) {
            $fqns[] = substr((string)$fqsen, 1);
        }
    }
    if ($type instanceof Types\Compound) {
        for ($i = 0; $t = $type->get($i); $i++) {
            foreach (getFqnsFromType($t) as $fqn) {
                $fqns[] = $fqn;
            }
        }
    }
    return $fqns;
}

/**
 * Concatenates two names (joining them with a `\\`).
 *
 * nameConcat('Foo\\Bar', 'Baz') === 'Foo\\Bar\\Baz'
 * nameConcat('Foo\\Bar\\', '\\Baz') === 'Foo\\Bar\\Baz'
 * nameConcat('\\Foo\\Bar', '\\Baz') === 'Foo\\Bar\\Baz'
 *
 * @return string
 */
function nameConcat(string $a, string $b): string
{
    $a = normalize($a);
    $b = normalize($b);
    if($a === '') {
        return $b;
    }
    return "$a\\$b";
}

function normalize($name) {
    return trim($name, "\\");
}

/**
 * Returns the first component of $name.
 *
 * nameGetFirstPart('Foo\Bar') === 'Foo'
 * nameGetFirstPart('\Foo\Bar') === 'Foo'
 * nameGetFirstPart('') === ''
 * nameGetFirstPart('\') === ''
 */
function nameGetFirstPart(string $name): string
{
    $parts = explode('\\', $name, 3);
    if ($parts[0] === '' && count($parts) > 1) {
        return $parts[1];
    } else {
        return $parts[0];
    }
}
