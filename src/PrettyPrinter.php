<?php

declare(strict_types=1);

namespace TypeLang\Printer;

use TypeLang\Parser\Node\Node;
use TypeLang\Parser\Node\Stmt\Literal\LiteralNode;
use TypeLang\Parser\Node\Stmt\Type\Callable\ArgumentNodeInterface;
use TypeLang\Parser\Node\Stmt\Type\Callable\NamedArgumentNode;
use TypeLang\Parser\Node\Stmt\Type\Callable\OptionalArgumentNode;
use TypeLang\Parser\Node\Stmt\Type\Callable\OutArgumentNode;
use TypeLang\Parser\Node\Stmt\Type\Callable\VariadicArgumentNode;
use TypeLang\Parser\Node\Stmt\Type\CallableTypeNode;
use TypeLang\Parser\Node\Stmt\Type\ClassConstMaskNode;
use TypeLang\Parser\Node\Stmt\Type\ClassConstNode;
use TypeLang\Parser\Node\Stmt\Type\ConstMaskNode;
use TypeLang\Parser\Node\Stmt\Type\IntersectionTypeNode;
use TypeLang\Parser\Node\Stmt\Type\LogicalTypeNode;
use TypeLang\Parser\Node\Stmt\Type\NamedTypeNode;
use TypeLang\Parser\Node\Stmt\Type\Shape\FieldNodeInterface;
use TypeLang\Parser\Node\Stmt\Type\Shape\FieldsListNode;
use TypeLang\Parser\Node\Stmt\Type\Shape\NamedFieldNode;
use TypeLang\Parser\Node\Stmt\Type\Shape\NumericFieldNode;
use TypeLang\Parser\Node\Stmt\Type\Shape\OptionalFieldNode;
use TypeLang\Parser\Node\Stmt\Statement;
use TypeLang\Parser\Node\Stmt\Type\Template\ParameterNode;
use TypeLang\Parser\Node\Stmt\Type\Template\ParametersListNode;
use TypeLang\Parser\Node\Stmt\Type\TypeStatement;
use TypeLang\Parser\Node\Stmt\Type\UnionTypeNode;
use TypeLang\Parser\Traverser;

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
     * @return non-empty-string
     */
    public function make(Statement $stmt): string
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
            default => throw new \InvalidArgumentException(
                \sprintf('Non-printable node "%s"', $stmt::class),
            ),
        };
    }

    protected function printClassConstNode(ClassConstNode $node): string
    {
        return \vsprintf('%s::%s', [
            $node->class->toString(),
            $node->constant,
        ]);
    }

    /**
     * @return non-empty-string
     */
    protected function printClassConstMaskNode(ClassConstMaskNode $node): string
    {
        return \vsprintf('%s::%s', [
            $node->class->toString(),
            (string)$node->constant . '*',
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
    protected function printCallableArgumentNode(ArgumentNodeInterface $node): string
    {
        return match (true) {
            $node instanceof OptionalArgumentNode
                => $this->printCallableArgumentNode($node->of) . '=',
            $node instanceof NamedArgumentNode
                => $this->printCallableArgumentNode($node->of) . $node->name->getRawValue(),
            $node instanceof OutArgumentNode
                => \rtrim($this->printCallableArgumentNode($node->of)) . '& ',
            $node instanceof VariadicArgumentNode
                => $this->printCallableArgumentNode($node->of) . '...',
            default => $this->make($node->getType()) . ' ',
        };
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
        return $node->getRawValue();
    }

    /**
     * @return non-empty-string
     */
    protected function printNamedTypeNode(NamedTypeNode $node): string
    {
        $result = $node->name->toString();

        if ($node->parameters !== null) {
            $result .= $this->printTemplateParametersNode($node->parameters);
        }

        if ($node->fields !== null) {
            $result .= $this->printShapeFieldsNode($node->fields);
        }

        return $result;
    }

    /**
     * @return non-empty-string
     */
    protected function printTemplateParametersNode(ParametersListNode $params): string
    {
        $result = [];

        foreach ($params->list as $param) {
            $result[] = $this->printTemplateParameterNode($param);
        }

        return \sprintf('<%s>', \implode(', ', $result));
    }

    /**
     * @return non-empty-string
     */
    protected function printTemplateParameterNode(ParameterNode $param): string
    {
        return $this->make($param->value);
    }

    /**
     * @return non-empty-string
     */
    protected function printShapeFieldsNode(FieldsListNode $shape): string
    {
        $fields = $this->nested(function () use ($shape): array {
            $prefix = $this->newLine . $this->prefix();
            $fields = [];

            foreach ($shape->list as $field) {
                $fields[] = $prefix . $this->printShapeFieldNode($field);
            }

            if (!$shape->sealed) {
                $fields[] = $prefix . '...';
            }

            /** @var list<non-empty-string> */
            return $fields;
        });

        return \vsprintf('{%s%s}', [
            \implode(',', $fields),
            $this->newLine . $this->prefix(),
        ]);
    }

    /**
     * @return non-empty-string
     */
    protected function printShapeFieldNode(FieldNodeInterface $field): string
    {
        $fieldName = $this->printShapeFieldName($field);

        if ($fieldName !== '') {
            return \vsprintf('%s: %s', [
                $fieldName,
                $this->make($field->getValue()),
            ]);
        }

        return $this->make($field->getValue());
    }

    /**
     * @return non-empty-string
     */
    protected function printShapeFieldName(FieldNodeInterface $field): string
    {
        return match (true) {
            $field instanceof OptionalFieldNode
                => $this->printShapeFieldName($field->of) . '?',
            $field instanceof NumericFieldNode
                => $this->printShapeFieldName($field->of) . $field->index->getRawValue(),
            $field instanceof NamedFieldNode
                => $this->printShapeFieldName($field->of) . $field->name->getRawValue(),
            default => '',
        };
    }
}
