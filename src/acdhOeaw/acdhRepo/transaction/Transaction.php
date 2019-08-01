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

namespace acdhOeaw\acdhRepo\transaction;

use acdhOeaw\acdhRepo\RestController as RC;

/**
 * Description of Transaction
 *
 * @author zozlak
 */
class Transaction {

    private $id;
    private $startedAt;
    private $lastRequest;
    private $state;

    public function __construct() {
        header('Cache-Control: no-cache');
        $id = (int) filter_input(\INPUT_SERVER, 'HTTP_X_TRANSACTION_ID');
        $this->fetchData($id);
    }

    public function getId(): ?int {
        return $this->id;
    }

    public function options() {
        http_response_code(204);
        header('Allow: OPTIONS, POST, HEAD, GET, PATCH, PUT, DELETE');
    }

    public function head(): void {
        if ($this->id === null) {
            throw new RepoException('Unknown transaction', 400);
        }
        header('Content-Type: application/json');
        header('X-Transaction-Id: ' . $this->id);
    }

    public function get(): void {
        $this->head();
        echo json_encode([
            'transactionId' => $this->id,
            'startedAt'     => $this->startedAt,
            'lastRequest'   => $this->lastRequest,
            'state'         => $this->state,
        ]) . "\n";
    }

    public function patch(): void {
        if ($this->id === null) {
            throw new RepoException('Unknown transaction', 400);
        }

        $query = RC::$pdo->prepare("
            UPDATE transactions SET last_request = now() WHERE transaction_id = ?
        ");
        $query->execute([$this->id]);
        $this->fetchData($this->id);
        $this->get();
    }

    public function delete(): void {
        if ($this->id === null) {
            throw new RepoException('Unknown transaction', 400);
        }

        $query = RC::$pdo->prepare("
            UPDATE transactions SET state = 'rollback' WHERE transaction_id = ?
        ");
        $query->execute([$this->id]);

        $this->wait();
        http_response_code(204);
    }

    public function put(): void {
        $id = TransactionController::registerTransaction(RC::$config);
        $this->fetchData($id);
        $this->get();
    }

    public function post(): void {
        if ($this->id === null) {
            throw new RepoException('Unknown transaction', 400);
        }

        //TODO - transaction handlers go here

        $query = RC::$pdo->prepare("
            UPDATE transactions SET state = 'commit' WHERE transaction_id = ?
        ");
        $query->execute([$this->id]);

        $this->wait();
        http_response_code(204);
    }

    private function fetchData(?int $id): void {
        $query = RC::$pdo->prepare("
            UPDATE transactions SET last_request = now() WHERE transaction_id = ?
            RETURNING started, last_request AS last, state
        ");
        $query->execute([$id]);
        $data  = $query->fetchObject();
        if ($data !== false) {
            $this->id          = $id;
            $this->startedAt   = $data->started;
            $this->lastRequest = $data->last;
            $this->state       = $data->state;
        }
    }

    /**
     * Actively waits until the transaction controller daemon rollbacks/commits the transaction
     */
    private function wait() {
        $query = RC::$pdo->prepare("SELECT count(*) FROM transactions WHERE transaction_id = ?");
        do {
            usleep(1000 * RC::$config->transactionController->checkInterval / 4);
            $query->execute([$this->id]);
            $count = $query->fetchColumn();
        } while ($count > 0);
    }

}
