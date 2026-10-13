<?php

declare (strict_types=1);
namespace EwdUpcpVendor\Composer\Pcre\PHPStan;

use EwdUpcpVendor\Composer\Pcre\Preg;
use EwdUpcpVendor\PhpParser\Node\Expr\StaticCall;
use EwdUpcpVendor\PHPStan\Analyser\Scope;
use EwdUpcpVendor\PHPStan\Reflection\MethodReflection;
use EwdUpcpVendor\PHPStan\Reflection\ParameterReflection;
use EwdUpcpVendor\PHPStan\TrinaryLogic;
use EwdUpcpVendor\PHPStan\Type\Php\RegexArrayShapeMatcher;
use EwdUpcpVendor\PHPStan\Type\StaticMethodParameterOutTypeExtension;
use EwdUpcpVendor\PHPStan\Type\Type;
final class PregMatchParameterOutTypeExtension implements StaticMethodParameterOutTypeExtension
{
    /**
     * @var RegexArrayShapeMatcher
     */
    private $regexShapeMatcher;
    public function __construct(RegexArrayShapeMatcher $regexShapeMatcher)
    {
        $this->regexShapeMatcher = $regexShapeMatcher;
    }
    public function isStaticMethodSupported(MethodReflection $methodReflection, ParameterReflection $parameter) : bool
    {
        return $methodReflection->getDeclaringClass()->getName() === Preg::class && \in_array($methodReflection->getName(), ['match', 'isMatch', 'matchStrictGroups', 'isMatchStrictGroups', 'matchAll', 'isMatchAll', 'matchAllStrictGroups', 'isMatchAllStrictGroups'], \true) && $parameter->getName() === 'matches';
    }
    public function getParameterOutTypeFromStaticMethodCall(MethodReflection $methodReflection, StaticCall $methodCall, ParameterReflection $parameter, Scope $scope) : ?Type
    {
        $args = $methodCall->getArgs();
        $patternArg = $args[0] ?? null;
        $matchesArg = $args[2] ?? null;
        $flagsArg = $args[3] ?? null;
        if ($patternArg === null || $matchesArg === null) {
            return null;
        }
        $flagsType = PregMatchFlags::getType($flagsArg, $scope);
        if ($flagsType === null) {
            return null;
        }
        if (\stripos($methodReflection->getName(), 'matchAll') !== \false) {
            return $this->regexShapeMatcher->matchAllExpr($patternArg->value, $flagsType, TrinaryLogic::createMaybe(), $scope);
        }
        return $this->regexShapeMatcher->matchExpr($patternArg->value, $flagsType, TrinaryLogic::createMaybe(), $scope);
    }
}
