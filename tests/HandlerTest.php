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
 * Description of HandlerTest
 *
 * @author zozlak
 */
class HandlerTest extends TestBase {

    static private $rmqSrvr;
    
    static public function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        $cmd          = 'php -f ' . __DIR__ . '/handlerRun.php ' . __DIR__ . '/../config.yaml';
        $pipes        = [];
        self::$rmqSrvr = proc_open($cmd, [], $pipes, __DIR__);
        usleep(100000); // give it some time to start
    }
    
    static public function tearDownAfterClass(): void {
        parent::tearDownAfterClass();
        // proc_open() runs the command by invoking shell, so the actual process's PID is (if everything goes fine) one greater
        $s = proc_get_status(self::$rmqSrvr);
        posix_kill($s['pid'] + 1, 15);
        proc_close(self::$rmqSrvr);
    }
    
    /**
     * 
     * @group handler
     */
    public function testHandlerWorks(): void {
        $txId = $this->beginTransaction();
        $this->assertGreaterThan(0, $txId);

        $meta     = $this->createMetadata();
        $headers  = array_merge($this->getHeaders($txId), [
            'Content-Type' => 'application/n-triples'
        ]);
        $req      = new Request('post', self::$baseUrl . 'metadata', $headers, $meta->getGraph()->serialise('application/n-triples'));
        $resp     = self::$client->send($req);
        $this->assertEquals(201, $resp->getStatusCode());
        $location = $resp->getHeader('Location')[0] ?? null;
        
        $this->assertTrue(false);
    }

}
