<?php

declare(strict_types=1);

namespace TypeLang\Printer;

use TypeLang\Parser\Node\FullQualifiedName;
use TypeLang\Parser\Node\Literal\LiteralNode;
use TypeLang\Parser\Node\Name;
use TypeLang\Parser\Node\Stmt\Callable\ArgumentNodeInterface;
use TypeLang\Parser\Node\Stmt\Callable\NamedArgumentNode;
use TypeLang\Parser\Node\Stmt\Callable\OptionalArgumentNode;
use TypeLang\Parser\Node\Stmt\Callable\OutArgumentNode;
use TypeLang\Parser\Node\Stmt\Callable\VariadicArgumentNode;
use TypeLang\Parser\Node\Stmt\CallableTypeNode;
use TypeLang\Parser\Node\Stmt\ClassConstMaskNode;
use TypeLang\Parser\Node\Stmt\ConstMaskNode;
use TypeLang\Parser\Node\Stmt\IntersectionTypeNode;
use TypeLang\Parser\Node\Stmt\NamedTypeNode;
use TypeLang\Parser\Node\Stmt\Shape\FieldNodeInterface;
use TypeLang\Parser\Node\Stmt\Shape\FieldsListNode;
use TypeLang\Parser\Node\Stmt\Shape\NamedFieldNode;
use TypeLang\Parser\Node\Stmt\Shape\NumericFieldNode;
use TypeLang\Parser\Node\Stmt\Shape\OptionalFieldNode;
use TypeLang\Parser\Node\Stmt\Statement;
use TypeLang\Parser\Node\Stmt\Template\ParameterNode;
use TypeLang\Parser\Node\Stmt\Template\ParametersListNode;
use TypeLang\Parser\Node\Stmt\UnionTypeNode;

class PrettyPrinter extends Printer
{
    private const DEFAULT_NEW_LINE_DELIMITER = "\n";

    private const DEFAULT_INDENTION = '    ';

    public function __construct(
        private readonly string $newLine = self::DEFAULT_NEW_LINE_DELIMITER,
        private readonly string $indention = self::DEFAULT_INDENTION,
    ) {}

    /**
     * @param int<0, max> $depth
     *
     * @return ($depth > 0 ? non-empty-string : string)
     */
    protected function prefix(int $depth = 0): string
    {
        return \str_repeat($this->indention, $depth);
    }

    /**
     * @param non-empty-string $text
     * @param int<0, max> $depth
     *
     * @return non-empty-string
     */
    protected function prefixed(string $text, int $depth = 0): string
    {
        return $this->prefix($depth) . $text;
    }

    /**
     * @param int<0, max> $depth
     *
     * @return non-empty-string
     */
    public function print(Statement $stmt, int $depth = 0): string
    {
        return match (true) {
            $stmt instanceof LiteralNode => $this->printLiteralNode($stmt, $depth),
            $stmt instanceof NamedTypeNode => $this->printNamedTypeNode($stmt, $depth),
            $stmt instanceof ClassConstMaskNode => $this->printClassConstMaskNode($stmt, $depth),
            $stmt instanceof ConstMaskNode => $this->printConstMaskNode($stmt, $depth),
            $stmt instanceof CallableTypeNode => $this->printCallableTypeNode($stmt, $depth),
            $stmt instanceof UnionTypeNode => $this->printUnionTypeNode($stmt, $depth),
            $stmt instanceof IntersectionTypeNode => $this->printIntersectionTypeNode($stmt, $depth),
            default => throw new \InvalidArgumentException(
                \sprintf('Non-printable node "%s"', $stmt::class),
            ),
        };
    }

    /**
     * @param int<0, max> $depth
     *
     * @return non-empty-string
     */
    protected function printClassConstMaskNode(ClassConstMaskNode $node, int $depth = 0): string
    {
        return \vsprintf('%s::%s', [
            $node->class->toString(),
            (string)$node->constant . '*',
        ]);
    }

    /**
     * @param int<0, max> $depth
     *
     * @return non-empty-string
     */
    protected function printConstMaskNode(ConstMaskNode $node, int $depth = 0): string
    {
        return $node->name->toString() . '*';
    }

    /**
     * @param int<0, max> $depth
     *
     * @return non-empty-string
     */
    protected function printCallableTypeNode(CallableTypeNode $node, int $depth = 0): string
    {
        $result = $node->name->toString();

        $arguments = [];

        foreach ($node->arguments as $argument) {
            $arguments[] = \rtrim($this->printCallableArgumentNode($argument, $depth));
        }

        // Add arguments
        $result .= \sprintf('(%s)', \implode(', ', $arguments));

        // Add return type
        if ($node->type !== null) {
            $returnType = $this->print($node->type, $depth);

            if ($this->shouldWrapReturnType($returnType)) {
                $returnType = \sprintf('(%s)', $returnType);
            }

            $result .= \sprintf(': %s', $returnType);
        }

        return $result;
    }

