<a href="https://github.com/php-type-language" target="_blank">
    <picture>
        <img align="center" src="https://github.com/php-type-language/.github/blob/master/assets/dark.png?raw=true">
    </picture>
</a>

---

<p align="center">
    <a href="https://packagist.org/packages/phptl/printer"><img src="https://poser.pugx.org/phptl/printer/require/php?style=for-the-badge" alt="PHP 8.1+"></a>
    <a href="https://packagist.org/packages/phptl/printer"><img src="https://poser.pugx.org/phptl/printer/version?style=for-the-badge" alt="Latest Stable Version"></a>
    <a href="https://packagist.org/packages/phptl/printer"><img src="https://poser.pugx.org/phptl/printer/v/unstable?style=for-the-badge" alt="Latest Unstable Version"></a>
    <a href="https://raw.githubusercontent.com/php-type-language/printer/blob/master/LICENSE"><img src="https://poser.pugx.org/phptl/printer/license?style=for-the-badge" alt="License MIT"></a>
</p>
<p align="center">
    <a href="https://github.com/php-type-language/printer/actions"><img src="https://github.com/php-type-language/printer/workflows/build/badge.svg"></a>
</p>

The PHP reference implementation for Type Language Printer.

## Installation

Type Language Parser is available as composer repository and can be installed
using the following command in a root of your project:

```sh
$ composer require phptl/printer
```

## Quick Start

```php
$parser = new \TypeLang\Parser\Parser();
$statement = $parser->parse(<<<'PHP'
    array{
        field1: (callable(Example,int):mixed),
        field2: list<Some>,
        field3: iterable<array-key, array{int, non-empty-string}>,
        Some::CONST_*,
        "\njson_flags": \JSON_*,
        ...
    }
    PHP);

// Print Statement

$native = new \TypeLang\Printer\NativeTypePrinter();
echo $native->print($statement);

// Expected Output:
// array

$phpdoc = new \TypeLang\Printer\PhpDocTypePrinter();
echo $phpdoc->print($statement);

// Expected Output:
// array{
//     field1: callable(Example, int): mixed,
//     field2: list<Some>,
//     field3: iterable<array-key, array{
//         int,
//         non-empty-string
//     }>,
//     Some::CONST_*,
//     "\njson_flags": \JSON_*,
//     ...
// }
```
