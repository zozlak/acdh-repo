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

namespace acdhOeaw\acdhRepo;

use DateTime;
use EasyRdf\Graph;
use EasyRdf\Resource;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;

/**
 * Description of RestTest
 *
 * @author zozlak
 */
class RestTest extends \PHPUnit\Framework\TestCase {

    private $baseUrl = 'http://127.0.0.1/rest/';
    private $client;
    private $config;

    public function setUp(): void {
        $this->client = new Client(['http_errors' => false]);
        $this->config = json_decode(json_encode(yaml_parse(file_get_contents(__DIR__ . '/../config.yaml'))));
    }

    public function tearDown(): void {
        
    }
    
    public function testTransactionEmpty(): void {
        // commit
        $req  = new Request('post', $this->baseUrl . 'transaction');
        $resp = $this->client->send($req);
        $this->assertEquals(201, $resp->getStatusCode());
        $txId = $resp->getHeader('X-Transaction-Id')[0] ?? null;
        $this->assertGreaterThan(0, $txId);

        $req  = new Request('put', $this->baseUrl . 'transaction', $this->getHeaders($txId));
        $resp = $this->client->send($req);
        $this->assertEquals(204, $resp->getStatusCode());

        $req  = new Request('get', $this->baseUrl . 'transaction', $this->getHeaders($txId));
        $resp = $this->client->send($req);
        $this->assertEquals(400, $resp->getStatusCode());

        // rollback
        $req  = new Request('post', $this->baseUrl . 'transaction');
        $resp = $this->client->send($req);
        $this->assertEquals(201, $resp->getStatusCode());
        $txId = $resp->getHeader('X-Transaction-Id')[0] ?? null;
        $this->assertGreaterThan(0, $txId);

        $req  = new Request('delete', $this->baseUrl . 'transaction', $this->getHeaders($txId));
        $resp = $this->client->send($req);
        $this->assertEquals(204, $resp->getStatusCode());

        $req  = new Request('get', $this->baseUrl . 'transaction', $this->getHeaders($txId));
        $resp = $this->client->send($req);
        $this->assertEquals(400, $resp->getStatusCode());
    }

    public function testResourceCreate(): void {
        $txId = $this->beginTransaction();
        $this->assertGreaterThan(0, $txId);

        $headers  = [
            'X-Transaction-Id'    => $txId,
            'Content-Disposition' => 'attachment; filename="test.ttl"',
            'Content-Type'        => 'text/turtle',
            'Eppn'                => 'admin',
        ];
        $body     = file_get_contents(__DIR__ . '/data/test.ttl');
        $req      = new Request('post', $this->baseUrl, $headers, $body);
        $resp     = $this->client->send($req);
        $this->assertEquals(201, $resp->getStatusCode());
        $location = $resp->getHeader('Location')[0] ?? null;
        $this->assertIsString($location);

        $req  = new Request('get', $location, $this->getHeaders($txId));
        $resp = $this->client->send($req);
        $this->assertEquals(200, $resp->getStatusCode());
        $this->assertEquals($body, $resp->getBody(), 'created file content mismatch');

        $req   = new Request('get', $location . '/metadata', $this->getHeaders($txId));
        $resp  = $this->client->send($req);
        $this->assertEquals(200, $resp->getStatusCode());
        $graph = new Graph();
        $graph->parse($resp->getBody());
        $res   = $graph->resource($location);
        $this->assertEquals('md5:' . md5_file(__DIR__ . '/data/test.ttl'), (string) $res->getLiteral('http://www.loc.gov/premis/rdf/v1#hasMessageDigest'));

        $this->assertEquals(204, $this->commitTransaction($txId));

        $req  = new Request('get', $location, $this->getHeaders());
        $resp = $this->client->send($req);
        $this->assertEquals(200, $resp->getStatusCode());
        $this->assertEquals($body, $resp->getBody(), 'created file content mismatch');
    }