    /**
     * @param int<0, max> $depth
     *
     * @return non-empty-string
     */
    protected function printCallableArgumentNode(ArgumentNodeInterface $node, int $depth = 0): string
    {
        return match (true) {
            $node instanceof OptionalArgumentNode
                => $this->printCallableArgumentNode($node->of, $depth) . '=',
            $node instanceof NamedArgumentNode
                => $this->printCallableArgumentNode($node->of, $depth) . $node->name->getRawValue(),
            $node instanceof OutArgumentNode
                => \rtrim($this->printCallableArgumentNode($node->of, $depth)) . '& ',
            $node instanceof VariadicArgumentNode
                => $this->printCallableArgumentNode($node->of, $depth) . '...',
            default => $this->print($node->getType(), $depth) . ' ',
        };
    }

    private function shouldWrapReturnType(string $value): bool
    {
        // Should return "false" in case of return type already
        // has been wrapped by round brackets.
        $isAlreadyWrapped = \str_starts_with($value, '(')
            && \str_ends_with($value, ')');

        if ($isAlreadyWrapped) {
            return false;
        }

        // Should return "true" in case of return type contains
        // union or intersection types.
        return \str_contains($value, '|')
            || \str_contains($value, '&');
    }

    /**
     * @param int<0, max> $depth
     *
     * @return non-empty-string
     */
    protected function printUnionTypeNode(UnionTypeNode $node, int $depth = 0): string
    {
        return \vsprintf('(%s)', [
            \implode(' | ', [
                ...$this->unwrapAndPrint($node, $depth),
            ]),
        ]);
    }

    /**
     * @param int<0, max> $depth
     *
     * @return non-empty-string
     */
    protected function printIntersectionTypeNode(IntersectionTypeNode $node, int $depth = 0): string
    {
        return \vsprintf('(%s)', [
            \implode(' & ', [
                ...$this->unwrapAndPrint($node, $depth),
            ]),
        ]);
    }

    /**
     * @param int<0, max> $depth
     *
     * @return non-empty-string
     */
    protected function printLiteralNode(LiteralNode $node, int $depth = 0): string
    {
        return $node->getRawValue();
    }

    /**
     * @param int<0, max> $depth
     *
     * @return non-empty-string
     */
    protected function printNamedTypeNode(NamedTypeNode $node, int $depth = 0): string
    {
        $result = $node->name->toString();

        if ($node->parameters !== null) {
            $result .= $this->printTemplateParametersNode($node->parameters, $depth);
        }

        if ($node->fields !== null) {
            $result .= $this->printShapeFieldsNode($node->fields, $depth);
        }

        return $result;
    }

    /**
     * @param int<0, max> $depth
     *
     * @return non-empty-string
     */
    protected function printTemplateParametersNode(ParametersListNode $params, int $depth = 0): string
    {
        $result = [];

        foreach ($params->list as $param) {
            $result[] = $this->printTemplateParameterNode($param, $depth);
        }

        return \sprintf('<%s>', \implode(', ', $result));
    }

    /**
     * @param int<0, max> $depth
     *
     * @return non-empty-string
     */
    protected function printTemplateParameterNode(ParameterNode $param, int $depth = 0): string
    {
        return $this->print($param->value, $depth);
    }

    /**
     * @param int<0, max> $depth
     *
     * @return non-empty-string
     */
    protected function printShapeFieldsNode(FieldsListNode $shape, int $depth = 0): string
    {
        $fields = [];

        $prefix = $this->newLine . $this->prefix($depth + 1);

        foreach ($shape->list as $field) {
            $fields[] = $prefix . $this->printShapeFieldNode($field, $depth + 1);
        }

        if (!$shape->sealed) {
            $fields[] = $prefix . '...';
        }

        return \vsprintf('{%s%s}', [
            \implode(',', $fields),
            $this->newLine . $this->prefix($depth),
        ]);
    }

    /**
     * @param int<0, max> $depth
     *
     * @return non-empty-string
     */
    protected function printShapeFieldNode(FieldNodeInterface $field, int $depth = 0): string
    {
        $fieldName = $this->printShapeFieldName($field, $depth);

        if ($fieldName !== '') {
            return \vsprintf('%s: %s', [
                $fieldName,
                $this->print($field->getValue(), $depth),
            ]);
        }

        return $this->print($field->getValue(), $depth);
    }

    /**
     * @param int<0, max> $depth
     *
     * @return non-empty-string
     */
    private function printShapeFieldName(FieldNodeInterface $field, int $depth = 0): string
    {
        return match (true) {
            $field instanceof OptionalFieldNode
                => $this->printShapeFieldName($field->of, $depth) . '?',
            $field instanceof NumericFieldNode
                => $this->printShapeFieldName($field->of, $depth) . $field->index->getRawValue(),
            $field instanceof NamedFieldNode
                => $this->printShapeFieldName($field->of, $depth) . $field->name->getRawValue(),
            default => '',
        };
    }
}
