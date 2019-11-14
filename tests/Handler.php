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

use PDO;
use EasyRdf\Graph;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use zozlak\logging\Log;
use acdhOeaw\acdhRepo\RestController as RC;

function txCommit(string $method, int $txId, array $resourceIds): void {
    Handler::onTxCommit($method, $txId, $resourceIds);
}

/**
 * Bunch of test handlers
 *
 * @author zozlak
 */
class Handler {

    static public function onTxCommit(string $method, int $txId,
                                      array $resourceIds): void {
        RC::$log->debug("\t\ton$method handler for " . $txId);

        $cfg   = yaml_parse_file(__DIR__ . '/../config.yaml');
        $pdo   = new PDO($cfg['dbConnStr']['admin']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->beginTransaction();
        $query = $pdo->prepare("
            INSERT INTO metadata (id, property, type, lang, value) 
            VALUES (?, 'https://commit/property', 'http://www.w3.org/2001/XMLSchema#string', '', ?)
        ");
        foreach ($resourceIds as $i) {
            $query->execute([$i, $method . $txId]);
        }
        $pdo->commit();
    }

    /**
     *
     * @var \PhpAmqpLib\Connection\AMQPStreamConnection
     */
    private $rmqConn;

    /**
     *
     * @var \PhpAmqpLib\Channel\AMQPChannel
     */
    private $rmqChannel;

    /**
     *
     * @var \zozlak\logging\Log
     */
    private $log;

    public function __construct(string $configFile) {
        $cfg       = json_decode(json_encode(yaml_parse_file($configFile)));
        $this->log = new Log($cfg->rest->logging->file, $cfg->rest->logging->level);
        $cfg       = $cfg->rest->handlers;

        $this->rmqConn    = new AMQPStreamConnection($cfg->rabbitMq->host, $cfg->rabbitMq->port, $cfg->rabbitMq->user, $cfg->rabbitMq->password);
        $this->rmqChannel = $this->rmqConn->channel();
        $this->rmqChannel->basic_qos(null, 1, null);

        foreach ($cfg->methods as $method) {
            foreach ($method ?? [] as $h) {
                if ($h->type === 'rpc') {
                    $this->rmqChannel->queue_declare($h->queue, false, false, false, false);
                    $clbck = [$this, $h->queue];
                    $this->rmqChannel->basic_consume($h->queue, '', false, false, false, false, $clbck);
                }
            }
        }
    }

    public function __destruct() {
        $this->rmqChannel->close();
        $this->rmqConn->close();
    }

    public function loop(): void {
        while ($this->rmqChannel->is_consuming()) {
            $this->rmqChannel->wait();
        }
    }

    public function onUpdateRpc(object $req): void {
        $this->log->debug("\t\tonUpdateRpc");
        $data = $this->parse($req->body);
        $this->log->debug("\t\t\tfor " . $data->uri);

        usleep(300000);
        $data->metadata->addLiteral('https://rpc/property', 'update rpc');

        $rdf  = $data->metadata->getGraph()->serialise('application/n-triples');
        $opts = ['correlation_id' => $req->get('correlation_id')];
        $msg  = new AMQPMessage($rdf, $opts);
        $req->delivery_info['channel']->basic_publish($msg, '', $req->get('reply_to'));
        $req->delivery_info['channel']->basic_ack($req->delivery_info['delivery_tag']);
    }

    public function onCreateRpc(object $req): void {
        $this->log->debug("\t\tonCreateRpc");
        $data = $this->parse($req->body);
        $this->log->debug("\t\t\tfor " . $data->uri);

        $data->metadata->addLiteral('https://rpc/property', 'create rpc');

        $rdf  = $data->metadata->getGraph()->serialise('application/n-triples');
        $opts = ['correlation_id' => $req->get('correlation_id')];
        $msg  = new AMQPMessage($rdf, $opts);
        $req->delivery_info['channel']->basic_publish($msg, '', $req->get('reply_to'));
        $req->delivery_info['channel']->basic_ack($req->delivery_info['delivery_tag']);
    }

    public function onCommitRpc(object $req): void {
        $this->log->debug("\t\tonCommitRpc");
        $data = json_decode($req->body); // method, transactionId, resourceIds
        $this->log->debug("\t\t\tfor " . $data->method . " on transaction " . $data->transactionId);

        $cfg   = yaml_parse_file(__DIR__ . '/../config.yaml');
        $pdo   = new PDO($cfg['dbConnStr']['admin']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->beginTransaction();
        $query = $pdo->prepare("
            INSERT INTO metadata (id, property, type, lang, value) 
            VALUES (?, 'https://commit/property', 'http://www.w3.org/2001/XMLSchema#string', '', ?)
        ");
        foreach ($data->resourceIds as $i) {
            $query->execute([$i, $data->method . $data->transactionId]);
        }
        $pdo->commit();

        $opts = ['correlation_id' => $req->get('correlation_id')];
        $msg  = new AMQPMessage('', $opts);
        $req->delivery_info['channel']->basic_publish($msg, '', $req->get('reply_to'));
        $req->delivery_info['channel']->basic_ack($req->delivery_info['delivery_tag']);
    }

    private function parse(string $msg): object {
        $data           = json_decode($msg);
        $graph          = new Graph();
        $graph->parse($data->metadata, 'application/n-triples');
        $data->metadata = $graph->resource($data->uri);
        return $data;
    }

}
