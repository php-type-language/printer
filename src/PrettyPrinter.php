<?php

declare(strict_types=1);

namespace TypeLang\Printer;

use TypeLang\Parser\Node\Node;
use TypeLang\Parser\Node\Literal\LiteralNode;
use TypeLang\Parser\Node\Stmt\Callable\ArgumentNode;
use TypeLang\Parser\Node\Stmt\CallableTypeNode;
use TypeLang\Parser\Node\Stmt\ClassConstMaskNode;
use TypeLang\Parser\Node\Stmt\ClassConstNode;
use TypeLang\Parser\Node\Stmt\ConstMaskNode;
use TypeLang\Parser\Node\Stmt\IntersectionTypeNode;
use TypeLang\Parser\Node\Stmt\LogicalTypeNode;
use TypeLang\Parser\Node\Stmt\NamedTypeNode;
use TypeLang\Parser\Node\Stmt\NullableTypeNode;
use TypeLang\Parser\Node\Stmt\Shape\FieldNode;
use TypeLang\Parser\Node\Stmt\Shape\FieldsListNode;
use TypeLang\Parser\Node\Stmt\Shape\NamedFieldNode;
use TypeLang\Parser\Node\Stmt\Shape\NumericFieldNode;
use TypeLang\Parser\Node\Statement;
use TypeLang\Parser\Node\Stmt\Shape\StringNamedFieldNode;
use TypeLang\Parser\Node\Stmt\Template\ArgumentNode as TemplateArgumentNode;
use TypeLang\Parser\Node\Stmt\Template\ArgumentsListNode as TemplateArgumentsListNode;
use TypeLang\Parser\Node\Stmt\TypeStatement;
use TypeLang\Parser\Node\Stmt\UnionTypeNode;
use TypeLang\Parser\Traverser;
use TypeLang\Printer\Exception\NonPrintableNodeException;

class PrettyPrinter extends Printer
{
    /**
     * Wrap union type (joined by "|") by whitespaces.
     *
     * ```
     * $wrapUnionType = true;
     * // Type | Some | Any
     *
     * $wrapUnionType = false;
     * // Type|Some|Any
     * ```
     */
    public bool $wrapUnionType = false;

    /**
     * Wrap intersection type (joined by "&") by whitespaces.
     *
     * ```
     *  $wrapIntersectionType = true;
     *  // Type & Some & Any
     *
     *  $wrapIntersectionType = false;
     *  // Type&Some&Any
     *  ```
     */
    public bool $wrapIntersectionType = true;

    /**
     * Add whitespace at the start of callable return type.
     *
     * ```
     * $wrapCallableReturnType = true;
     * // callable(): void
     *
     * $wrapCallableReturnType = false;
     * // callable():void
     * ```
     */
    public bool $wrapCallableReturnType = true;

    /**
     * The number of elements in the shape after which it is
     * formatted as multiline.
     *
     * ```
     * $multilineShape = 2;
     * // array{some, any}
     *
     * $multilineShape = 1;
     * // array{
     * //     some,
     * //     any
     * // }
     * ```
     *
     * @var int<0, max>
     */
    public int $multilineShape = 1;

    /**
     * @return non-empty-string
     */
    protected function make(Statement $stmt): string
    {
        return match (true) {
            $stmt instanceof LiteralNode => $this->printLiteralNode($stmt),
            $stmt instanceof NamedTypeNode => $this->printNamedTypeNode($stmt),
            $stmt instanceof ClassConstNode => $this->printClassConstNode($stmt),
            $stmt instanceof ClassConstMaskNode => $this->printClassConstMaskNode($stmt),
            $stmt instanceof ConstMaskNode => $this->printConstMaskNode($stmt),
            $stmt instanceof CallableTypeNode => $this->printCallableTypeNode($stmt),
            $stmt instanceof UnionTypeNode => $this->printUnionTypeNode($stmt),
            $stmt instanceof IntersectionTypeNode => $this->printIntersectionTypeNode($stmt),
            $stmt instanceof NullableTypeNode => $this->printNullableType($stmt),
            default => throw NonPrintableNodeException::fromInvalidNode($stmt),
        };
    }

    /**
     * @return non-empty-string
     */
    protected function printNullableType(NullableTypeNode $node): string
    {
        return '?' . $this->make($node->type);
    }

    /**
     * @return non-empty-string
     */
    protected function printClassConstNode(ClassConstNode $node): string
    {
        /** @var non-empty-string */
        return \vsprintf('%s::%s', [
            $node->class->toString(),
            (string)$node->constant?->toString(),
        ]);
    }

    /**
     * @return non-empty-string
     */
    protected function printClassConstMaskNode(ClassConstMaskNode $node): string
    {
        /** @var non-empty-string */
        return \vsprintf('%s::%s', [
            $node->class->toString(),
            (string)($node->constant?->toString()) . '*',
        ]);
    }

    /**
     * @return non-empty-string
     */
    protected function printConstMaskNode(ConstMaskNode $node): string
    {
        return $node->name->toString() . '*';
    }

    /**
     * @return non-empty-string
     */
    protected function printCallableTypeNode(CallableTypeNode $node): string
    {
        $result = $node->name->toString();

        $arguments = [];

        foreach ($node->arguments as $argument) {
            $arguments[] = \rtrim($this->printCallableArgumentNode($argument));
        }

        // Add arguments
        $result .= \sprintf('(%s)', \implode(', ', $arguments));

        // Add return type
        if ($node->type !== null) {
            $returnType = $this->make($node->type);

            if ($this->shouldWrapReturnType($node->type)) {
                $returnType = \sprintf('(%s)', $returnType);
            }

            $returnTypeFormat = $this->wrapCallableReturnType ? ': %s' : ':%s';
            $result .= \sprintf($returnTypeFormat, $returnType);
        }

        return $result;
    }

