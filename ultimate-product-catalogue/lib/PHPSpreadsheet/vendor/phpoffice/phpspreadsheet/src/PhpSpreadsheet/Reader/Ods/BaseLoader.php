<?php

namespace EwdUpcpVendor\PhpOffice\PhpSpreadsheet\Reader\Ods;

use DOMElement;
use EwdUpcpVendor\PhpOffice\PhpSpreadsheet\Spreadsheet;
abstract class BaseLoader
{
    /**
     * @var Spreadsheet
     */
    protected $spreadsheet;
    /**
     * @var string
     */
    protected $tableNs;
    public function __construct(Spreadsheet $spreadsheet, string $tableNs)
    {
        $this->spreadsheet = $spreadsheet;
        $this->tableNs = $tableNs;
    }
    public abstract function read(DOMElement $workbookData) : void;
}
