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

use Composer\Autoload\ClassLoader;
use EasyRdf\Graph;
use EasyRdf\Resource;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use acdhOeaw\acdhRepo\RestController as RC;

/**
 * Description of CallbackController
 *
 * @author zozlak
 */
class HandlersController {

    const TYPE_RPC  = 'rpc';
    const TYPE_FUNC = 'function';

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
     * @var string
     */
    private $rmqQueue;

    /**
     *
     * @var array
     */
    private $handlers = [];

    /**
     *
     * @var array
     */
    private $queue;

    /**
     *
     * @var int
     */
    private $rmqTimeout = 1;

    /**
     *
     * @var bool
     */
    private $rmqExceptionOnTimeout = true;

    public function __construct(object $cfg, ClassLoader $loader) {
        if (isset($cfg->rabbitMq)) {
            RC::$log->info('Initializing rabbitMQ connection');

            $this->rmqTimeout            = (float) $cfg->rabbitMq->timeout;
            $this->rmqExceptionOnTimeout = (bool) $cfg->rabbitMq->exceptionOnTimeout;

            $this->rmqConn    = new AMQPStreamConnection($cfg->rabbitMq->host, $cfg->rabbitMq->port, $cfg->rabbitMq->user, $cfg->rabbitMq->password);
            $this->rmqChannel = $this->rmqConn->channel();
            list($this->rmqQueue,, ) = $this->rmqChannel->queue_declare('', false, false, true, false);
            $clbck            = [$this, 'callback'];
            $this->rmqChannel->basic_consume($this->rmqQueue, '', false, true, false, false, $clbck);
        }
        $this->handlers = array_map(function($x) {
            return $x ?? [];
        }, (array) $cfg->methods);

        foreach ((array) $cfg->classLoader ?? [] as $nmsp => $path) {
            $nmsp = preg_replace('|\$|', '', $nmsp) . "\\";
            $loader->addPsr4($nmsp, $path);
        }

        $info = [];
        foreach ($this->handlers as $k => $v) {
            $info[] = "$k(" . count($v) . ")";
        }
        RC::$log->info('Registered handlers: ' . implode(', ', $info));
    }

    public function __destruct() {
        if ($this->rmqChannel !== null) {
            $this->rmqChannel->close();
        }
        if ($this->rmqConn !== null) {
            $this->rmqConn->close();
        }
    }

    public function handleResource(string $method, Resource $res, ?string $path): Resource {
        if (!isset($this->handlers[$method])) {
            return $res;
        }
        foreach ($this->handlers[$method] as $i) {
            switch ($i->type) {
                case self::TYPE_RPC:
                    $res = $this->callRpcResource($method, $i->queue, $res, $path);
                    break;
                case self::TYPE_FUNC:
                    $res = $this->callFunction($i->function, $i->class ?? '', $res, $path);
                    break;
                default:
                    throw new RepoException('unknown handler type: ' . $i->type, 500);
            }
        }
        return $res;
    }

    public function handleTransaction(string $method, int $txId,
                                      array $resourceIds): void {
        $methodKey = 'tx' . strtoupper(substr($method, 0, 1)) . substr($method, 1);
        if (!isset($this->handlers[$methodKey])) {
            return;
        }
        foreach ($this->handlers[$methodKey] as $i) {
            switch ($i->type) {
                case self::TYPE_RPC:
                    $data = json_encode([
                        'method'        => $method,
                        'transactionId' => $txId,
                        'resourceIds'   => $resourceIds,
                    ]);
                    $res  = $this->sendRmqMessage($i->queue, $res, $data);
                    break;
                case self::TYPE_FUNC:
                    $res  = $this->callFunction($i->function, $i->class ?? '', $method, $txId, $resourceIds);
                    break;
                default:
                    throw new RepoException('unknown handler type: ' . $i->type, 500);
            }
        }
    }

    private function callRpcResource(string $method, string $queue,
                                     Resource $res, ?string $path): Resource {
        $data   = json_encode([
            'method'   => $method,
            'path'     => $path,
            'uri'      => $res->getUri(),
            'metadata' => $res->getGraph()->serialise('application/n-triples'),
        ]);
        $result = $this->sendRmqMessage($queue, $data);
        if ($result === null) {
            $result = $res;
        } else {
            $result = $result->resource($res->getUri());
        }
        return $result;
    }

    private function sendRmqMessage(string $queue, string $data) {
        $id               = uniqid();
        RC::$log->debug("\tcalling RPC handler with id $id using the $queue queue");
        $opts             = ['correlation_id' => $id, 'reply_to' => $this->rmqQueue];
        $msg              = new AMQPMessage($data, $opts);
        $this->rmqChannel->basic_publish($msg, '', $queue);
        $this->queue[$id] = null;
        try {
            $this->rmqChannel->wait(null, false, $this->rmqTimeout);
        } catch (AMQPTimeoutException $e) {
            
        }
        if ($this->queue[$id] === null) {
            if ($this->rmqExceptionOnTimeout) {
                throw new RepoException("$queue handler timeout", 500);
            }
            return null;
        }
        return $this->queue[$id];
    }

    private function callFunction(string $func, string $class, ...$params) {
        RC::$log->debug("\tcalling function handler $class::$func()");
        if (!empty($class)) {
            $result = $class::$func(...$params);
        } else {
            $result = $func(...$params);
        }
        return $result;
    }

    public function callback($msg): void {
        $id = $msg->get('correlation_id');
        RC::$log->debug("\t\tresponse with id $id received");
        if (key_exists($id, $this->queue)) {
            // works also for an empty message body
            $graph            = new Graph();
            $graph->parse($msg->body, 'application/n-triples');
            $this->queue[$id] = $graph;
        }
    }

}
