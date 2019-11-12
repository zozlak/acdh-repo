<?php

/*
 * The MIT License
 *
 * Copyright 2019 Austrian Centre for Digital Humanities.
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
use GuzzleHttp\Psr7\Request;

/**
 * Description of RestTest
 *
 * @author zozlak
 */
class RestTest extends TestBase {

    /**
     * @group rest
     */
    public function testTransactionEmpty(): void {
        // commit
        $req  = new Request('post', self::$baseUrl . 'transaction');
        $resp = self::$client->send($req);
        $this->assertEquals(201, $resp->getStatusCode());
        $txId = $resp->getHeader('X-Transaction-Id')[0] ?? null;
        $this->assertGreaterThan(0, $txId);

        $req  = new Request('put', self::$baseUrl . 'transaction', $this->getHeaders($txId));
        $resp = self::$client->send($req);
        $this->assertEquals(204, $resp->getStatusCode());

        $req  = new Request('get', self::$baseUrl . 'transaction', $this->getHeaders($txId));
        $resp = self::$client->send($req);
        $this->assertEquals(400, $resp->getStatusCode());

        // rollback
        $req  = new Request('post', self::$baseUrl . 'transaction');
        $resp = self::$client->send($req);
        $this->assertEquals(201, $resp->getStatusCode());
        $txId = $resp->getHeader('X-Transaction-Id')[0] ?? null;
        $this->assertGreaterThan(0, $txId);

        $req  = new Request('delete', self::$baseUrl . 'transaction', $this->getHeaders($txId));
        $resp = self::$client->send($req);
        $this->assertEquals(204, $resp->getStatusCode());

        $req  = new Request('get', self::$baseUrl . 'transaction', $this->getHeaders($txId));
        $resp = self::$client->send($req);
        $this->assertEquals(400, $resp->getStatusCode());
    }

    /**
     * @group rest
     */
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
        $req      = new Request('post', self::$baseUrl, $headers, $body);
        $resp     = self::$client->send($req);
        $this->assertEquals(201, $resp->getStatusCode());
        $location = $resp->getHeader('Location')[0] ?? null;
        $this->assertIsString($location);

        $req  = new Request('get', $location, $this->getHeaders($txId));
        $resp = self::$client->send($req);
        $this->assertEquals(200, $resp->getStatusCode());
        $this->assertEquals($body, $resp->getBody(), 'created file content mismatch');

        $req   = new Request('get', $location . '/metadata', $this->getHeaders($txId));
        $resp  = self::$client->send($req);
        $this->assertEquals(200, $resp->getStatusCode());
        $graph = new Graph();
        $graph->parse($resp->getBody());
        $res   = $graph->resource($location);
        $this->assertEquals('md5:' . md5_file(__DIR__ . '/data/test.ttl'), (string) $res->getLiteral(self::$config->schema->hash));

        $this->assertEquals(204, $this->commitTransaction($txId));

