<?php

namespace EwdUpcpVendor\Matrix\Decomposition;

use EwdUpcpVendor\Matrix\Exception;
use EwdUpcpVendor\Matrix\Matrix;
class Decomposition
{
    const LU = 'LU';
    const QR = 'QR';
    /**
     * @throws Exception
     */
    public static function decomposition($type, Matrix $matrix)
    {
        switch (\strtoupper($type)) {
            case self::LU:
                return new LU($matrix);
            case self::QR:
                return new QR($matrix);
            default:
                throw new Exception('Invalid Decomposition');
        }
    }
}
