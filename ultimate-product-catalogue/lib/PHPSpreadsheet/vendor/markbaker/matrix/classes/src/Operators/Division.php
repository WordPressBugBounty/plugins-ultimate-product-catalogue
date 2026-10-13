<?php

namespace EwdUpcpVendor\Matrix\Operators;

use EwdUpcpVendor\Matrix\Div0Exception;
use EwdUpcpVendor\Matrix\Exception;
use EwdUpcpVendor\Matrix\Matrix;
use EwdUpcpVendor\Matrix\Functions;
class Division extends Multiplication
{
    /**
     * Execute the division
     *
     * @param mixed $value The matrix or numeric value to divide the current base value by
     * @throws Exception If the provided argument is not appropriate for the operation
     * @return $this The operation object, allowing multiple divisions to be chained
     **/
    public function execute($value, string $type = 'division') : Operator
    {
        if (\is_array($value)) {
            $value = new Matrix($value);
        }
        if (\is_object($value) && $value instanceof Matrix) {
            $value = Functions::inverse($value, $type);
            return $this->multiplyMatrix($value, $type);
        } elseif (\is_numeric($value)) {
            return $this->multiplyScalar(1 / $value, $type);
        }
        throw new Exception('Invalid argument for division');
    }
}