    public function testTransactionCreateRollback(): void {
        $txId = $this->beginTransaction();
        $this->assertGreaterThan(0, $txId);

        $location = $this->createResource($txId);

        $req  = new Request('get', $location, $this->getHeaders($txId));
        $resp = $this->client->send($req);
        $this->assertEquals(200, $resp->getStatusCode());
        $this->assertEquals(file_get_contents(__DIR__ . '/data/test.ttl'), $resp->getBody(), 'created file content mismatch');

        $this->assertEquals(204, $this->rollbackTransaction($txId));

        $req  = new Request('get', $location, $this->getHeaders());
        $resp = $this->client->send($req);
        $this->assertEquals(404, $resp->getStatusCode());
    }

    public function testResourceDelete(): void {
        $location = $this->createResource();

        $txId = $this->beginTransaction();
        $this->assertGreaterThan(0, $txId);

        $req  = new Request('delete', $location, $this->getHeaders($txId));
        $resp = $this->client->send($req);
        $this->assertEquals(204, $resp->getStatusCode());

        $req  = new Request('get', $location, $this->getHeaders($txId));
        $resp = $this->client->send($req);
        $this->assertEquals(410, $resp->getStatusCode());

        $this->assertEquals(204, $this->commitTransaction($txId));

        $req  = new Request('get', $location, $this->getHeaders());
        $resp = $this->client->send($req);
        $this->assertEquals(410, $resp->getStatusCode());
    }

    public function testTombstoneDelete(): void {
        $location = $this->createResource();
        $this->deleteResource($location);

        // make sure tombstone is there
        $req  = new Request('get', $location, $this->getHeaders());
        $resp = $this->client->send($req);
        $this->assertEquals(410, $resp->getStatusCode());

        // delete tombstone
        $txId = $this->beginTransaction();
        $this->assertGreaterThan(0, $txId);

        $req  = new Request('delete', $location . '/tombstone', $this->getHeaders($txId));
        $resp = $this->client->send($req);
        $this->assertEquals(204, $resp->getStatusCode());

        $req  = new Request('get', $location, $this->getHeaders($txId));
        $resp = $this->client->send($req);
        $this->assertEquals(404, $resp->getStatusCode());

        $this->assertEquals(204, $this->commitTransaction($txId));

        $req  = new Request('get', $location, $this->getHeaders());
        $resp = $this->client->send($req);
        $this->assertEquals(404, $resp->getStatusCode());
    }

    public function testTombstoneDeleteActive(): void {
        $location = $this->createResource();

        $txId = $this->beginTransaction();
        $this->assertGreaterThan(0, $txId);
        $req  = new Request('delete', $location . '/tombstone', $this->getHeaders($txId));
        $resp = $this->client->send($req);
        $this->assertEquals(405, $resp->getStatusCode());

        $this->rollbackTransaction($txId);
    }

    public function testTransactionDeleteRollback(): void {
        // create a resource and make sure it's there
        $location = $this->createResource();
        $req      = new Request('get', $location, $this->getHeaders());
        $resp     = $this->client->send($req);
        $this->assertEquals(200, $resp->getStatusCode());

        // begin a transaction
        $txId = $this->beginTransaction();
        $this->assertGreaterThan(0, $txId);

        // delete the resource and make sure it's not there
        $req  = new Request('delete', $location, $this->getHeaders($txId));
        $resp = $this->client->send($req);
        $this->assertEquals(204, $resp->getStatusCode());

        $req  = new Request('delete', $location . '/tombstone', $this->getHeaders($txId));
        $resp = $this->client->send($req);
        $this->assertEquals(204, $resp->getStatusCode());

        $req  = new Request('get', $location, $this->getHeaders($txId));
        $resp = $this->client->send($req);
        $this->assertEquals(404, $resp->getStatusCode());

        // rollback the transaction and check if the resource is back
        $this->assertEquals(204, $this->rollbackTransaction($txId));

        $req  = new Request('get', $location, $this->getHeaders());
        $resp = $this->client->send($req);
        $this->assertEquals(200, $resp->getStatusCode());
        $this->assertEquals(file_get_contents(__DIR__ . '/data/test.ttl'), $resp->getBody(), 'file content mismatch');
    }

