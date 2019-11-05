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

use DateTime;
use PDO;
use EasyRdf\Graph;
use EasyRdf\Resource;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;

/**
 * Description of TestBase
 *
 * @author zozlak
 */
class TestBase extends \PHPUnit\Framework\TestCase {

    static protected $baseUrl = 'http://127.0.0.1/rest/';
    static protected $client;
    static protected $config;
    static protected $txCtrl;
    static protected $pdo;

    static public function setUpBeforeClass(): void {
        self::$client = new Client(['http_errors' => false]);
        self::$config = json_decode(json_encode(yaml_parse(file_get_contents(__DIR__ . '/../config.yaml'))));
        self::$pdo    = new PDO(self::$config->dbConnStr->admin);
        self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        if (file_exists(self::$config->transactionController->logging->file)) {
            unlink(self::$config->transactionController->logging->file);
        }
        if (file_exists(self::$config->rest->logging->file)) {
            unlink(self::$config->rest->logging->file);
        }
        $cmd          = 'php -f ' . __DIR__ . '/../transactionDaemon.php ' . __DIR__ . '/../config.yaml';
        $pipes        = [];
        self::$txCtrl = proc_open($cmd, [], $pipes, __DIR__ . '/../');
        usleep(500000); // give the transaction manager time to start
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
    }

    public function setUp(): void {
        self::$pdo->query("TRUNCATE transactions CASCADE");
    }

    public function tearDown(): void {
        
    }

    protected function beginTransaction(): ?string {
        $req  = new Request('post', self::$baseUrl . 'transaction');
        $resp = self::$client->send($req);
        return $resp->getHeader('X-Transaction-Id')[0] ?? null;
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
        $r->addResource('https://vocabs.acdh.oeaw.ac.at/schema#hasIdentifier', 'https://' . rand());
        $r->addResource('http://test#hasRelation', 'https://' . rand());
        $r->addLiteral('http://test#hasTitle', 'title');
        $r->addLiteral('http://test#hasDate', new DateTime());
        $r->addLiteral('http://test#hasNumber', 123.5);
        return $r;
    }

    protected function createResource(int $txId = null): string {
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
        $req      = new Request('post', self::$baseUrl, $headers, $body);
        $resp     = self::$client->send($req);
        $location = $resp->getHeader('Location')[0] ?? null;

        if (!$extTx) {
            $this->commitTransaction($txId);
        }

        return $location;
    }

    protected function deleteResource(string $location, int $txId = null): void {
        $extTx = $txId !== null;
        if (!$extTx) {
            $txId = $this->beginTransaction();
        }

        $req = new Request('delete', $location, $this->getHeaders($txId));
        self::$client->send($req);

        if (!$extTx) {
            $this->commitTransaction($txId);
        }
    }

    protected function getHeaders($txId = null): array {
        return [
            'X-Transaction-Id' => $txId,
            'Eppn'             => 'admin',
        ];
    }

    protected function extractResource($body, $location): Resource {
        if (is_a($body, 'GuzzleHttp\Psr7\Response')) {
            $body = $body->getBody();
        }
        $graph = new Graph();
        $graph->parse($body);
        return $graph->resource($location);
    }

}
