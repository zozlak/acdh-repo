<?php

/* 
 * The MIT License
 *
 * Copyright 2019 zozlak.
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

use EasyRdf\Graph;
use acdhOeaw\fedora\Fedora;
use acdhOeaw\util\RepoConfig as RC;
use acdhOeaw\util\Indexer;
use acdhOeaw\util\MetadataCollection;

$composer = require_once __DIR__ . '/vendor/autoload.php';

RC::init(__DIR__ . '/config.ini');
$fedora = new Fedora();

echo "really simple transactions\n";
$fedora->begin();
$fedora->rollback();

$fedora->begin();
$fedora->commit();

echo "\nget a resource out of transaction\n";
$resource = $fedora->getResourceByUri('http://127.0.0.1/rest/8317');
//echo $resource->getMetadata()->getGraph()->serialise('turtle');

echo "\nget a resource within a transaction\n";
$fedora->begin();
$resource = $fedora->getResourceByUri('http://127.0.0.1/rest/8317');
//echo $resource->getMetadata()->getGraph()->serialise('turtle');
$fedora->commit();

echo "\ncreate a binary resource\n";
$graph = new Graph();
$meta = $graph->newBNode();
$fedora->begin();
$meta->addLiteral('https://vocabs.acdh.oeaw.ac.at/schema#hasTitle', 'sample title');
$resource = $fedora->createResource($meta, ['data' => 'config.ini', 'contentType' => 'text/plain', 'filename' => 'config.ini']);
//echo $resource->getMetadata()->getGraph()->serialise('ntriples');
$fedora->commit();

echo "\nsmall indexer task\n";
Indexer::$debug = true;
$fedora->begin();
$resource = $fedora->getResourceByUri('http://127.0.0.1/rest/8317');
$indexer = new Indexer($resource);
$indexer->setPaths(['repo/rdbms/tests']);
$indexer->setFilter('/composer.json/');
$indexer->setDepth(0);
$resources = $indexer->index();
$fedora->commit();
foreach ($resources as $i) {
    echo $i->getUri(true) . "\n";
}

echo "\nsmall graph import\n";
MetadataCollection::$debug = true;
$fedora->begin();
$collection = new MetadataCollection($fedora, __DIR__ . '/data/test.ttl');
$collection->import('https://my.id', MetadataCollection::CREATE);
$fedora->commit();
