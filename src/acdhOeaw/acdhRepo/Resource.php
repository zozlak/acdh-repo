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

use acdhOeaw\acdhRepo\RestController as RC;
use acdhOeaw\acdhRepo\Transaction;
use acdhOeaw\acdhRepoLib\RepoResourceInterface as RRI;

/**
 * Description of Resource
 *
 * @author zozlak
 */
class Resource {

    const STATE_ACTIVE    = 'active';
    const STATE_TOMBSTONE = 'tombstone';
    const STATE_DELETED   = 'deleted';

    private $id;

    public function __construct(?int $id) {
        $this->id = $id;
    }

    public function optionsMetadata(int $code = 204): void {
        http_response_code($code);
        header('Allow: OPTIONS, HEAD, GET, PATCH');
        header('Accept: ' . Metadata::getAcceptedFormats());
    }

    public function headMetadata(bool $get = false): void {
        $this->checkCanRead(true);
        $meta       = new Metadata($this->id);
        $mode       = filter_input(\INPUT_SERVER, RC::getHttpHeaderName('metadataReadMode')) ?? RC::$config->rest->defaultMetadataReadMode;
        $parentProp = filter_input(\INPUT_SERVER, RC::getHttpHeaderName('metadataParentProperty')) ?? RC::$config->schema->parent;
        $meta->loadFromDb(strtolower($mode), $parentProp);

        $format = filter_input(\INPUT_GET, 'format');
        if (!empty($format) && !in_array($format, RC::$config->rest->metadataFormats)) {
            throw new RepoException('Unsupported metadata format requested', 400);
        }
        $format = $meta->outputHeaders($format);
        $meta->outputRdf($format);
    }

    public function getMetadata(): void {
        $this->headMetadata(true);
    }

    public function patchMetadata(): void {
        $this->checkCanWrite();
        $meta = new Metadata($this->id);
        $meta->loadFromRequest();
        $mode = filter_input(\INPUT_SERVER, RC::getHttpHeaderName('metadataWriteMode')) ?? RC::$config->rest->defaultMetadataWriteMode;
        $meta->merge(strtolower($mode));
        $meta->loadFromResource(RC::$handlersCtl->handleResource('updateMetadata', $this->id, $meta->getResource(), null));
        $meta->save();
        $this->getMetadata();
    }

    public function move(): void {
        $this->checkCanWrite();
        $srcUri = $this->getUri();
        
        // check writes on destination resource and lock it
        $dst   = filter_input(\INPUT_SERVER, 'HTTP_DESTINATION');
        $p     = strlen(RC::getBaseUrl());
        if (substr($dst, 0, $p) !== RC::getBaseUrl()) {
            throw new RepoException('Destination resource outside the repository', 400);
        }
        $dstId = substr($dst, $p);
        $srcId = $this->id;
        $this->id = $dstId;
        $this->checkCanWrite();
        
        // move identifiers and references
        $query = RC::$pdo->prepare("UPDATE identifiers SET id = ? WHERE id = ? AND ids <> ?");
        $query->execute([$dstId, $srcId, $srcUri]);
        $query = RC::$pdo->prepare("UPDATE relations SET target_id = ? WHERE target_id = ?");
        $query->execute([$dstId, $srcId]);
        // mark resource as deleted
        $query = RC::$pdo->prepare("UPDATE resources SET state = ? WHERE id = ?");
        $query->execute([self::STATE_DELETED, $srcId]);
        $query = RC::$pdo->prepare("DELETE FROM relations WHERE id = ?");
        $query->execute([$srcId]);
        $query = RC::$pdo->prepare("DELETE FROM identifiers WHERE id = ?");
        $query->execute([$srcId]);
        
        $this->headMetadata(true);
    }

    public function options(int $code = 204): void {
        http_response_code($code);
        header('Allow: OPTIONS, HEAD, GET, PUT, DELETE');
    }

    public function head(): void {
        $this->checkCanRead();
        $binary = new BinaryPayload($this->id);
        $binary->outputHeaders();
    }

    public function get(): void {
        $this->head();
        $binary = new BinaryPayload($this->id);
        $path   = $binary->getPath();
        if (file_exists($path)) {
            readfile($path);
        }
    }
    
