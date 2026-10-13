<?php

namespace EwdUpcpVendor\PhpOffice\PhpSpreadsheet\Writer;

use EwdUpcpVendor\ZipStream\Option\Archive;
use EwdUpcpVendor\ZipStream\ZipStream;
class ZipStream3
{
    /**
     * @param resource $fileHandle
     */
    public static function newZipStream($fileHandle) : ZipStream
    {
        $reflection = new \ReflectionClass(ZipStream::class);
        $values = ['enableZip64' => false, 'outputStream' => $fileHandle, 'sendHttpHeaders' => false, 'defaultEnableZeroHeader' => false];
        $arguments = [];
        foreach ($reflection->getConstructor()->getParameters() as $parameter) {
            $arguments[] = array_key_exists($parameter->getName(), $values) ? $values[$parameter->getName()] : $parameter->getDefaultValue();
        }
        return $reflection->newInstanceArgs($arguments);
    }
}
