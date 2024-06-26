<?php

declare(strict_types=1);

namespace TypeLang\Printer;

use TypeLang\Parser\Node\Statement;
use TypeLang\Parser\Node\Stmt\LogicalTypeNode;
use TypeLang\Parser\Node\Stmt\TypeStatement;

abstract class Printer implements PrinterInterface
{
    /**
     * @var int<0, max>
     */
    protected int $depth = 0;

    /**
     * @var int<0, max>
     */
    protected int $nesting = 0;

    /**
     * @var non-empty-string
     */
    protected const DEFAULT_NEW_LINE_DELIMITER = "\n";

    /**
     * @var non-empty-string
     */
    protected const DEFAULT_INDENTION = '    ';

    /**
     * @param non-empty-string $newLine
     * @param non-empty-string $indention
     */
    public function __construct(
        protected readonly string $newLine = self::DEFAULT_NEW_LINE_DELIMITER,
        protected readonly string $indention = self::DEFAULT_INDENTION,
    ) {}

    public function print(Statement $stmt): string
    {
        $this->nesting = $this->depth = 0;

        return $this->make($stmt);
    }

    /**
     * @return non-empty-string
     */
    abstract protected function make(Statement $stmt): string;

    /**
     * @param int<0, max>|null $depth
     */
    protected function prefix(?int $depth = null): string
    {
        $depth ??= $this->depth;

        if ($depth <= 0) {
            return '';
        }

        return \str_repeat($this->indention, $depth);
    }

    /**
     * @template TResult of mixed
     *
     * @param callable():TResult $section
     *
     * @return TResult
     */
    protected function nested(callable $section): mixed
    {
        ++$this->depth;

        $result = $section();

        --$this->depth;

        return $result;
    }

    /**
     * @param iterable<mixed, Statement> $stmts
     *
     * @return list<non-empty-string>
     */
    protected function printMap(iterable $stmts): array
    {
        $result = [];

        foreach ($stmts as $stmt) {
            $result[] = $this->make($stmt);
        }

        $result = \array_unique($result);

        if (\in_array('mixed', $result, true)) {
            return ['mixed'];
        }

        /** @var list<non-empty-string> */
        return $result;
    }

    /**
     * @template T of TypeStatement
     *
     * @param LogicalTypeNode<T> $logical
     *
     * @return iterable<array-key, T>
     */
    protected function unwrap(LogicalTypeNode $logical): iterable
    {
        foreach ($logical->statements as $statement) {
            if ($statement instanceof $logical) {
                yield from $this->unwrap($statement);
            } else {
                yield $statement;
            }
        }
    }

    /**
     * @param LogicalTypeNode<TypeStatement> $stmt
     *
     * @return list<non-empty-string>
     */
    protected function unwrapAndPrint(LogicalTypeNode $stmt): iterable
    {
        return $this->printMap($this->unwrap($stmt));
    }
}
