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

use EasyRdf\Graph;
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

    public function setUp(): void {
        $this->client = new Client(['http_errors' => false]);
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

    //---------- HELPERS ----------

    private function beginTransaction() {
        $req  = new Request('post', $this->baseUrl . 'transaction');
        $resp = $this->client->send($req);
        return $resp->getHeader('X-Transaction-Id')[0] ?? null;
    }

    private function commitTransaction(int $txId) {
        $req  = new Request('put', $this->baseUrl . 'transaction', $this->getHeaders($txId));
        $resp = $this->client->send($req);
        return $resp->getStatusCode();
    }

    private function rollbackTransaction(int $txId) {
        $req  = new Request('delete', $this->baseUrl . 'transaction', $this->getHeaders($txId));
        $resp = $this->client->send($req);
        return $resp->getStatusCode();
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

}
