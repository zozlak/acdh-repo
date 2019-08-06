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

use PDO;
use PDOException;
use RuntimeException;
use acdhOeaw\acdhRepo\RestController as RC;

/**
 * Description of Transaction
 *
 * @author zozlak
 */
class Transaction {

    const STATE_ACTIVE             = 'active';
    const STATE_COMMIT             = 'commit';
    const STATE_ROLLBACK           = 'rollback';
    const PG_FOREIGN_KEY_VIOLATION = 23503;

    private $id;
    private $startedAt;
    private $lastRequest;
    private $state;

    /**
     * Database connection.
     * A separate is required so it can commit changes independently from the main connection.
     * @var \PDO
     */
    private $pdo;

    public function __construct() {
        $this->pdo = new PDO(RC::$config->dbConnStr);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        header('Cache-Control: no-cache');
        $id = (int) filter_input(\INPUT_SERVER, 'HTTP_X_TRANSACTION_ID');
        $this->fetchData($id);
    }

    public function getId(): ?int {
        return $this->id;
    }

    public function getState(): ?string {
        return $this->state;
    }

    public function options(int $code = 204) {
        http_response_code($code);
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

        $query = $this->pdo->prepare("
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

        $query = $this->pdo->prepare("
            UPDATE transactions SET state = 'rollback' WHERE transaction_id = ?
        ");
        $query->execute([$this->id]);

        $this->wait();
        http_response_code(204);
    }

    public function put(): void {
        if ($this->id === null) {
            throw new RepoException('Unknown transaction', 400);
        }

        //TODO - transaction handlers go here

        try {
            $query = $this->pdo->prepare("
                DELETE FROM resources
                WHERE transaction_id = ? AND state = ?
            ");
            $query->execute([$this->id, Resource::STATE_DELETED]);

            $query = $this->pdo->prepare("
                UPDATE transactions SET state = ? WHERE transaction_id = ?
            ");
            $query->execute([self::STATE_COMMIT, $this->id]);
            http_response_code(204);
        } catch (PDOException $e) {
            RC::$log->error($e);
            http_response_code(409);
        }

        $this->wait();
    }

    public function post(): void {
        try {
            $id = TransactionController::registerTransaction(RC::$config);
        } catch (RepoException $e) {
            throw new RuntimeException('Transaction creation failed', 500, $e);
        }
        http_response_code(201);
        $this->fetchData($id);
        $this->get();
    }

    private function fetchData(?int $id): void {
        $query = $this->pdo->prepare("
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
    private function wait(): void {
        $query = $this->pdo->prepare("SELECT * FROM transactions WHERE transaction_id = ?");
        do {
            usleep(1000 * RC::$config->transactionController->checkInterval / 4);
            RC::$log->info('Waiting for the transaction ' . $this->id . ' to stop');
            $query->execute([$this->id]);
            $exists = $query->fetchObject() !== false;
        } while ($exists);
    }

}