        $req  = new Request('get', $location, $this->getHeaders());
        $resp = self::$client->send($req);
        $this->assertEquals(200, $resp->getStatusCode());
        $this->assertEquals($body, $resp->getBody(), 'created file content mismatch');
    }

    /**
     * @group rest
     */
    public function testTransactionCreateRollback(): void {
        $txId = $this->beginTransaction();
        $this->assertGreaterThan(0, $txId);

        $location = $this->createResource($txId);

        $req  = new Request('get', $location, $this->getHeaders($txId));
        $resp = self::$client->send($req);
        $this->assertEquals(200, $resp->getStatusCode());
        $this->assertEquals(file_get_contents(__DIR__ . '/data/test.ttl'), $resp->getBody(), 'created file content mismatch');

        $this->assertEquals(204, $this->rollbackTransaction($txId));

        $req  = new Request('get', $location, $this->getHeaders());
        $resp = self::$client->send($req);
        $this->assertEquals(404, $resp->getStatusCode());
    }

    /**
     * @group rest
     */
    public function testResourceDelete(): void {
        $location = $this->createResource();

        $txId = $this->beginTransaction();
        $this->assertGreaterThan(0, $txId);

        $req  = new Request('delete', $location, $this->getHeaders($txId));
        $resp = self::$client->send($req);
        $this->assertEquals(204, $resp->getStatusCode());

        $req  = new Request('get', $location, $this->getHeaders($txId));
        $resp = self::$client->send($req);
        $this->assertEquals(410, $resp->getStatusCode());

        $this->assertEquals(204, $this->commitTransaction($txId));

        $req  = new Request('get', $location, $this->getHeaders());
        $resp = self::$client->send($req);
        $this->assertEquals(410, $resp->getStatusCode());
    }

    /**
     * @group rest
     */
    public function testTombstoneDelete(): void {
        $location = $this->createResource();
        $this->deleteResource($location);

        // make sure tombstone is there
        $req  = new Request('get', $location, $this->getHeaders());
        $resp = self::$client->send($req);
        $this->assertEquals(410, $resp->getStatusCode());

        // delete tombstone
        $txId = $this->beginTransaction();
        $this->assertGreaterThan(0, $txId);

        $req  = new Request('delete', $location . '/tombstone', $this->getHeaders($txId));
        $resp = self::$client->send($req);
        $this->assertEquals(204, $resp->getStatusCode());

        $req  = new Request('get', $location, $this->getHeaders($txId));
        $resp = self::$client->send($req);
        $this->assertEquals(404, $resp->getStatusCode());

        $this->assertEquals(204, $this->commitTransaction($txId));

        $req  = new Request('get', $location, $this->getHeaders());
        $resp = self::$client->send($req);
        $this->assertEquals(404, $resp->getStatusCode());
    }

    /**
     * @group rest
     */
    public function testTombstoneDeleteActive(): void {
        $location = $this->createResource();

        $txId = $this->beginTransaction();
        $this->assertGreaterThan(0, $txId);
        $req  = new Request('delete', $location . '/tombstone', $this->getHeaders($txId));
        $resp = self::$client->send($req);
        $this->assertEquals(405, $resp->getStatusCode());

        $this->rollbackTransaction($txId);
    }

    /**
     * @group rest
     */
    public function testTransactionDeleteRollback(): void {
        // create a resource and make sure it's there
        $location = $this->createResource();
        $req      = new Request('get', $location, $this->getHeaders());
        $resp     = self::$client->send($req);
        $this->assertEquals(200, $resp->getStatusCode());

        // begin a transaction
        $txId = $this->beginTransaction();
        $this->assertGreaterThan(0, $txId);

        // delete the resource and make sure it's not there
        $req  = new Request('delete', $location, $this->getHeaders($txId));
        $resp = self::$client->send($req);
        $this->assertEquals(204, $resp->getStatusCode());

        $req  = new Request('delete', $location . '/tombstone', $this->getHeaders($txId));
        $resp = self::$client->send($req);
        $this->assertEquals(204, $resp->getStatusCode());

        $req  = new Request('get', $location, $this->getHeaders($txId));
        $resp = self::$client->send($req);
        $this->assertEquals(404, $resp->getStatusCode());

        // rollback the transaction and check if the resource is back
        $this->assertEquals(204, $this->rollbackTransaction($txId));

        $req  = new Request('get', $location, $this->getHeaders());
        $resp = self::$client->send($req);
        $this->assertEquals(200, $resp->getStatusCode());
        $this->assertEquals(file_get_contents(__DIR__ . '/data/test.ttl'), $resp->getBody(), 'file content mismatch');
    }

    /**
     * @group rest
     */
    public function testHead(): void {
        $location = $this->createResource();

        $req  = new Request('head', $location, $this->getHeaders());
        $resp = self::$client->send($req);
        $this->assertEquals(200, $resp->getStatusCode());
        $this->assertEquals('attachment; filename="test.ttl"', $resp->getHeader('Content-Disposition')[0] ?? '');
        $this->assertEquals('text/turtle;charset=UTF-8', $resp->getHeader('Content-Type')[0] ?? '');
        $this->assertEquals(541, $resp->getHeader('Content-Length')[0] ?? '');

        $headers = array_merge($this->getHeaders(), ['Accept' => 'application/n-triples']);
        $req     = new Request('head', $location . '/metadata', $headers);
        $resp    = self::$client->send($req);
        $this->assertEquals(200, $resp->getStatusCode());
        $this->assertEquals('application/n-triples', $resp->getHeader('Content-Type')[0] ?? '');

        $headers = array_merge($this->getHeaders(), ['Accept' => 'text/*']);
        $req     = new Request('head', $location . '/metadata', $headers);
        $resp    = self::$client->send($req);
        $this->assertEquals(200, $resp->getStatusCode());
        $this->assertEquals('text/turtle;charset=UTF-8', $resp->getHeader('Content-Type')[0] ?? '');
    }

    /**
     * @group rest
     */
    public function testOptions(): void {
        $resp = self::$client->send(new Request('options', self::$baseUrl));
        $this->assertEquals('OPTIONS, POST', $resp->getHeader('Allow')[0] ?? '');

        $resp = self::$client->send(new Request('options', self::$baseUrl . 'metadata'));
        $this->assertEquals('OPTIONS, POST', $resp->getHeader('Allow')[0] ?? '');

        $resp = self::$client->send(new Request('options', self::$baseUrl . '1'));
        $this->assertEquals('OPTIONS, HEAD, GET, PUT, DELETE', $resp->getHeader('Allow')[0] ?? '');

        $resp = self::$client->send(new Request('options', self::$baseUrl . '1/metadata'));
        $this->assertEquals('OPTIONS, HEAD, GET, PATCH', $resp->getHeader('Allow')[0] ?? '');

        $resp = self::$client->send(new Request('options', self::$baseUrl . '1/tombstone'));
        $this->assertEquals('OPTIONS, DELETE', $resp->getHeader('Allow')[0] ?? '');
        
        $resp = self::$client->send(new Request('options', self::$baseUrl . 'search'));
        $this->assertEquals('OPTIONS, HEAD, GET, POST', $resp->getHeader('Allow')[0] ?? '');
    }

    /**
     * @group rest
     */
    public function testPut(): void {
        // create a resource and make sure it's there
        $location = $this->createResource();
        $req      = new Request('get', $location, $this->getHeaders());
        $resp     = self::$client->send($req);
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
        $resp    = self::$client->send($req);
        $this->assertEquals(204, $resp->getStatusCode());

        $req  = new Request('get', $location, $this->getHeaders());
        $resp = self::$client->send($req);
        $this->assertEquals(200, $resp->getStatusCode());
        $this->assertEquals(file_get_contents(__FILE__), $resp->getBody(), 'file content mismatch');

        $this->commitTransaction($txId);

        $req  = new Request('get', $location, $this->getHeaders());
        $resp = self::$client->send($req);
        $this->assertEquals(200, $resp->getStatusCode());
        $this->assertEquals(file_get_contents(__FILE__), $resp->getBody(), 'file content mismatch');
    }

    /**
     * @group rest
     */
    public function testResourceCreateMetadata(): void {
        $idProp = self::$config->schema->id;

        $txId = $this->beginTransaction();

        $meta    = $this->createMetadata();
        $headers = array_merge($this->getHeaders($txId), [
            'Content-Type' => 'application/n-triples'
        ]);
        $req     = new Request('post', self::$baseUrl . 'metadata', $headers, $meta->getGraph()->serialise('application/n-triples'));
        $resp    = self::$client->send($req);

        $this->assertEquals(201, $resp->getStatusCode());
        $location = $resp->getHeader('Location')[0] ?? null;

        $req  = new Request('get', $location, $this->getHeaders());
        $resp = self::$client->send($req);
        $this->assertEquals(204, $resp->getStatusCode());

        $req     = new Request('get', $location . '/metadata', $this->getHeaders());
        $resp    = self::$client->send($req);
        $this->assertEquals(200, $resp->getStatusCode());
        $graph   = new Graph();
        $body    = (string) $resp->getBody();
        $graph->parse($body, preg_replace('/;.*$/', '', $resp->getHeader('Content-Type')[0]));
        $res     = $graph->resource($location);
        $this->assertEquals(2, count($res->allResources($idProp)));
        $allowed = [$location, (string) $meta->getResource($idProp)];
        foreach ($res->allResources($idProp) as $i) {
            $this->assertTrue(in_array((string) $i, $allowed));
        }
        $this->assertRegExp('|^http://127.0.0.1/rest/[0-9]+$|', (string) $res->getResource('http://test/hasRelation'));
        $this->assertEquals('title', (string) $res->getLiteral('http://test/hasTitle'));
        $this->assertEquals(date('Y-m-d'), substr((string) $res->getLiteral('http://test/hasDate'), 0, 10));
        $this->assertEquals(123.5, (string) $res->getLiteral('http://test/hasNumber'));

        $this->commitTransaction($txId);

        // check if everything is still in place after the transaction end
        $req  = new Request('get', $location . '/metadata', $this->getHeaders());
        $resp = self::$client->send($req);
        $this->assertEquals(200, $resp->getStatusCode());
        $this->assertEquals((string) $body, (string) $resp->getBody());
    }

    /**
     * @group rest
     */
    public function testPatchMetadataMerge(): void {
        // set up and remember an initial state
        $location = $this->createResource();

        $req  = new Request('get', $location . '/metadata', $this->getHeaders());
        $resp = self::$client->send($req);
        $this->assertEquals(200, $resp->getStatusCode());
        $res1 = $this->extractResource($resp, $location);

        // PATCH
        $txId = $this->beginTransaction();

        $meta    = $this->createMetadata($location);
        $headers = array_merge($this->getHeaders($txId), [
            'Content-Type' => 'application/n-triples'
        ]);
        $req     = new Request('patch', $location . '/metadata', $headers, $meta->getGraph()->serialise('application/n-triples'));
        $resp    = self::$client->send($req);
        $this->assertEquals(200, $resp->getStatusCode());
        $res2    = $this->extractResource($resp, $location);
        $this->assertEquals('test.ttl', (string) $res2->getLiteral(self::$config->schema->fileName));
        $this->assertEquals('title', (string) $res2->getLiteral('http://test/hasTitle'));

        $this->commitTransaction($txId);

        // make sure nothing changed after transaction commit
        $req  = new Request('get', $location . '/metadata', $this->getHeaders());
        $resp = self::$client->send($req);
        $this->assertEquals(200, $resp->getStatusCode());
        $res3 = $this->extractResource($resp, $location);
        $this->assertEquals('test.ttl', (string) $res3->getLiteral(self::$config->schema->fileName));
        $this->assertEquals('title', (string) $res3->getLiteral('http://test/hasTitle'));

        // compare metadata
    }

    /**
     * @group rest
     */
    public function testPatchMetadataRollback(): void {
        // set up and remember an initial state
        $location = $this->createResource();

        $req  = new Request('get', $location . '/metadata', $this->getHeaders());
        $resp = self::$client->send($req);
        $this->assertEquals(200, $resp->getStatusCode());
        $res1 = $this->extractResource($resp, $location);

        // PATCH
        $txId = $this->beginTransaction();

        $meta    = $this->createMetadata($location);
        $headers = array_merge($this->getHeaders($txId), [
            'Content-Type' => 'application/n-triples'
        ]);
        $req     = new Request('patch', $location . '/metadata', $headers, $meta->getGraph()->serialise('application/n-triples'));
        $resp    = self::$client->send($req);
        $this->assertEquals(200, $resp->getStatusCode());
        $res2    = $this->extractResource($resp, $location);
        $this->assertEquals('test.ttl', (string) $res2->getLiteral(self::$config->schema->fileName));
        $this->assertEquals('title', (string) $res2->getLiteral('http://test/hasTitle'));

        $this->rollbackTransaction($txId);

        // make sure nothing changed after transaction commit
        $req  = new Request('get', $location . '/metadata', $this->getHeaders());
        $resp = self::$client->send($req);
        $this->assertEquals(200, $resp->getStatusCode());
        $res3 = $this->extractResource($resp, $location);
        $this->assertEquals('test.ttl', (string) $res3->getLiteral(self::$config->schema->fileName));
        $this->assertEquals(null, $res3->getLiteral('http://test/hasTitle'));
    }

    /**
     * @group rest
     */
    public function testUnbinaryResource(): void {
        $location = $this->createResource();

        $req  = new Request('get', $location, $this->getHeaders());
        $resp = self::$client->send($req);
        $this->assertEquals(200, $resp->getStatusCode());

        $txId = $this->beginTransaction();

        $req  = new Request('put', $location, $this->getHeaders($txId), '');
        $resp = self::$client->send($req);
        $this->assertEquals(204, $resp->getStatusCode());

        $req  = new Request('get', $location, $this->getHeaders($txId));
        $resp = self::$client->send($req);
        $this->assertEquals(204, $resp->getStatusCode());

        $this->commitTransaction($txId);

        $resp = self::$client->send($req);
        $this->assertEquals(204, $resp->getStatusCode());

        $req  = new Request('get', $location . '/metadata', $this->getHeaders($txId));
        $resp = self::$client->send($req);
        $this->assertEquals(200, $resp->getStatusCode());
        $res  = $this->extractResource($resp, $location);
        $this->assertNull($res->getLiteral(self::$config->schema->fileName));
        $this->assertNull($res->getLiteral(self::$config->schema->binarySize));
        $this->assertNull($res->getLiteral(self::$config->schema->hash));
    }

}
