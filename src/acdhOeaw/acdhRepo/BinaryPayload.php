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
 * Represents a request binary payload.
 *
 * @author zozlak
 */
class BinaryPayload {

    /**
     *
     * @var int
     */
    private $id;
    private $hash;
    private $size;

    public function __construct(int $id) {
        $this->id = $id;
    }

    public function upload(): void {
        $tmpPath    = RC::$config->storage->tmpDir . '/' . $this->id;
        $input      = fopen('php://input', 'rb');
        $output     = fopen($tmpPath, 'wb');
        $this->size = 0;
        $hash       = hash_init(RC::$config->storage->hashAlgorithm);
        while (!feof($input)) {
            $buffer     = fread($input, 1048576);
            hash_update($hash, $buffer);
            $this->size += fwrite($output, $buffer);
        }
        fclose($input);
        fclose($output);
        $this->hash = RC::$config->storage->hashAlgorithm . ':' . hash_final($hash, false);

        $digest = filter_input(INPUT_SERVER, 'HTTP_DIGEST'); // client-side hash to be compared after the upload
        if (!empty($digest)) {
            //TODO - see https://fedora.info/2018/11/22/spec/#http-post
        }

        rename($tmpPath, $this->getPath(true));
    }

    public function updateMetadata(): void {
        $contentDisposition = trim(filter_input(INPUT_SERVER, 'HTTP_CONTENT_DISPOSITION'));
        $fileName           = null;
        if (preg_match('/^attachment; filename=/', $contentDisposition)) {
            $fileName = preg_replace('/^attachment; filename="?/', '', $contentDisposition);
            $fileName = preg_replace('/"$/', '', $fileName);
        }

        $contentType = filter_input(INPUT_SERVER, 'CONTENT_TYPE');
        if (empty($contentType)) {
            if (!empty($fileName)) {
                $contentType = GuzzleHttp\Psr7\mimetype_from_filename($fileName);
                if ($contentType === null) {
                    $contentType = mime_content_type($this->getPath(false));
                }
            }
            if (empty($contentType)) {
                $contentType = RC::$config->rest->defaultMime;
            }
        }

        $queryD = RC::$pdo->prepare("DELETE FROM metadata WHERE (id, property) = (?, ?)");
        $queryV = RC::$pdo->prepare("
            INSERT INTO metadata (id, property, type, lang, value_n, value_t, value) 
            VALUES (?, ?, ?, '', ?, ?, ?)
        ");
        $queryS = RC::$pdo->prepare("
            INSERT INTO metadata (id, property, type, lang, text, textraw) 
            VALUES (?, ?, 'http://www.w3.org/2001/XMLSchema#string', '', to_tsvector(?), ?)
        ");

        foreach (RC::$config->schema->fileName as $i) {
            $queryD->execute([$this->id, $i]);
        }
        if (!empty($fileName)) {
            foreach (RC::$config->schema->fileName as $i) {
                $queryS->execute([$this->id, $i, $fileName, $fileName]);
            }
        }
        foreach (RC::$config->schema->mime as $i) {
            $queryD->execute([$this->id, $i]);
            $queryS->execute([$this->id, $i, $contentType, $contentType]);
        }
        foreach (RC::$config->schema->binarySize as $i) {
            $queryD->execute([$this->id, $i]);
        }
        foreach (RC::$config->schema->hash as $i) {
            $queryD->execute([$this->id, $i]);
        }
        if ($this->size > 0) {
            foreach (RC::$config->schema->binarySize as $i) {
                $queryV->execute([
                    $this->id, $i,
                    'http://www.w3.org/2001/XMLSchema#long',
                    $this->size, null, $this->size
                ]);
            }
            foreach (RC::$config->schema->hash as $i) {
                $queryS->execute([$this->id, $i, $this->hash, $this->hash]);
            }
        }
    }

    public function backup(string $suffix): bool {
        return rename($this->getPath(false), $this->getPath(true, $suffix));
    }
    
    public function restore(string $suffix): bool {
        $backupPath = $this->getPath(false, $suffix);
        if (file_exists($backupPath)) {
            return rename($backupPath, $this->getPath(true));
        }
        return false;
    }
    
    public function delete(string $suffix = ''): bool {
        $path = $this->getPath(false, $suffix);
        if (file_exists($path)) {
            return unlink($path);
        }
        return false;
    }
    
    public function getPath(bool $create = false, string $suffix = ''): string {
        return $this->getStorageDir($this->id, $create) . '/' . $this->id . (empty($suffix) ? '' : '.' . $suffix);
    }

    private function getStorageDir(int $id, bool $create, string $path = null,
                                   int $level = 0): string {
        if (empty($path)) {
            $path = RC::$config->storage->dir;
        }
        if ($level < RC::$config->storage->levels) {
            $path = sprintf('%s/%02d', $path, $id % 100);
            if ($create && !file_exists($path)) {
                mkdir($path, base_convert(RC::$config->storage->modeDir, 8, 10));
            }
            $path = $this->getStorageDir((int) $id / 100, $create, $path, $level + 1);
        }
        return $path;
    }

}
