<?php

namespace EwdUpcpVendor\PhpOffice\PhpSpreadsheet\Writer;

use EwdUpcpVendor\ZipStream\Option\Archive;
use EwdUpcpVendor\ZipStream\ZipStream;
class ZipStream2
{
    /**
     * @param resource $fileHandle
     */
    public static function newZipStream($fileHandle) : ZipStream
    {
        $options = new Archive();
        $options->setEnableZip64(\false);
        $options->setOutputStream($fileHandle);
        return new ZipStream(null, $options);
    }
}