    /**
     * Get the values of all properties of a graph to a Metadata.
     * This is used for calculating a diff between two Metadata graphs.
     * 
     * @param \acdhOeaw\acdhRepo\Metadata $meta
     * @return array
     */
    private function valuesOfGraphProperties(Metadata $meta) {
        $valueProperties = [];
        foreach($meta->graph->propertyUris() as $p) {
            foreach($meta->all($p) as $v) {
                $valueProperties[$p][] = $v;
            }
        }
        return $valueProperties;
    }
    
    /**
     * Compare two property-arrays and return all differences. The reference is
     * propMerge: It is only of relevance, what values from propMerge are different
     * to propOriginal. It is expected, that propMerge has every property that
     * propPoriginal also has.
     * 
     * @param array $propOriginal
     * @param array $propMerge
     * @return array
     */
    private function diffGraphProperties(array $propOriginal, array $propMerge) {
      $diff = [];
      foreach($propMerge as $prop=>$values) {
          if (isset($propOriginal[$prop])) {
              // Return only if there are differnt values in the property.
              $propValDiffs = array_diff($values, $propOriginal[$prop]);
              if (!empty($propValDiffs)) {
                  $diff[$prop] = $propValDiffs;
              }
          } else {
              // Return full property if it is new.
              $diff[$prop] = $values;
          }
      }
      return $diff;
    }

    public function put(): void {
        $this->checkCanWrite();

        // To compare the differences that should be returned as response after
        // a successful merge, it is necessary to have the original metadata.
        $tmp  = new Metadata($this->id);
        $tmp->loadFromDb(RRI::META_RESOURCE);
        $originalResourceMetadata = $this->valuesOfGraphProperties($tmp->graph->resource());

        $binary = new BinaryPayload($this->id);
        $binary->upload();

        $meta = new Metadata($this->id);
        $meta->update($binary->getRequestMetadata());
        $meta->merge(Metadata::SAVE_MERGE);
        $meta->loadFromResource(RC::$handlersCtl->handleResource('updateBinary', $this->id, $meta->getResource(), $binary->getPath()));
        $meta->save();
        
        // To calculate the difference between metadata of the original and the new
        // resource, first get the metadata from the udpated resource
        $mergeResourceMetadata = $this->valuesOfGraphProperties($meta);
        // Then it is necessary to have the original data to compare it and to
        // find out the differences.
        $diff = $this->diffGraphProperties($mergeResourceMetadata, $originalResourceMetadata);
        
        if (!empty($diff)) {
            // Now create a new metadata object out of the differences
            $response = new Metadata($this->id);
            foreach($diff as $prop=>$values) {
                foreach($values as $val) {
                  $response->graph->add($response->getUri(), $prop, $val);
              }
            }

            // Return the differences as request body.
            http_response_code(200);
            echo $response->outputRdf('text/html');
        } else {
            // If there is no difference, return a 204.
            // @todo: Return a specific message if there are no differences?
            http_response_code(204);
        }
    }