    protected function shouldWrapReturnType(TypeStatement $type): bool
    {
        if ($type instanceof LogicalTypeNode) {
            return true;
        }

        $visitor = Traverser::through(
            visitor: new Traverser\ClassNameMatcherVisitor(
                class: LogicalTypeNode::class,
                break: static function (Node $node): bool {
                    // Break on non-empty template parameters.
                    $isInTemplate = $node instanceof NamedTypeNode
                        && $node->parameters !== null
                        && $node->parameters->list !== [];

                    // Break on non-empty shape fields.
                    $isInShape = $node instanceof NamedTypeNode
                        && $node->fields !== null
                        && $node->fields->list !== [];

                    return $isInTemplate || $isInShape;
                },
            ),
            nodes: [$type],
        );

        return $visitor->isFound();
    }

    /**
     * @return non-empty-string
     */
    protected function printCallableArgumentNode(ArgumentNode $node): string
    {
        $type = $this->make($node->type);

        if ($node->type instanceof LogicalTypeNode) {
            $type = \sprintf('(%s)', $type);
        }

        $result = [$type];

        if ($node->name !== null) {
            $result[] = ' ';
        }

        if ($node->output) {
            $result[] = '&';
        }

        if ($node->variadic) {
            $result[] = '...';
        }

        if ($node->name !== null) {
            $result[] = $this->printLiteralNode($node->name);
        }

        if ($node->optional) {
            $result[] = '=';
        }

        return \implode('', $result);
    }

    /**
     * @return non-empty-string
     */
    protected function printUnionTypeNode(UnionTypeNode $node): string
    {
        $delimiter = $this->wrapUnionType ? ' | ' : '|';

        try {
            /** @var non-empty-string */
            return \vsprintf($this->nesting > 0 ? '(%s)' : '%s', [
                \implode($delimiter, [
                    ...$this->unwrapAndPrint($node),
                ]),
            ]);
        } finally {
            ++$this->nesting;
        }
    }

    /**
     * @return non-empty-string
     */
    protected function printIntersectionTypeNode(IntersectionTypeNode $node): string
    {
        $delimiter = $this->wrapIntersectionType ? ' & ' : '&';

        try {
            /** @var non-empty-string */
            return \vsprintf($this->nesting > 0 ? '(%s)' : '%s', [
                \implode($delimiter, [
                    ...$this->unwrapAndPrint($node),
                ]),
            ]);
        } finally {
            ++$this->nesting;
        }
    }

    /**
     * @return non-empty-string
     */
    protected function printLiteralNode(LiteralNode $node): string
    {
        /** @var non-empty-string */
        return $node->getRawValue();
    }

    /**
     * @return non-empty-string
     */
    protected function printNamedTypeNode(NamedTypeNode $node): string
    {
        $result = $node->name->toString();

        if ($node->arguments !== null) {
            $result .= $this->printTemplateArgumentsNode($node->arguments);
        }

        if ($node->fields !== null) {
            $result .= $this->printShapeFieldsNode($node->fields);
        }

        /** @var non-empty-string */
        return $result;
    }

    /**
     * @return non-empty-string
     */
    protected function printTemplateArgumentsNode(TemplateArgumentsListNode $params): string
    {
        $result = [];

        foreach ($params->list as $param) {
            $result[] = $this->printTemplateArgumentNode($param);
        }

        /** @var non-empty-string */
        return \sprintf('<%s>', \implode(', ', $result));
    }

    /**
     * @return non-empty-string
     */
    protected function printTemplateArgumentNode(TemplateArgumentNode $param): string
    {
        return $this->make($param->value);
    }

    /**
     * @return non-empty-string
     */
    protected function printShapeFieldsNode(FieldsListNode $shape): string
    {
        if (\count($shape->list) <= $this->multilineShape) {
            /** @var non-empty-string */
            return \vsprintf('{%s}', [
                \implode(', ', $this->getShapeFieldsNodes($shape)),
            ]);
        }

        /** @var non-empty-string */
        return \vsprintf('{%s%s%s}', [
            $this->newLine,
            \implode(',' . $this->newLine, $this->nested(fn(): array => $this->getShapeFieldsNodes($shape))),
            $this->newLine . $this->prefix(),
        ]);
    }

    /**
     * @return list<non-empty-string>
     */
    private function getShapeFieldsNodes(FieldsListNode $shape): array
    {
        $prefix = $this->prefix();

        $fields = [];

        foreach ($shape->list as $field) {
            $fields[] = $prefix . $this->printShapeFieldNode($field);
        }

        if (!$shape->sealed) {
            $fields[] = $prefix . '...';
        }

        /** @var list<non-empty-string> */
        return $fields;
    }

    /**
     * @return non-empty-string
     */
    protected function printShapeFieldNode(FieldNode $field): string
    {
        $name = $this->printShapeFieldName($field);

        if ($name !== '') {
            if ($field->optional) {
                $name .= '?';
            }

            /** @var non-empty-string */
            return \vsprintf('%s: %s', [
                $name,
                $this->make($field->getValue()),
            ]);
        }

        return $this->make($field->getValue());
    }

    protected function printShapeFieldName(FieldNode $field): string
    {
        return match (true) {
            $field instanceof StringNamedFieldNode => $field->name->getRawValue(),
            $field instanceof NumericFieldNode => $field->index->getRawValue(),
            $field instanceof NamedFieldNode => $field->name->toString(),
            default => '',
        };
    }
}
