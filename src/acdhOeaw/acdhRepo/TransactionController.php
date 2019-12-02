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

use PDO;
use PDOException;
use Throwable;
use acdhOeaw\acdhRepo\RepoException;
use zozlak\logging\Log;

/**
 * Description of TransactionController
 *
 * @author zozlak
 */
class TransactionController {

    const TYPE_UNIX                  = 'unix';
    const TYPE_INET                  = 'inet';
    const DBERROR_LOCK_NOT_AVAILABLE = '55P03';

    private static function getSocketConfig(object $config): array {
        $c = $config->transactionController->socket;
        switch ($c->type) {
            case self::TYPE_INET:
                $type    = AF_INET;
                $address = $c->address;
                $port    = $c->port;
                break;
            case self::TYPE_UNIX:
                $type    = AF_UNIX;
                $address = $c->path;
                $port    = 0;
                break;
            default:
                throw new RepoException('Unknown socket type');
        }
        return [$type, $address, $port];
    }

    /**
     * Registers a new transaction by connecting to the transaction controller daemon
     * @param object $config
     * @return int
     * @throws RepoException
     */
    public static function registerTransaction(object $config): int {
        list($type, $address, $port) = self::getSocketConfig($config);

        $socket = @socket_create($type, SOCK_STREAM, 0);
        if ($socket === false) {
            throw new RepoException("Failed to create a socket: " . socket_strerror(socket_last_error()) . "\n");
        }

        $ret = @socket_connect($socket, $address, $port);
        if ($ret === false) {
            throw new RepoException("Failed to connect to a socket: " . socket_strerror(socket_last_error($socket)) . "\n");
        }

        $txId = socket_read($socket, 100, PHP_NORMAL_READ);

        socket_close($socket);
        return (int) $txId;
    }

    private $configFile;
    private $config;
    private $socket;
    private $log;
    private $loop  = true;
    private $child = false;

    public function __construct(string $configFile) {
        $this->configFile = $configFile;
        $this->loadConfig();
        $c                = $this->config->transactionController;

        $this->log = new Log($c->logging->file, $c->logging->level);

        list($type, $address, $port) = self::getSocketConfig($this->config);
        if (file_exists($address)) {
            unlink($address);
        }

        $this->socket = @socket_create($type, SOCK_STREAM, 0);
        if ($this->socket === false) {
            throw new RepoException("Failed to create a socket: " . socket_strerror(socket_last_error()) . "\n");
        }

        $ret = @socket_bind($this->socket, $address, $port);
        if ($ret === false) {
            throw new RepoException("Failed to bind to a socket: " . socket_strerror(socket_last_error($this->socket)) . "\n");
        }
        $ret = @socket_listen($this->socket, SOMAXCONN);
        if ($ret === false) {
            throw new RepoException("Failed to listen on a socket: " . socket_strerror(socket_last_error($this->socket)) . "\n");
        }
        $ret = socket_set_nonblock($this->socket);
        if ($ret === false) {
            throw new RepoException("Failed to set socket in a non-blocking mode\n");
        }
    }

    public function __destruct() {
        if (!$this->child) {
            if (is_resource($this->socket)) {
                socket_close($this->socket);
            }
            $c = $this->config->transactionController;
            if ($c->socket->type === self::TYPE_UNIX && file_exists($c->socket->path)) {
                unlink($c->socket->path);
            }
        }
    }

    public function handleRequests(): void {
        while ($this->loop) {
            $connSocket = socket_accept($this->socket);
            if ($connSocket === false) {
                usleep(1000);
            } else {
                $pid = pcntl_fork();
                if ($pid === 0) {
                    $this->child = true;
                    $this->handleRequest($connSocket);
                    socket_close($connSocket);
                    $this->stop();
                } elseif ($pid === -1) {
                    $this->log->error("Failed to fork\n");
                    socket_close($connSocket);
                } else {
                    socket_close($connSocket);
                }
            }
        }
    }

    public function stop(): void {
        $this->loop = false;
    }

    public function loadConfig(): void {
        if ($this->log !== null) {
            $this->log->info('Reloading configuration');
        }
        $this->config           = json_decode(json_encode(yaml_parse_file($this->configFile)));
        RestController::$config = $this->config;
    }

