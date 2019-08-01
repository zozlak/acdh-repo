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

use acdhOeaw\acdhRepo\RestController as RC;

/**
 * Description of Resource
 *
 * @author zozlak
 */
class Resource {

    private $id;

    public function __construct(?int $id) {
        $this->id = $id;
    }

    public function getMetadata(): void {
        $this->checkIfExists();
    }
    
    public function patchMetadata(): void {
        $this->checkIfExists(true);
    }
    
    public function putMetadata(): void {
        $this->checkIfExists(true);
    }
    
    public function postMetadata(): void {
        $this->checkIfExists(true);
    }
    
    public function get(): void {
        $this->checkIfExists();
        
        //TODO embed access rights check into the database
    }
    
    public function put(): void {
        $this->checkIfExists(true);
    }
    
    public function delete(): void {
        $this->checkIfExists(true);
    }
    
    public function deleteTombstone(): void {
        $this->checkIfExists(true);
    }
    
    public function postCollection(): void {
        RC::$auth->checkCreateRights();
    }
    
    public function postCollectionMetadata(): void {
        
    }
    
    private function checkIfExists(bool $checkTransaction = false): void {
        $transactionId = RC::$transaction->getId();
        if ($checkTransaction && $transactionId === null) {
            throw new RepoException('Action requires an opened transaction', 400);
        }
        
        $query = RC::$pdo->prepare("
            SELECT (transaction_id IS NULL OR transaction_id = ?)::int AS valid
            FROM resources 
            WHERE id = ?
        ");
        $query->execute([$transactionId, $this->id]);
        $result = $query->fetchColumn();
        if ($result === false) {
            throw new RepoException('Not found', 404);
        }
        if ($checkTransaction && $result === 0) {
            throw new RepoException('Owned by other transaction', 403);
        }
    }
}