    public function testHead(): void {
        $location = $this->createResource();

        $req  = new Request('head', $location, $this->getHeaders());
        $resp = $this->client->send($req);
        $this->assertEquals(200, $resp->getStatusCode());
        $this->assertEquals('attachment; filename="test.ttl"', $resp->getHeader('Content-Disposition')[0] ?? '');
        $this->assertEquals('text/turtle;charset=UTF-8', $resp->getHeader('Content-Type')[0] ?? '');
        $this->assertEquals(541, $resp->getHeader('Content-Length')[0] ?? '');

        $headers = array_merge($this->getHeaders(), ['Accept' => 'application/n-triples']);
        $req     = new Request('head', $location . '/metadata', $headers);
        $resp    = $this->client->send($req);
        $this->assertEquals(200, $resp->getStatusCode());
        $this->assertEquals('application/n-triples', $resp->getHeader('Content-Type')[0] ?? '');

        $headers = array_merge($this->getHeaders(), ['Accept' => 'text/*']);
        $req     = new Request('head', $location . '/metadata', $headers);
        $resp    = $this->client->send($req);
        $this->assertEquals(200, $resp->getStatusCode());
        $this->assertEquals('text/turtle;charset=UTF-8', $resp->getHeader('Content-Type')[0] ?? '');
    }

    public function testOptions(): void {
        $resp = $this->client->send(new Request('options', $this->baseUrl));
        $this->assertEquals('OPTIONS, POST', $resp->getHeader('Allow')[0] ?? '');

        $resp = $this->client->send(new Request('options', $this->baseUrl . 'metadata'));
        $this->assertEquals('OPTIONS, POST', $resp->getHeader('Allow')[0] ?? '');

        $resp = $this->client->send(new Request('options', $this->baseUrl . '1'));
        $this->assertEquals('OPTIONS, HEAD, GET, PUT, DELETE', $resp->getHeader('Allow')[0] ?? '');

        $resp = $this->client->send(new Request('options', $this->baseUrl . '1/metadata'));
        $this->assertEquals('OPTIONS, HEAD, GET, PATCH', $resp->getHeader('Allow')[0] ?? '');

        $resp = $this->client->send(new Request('options', $this->baseUrl . '1/tombstone'));
        $this->assertEquals('OPTIONS, DELETE', $resp->getHeader('Allow')[0] ?? '');
    }

    public function testPut(): void {
        // create a resource and make sure it's there
        $location = $this->createResource();
        $req      = new Request('get', $location, $this->getHeaders());
        $resp     = $this->client->send($req);
        $this->assertEquals(200, $resp->getStatusCode());

        $txId    = $this->beginTransaction();
        $headers = [
            'X-Transaction-Id'    => $txId,
            'Content-Disposition' => 'attachment; filename="RestTest.php"',
            'Content-Type'        => 'application/php',
            'Eppn'                => 'admin',
        ];
        $body    = file_get_contents(__FILE__);
        $req     = new Request('put', $location, $headers, $body);
        $resp    = $this->client->send($req);
        $this->assertEquals(204, $resp->getStatusCode());

        $req  = new Request('get', $location, $this->getHeaders());
        $resp = $this->client->send($req);
        $this->assertEquals(200, $resp->getStatusCode());
        $this->assertEquals(file_get_contents(__FILE__), $resp->getBody(), 'file content mismatch');

        $this->commitTransaction($txId);

        $req  = new Request('get', $location, $this->getHeaders());
        $resp = $this->client->send($req);
        $this->assertEquals(200, $resp->getStatusCode());
        $this->assertEquals(file_get_contents(__FILE__), $resp->getBody(), 'file content mismatch');
    }