    public function delete(): void {
        $this->checkCanWrite();

        $query = RC::$pdo->prepare("
            UPDATE resources SET state = ? WHERE id = ?
            RETURNING state, transaction_id
        ");
        $query->execute([self::STATE_TOMBSTONE, $this->id]);
        RC::$log->debug($query->fetchObject());

        $binary = new BinaryPayload($this->id);
        $binary->backup(RC::$transaction->getId());

        // delete from relations and identifiers so it doesn't enforce/block existence of any other resources
        // keep metadata because they can still store important information, e.g. access rights
        $query = RC::$pdo->prepare("DELETE FROM relations WHERE id = ?");
        $query->execute([$this->id]);
        $query = RC::$pdo->prepare("DELETE FROM identifiers WHERE id = ?");
        $query->execute([$this->id]);

        $meta = new Metadata($this->id);
        $meta->merge(Metadata::SAVE_MERGE);
        $meta->loadFromResource(RC::$handlersCtl->handleResource('delete', $this->id, $meta->getResource(), $binary->getPath()));
        $meta->save();

        http_response_code(204);
    }

    public function optionsTombstone(int $code = 204): void {
        http_response_code($code);
        header('Allow: OPTIONS, DELETE');
    }

    public function deleteTombstone(): void {
        $this->checkCanWrite(true);

        $query = RC::$pdo->prepare("
            UPDATE resources SET state = ? WHERE id = ? 
            RETURNING state, transaction_id
        ");
        $query->execute([self::STATE_DELETED, $this->id]);

        $meta = new Metadata($this->id);
        $meta->loadFromDb(RRI::META_RESOURCE);
        RC::$handlersCtl->handleResource('deleteTombstone', $this->id, $meta->getResource(), null);

        RC::$log->debug($query->fetchObject());
        http_response_code(204);
    }

    public function optionsCollection(int $code = 204): void {
        http_response_code($code);
        header('Allow: OPTIONS, POST');
    }

    public function postCollection(): void {
        $this->checkCanCreate();

        $this->createResource();

        $binary = new BinaryPayload($this->id);
        $binary->upload();

        $meta = new Metadata($this->id);
        $meta->update($binary->getRequestMetadata());
        $meta->update(RC::$auth->getCreateRights());
        $meta->merge(Metadata::SAVE_OVERWRITE);
        $meta->loadFromResource(RC::$handlersCtl->handleResource('create', $this->id, $meta->getResource(), $binary->getPath()));
        $meta->save();

        http_response_code(201);
        header('Location: ' . $this->getUri());
    }

    public function optionsCollectionMetadata(int $code = 204): void {
        http_response_code($code);
        header('Allow: OPTIONS, POST');
        header('Accept: ' . Metadata::getAcceptedFormats());
    }

    public function postCollectionMetadata(): void {
        $this->checkCanCreate();

        $this->createResource();

        $meta  = new Metadata($this->id);
        $count = $meta->loadFromRequest(RC::getBaseUrl());
        RC::$log->debug("\t$count triples loaded from the user request");
        $meta->update(RC::$auth->getCreateRights());
        $meta->merge(Metadata::SAVE_OVERWRITE);
        $meta->loadFromResource(RC::$handlersCtl->handleResource('create', $this->id, $meta->getResource(), null));
        $meta->save();

        http_response_code(201);
        header('Location: ' . $this->getUri());
    }

    public function getUri(): string {
        return RC::getBaseUrl() . $this->id;
    }

    public function checkCanRead(bool $metadata = false): void {
        $query = RC::$pdo->prepare("SELECT state FROM resources WHERE id = ?");
        $query->execute([$this->id]);
        $state = $query->fetchColumn();

        if ($state === false || $state === self::STATE_DELETED) {
            throw new RepoException('Not Found', 404);
        }
        if ($state === self::STATE_TOMBSTONE) {
            throw new RepoException('Gone', 410);
        }

        RC::$auth->checkAccessRights($this->id, 'read', $metadata);
    }

    public function checkCanCreate(): void {
        $this->checkTransactionState();
        RC::$auth->checkCreateRights();
    }

    public function checkCanWrite(bool $tombstone = false): void {
        $this->checkTransactionState();

        $txId   = RC::$transaction->getId();
        $query  = RC::$pdo->prepare("
            UPDATE resources 
            SET transaction_id = ?
            WHERE id = ? AND (transaction_id IS NULL OR transaction_id = ?)
            RETURNING state
        ");
        $query->execute([$txId, $this->id, $txId]);
        $result = $query->fetchObject();
        if ($result === false) {
            $query = RC::$pdo->prepare("SELECT state FROM resources WHERE id = ?");
            $query->execute([$this->id]);
            $state = $query->fetchColumn();
            if ($state === false || $state === self::STATE_DELETED) {
                throw new RepoException('Not found', 404);
            } else {
                throw new RepoException('Owned by other transaction', 403);
            }
        }
        if ($result->state === self::STATE_DELETED) {
            throw new RepoException('Not Found', 404);
        }
        if (!$tombstone && $result->state === self::STATE_TOMBSTONE) {
            throw new RepoException('Gone', 410);
        }
        if ($tombstone && $result->state !== self::STATE_TOMBSTONE) {
            throw new RepoException('Not a tombstone', 405);
        }

        RC::$auth->checkAccessRights($this->id, 'write', false);
    }

    private function checkTransactionState(): void {
        $txState = RC::$transaction->getState();
        if (empty($txState)) {
            throw new RepoException('Begin transaction first', 400);
        }
        if ($txState !== Transaction::STATE_ACTIVE) {
            throw new RepoException('Wrong transaction state: ' . $txState, 400);
        }
    }

    private function createResource(): void {
        $query    = RC::$pdo->prepare("INSERT INTO resources (transaction_id) VALUES (?) RETURNING id");
        $query->execute([RC::$transaction->getId()]);
        $this->id = $query->fetchColumn();
        RC::$log->info("\t" . $this->getUri());
    }

}
