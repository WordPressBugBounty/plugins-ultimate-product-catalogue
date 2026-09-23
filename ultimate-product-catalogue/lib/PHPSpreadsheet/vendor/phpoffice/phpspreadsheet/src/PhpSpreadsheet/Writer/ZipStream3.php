<?php

namespace PhpOffice\PhpSpreadsheet\Writer;

use ZipStream\Option\Archive;
use ZipStream\ZipStream;

class ZipStream3
{
    /**
     * @param resource $fileHandle
     */
    public static function newZipStream($fileHandle): ZipStream
    {
        // PhpSpreadsheet 1.30.6's upstream adapter uses PHP 8 named
        // arguments here even though the package supports PHP 7.4 when
        // ZipStream 2 is selected. Build the ZipStream 3 argument list by
        // parameter name so the complete plugin archive remains parseable on
        // PHP 7.4 while preserving the same values when ZipStream 3 is used.
        $overrides = [
            'enableZip64' => false,
            'outputStream' => $fileHandle,
            'sendHttpHeaders' => false,
            'defaultEnableZeroHeader' => false,
        ];
        $reflection = new \ReflectionClass(ZipStream::class);
        $constructor = $reflection->getConstructor();
        $arguments = [];

        foreach ($constructor->getParameters() as $parameter) {
            if (array_key_exists($parameter->getName(), $overrides)) {
                $arguments[] = $overrides[$parameter->getName()];
            } elseif ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
            } else {
                throw new \RuntimeException('Unsupported ZipStream constructor contract.');
            }
        }

        return $reflection->newInstanceArgs($arguments);
    }
}