    public function testResourceCreateMetadata(): void {
        $idProp = $this->config->schema->id;

        $txId = $this->beginTransaction();

        $meta    = $this->createMetadata();
        $headers = array_merge($this->getHeaders($txId), [
            'Content-Type' => 'application/n-triples'
        ]);
        $req     = new Request('post', $this->baseUrl . 'metadata', $headers, $meta->getGraph()->serialise('application/n-triples'));
        $resp    = $this->client->send($req);

        $this->assertEquals(201, $resp->getStatusCode());
        $location = $resp->getHeader('Location')[0] ?? null;

        $req  = new Request('get', $location, $this->getHeaders());
        $resp = $this->client->send($req);
        $this->assertEquals(204, $resp->getStatusCode());

        $req     = new Request('get', $location . '/metadata', $this->getHeaders());
        $resp    = $this->client->send($req);
        $this->assertEquals(200, $resp->getStatusCode());
        $graph   = new Graph();
        $body    = $resp->getBody();
        $graph->parse($body, preg_replace('/;.*$/', '', $resp->getHeader('Content-Type')[0]));
        $res     = $graph->resource($location);
        $this->assertEquals(2, count($res->allResources($idProp)));
        $allowed = [$location, (string) $meta->getResource($idProp)];
        foreach ($res->allResources($idProp) as $i) {
            $this->assertTrue(in_array((string) $i, $allowed));
        }
        $this->assertRegExp('|^http://127.0.0.1/rest/[0-9]+$|', (string) $res->getResource('http://test#hasRelation'));
        $this->assertEquals('title', (string) $res->getLiteral('http://test#hasTitle'));
        $this->assertEquals(date('Y-m-d'), substr((string) $res->getLiteral('http://test#hasDate'), 0, 10));
        $this->assertEquals(123.5, (string) $res->getLiteral('http://test#hasNumber'));

        $this->commitTransaction($txId);

        // check if everything is still in place after the transaction end
        $req  = new Request('get', $location . '/metadata', $this->getHeaders());
        $resp = $this->client->send($req);
        $this->assertEquals(200, $resp->getStatusCode());
        $this->assertEquals((string) $body, (string) $resp->getBody());
    }

    public function testPatchMetadataMerge(): void {
        // set up and remember an initial state
        $location = $this->createResource();

        $req  = new Request('get', $location . '/metadata', $this->getHeaders());
        $resp = $this->client->send($req);
        $this->assertEquals(200, $resp->getStatusCode());
        $res1 = $this->extractResource($resp, $location);

        // PATCH
        $txId = $this->beginTransaction();

        $meta    = $this->createMetadata($location);
        $headers = array_merge($this->getHeaders($txId), [
            'Content-Type' => 'application/n-triples'
        ]);
        $req     = new Request('patch', $location . '/metadata', $headers, $meta->getGraph()->serialise('application/n-triples'));
        $resp    = $this->client->send($req);
        $this->assertEquals(200, $resp->getStatusCode());
        $res2    = $this->extractResource($resp, $location);
        $this->assertEquals('test.ttl', (string) $res2->getLiteral($this->config->schema->fileName));
        $this->assertEquals('title', (string) $res2->getLiteral('http://test#hasTitle'));

        $this->commitTransaction($txId);

        // make sure nothing changed after transaction commit
        $req  = new Request('get', $location . '/metadata', $this->getHeaders());
        $resp = $this->client->send($req);
        $this->assertEquals(200, $resp->getStatusCode());
        $res3 = $this->extractResource($resp, $location);
        $this->assertEquals('test.ttl', (string) $res3->getLiteral($this->config->schema->fileName));
        $this->assertEquals('title', (string) $res3->getLiteral('http://test#hasTitle'));

        // compare metadata
    }

    public function testPatchMetadataRollback(): void {
        // set up and remember an initial state
        $location = $this->createResource();

        $req  = new Request('get', $location . '/metadata', $this->getHeaders());
        $resp = $this->client->send($req);
        $this->assertEquals(200, $resp->getStatusCode());
        $res1 = $this->extractResource($resp, $location);

        // PATCH
        $txId = $this->beginTransaction();

        $meta    = $this->createMetadata($location);
        $headers = array_merge($this->getHeaders($txId), [
            'Content-Type' => 'application/n-triples'
        ]);
        $req     = new Request('patch', $location . '/metadata', $headers, $meta->getGraph()->serialise('application/n-triples'));
        $resp    = $this->client->send($req);
        $this->assertEquals(200, $resp->getStatusCode());
        $res2    = $this->extractResource($resp, $location);
        $this->assertEquals('test.ttl', (string) $res2->getLiteral($this->config->schema->fileName));
        $this->assertEquals('title', (string) $res2->getLiteral('http://test#hasTitle'));

        $this->rollbackTransaction($txId);

        // make sure nothing changed after transaction commit
        $req  = new Request('get', $location . '/metadata', $this->getHeaders());
        $resp = $this->client->send($req);
        $this->assertEquals(200, $resp->getStatusCode());
        $res3 = $this->extractResource($resp, $location);
        $this->assertEquals('test.ttl', (string) $res3->getLiteral($this->config->schema->fileName));
        $this->assertEquals(null, $res3->getLiteral('http://test#hasTitle'));
    }

