<?php

declare(strict_types=1);

namespace Rector\NodeCollector\NodeCollector;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\TypeWithClassName;
use Rector\Core\Exception\ShouldNotHappenException;
use Rector\NodeNameResolver\NodeNameResolver;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\NodeTypeResolver\NodeTypeResolver;
use ReflectionMethod;

/**
 * This service contains all the parsed nodes. E.g. all the functions, method call, classes, static calls etc. It's
 * useful in case of context analysis, e.g. find all the usage of class method to detect, if the method is used.
 */
final class NodeRepository
{
    /**
     * @var array<class-string, ClassMethod[]>
     */
    private array $classMethodsByType = [];

    public function __construct(
        private ParsedPropertyFetchNodeCollector $parsedPropertyFetchNodeCollector,
        private NodeNameResolver $nodeNameResolver,
        private ParsedNodeCollector $parsedNodeCollector,
        private ReflectionProvider $reflectionProvider,
        private NodeTypeResolver $nodeTypeResolver
    ) {
    }

    public function collect(Node $node): void
    {
        if ($node instanceof ClassMethod) {
            $this->addMethod($node);
        }
    }

    public function findClassMethod(string $className, string $methodName): ?ClassMethod
    {
        if (\str_contains($methodName, '\\')) {
            $message = sprintf('Class and method arguments are switched in "%s"', __METHOD__);
            throw new ShouldNotHappenException($message);
        }

        if (isset($this->classMethodsByType[$className][$methodName])) {
            return $this->classMethodsByType[$className][$methodName];
        }

        if (! $this->reflectionProvider->hasClass($className)) {
            return null;
        }

        $classReflection = $this->reflectionProvider->getClass($className);
        foreach ($classReflection->getParents() as $parentClassReflection) {
            if (isset($this->classMethodsByType[$parentClassReflection->getName()][$methodName])) {
                return $this->classMethodsByType[$parentClassReflection->getName()][$methodName];
            }
        }

        return null;
    }

    /**
     * @param MethodReflection|ReflectionMethod $methodReflection
     */
    public function findClassMethodByMethodReflection(object $methodReflection): ?ClassMethod
    {
        $methodName = $methodReflection->getName();

        $declaringClass = $methodReflection->getDeclaringClass();
        $className = $declaringClass->getName();

        return $this->findClassMethod($className, $methodName);
    }

    /**
     * @return PropertyFetch[]
     */
    public function findPropertyFetchesByProperty(Property $property): array
    {
        /** @var string|null $className */
        $className = $property->getAttribute(AttributeKey::CLASS_NAME);
        if ($className === null) {
            return [];
        }

        $propertyName = $this->nodeNameResolver->getName($property);
        return $this->parsedPropertyFetchNodeCollector->findPropertyFetchesByTypeAndName($className, $propertyName);
    }

    /**
     * @return PropertyFetch[]
     */
    public function findPropertyFetchesByPropertyFetch(PropertyFetch $propertyFetch): array
    {
        $propertyFetcheeType = $this->nodeTypeResolver->getStaticType($propertyFetch->var);
        if (! $propertyFetcheeType instanceof TypeWithClassName) {
            return [];
        }

        $className = $this->nodeTypeResolver->getFullyQualifiedClassName($propertyFetcheeType);

        /** @var string $propertyName */
        $propertyName = $this->nodeNameResolver->getName($propertyFetch);

        return $this->parsedPropertyFetchNodeCollector->findPropertyFetchesByTypeAndName($className, $propertyName);
    }

