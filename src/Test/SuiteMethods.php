<?php

declare(strict_types=1);

namespace ThriveData\ThrivePHP\Test;

/** @internal Discovers effective annotated methods and orders their lifecycle phases. */
final class SuiteMethods
{
    /** @return array<class-string, list<array{method: \ReflectionMethod, name: string, order: array}>> */
    public static function discover(Suite $suite): array
    {
        $class = new \ReflectionObject($suite);
        $depths = [];
        for ($ancestor = $class; $ancestor; $ancestor = $ancestor->getParentClass()) {
            $depths[$ancestor->getName()] = count($depths);
        }

        $attributes = [SuiteBefore::class, SuiteAfter::class, CaseBefore::class, CaseAfter::class, CaseName::class];
        $methods = [];
        foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $annotated = false;
            foreach ($attributes as $attribute) {
                if ($method->getAttributes($attribute)) {
                    $annotated = true;
                    break;
                }
            }
            if (!$annotated) {
                continue;
            }
            $origin = self::origin($method);
            $key = $origin->getDeclaringClass()->getName() . '::' . strtolower($origin->getName());
            $original = strcasecmp($method->getName(), $origin->getName()) === 0;
            $rank = [$original ? 0 : 1, $method->getName()];
            if (!isset($methods[$key]) || $rank < $methods[$key]['rank']) {
                $methods[$key] = ['method' => $method, 'origin' => $origin, 'rank' => $rank];
            }
        }

        $phases = [];
        foreach ($attributes as $attribute) {
            $selected = [];
            foreach ($methods as ['method' => $method, 'origin' => $origin]) {
                foreach ($method->getAttributes($attribute) as $metadata) {
                    $instance = $metadata->newInstance();
                    $depth = $depths[$method->getDeclaringClass()->getName()];
                    $trait = $origin->getDeclaringClass()->isTrait();
                    $selected[] = [
                        'method' => $method,
                        'name' => $instance instanceof CaseName ? $instance->name : $method->getName(),
                        'order' => [
                            in_array($attribute, [SuiteAfter::class, CaseAfter::class], true) ? $depth : -$depth,
                            $trait ? 1 : 0,
                            $trait ? $origin->getDeclaringClass()->getName() : '',
                            $origin->getStartLine(),
                            $method->getName(),
                        ],
                    ];
                }
            }
            usort($selected, static fn (array $a, array $b) => $a['order'] <=> $b['order']);
            $phases[$attribute] = $selected;
        }
        return $phases;
    }

    /** Resolve aliases and nested imports to the original trait method, retaining its name. */
    private static function origin(\ReflectionMethod $method): \ReflectionMethod
    {
        $class = $method->getDeclaringClass();
        $alias = $class->getTraitAliases()[$method->getName()] ?? null;
        if ($alias !== null) {
            [$trait, $name] = explode('::', $alias);
            $candidate = new \ReflectionMethod($trait, $name);
            if (self::sameSource($method, $candidate)) {
                self::checkSourceOverlap($class, $candidate);
                return self::origin($candidate);
            }
        }
        $origins = [];
        foreach ($class->getTraits() as $trait) {
            if ($trait->hasMethod($method->getName())) {
                $candidate = $trait->getMethod($method->getName());
                if (self::sameSource($method, $candidate)) {
                    self::checkSourceOverlap($class, $candidate);
                    $origin = self::origin($candidate);
                    $origins[$origin->getDeclaringClass()->getName() . '::' . $origin->getName()] = $origin;
                }
            }
        }
        if (count($origins) > 1) {
            throw new \LogicException('Ambiguous trait method origin for ' . $class->getName() . '::' . $method->getName()
                . ': ' . implode(', ', array_keys($origins)) . '. Use distinct method names or separate source lines.');
        }
        return $origins ? reset($origins) : $method;
    }

    /** Reflection cannot distinguish an import from an override on the same source line. */
    private static function checkSourceOverlap(\ReflectionClass $class, \ReflectionMethod $traitMethod): void
    {
        if ($class->getFileName() === $traitMethod->getFileName()
            && $traitMethod->getStartLine() >= $class->getStartLine()
            && $traitMethod->getStartLine() <= $class->getEndLine()) {
            throw new \LogicException('Ambiguous trait method origin for ' . $class->getName() . '::' . $traitMethod->getName()
                . ': the consuming class and trait method share source lines. Put their declarations on separate lines.');
        }
    }

    /** Source coordinates select the imported implementation after name/alias resolution. */
    private static function sameSource(\ReflectionMethod $a, \ReflectionMethod $b): bool
    {
        return $a->getFileName() === $b->getFileName()
            && $a->getStartLine() === $b->getStartLine()
            && $a->getEndLine() === $b->getEndLine();
    }
}
