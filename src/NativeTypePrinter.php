<?php

declare(strict_types=1);

namespace TypeLang\Printer;

use TypeLang\Parser\Node\Stmt\CallableTypeNode;
use TypeLang\Parser\Node\Stmt\ClassConstMaskNode;
use TypeLang\Parser\Node\Stmt\ClassConstNode;
use TypeLang\Parser\Node\Stmt\Condition\Condition;
use TypeLang\Parser\Node\Stmt\Condition\EqualConditionNode;
use TypeLang\Parser\Node\Stmt\Condition\NotEqualConditionNode;
use TypeLang\Parser\Node\Stmt\ConstMaskNode;
use TypeLang\Parser\Node\Stmt\IntersectionTypeNode;
use TypeLang\Parser\Node\Stmt\NamedTypeNode;
use TypeLang\Parser\Node\Stmt\TernaryConditionNode;
use TypeLang\Parser\Node\Stmt\TypesListNode;
use TypeLang\Parser\Node\Stmt\UnionTypeNode;
use TypeLang\Printer\Exception\NonPrintableNodeException;

/**
 * @psalm-suppress UndefinedAttributeClass : For "Override" attribute support.
 * @psalm-suppress InvalidAttribute : For "Override" attribute support.
 */
class NativeTypePrinter extends PrettyPrinter
{
    /**
     * @var array<non-empty-string, non-empty-string>
     */
    private array $aliases = [
        // never
        'never-return' => 'never',
        'never-returns' => 'never',
        'no-return' => 'never',
        'empty' => 'never',
        'noreturn' => 'never',
        // mixed
        'value-of' => 'mixed',
        'resource' => 'mixed',
        'resource (closed)' => 'mixed',
        'closed-resource' => 'mixed',
        'non-empty-mixed' => 'mixed',
        // int
        'int-mask' => 'int',
        'integer' => 'int',
        'int-mask-of' => 'int',
        'literal-int' => 'int',
        'positive-int' => 'int',
        'negative-int' => 'int',
        'non-positive-int' => 'int',
        'non-negative-int' => 'int',
        'non-zero-int' => 'int',
        // float
        'double' => 'float',
        // bool
        'boolean' => 'bool',
        // array
        '__always-list' => 'array',
        'associative-array' => 'array',
        'list' => 'array',
        'non-empty-list' => 'array',
        'non-empty-array' => 'array',
        // string
        'class-string' => 'string',
        'interface-string' => 'string',
        'trait-string' => 'string',
        'enum-string' => 'string',
        'numeric-string' => 'string',
        'literal-string' => 'string',
        'non-empty-literal-string' => 'string',
        'non-empty-string' => 'string',
        'lowercase-string' => 'string',
        'non-empty-lowercase-string' => 'string',
        'truthy-string' => 'string',
        'non-falsy-string' => 'string',
        // object
        'stringable-object' => 'object',
        // string|\Stringable
        '__stringandstringable' => 'string|stringable',
        // int|string
        'array-key' => 'int|string',
        'key-of' => 'int|string',
        // float|int
        'number' => 'float|int',
        // float|int|string
        'numeric' => 'float|int|string',
        // bool|float|int|string
        'scalar' => 'bool|float|int|string',
        'non-empty-scalar' => 'bool|float|int|string',
        'empty-scalar' => 'bool|float|int|string',
        // callable
        'callable-array' => 'callable',
        'callable-object' => 'callable',
        'callable-string' => 'callable',
        'pure-callable' => 'callable',
        'pure-Closure' => \Closure::class,
    ];

    /**
     * @param array<non-empty-string, non-empty-string> $aliases
     * @param non-empty-string $newLine
     * @param non-empty-string $indention
     */
    public function __construct(
        array $aliases = [],
        string $newLine = self::DEFAULT_NEW_LINE_DELIMITER,
        string $indention = self::DEFAULT_INDENTION
    ) {
        parent::__construct($newLine, $indention);

        foreach ($aliases as $alias => $type) {
            $this->addTypeAlias($alias, $type);
        }
    }

    /**
     * @param non-empty-string $alias
     * @param non-empty-string $type
     */
    public function addTypeAlias(string $alias, string $type): void
    {
        if (\str_contains($type, '|')) {
            /** @psalm-suppress ArgumentTypeCoercion */
            $this->addUnionTypeAlias($alias, \explode('|', $type));

            return;
        }

        if (\str_contains($type, '&')) {
            /** @psalm-suppress ArgumentTypeCoercion */
            $this->addUnionTypeAlias($alias, \explode('&', $type));

            return;
        }

        $this->aliases[\strtolower($alias)] = $type;
    }

    /**
     * @param non-empty-string $alias
     * @param non-empty-list<non-empty-string> $types
     */
    public function addUnionTypeAlias(string $alias, array $types): void
    {
        \sort($types);

        $this->aliases[\strtolower($alias)] = \implode('|', $types);
    }

    /**
     * @param non-empty-string $alias
     * @param non-empty-list<non-empty-string> $types
     */
    public function addIntersectionTypeAlias(string $alias, array $types): void
    {
        \sort($types);

        $this->aliases[\strtolower($alias)] = \implode('&', $types);
    }

    /**
     * @return non-empty-string
     */
    #[\Override]
    protected function printTypeListNode(TypesListNode $node): string
    {
        return 'iterable';
    }

    #[\Override]
    protected function printTernaryType(TernaryConditionNode $node): string
    {
        return $this->make(new UnionTypeNode(
            $node->then,
            $node->else,
        ));
    }

    #[\Override]
    protected function printCondition(Condition $node): string
    {
        return match (true) {
            $node instanceof EqualConditionNode => '===',
            $node instanceof NotEqualConditionNode => '!==',
            default => throw NonPrintableNodeException::fromInvalidNode($node),
        };
    }

    #[\Override]
    protected function printClassConstMaskNode(ClassConstMaskNode $node): string
    {
        return 'int';
    }

    #[\Override]
    protected function printConstMaskNode(ConstMaskNode $node): string
    {
        return 'int';
    }

    #[\Override]
    protected function printClassConstNode(ClassConstNode $node): string
    {
        return 'mixed';
    }

    #[\Override]
    protected function printUnionTypeNode(UnionTypeNode $node): string
    {
        try {
            /** @var non-empty-string */
            return \vsprintf($this->nesting > 0 ? '(%s)' : '%s', [
                \implode('|', [...$this->unwrapAndPrint($node)])
            ]);
        } finally {
            ++$this->nesting;
        }
    }

    #[\Override]
    protected function printIntersectionTypeNode(IntersectionTypeNode $node): string
    {
        try {
            /** @var non-empty-string */
            return \vsprintf($this->nesting > 0 ? '(%s)' : '%s', [
                \implode('&', [...$this->unwrapAndPrint($node)])
            ]);
        } finally {
            ++$this->nesting;
        }
    }

    /**
     * @param non-empty-string $name
     *
     * @return non-empty-string
     */
    protected function getTypeName(string $name): string
    {
        return $this->aliases[\strtolower($name)] ?? $name;
    }

    #[\Override]
    protected function printCallableTypeNode(CallableTypeNode $node): string
    {
        return $this->getTypeName($node->name->toString());
    }

    #[\Override]
    protected function printNamedTypeNode(NamedTypeNode $node): string
    {
        return $this->getTypeName($node->name->toString());
    }
}
