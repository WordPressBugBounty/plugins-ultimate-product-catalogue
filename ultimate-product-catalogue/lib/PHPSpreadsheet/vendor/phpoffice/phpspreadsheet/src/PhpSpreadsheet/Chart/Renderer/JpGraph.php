<?php

namespace EwdUpcpVendor\PhpOffice\PhpSpreadsheet\Chart\Renderer;

/**
 * Jpgraph is not oficially maintained in Composer, so the version there
 * could be out of date. For that reason, all unit test requiring Jpgraph
 * are skipped. So, do not measure code coverage for this class till that
 * is fixed.
 *
 * This implementation uses abandoned package
 * https://packagist.org/packages/jpgraph/jpgraph
 *
 * @codeCoverageIgnore
 */
class JpGraph extends JpGraphRendererBase
{
    protected static function init() : void
    {
        static $loaded = \false;
        if ($loaded) {
            return;
        }
        // JpGraph is no longer included with distribution, but user may install it.
        // So Scrutinizer's complaint that it can't find it is reasonable, but unfixable.
        \EwdUpcpVendor\JpGraph\JpGraph::load();
        \EwdUpcpVendor\JpGraph\JpGraph::module('bar');
        \EwdUpcpVendor\JpGraph\JpGraph::module('contour');
        \EwdUpcpVendor\JpGraph\JpGraph::module('line');
        \EwdUpcpVendor\JpGraph\JpGraph::module('pie');
        \EwdUpcpVendor\JpGraph\JpGraph::module('pie3d');
        \EwdUpcpVendor\JpGraph\JpGraph::module('radar');
        \EwdUpcpVendor\JpGraph\JpGraph::module('regstat');
        \EwdUpcpVendor\JpGraph\JpGraph::module('scatter');
        \EwdUpcpVendor\JpGraph\JpGraph::module('stock');
        $loaded = \true;
    }
}