    private function handleRequest($connSocket): void {
        try {
            $timeout       = $this->config->transactionController->timeout;
            $checkInterval = 1000 * $this->config->transactionController->checkInterval;

            $this->log->info("Handling a connection");

            $pdo        = new PDO($this->config->dbConnStr->admin);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $preTxState = new PDO($this->config->dbConnStr->admin);
            $preTxState->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $preTxState->query("START TRANSACTION ISOLATION LEVEL SERIALIZABLE READ ONLY DEFERRABLE");
            $snapshot = $preTxState->query("SELECT pg_export_snapshot()")->fetchColumn();

            $query = $pdo->prepare("
                INSERT INTO transactions (transaction_id, snapshot) VALUES ((random() * 9223372036854775807)::bigint, ?) 
                RETURNING transaction_id AS id
            ");
            $query->execute([$snapshot]);
            $txId  = $query->fetchColumn();
            $this->log->info("Transaction $txId created");

            $checkQuery = $pdo->prepare("
                SELECT 
                    state, 
                    extract(epoch from now() - last_request) AS delay 
                FROM transactions 
                WHERE transaction_id = ?
                FOR UPDATE NOWAIT
            ");
            $checkQuery->execute([$txId]); // only to make sure it's runs fine before we confirm readiness to the client

            $ret = @socket_write($connSocket, $txId . "\n");
            if ($ret === false) {
                $this->log->error("Transaction $txId - client notification error: " . socket_strerror(socket_last_error($connSocket)));
            } else {
                $this->log->info("Transaction $txId - client notified");
            }
            $state = (object) ['state' => Transaction::STATE_ACTIVE, 'delay' => 0];
            do {
                usleep($checkInterval);
                try {
                    $checkQuery->execute([$txId]);
                    $state = $checkQuery->fetchObject();
                } catch (PDOException $e) {
                    if ($e->getCode() !== self::DBERROR_LOCK_NOT_AVAILABLE) {
                        $state = false;
                    }
                }

                if ($state !== false) {
                    $this->log->debug("Transaction $txId state: " . $state->state . ", " . $state->delay . " s");
                } else {
                    $this->log->info("Transaction $txId state: not exists");
                }
            } while ($state !== false && $state->state === Transaction::STATE_ACTIVE && $state->delay < $timeout);

            if ($state === false || $state->state !== Transaction::STATE_COMMIT) {
                $this->rollbackTransaction($txId, $pdo, $preTxState);
            } else {
                $this->commitTransaction($txId, $pdo, $preTxState);
            }
            $preTxState->query('COMMIT');

            $pdo->beginTransaction();
            $query = $pdo->prepare("UPDATE resources SET transaction_id = null WHERE transaction_id = ?");
            $query->execute([$txId]);
            $query = $pdo->prepare("DELETE FROM transactions WHERE transaction_id = ?");
            $query->execute([$txId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $this->log->error($e);
        } finally {
            if (isset($txId)) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $pdo->beginTransaction();
                $query = $pdo->prepare("UPDATE resources SET transaction_id = null WHERE transaction_id = ?");
                $query->execute([$txId]);
                $query = $pdo->prepare("DELETE FROM transactions WHERE transaction_id = ?");
                $query->execute([$txId]);
                $pdo->commit();
            }
            $this->log->info("Transaction $txId finished");
        }
    }

    /**
     * Rolls back a transaction by:
     * - finding all resources visible for the $currState assigned to the transaction $txId
     * - bringing their state back to the one visible for the $preTxState
     * @param PDO $pdo
     * @param PDO $preTxState
     * @return void
     */
    private function rollbackTransaction(int $txId, PDO $curState,
                                         PDO $prevState): void {
        $this->log->info("Transaction $txId - rollback");

        $queryResDel  = $curState->prepare("DELETE FROM resources WHERE id = ?");
        $queryIdDel   = $curState->prepare("DELETE FROM identifiers WHERE id = ?");
        $queryRelDel  = $curState->prepare("DELETE FROM relations WHERE id = ?");
        $queryMetaDel = $curState->prepare("DELETE FROM metadata WHERE id = ?");
        $queryFtsDel  = $curState->prepare("DELETE FROM full_text_search WHERE id = ?");
        $queryResUpd  = $curState->prepare("UPDATE resources SET state = ? WHERE id = ?");
        $queryIdIns   = $curState->prepare("INSERT INTO identifiers (ids, id) VALUES (?, ?)");
        $queryRelIns  = $curState->prepare("INSERT INTO relations (id, target_id, property) VALUES (?, ?, ?)");
        $queryMetaIns = $curState->prepare("INSERT INTO metadata (mid, id, property, type, lang, value_n, value_t, value) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $queryFtsIns  = $curState->prepare("INSERT INTO full_text_search (ftsid, id, property, segments, raw) VALUES (?, ?, ?, ?, ?)");
        $queryIdSel   = $prevState->prepare("SELECT ids, id FROM identifiers WHERE id = ?");
        $queryRelSel  = $prevState->prepare("SELECT id, target_id, property FROM relations WHERE id = ?");
        $queryMetaSel = $prevState->prepare("SELECT mid, id, property, type, lang, value_n, value_t, value FROM metadata WHERE id = ?");
        $queryFtsSel  = $prevState->prepare("SELECT ftsid, id, property, segments, raw FROM full_text_search WHERE id = ?");
        $queryPrev    = $prevState->prepare("SELECT state FROM resources WHERE id = ?");
        $queryCur     = $curState->prepare("SELECT id FROM resources WHERE transaction_id = ?");
        $queryCur->execute([$txId]);
        $toRestore    = [];
        while ($rid          = $queryCur->fetchColumn()) {
            $queryPrev->execute([$rid]);
            $state  = $queryPrev->fetchColumn();
            $binary = new BinaryPayload($rid);
            if ($state === false) {
                // resource didn't exist before - delete it
                $this->log->debug("  deleting $rid");
                $queryResDel->execute([$rid]);
                $binary->delete();
            } else {
                // must be processed later as they can cause conflicts with resources still to be deleted
                $toRestore[$rid] = $state;
            }
        }
        foreach ($toRestore as $rid => $state) {
            // resource existed before - restore it's state
            $this->log->debug("  revoking $rid state to $state");

            $queryResUpd->execute([$state, $rid]);

            $queryIdDel->execute([$rid]);
            $queryIdSel->execute([$rid]);
            while ($i = $queryIdSel->fetch(PDO::FETCH_NUM)) {
                $queryIdIns->execute($i);
            }

            $queryRelDel->execute([$rid]);
            $queryRelSel->execute([$rid]);
            while ($i = $queryRelSel->fetch(PDO::FETCH_NUM)) {
                $queryRelIns->execute($i);
            }

            $queryMetaDel->execute([$rid]);
            $queryMetaSel->execute([$rid]);
            while ($i = $queryMetaSel->fetch(PDO::FETCH_NUM)) {
                $queryMetaIns->execute($i);
            }

            $queryFtsDel->execute([$rid]);
            $queryFtsSel->execute([$rid]);
            while ($i = $queryFtsSel->fetch(PDO::FETCH_NUM)) {
                $queryFtsIns->execute($i);
            }

            $binary->restore($txId);
        }
    }

    /**
     * Commits a transaction, e.g. saves metadata history changes.
     * @param int $txId
     * @param PDO $curState
     * @param PDO $prevState
     * @return void
     */
    private function commitTransaction(int $txId, PDO $curState, PDO $prevState): void {
        $this->log->info("Transaction $txId - commit");

        if ($this->config->transactionController->simplifyMetaHistory) {
            $query = $curState->prepare("
                WITH todel AS (
                    SELECT *
                    FROM (
                        SELECT 
                            mh.*, 
                            min(date) OVER (PARTITION BY id) AS datemin
                        FROM
                            metadata_history mh
                            JOIN resources r USING (id)
                            JOIN transactions t USING (transaction_id)
                        WHERE 
                            transaction_id = ?
                            AND mh.date >= t.started
                    ) t1
                    WHERE date > datemin
                )
                DELETE FROM metadata_history WHERE midh IN (SELECT midh FROM todel)
            ");
            $query->execute([$txId]);
            $this->log->info("Transaction $txId - " . $query->rowCount() . " metadata history rows removed");
        }
    }

}
