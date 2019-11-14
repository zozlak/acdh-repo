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

namespace acdhOeaw\acdhRepo\tests;

use DateTime;
use PDO;
use EasyRdf\Graph;
use EasyRdf\Resource;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use acdhOeaw\acdhRepo\Metadata;

/**
 * Description of TestBase
 *
 * @author zozlak
 */
class TestBase extends \PHPUnit\Framework\TestCase {

    static protected $baseUrl;

    /**
     *
     * @var \GuzzleHttp\Client;
     */
    static protected $client;
    static protected $config;
    static protected $txCtrl;

    /**
     *
     * @var \PDO
     */
    static protected $pdo;

    static public function setUpBeforeClass(): void {
        file_put_contents(__DIR__ . '/../config.yaml', file_get_contents(__DIR__ . '/config.yaml'));

        self::$client  = new Client(['http_errors' => false]);
        self::$config  = json_decode(json_encode(yaml_parse_file(__DIR__ . '/../config.yaml')));
        self::$baseUrl = self::$config->rest->urlBase . self::$config->rest->pathBase;
        self::$pdo     = new PDO(self::$config->dbConnStr->admin);
        self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $cmd          = 'php -f ' . __DIR__ . '/../transactionDaemon.php ' . __DIR__ . '/../config.yaml';
        $pipes        = [];
        self::$txCtrl = proc_open($cmd, [], $pipes, __DIR__ . '/../');
        if (self::$txCtrl === false) {
            throw new Exception('failed to start handlerRun.php');
        }

        // give services like the transaction manager or tika time to start
        usleep(500000);
        self::reloadTxCtrlConfig();
    }

    static public function tearDownAfterClass(): void {
        // proc_open() runs the command by invoking shell, so the actual process's PID is (if everything goes fine) one greater
        $s = proc_get_status(self::$txCtrl);
        posix_kill($s['pid'] + 1, 15);
        proc_close(self::$txCtrl);
    }

    static protected function reloadTxCtrlConfig(): void {
        // proc_open() runs the command by invoking shell, so the actual process's PID is (if everything goes fine) one greater
        $s = proc_get_status(self::$txCtrl);
        posix_kill($s['pid'] + 1, 10);
        usleep(100000);
    }

    public function setUp(): void {
        self::$pdo->query("TRUNCATE transactions CASCADE");
    }

    public function tearDown(): void {
        
    }

    protected function beginTransaction(): ?string {
        $req  = new Request('post', self::$baseUrl . 'transaction');
        $resp = self::$client->send($req);
        return $resp->getHeader(self::$config->rest->headers->transactionId)[0] ?? null;
    }

    protected function commitTransaction(int $txId): int {
        $req  = new Request('put', self::$baseUrl . 'transaction', $this->getHeaders($txId));
        $resp = self::$client->send($req);
        return $resp->getStatusCode();
    }

    protected function rollbackTransaction(int $txId): int {
        $req  = new Request('delete', self::$baseUrl . 'transaction', $this->getHeaders($txId));
        $resp = self::$client->send($req);
        return $resp->getStatusCode();
    }

    protected function createMetadata($uri = null): Resource {
        $g = new Graph();
        $r = $g->resource($uri ?? self::$baseUrl);
        $r->addResource(self::$config->schema->id, 'https://' . rand());
        $r->addResource('http://test/hasRelation', 'https://' . rand());
        $r->addLiteral('http://test/hasTitle', 'title');
        $r->addLiteral('http://test/hasDate', new DateTime());
        $r->addLiteral('http://test/hasNumber', 123.5);
        return $r;
    }

    protected function createBinaryResource(int $txId = null) {
        $extTx = $txId !== null;
        if (!$extTx) {
            $txId = $this->beginTransaction();
        }

        $headers  = [
            self::$config->rest->headers->transactionId => $txId,
            'Content-Disposition'                       => 'attachment; filename="test.ttl"',
            'Content-Type'                              => 'text/turtle',
            'Eppn'                                      => 'admin',
        ];
        $body     = file_get_contents(__DIR__ . '/data/test.ttl');
        $req      = new Request('post', self::$baseUrl, $headers, $body);
        $resp     = self::$client->send($req);
        $location = $resp->getHeader('Location')[0] ?? null;

        if (!$extTx) {
            $this->commitTransaction($txId);
        }

        return $location ?? $resp;
    }

    protected function createMetadataResource(?Resource $meta = null, int $txId = null) {
        if ($meta === null) {
            $meta = (new Graph())->resource(self::$baseUrl);
        }
        
        $extTx = $txId !== null;
        if (!$extTx) {
            $txId = $this->beginTransaction();
        }

        $headers  = [
            self::$config->rest->headers->transactionId => $txId,
            'Content-Type'                              => 'text/turtle',
            'Eppn'                                      => 'admin',
        ];
        $body     = $meta->getGraph()->serialise('turtle');
        $req      = new Request('post', self::$baseUrl . 'metadata', $headers, $body);
        $resp     = self::$client->send($req);
        $location = $resp->getHeader('Location')[0] ?? null;

        if (!$extTx) {
            $this->commitTransaction($txId);
        }

        return $location ?? $resp;
    }
    
    protected function updateResource(Resource $meta, ?int $txId = null,
                                      string $mode = Metadata::SAVE_MERGE): Response {
        $extTx = $txId !== null;
        if (!$extTx) {
            $txId = $this->beginTransaction();
        }

        $headers = [
            self::$config->rest->headers->transactionId     => $txId,
            self::$config->rest->headers->metadataWriteMode => $mode,
            'Content-Type'                                  => 'application/n-triples',
            'Eppn'                                          => 'admin',
        ];
        $body    = $meta->getGraph()->serialise('application/n-triples');
        $req     = new Request('patch', $meta->getUri() . '/metadata', $headers, $body);
        $resp    = self::$client->send($req);

        if (!$extTx) {
            $this->commitTransaction($txId);
        }

        return $resp;
    }

    protected function deleteResource(string $location, int $txId = null): bool {
        $extTx = $txId !== null;
        if (!$extTx) {
            $txId = $this->beginTransaction();
        }

        $req  = new Request('delete', $location, $this->getHeaders($txId));
        $resp = self::$client->send($req);

        if (!$extTx) {
            $this->commitTransaction($txId);
        }

        return $resp->getStatusCode() === 204;
    }

    protected function getHeaders($txId = null): array {
        return [
            self::$config->rest->headers->transactionId => $txId,
            'Eppn'                                      => 'admin',
        ];
    }

    protected function extractResource($body, $location): Resource {
        if (is_a($body, 'GuzzleHttp\Psr7\Response')) {
            $body = (string) $body->getBody();
        }
        $graph = new Graph();
        try {
            $graph->parse($body, 'text/turtle');
        } catch (\EasyRdf\Parser\Exception $e) {
            echo "\n-----\n" . $body . "\n-----\n";
            throw $e;
        }
        return $graph->resource($location);
    }

    protected function getResourceMeta($location): Resource {
        $req  = new Request('get', $location . '/metadata');
        $resp = self::$client->send($req);
        return $this->extractResource($resp, $location);
    }

    protected function runSearch(array $opts, string $method = 'get'): Graph {
        $resp = self::$client->request($method, self::$baseUrl . 'search', $opts);
        $body = (string) $resp->getBody();
        $g    = new Graph();
        $g->parse((string) $body, 'text/turtle');
        return $g;
    }

}