    public function testUnbinaryResource(): void {
        $location = $this->createResource();

        $req  = new Request('get', $location, $this->getHeaders());
        $resp = $this->client->send($req);
        $this->assertEquals(200, $resp->getStatusCode());

        $txId = $this->beginTransaction();

        $req  = new Request('put', $location, $this->getHeaders($txId), '');
        $resp = $this->client->send($req);
        $this->assertEquals(204, $resp->getStatusCode());

        $req  = new Request('get', $location, $this->getHeaders($txId));
        $resp = $this->client->send($req);
        $this->assertEquals(204, $resp->getStatusCode());

        $this->commitTransaction($txId);

        $resp = $this->client->send($req);
        $this->assertEquals(204, $resp->getStatusCode());

        $req  = new Request('get', $location . '/metadata', $this->getHeaders($txId));
        $resp = $this->client->send($req);
        $this->assertEquals(200, $resp->getStatusCode());
        $res  = $this->extractResource($resp, $location);
        $this->assertNull($res->getLiteral($this->config->schema->fileName));
        $this->assertNull($res->getLiteral($this->config->schema->binarySize));
        $this->assertNull($res->getLiteral($this->config->schema->hash));
    }

    //---------- HELPERS ----------

    private function beginTransaction(): ?string {
        $req  = new Request('post', $this->baseUrl . 'transaction');
        $resp = $this->client->send($req);
        return $resp->getHeader('X-Transaction-Id')[0] ?? null;
    }

    private function commitTransaction(int $txId): int {
        $req  = new Request('put', $this->baseUrl . 'transaction', $this->getHeaders($txId));
        $resp = $this->client->send($req);
        return $resp->getStatusCode();
    }

    private function rollbackTransaction(int $txId): int {
        $req  = new Request('delete', $this->baseUrl . 'transaction', $this->getHeaders($txId));
        $resp = $this->client->send($req);
        return $resp->getStatusCode();
    }

    private function createMetadata($uri = null): Resource {
        $g = new Graph();
        $r = $g->resource($uri ?? $this->baseUrl);
        $r->addResource('https://vocabs.acdh.oeaw.ac.at/schema#hasIdentifier', 'https://' . rand());
        $r->addResource('http://test#hasRelation', 'https://' . rand());
        $r->addLiteral('http://test#hasTitle', 'title');
        $r->addLiteral('http://test#hasDate', new DateTime());
        $r->addLiteral('http://test#hasNumber', 123.5);
        return $r;
    }

    private function createResource(int $txId = null): string {
        $extTx = $txId !== null;
        if (!$extTx) {
            $txId = $this->beginTransaction();
        }

        $headers  = [
            'X-Transaction-Id'    => $txId,
            'Content-Disposition' => 'attachment; filename="test.ttl"',
            'Content-Type'        => 'text/turtle',
            'Eppn'                => 'admin',
        ];
        $body     = file_get_contents(__DIR__ . '/data/test.ttl');
        $req      = new Request('post', $this->baseUrl, $headers, $body);
        $resp     = $this->client->send($req);
        $location = $resp->getHeader('Location')[0] ?? null;

        if (!$extTx) {
            $this->commitTransaction($txId);
        }

        return $location;
    }

    private function deleteResource(string $location, int $txId = null): void {
        $extTx = $txId !== null;
        if (!$extTx) {
            $txId = $this->beginTransaction();
        }

        $req = new Request('delete', $location, $this->getHeaders($txId));
        $this->client->send($req);

        if (!$extTx) {
            $this->commitTransaction($txId);
        }
    }

    private function getHeaders($txId = null): array {
        return [
            'X-Transaction-Id' => $txId,
            'Eppn'             => 'admin',
        ];
    }

    private function extractResource($body, $location): Resource {
        if (is_a($body, 'GuzzleHttp\Psr7\Response')) {
            $body = $body->getBody();
        }
        $graph = new Graph();
        $graph->parse($body);
        return $graph->resource($location);
    }

}
