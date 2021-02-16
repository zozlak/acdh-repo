<?php

/*
 * The MIT License
 *
 * Copyright 2020 Austrian Centre for Digital Humanities.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

namespace acdhOeaw\acdhRepo;

use EasyRdf\Graph;
use EasyRdf\Resource;
use zozlak\RdfConstants as RDF;
use acdhOeaw\acdhRepo\RestController as RC;

/**
 * Provides simple HTML serialization of a resource metadata
 *
 * @author zozlak
 */
class MetadataGui {

    const TMPL = <<<TMPL
<!DOCTYPE html>
<html>
    <head>
        <meta charset="UTF-8"/>
        <title>%s</title>
        <style>
            body {font-family: monospace;}
            .n   {padding-left: 0rem; font-weight: normal;}
            .s   {padding-left: 0rem; font-weight: bold;}
            .p   {padding-left: 4rem; font-style: normal;}
            .o   {padding-left: 8rem; font-style: normal;}
            .tl  {font-style: italic;}
            .p > a {text-decoration: none; color: inherit;}
        </style>
    </head>
    <body>

TMPL;

    /**
     *
     * @var \EasyRdf\Graph
     */
    private $graph;

    /**
     *
     * @var \EasyRdf\Resource
     */
    private $res;

    /**
     *
     * @var array<string, string>
     */
    private $nmsp;

    public function __construct(Graph $graph, string $resource) {
        $this->graph = $graph;
        $this->res   = $this->graph->resource($resource);
        $this->nmsp  = RC::$config->schema->namespaces ?? [];
    }

    public function __toString(): string {
        $output = sprintf(self::TMPL, (string) $this->res);

        foreach ($this->nmsp as $p => $u) {
            $output .= sprintf('<div class="n">@prefix %s: &lt;%s&gt;&nbsp;.</div>', htmlentities($p), htmlentities($u)) . "\n";
        }
        $output .= "<br/>\n";

        $output     .= '<div class="s">' . $this->formatResource($this->res) . "</div>\n";
        $properties = $this->res->propertyUris();
        sort($properties);
        foreach ($properties as $p) {
            $objects = $this->res->all($p);
            $output  .= '<div class="p"><a href="' . htmlentities((string) $p) . '">' . $this->formatResource((string) $p) . "</a></div>\n";
            foreach ($objects as $n => $o) {
                $output .= '<div class="o">' . $this->formatObject($o) . '&nbsp;' . ($n + 1 === count($objects) ? '.' : ',') . "</div>\n";
            }
        }

        $output .= '</body></html>';
        return $output;
    }

    private function formatObject(object $o): string {
        if ($o instanceof Resource) {
            $base   = RC::getBaseUrl();
            $url    = htmlentities((string) $o);
            $o      = (string) $o;
            $suffix = substr($o, 0, strlen($base)) === $base ? '/metadata' : '';
            return sprintf('<a href="%s%s">%s</a>', $url, $suffix, $this->formatResource($o));
        } else {
            /* @var $o \EasyRdf\Literal */
            $v = (string) $o;
            $l = $o->getLang();
            $l = empty($l) ? '' : ('@' . $l);
            $t = $o->getDatatype();
            $t = empty($t) || $t === RDF::XSD_STRING ? '' : ('^^' . $t);
            return sprintf('"%s"<span class="tl">%s</span>', $v, $l . $t);
        }
    }

    private function formatResource(string $res): string {
        $res = (string) $res;
        foreach ($this->nmsp as $n => $u) {
            if (substr($res, 0, strlen($u)) === $u) {
                return $n . ':' . substr($res, strlen($u));
            }
        }
        return '&lt;' . htmlentities($res) . '&gt;';
    }

}