    public function hasClassChildren(Class_ $desiredClass): bool
    {
        $desiredClassName = $desiredClass->getAttribute(AttributeKey::CLASS_NAME);
        if ($desiredClassName === null) {
            return false;
        }

        foreach ($this->parsedNodeCollector->getClasses() as $classNode) {
            $currentClassName = $classNode->getAttribute(AttributeKey::CLASS_NAME);
            if ($currentClassName === null) {
                continue;
            }

            if (! $this->isChildOrEqualClassLike($desiredClassName, $currentClassName)) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * @return Trait_[]
     */
    public function findUsedTraitsInClass(ClassLike $classLike): array
    {
        $traits = [];

        foreach ($classLike->getTraitUses() as $traitUse) {
            foreach ($traitUse->traits as $trait) {
                $traitName = $this->nodeNameResolver->getName($trait);
                $foundTrait = $this->parsedNodeCollector->findTrait($traitName);
                if ($foundTrait !== null) {
                    $traits[] = $foundTrait;
                }
            }
        }

        return $traits;
    }

    /**
     * @return Class_[]|Interface_[]
     */
    public function findClassesAndInterfacesByType(string $type): array
    {
        return array_merge($this->findChildrenOfClass($type), $this->findImplementersOfInterface($type));
    }

    /**
     * @return Class_[]
     */
    public function findChildrenOfClass(string $class): array
    {
        $childrenClasses = [];

        foreach ($this->parsedNodeCollector->getClasses() as $classNode) {
            $currentClassName = $classNode->getAttribute(AttributeKey::CLASS_NAME);
            if (! $this->isChildOrEqualClassLike($class, $currentClassName)) {
                continue;
            }

            $childrenClasses[] = $classNode;
        }

        return $childrenClasses;
    }

    public function findInterface(string $class): ?Interface_
    {
        return $this->parsedNodeCollector->findInterface($class);
    }

    public function findClass(string $name): ?Class_
    {
        return $this->parsedNodeCollector->findClass($name);
    }

    /**
     * @param PropertyFetch|StaticPropertyFetch $expr
     */
    public function findPropertyByPropertyFetch(Expr $expr): ?Property
    {
        $propertyCaller = $expr instanceof StaticPropertyFetch ? $expr->class : $expr->var;

        $propertyCallerType = $this->nodeTypeResolver->getStaticType($propertyCaller);
        if (! $propertyCallerType instanceof TypeWithClassName) {
            return null;
        }

        $className = $this->nodeTypeResolver->getFullyQualifiedClassName($propertyCallerType);
        $class = $this->findClass($className);
        if (! $class instanceof Class_) {
            return null;
        }

        $propertyName = $this->nodeNameResolver->getName($expr->name);
        if ($propertyName === null) {
            return null;
        }

        return $class->getProperty($propertyName);
    }

    public function findTrait(string $name): ?Trait_
    {
        return $this->parsedNodeCollector->findTrait($name);
    }

    public function findClassLike(string $classLikeName): ?ClassLike
    {
        return $this->findClass($classLikeName) ?? $this->findInterface($classLikeName) ?? $this->findTrait(
            $classLikeName
        );
    }

    private function addMethod(ClassMethod $classMethod): void
    {
        $className = $classMethod->getAttribute(AttributeKey::CLASS_NAME);

        // anonymous
        if ($className === null) {
            return;
        }

        $methodName = $this->nodeNameResolver->getName($classMethod);
        $this->classMethodsByType[$className][$methodName] = $classMethod;
    }

    private function isChildOrEqualClassLike(string $desiredClass, ?string $currentClassName): bool
    {
        if ($currentClassName === null) {
            return false;
        }

        if (! $this->reflectionProvider->hasClass($desiredClass)) {
            return false;
        }

        if (! $this->reflectionProvider->hasClass($currentClassName)) {
            return false;
        }

        $desiredClassReflection = $this->reflectionProvider->getClass($desiredClass);
        $currentClassReflection = $this->reflectionProvider->getClass($currentClassName);

        if (! $currentClassReflection->isSubclassOf($desiredClassReflection->getName())) {
            return false;
        }
        return $currentClassName !== $desiredClass;
    }

    /**
     * @return Interface_[]
     */
    private function findImplementersOfInterface(string $interface): array
    {
        $implementerInterfaces = [];

        foreach ($this->parsedNodeCollector->getInterfaces() as $interfaceNode) {
            $className = $interfaceNode->getAttribute(AttributeKey::CLASS_NAME);

            if (! $this->isChildOrEqualClassLike($interface, $className)) {
                continue;
            }

            $implementerInterfaces[] = $interfaceNode;
        }

        return $implementerInterfaces;
    }
}
