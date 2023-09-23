<?php

declare(strict_types=1);

namespace TypeLang\Printer;

use TypeLang\Parser\Node\Stmt\BinaryStmt;
use TypeLang\Parser\Node\Stmt\Statement;

abstract class Printer implements PrinterInterface
{
    /**
     * @param int<0, max> $depth
     *
     * @return non-empty-string
     */
    abstract public function print(Statement $stmt, int $depth = 0): string;

    /**
     * @template TArgKey of array-key
     *
     * @param iterable<TArgKey, Statement> $stmts
     * @param int<0, max> $depth
     *
     * @return iterable<TArgKey, non-empty-string>
     */
    protected function printMap(iterable $stmts, int $depth = 0): iterable
    {
        foreach ($stmts as $stmt) {
            yield $this->print($stmt, $depth);
        }
    }

    /**
     * @return iterable<array-key, Statement>
     */
    protected function unwrap(BinaryStmt $stmt): iterable
    {
        yield from $stmt->a instanceof $stmt
            ? $this->unwrap($stmt->a)
            : [$stmt->a];

        yield from $stmt->b instanceof $stmt
            ? $this->unwrap($stmt->b)
            : [$stmt->b];
    }

    /**
     * @return iterable<array-key, non-empty-string>
     */
    protected function unwrapAndPrint(BinaryStmt $stmt, int $depth = 0): iterable
    {
        return $this->printMap($this->unwrap($stmt), $depth);
    }
}
