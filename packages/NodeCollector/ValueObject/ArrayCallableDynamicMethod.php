<?php

declare(strict_types=1);

namespace Rector\NodeCollector\ValueObject;

use PhpParser\Node\Expr;

final class ArrayCallableDynamicMethod
{
    public function __construct(
        private Expr $callerExpr,
        private string $class,
        private Expr $method
    ) {
    }

    public function getClass(): string
    {
        return $this->class;
    }

    public function getMethod(): Expr
    {
        return $this->method;
    }

    public function getCallerExpr(): Expr
    {
        return $this->callerExpr;
    }
}
