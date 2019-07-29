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
use Throwable;
use EasyRdf\Graph;
use EasyRdf\Literal;

/**
 * Description of RestController
 *
 * @author zozlak
 */
class RestController {

    const ID_CREATE        = 0;
    const LOGLEVEL_DEBUG   = 1;
    const LOGLEVEL_INFO    = 2;
    const LOGLEVEL_WARNING = 3;
    const LOGLEVEL_ERROR   = 4;
    const ACCESS_READ      = 1;
    const ACCESS_WRITE     = 2;

    static private $outputFormats = [
        'text/turtle'           => 'text/turtle',
        'application/rdf+xml'   => 'application/rdf+xml',
        'application/n-triples' => 'application/n-triples',
        'application/ld+json'   => 'application/ld+json',
        '*/*'                   => 'text/turtle',
        'text/*'                => 'text/turtle',
        'application/*'         => 'application/n-triples',
    ];

    /**
     *
     * @var object
     */
    private $config;
    private $pdo;
    private $routes = [];

    public function __construct(object $config) {
        $this->config = $config;
        $this->pdo    = new PDO($this->config->dbConnStr);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->beginTransaction();

        $e            = $this->config->rest->endpoints;
        $this->routes = [
            'POST'   => [
                '|^sparql/?$|'            => 'dummySparql',
                $e->transaction->begin    => 'beginTransaction',
                $e->transaction->commit   => 'dummyTransaction',
                $e->transaction->rollback => 'dummyTransaction',
                $e->transaction->prolong  => 'dummyTransaction',
                '//'                      => 'createResource',
            ],
            'PATCH'  => [
                $e->metadata => 'patchResourceMetadata',
            ],
            'PUT'    => [
                '//' => 'putResource',
            ],
            'DELETE' => [
                $e->tombstone => 'deleteResourceTombstone',
                '//'          => 'deleteResource',
            ],
            'GET'    => [
                $e->metadata      => 'getResourceMetadata',
                '//'              => 'getResource',
            ]
        ];

        $this->log("------------------------------", self::LOGLEVEL_INFO);
        $this->log(filter_input(INPUT_SERVER, 'REQUEST_METHOD') . " " . filter_input(INPUT_SERVER, 'REQUEST_URI'), self::LOGLEVEL_INFO);
    }

    public function __destruct() {
        $this->log("return code " . http_response_code(), self::LOGLEVEL_INFO);
        $this->pdo->commit();
    }

    public function handleRequest(): void {
        $method = filter_input(INPUT_SERVER, 'REQUEST_METHOD');
        if (!isset($this->routes[$method])) {
            http_response_code(501);
        } else {
            $path    = substr(filter_input(INPUT_SERVER, 'REQUEST_URI'), strlen($this->config->rest->pathBase));
            $handled = false;
            foreach ($this->routes[$method] as $regex => $method) {
                if (preg_match($regex, $path)) {
                    $this->log('Handling with ' . $method);
                    $handled = true;
                    try {
                        $this->$method();
                    } catch (RepoException $e) {
                        http_response_code($e->getCode());
                        echo rtrim($e->getMessage()) . "\n";
                    } catch (Throwable $e) {
                        http_response_code(500);
                        throw $e;
                    }
                    break;
                }
            }
            if (!$handled) {
                http_response_code(404);
            }
        }
    }

    private function deleteResourceTombstone(): void {
        list($id, $tx) = $this->getId();
        $this->checkRights($id, self::ACCESS_WRITE, $this->getRoles());

        if ($id === 0) {
            http_response_code(404);
        } else if ($id !== false) {
            http_response_code(400); // not a tombstone
        } else {
            $id    = (int) preg_replace('|^.*/([0-9]+)/fcr:tombstone/?|', '\\1', filter_input(INPUT_SERVER, 'REQUEST_URI'));
            $query = $this->pdo->prepare('DELETE FROM resources WHERE id = ?');
            try {
                $query->execute([$id]);
                http_response_code(204);
            } catch (PDOException $e) {
                http_response_code(409);
                throw $e;
            }
        }
    }

    private function deleteResource(): void {
        list($id, $tx) = $this->getId();
        $this->checkRights($id, self::ACCESS_WRITE, $this->getRoles());

        $query = $this->pdo->prepare('UPDATE resources SET tombstone = true WHERE id = ?');
        try {
            $query->execute([$id]);
            http_response_code(204);
        } catch (PDOException $ex) {
            http_response_code(409);
        }
    }

    private function getResource(): void {
        list($id, $tx) = $this->getId();
        $this->checkRights($id, self::ACCESS_READ, $this->getRoles());

        $path = $this->getResourcePath($id, false);
        if (file_exists($path)) {
            readfile($path);
        } else {
            http_response_code(204);
        }
    }

    private function patchResourceMetadata(): void {
        list($id, $tx) = $this->getId();
        $this->checkRights($id, self::ACCESS_WRITE, $this->getRoles());

        $this->saveMetadataHistory($id);

        $contentType = filter_input(INPUT_SERVER, 'CONTENT_TYPE');
        if ($contentType === 'application/sparql-update') {
            // https://github.com/farafiri/PHP-parsing-tool

            $input = fopen('php://input', 'r');
            $raw   = str_replace(["\n", "\r"], '', stream_get_contents($input));
            fclose($input);

            $delete = preg_replace('/.*(DELETE *{[^}]*}).*/', '\\1', $raw);
            preg_match_all('/<([^>]+)> *<([^>]+)> *("[^"]+"|<[^>]+>)/', $delete, $delete, PREG_SET_ORDER);
            $queryM = $this->pdo->prepare("DELETE FROM metadata WHERE (id, property, coalesce(value, textraw)) = (?, ?, ?)");
            $queryI = $this->pdo->prepare("DELETE FROM identifiers WHERE (id, ids) = (?, ?)");
            $queryR = $this->pdo->prepare("DELETE FROM relations WHERE (id, property) = (?, ?) AND target_id = (SELECT id FROM identifiers WHERE ids = ?) RETURNING target_id");
            foreach ($delete as $i) {
                if (substr($i[3], 0, 1) === '"') {
                    $queryM->execute([$id, $i[2], substr($i[3], 1, -1)]);
                } else if ($i[2] == $this->config->schema->id) {
                    $queryI->execute([$id, substr($i[3], 1, -1)]);
                    $this->log("removing id " . substr($i[3], 1, -1) . " affecting " . $queryI->rowCount() . " rows");
                } else {
                    $queryR->execute([$id, $i[2], substr($i[3], 1, -1)]);
                    $this->log("removing relation " . $i[2] . "  " . substr($i[3], 1, -1) . " affecting " . $queryR->rowCount() . " rows");
                }
            }

            $insert = preg_replace('/.*(INSERT *{[^}]*}).*/', '\\1', $raw);
            preg_match_all('/<([^>]+)> *<([^>]+)> *("[^"]+"|<[^>]+>)/', $insert, $insert, PREG_SET_ORDER);
            $queryV = $this->pdo->prepare("INSERT INTO metadata (id, property, type, lang, value_n, value_t, value) VALUES (?, ?, ?, '', ?, ?, ?)");
            $queryS = $this->pdo->prepare("INSERT INTO metadata (id, property, type, lang, text, textraw) VALUES (?, ?, 'http://www.w3.org/2001/XMLSchema#string', '', to_tsvector(?), ?)");
            $queryI = $this->pdo->prepare("INSERT INTO identifiers (id, ids) VALUES (?, ?)");
            $queryR = $this->pdo->prepare("INSERT INTO relations (id, target_id, property) SELECT ?, id, ? FROM identifiers WHERE ids = ?");
            foreach ($insert as $i) {
                $value = substr($i[3], 1, -1);
                if (substr($i[3], 0, 1) === '"') {
                    if (is_numeric($value)) {
                        $queryV->execute([$id, $i[2], 'http://www.w3.org/2001/XMLSchema#long',
                            $value, null, $value]);
                    } else if (preg_match('/^[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9](T[0-9][0-9](:[0-9][0-9])?(:[0-9][0-9])?([.][0-9]+)?Z?)?$/', $value)) {
                        $queryV->execute([$id, $i[2], 'http://www.w3.org/2001/XMLSchema#dateTime',
                            null, $value, $value]);
                    } else {
                        $queryS->execute([$id, $i[2], $value, $value]);
                    }
                } else if ($i[2] == $this->config->schema->id) {
                    $this->log("adding id " . $value);
                    $queryI->execute([$id, $value]);
                    $this->log("\taffecting " . $queryI->rowCount() . " rows");
                } else {
                    $this->log("adding relation " . $i[2] . " " . $value);
                    $queryR->execute([$id, $i[2], $value]);
                    if ($queryR->rowCount() === 0) {
                        $added = $this->autoAddId($value);
                        if ($added) {
                            $queryR->execute([$id, $i[2], $value]);
                        }
                    }
                    $this->log("\taffecting " . $queryR->rowCount() . " rows");
                }
            }
            $this->getResourceMetadata();
        } else if (isset(self::$outputFormats[$contentType])) {
            http_response_code(501);
        } else {
            http_response_code(415);
        }
    }

    private function autoAddId(string $ids): bool {
        $action = $this->config->dataChecks->autoAddIds->default;
        foreach ($this->config->dataChecks->autoAddIds->skipNamespaces as $i) {
            if (strpos($ids, $i) === 0) {
                $action = 'skip';
                break;
            }
        }
        foreach ($this->config->dataChecks->autoAddIds->addNamespaces as $i) {
            if (strpos($ids, $i) === 0) {
                $action = 'add';
                break;
            }
        }
        foreach ($this->config->dataChecks->autoAddIds->denyNamespaces as $i) {
            if (strpos($ids, $i) === 0) {
                $action = 'deny';
                break;
            }
        }
        switch ($action) {
            case 'deny':
                $this->log("\tdenied to create resource " . $ids, self::LOGLEVEL_ERROR);
                throw new RepoException('denied to create a non-existing id', 400);
            case 'add':
                $this->log("\tadding resource " . $ids);
                $id    = $this->pdo->query("INSERT INTO resources (id) VALUES (nextval('id_seq'::regclass)) RETURNING id")->fetchColumn();
                $query = $this->pdo->prepare("INSERT INTO identifiers (ids, id) VALUES (?, ?)");
                $query->execute([$ids, $id]);
                $this->createResourceGrantAccess($id);
                return true;
            default:
                $this->log("\tskipped creation of resource " . $ids, self::LOGLEVEL_DEBUG);
        }
        return false;
    }

    private function copyResource(int $id): array {
        $tmpPath = $this->config->storage->tmpDir . '/' . $id;
        $input   = fopen('php://input', 'rb');
        $output  = fopen($tmpPath, 'wb');
        $size    = 0;
        $hash    = hash_init($this->config->storage->hashAlgorithm);
        while (!feof($input)) {
            $buffer = fread($input, 1048576);
            hash_update($hash, $buffer);
            $size   += fwrite($output, $buffer);
        }
        fclose($input);
        fclose($output);
        $hash = $this->config->storage->hashAlgorithm . ':' . hash_final($hash, false);

        $digest = filter_input(INPUT_SERVER, 'HTTP_DIGEST'); // client-side hash to be compared after the upload
        if (!empty($digest)) {
            //TODO - see https://fedora.info/2018/11/22/spec/#http-post
        }

        $contentType        = filter_input(INPUT_SERVER, 'CONTENT_TYPE') ?? $this->config->rest->defaultMime;
        $contentDisposition = trim(filter_input(INPUT_SERVER, 'HTTP_CONTENT_DISPOSITION'));

        $fileName = null;
        if (preg_match('/^attachment; filename=/', $contentDisposition)) {
            $fileName = preg_replace('/^attachment; filename="?/', '', $contentDisposition);
            $fileName = preg_replace('/"$/', '', $fileName);
        }

        $queryD = $this->pdo->prepare("DELETE FROM metadata WHERE (id, property) = (?, ?)");
        $queryV = $this->pdo->prepare("INSERT INTO metadata (id, property, type, lang, value_n, value_t, value) VALUES (?, ?, ?, '', ?, ?, ?)");
        $queryS = $this->pdo->prepare("INSERT INTO metadata (id, property, type, lang, text, textraw) VALUES (?, ?, 'http://www.w3.org/2001/XMLSchema#string', '', to_tsvector(?), ?)");

        foreach ($this->config->schema->fileName as $i) {
            $queryD->execute([$id, $i]);
            $queryS->execute([$id, $i, $fileName, $fileName]);
        }
        if (!empty($fileName)) {
            foreach ($this->config->schema->fileName as $i) {
                $queryS->execute([$id, $i, $fileName, $fileName]);
            }
        }
        foreach ($this->config->schema->mime as $i) {
            $queryD->execute([$id, $i]);
            $queryS->execute([$id, $i, $contentType, $contentType]);
        }
        foreach ($this->config->schema->binarySize as $i) {
            $queryD->execute([$id, $i]);
        }
        foreach ($this->config->schema->hash as $i) {
            $queryD->execute([$id, $i]);
        }
        if ($size > 0) {
            foreach ($this->config->schema->binarySize as $i) {
                $queryV->execute([$id, $i, 'http://www.w3.org/2001/XMLSchema#long',
                    $size,
                    null, $size]);
            }
            foreach ($this->config->schema->hash as $i) {
                $queryS->execute([$id, $i, $hash, $hash]);
            }
        }

        return [$tmpPath, $size, $hash];
    }

    private function saveMetadataHistory(int $id): void {
        $this->log("saving metadata history");
        $query = $this->pdo->prepare("
            INSERT INTO metadata_history (id, property, type, lang, value)
              SELECT id, property, type, lang, coalesce(value, textraw, '') FROM metadata WHERE id = ?
            UNION
              SELECT id, 'ID', '', '', ids FROM identifiers WHERE id = ?
            UNION
              SELECT id, property, '', '', target_id::text FROM relations WHERE id = ?
        ");
        $query->execute([$id, $id, $id]);
    }

    private function putResource(): void {
        list($id, $tx) = $this->getId();
        $this->checkRights($id, self::ACCESS_WRITE, $this->getRoles());

        $this->saveMetadataHistory($id);

        list($tmpPath, $size, $hash) = $this->copyResource($id);

        $path = $this->getResourcePath($id, true);
        rename($tmpPath, $path);
        http_response_code(204);
    }

    private function createResourceGrantAccess(int $id): void {
        $c        = $this->config->accessControl;
        $role     = $this->getRole();
        $inserted = [];
        foreach ($c->creatorRights as $i) {
            if (!in_array($c->schema->$i, $inserted)) {
                $query      = $this->pdo->prepare("INSERT INTO metadata (id, property, type, lang, text, textraw) VALUES (?, ?, 'http://www.w3.org/2001/XMLSchema#string', '', to_tsvector(?), ?)");
                $query->execute([$id, $c->schema->$i, $role, $role]);
                $inserted[] = $c->schema->$i;
            }
        }
    }

    private function createResource(): void {
        $this->checkRights(self::ID_CREATE, self::ACCESS_WRITE, $this->getRoles());

        $query = $this->pdo->query("INSERT INTO resources (id) VALUES (nextval('id_seq')) RETURNING id");
        $id    = $query->fetchColumn();

        $query = $this->pdo->prepare("INSERT INTO identifiers (ids, id) VALUES (?, ?)");
        $query->execute([$this->getUriBase() . $id, $id]);

        list($tmpPath, $size, $hash) = $this->copyResource($id);

        $this->createResourceGrantAccess($id);

        $path = $this->getResourcePath($id, true);
        rename($tmpPath, $path);
        http_response_code(201);
        header('Location: ' . $this->getUriBase() . $id);
    }

    private function getResourcePath(int $id, bool $create): string {
        return $this->getStorageDir($id, $create) . '/' . $id;
    }

    private function getStorageDir(int $id, bool $create, string $path = null,
                                   int $level = 0): string {
        if (empty($path)) {
            $path = $this->config->storage->dir;
        }
        if ($level < $this->config->storage->levels) {
            $path = sprintf('%s/%02d', $path, $id % 100);
            if ($create && !file_exists($path)) {
                mkdir($path, base_convert($this->config->storage->modeDir, 8, 10));
            }
            $path = $this->getStorageDir((int) $id / 100, $create, $path, $level + 1);
        }
        return $path;
    }

    private function getUriBase(): string {
        return $this->config->rest->urlBase . $this->config->rest->pathBase;
    }

    private function beginTransaction(): void {
        http_response_code(201);
        header('Location: ' . $this->getUriBase() . 'tx:0');
    }

    private function dummyTransaction(): void {
        http_response_code(204);
    }

    private function getId(): array {
        $id     = substr(filter_input(INPUT_SERVER, 'REQUEST_URI'), strlen($this->config->rest->pathBase));
        $id     = preg_replace('|/fcr:[a-z]+/?$|', '', $id);
        $tx     = preg_replace('|^(tx:[0-9]+/).*|', '\\1', $id);
        $tx     = $tx !== $id ? $tx : '';
        $id     = (int) preg_replace('|^tx:[0-9]+/|', '', $id);
        $query  = $this->pdo->prepare("SELECT tombstone FROM resources JOIN identifiers USING (id) WHERE id = ?");
        $query->execute([$id]);
        $result = $query->fetchObject();
        if ($result === false) {
            throw new RepoException('Resource not found', 404);
        } else if ($result->tombstone) {
            throw new RepoException('Resource deleted', 410);
        }
        return [$id, $tx];
    }

    private function getResourceMetadata(): void {
        list($id, $tx) = $this->getId();
        $roles = $this->getRoles();

        $query  = "SELECT * FROM get_neighbors_metadata(?, ?)";
        $query  = $this->pdo->prepare($query);
        $query->execute([$id, $this->config->schema->parent]);
        $graph  = new Graph();
        $acl    = [$id];
        while ($triple = $query->fetchObject()) {
            if (!isset($acl[$triple->id])) {
                try {
                    $this->checkRights($triple->id, self::ACCESS_READ, $roles);
                    $acl[$triple->id] = true;
                } catch (RepoException $e) {
                    $acl[$triple->id] = false;
                }
            }
            if ($acl[$triple->id]) {
                $idTmp    = $this->getUriBase() . ($triple->id === $id ? $tx : '') . $triple->id;
                $resource = $graph->resource($idTmp);
                switch ($triple->type) {
                    case 'ID':
                        $resource->addResource($this->config->schema->id, $triple->value);
                        break;
                    case 'URI':
                        $resource->addResource($triple->property, $this->getUriBase() . $triple->value);
                        break;
                    default:
                        $literal = new Literal($triple->value, !empty($triple->lang) ? $triple->lang : null, $triple->type);
                        $resource->add($triple->property, $literal);
                }
                foreach ($this->config->rest->fixedMetadata as $property => $values) {
                    foreach ($values as $value) {
                        $resource->addResource($property, $value);
                    }
                }
            }
        }

        $accept = filter_input(INPUT_SERVER, 'HTTP_ACCEPT') ?? '*/*';
        header('Content-Type: ' . self::$outputFormats[$accept]);
        echo $graph->serialise(self::$outputFormats[$accept]);
    }

    // assumes the SPARQL query is the repo-php-util's Fedora::getResourceById() query and responds accordingly
    private function dummySparql(): void {
        $queryStr = str_replace(["\n", "\r"], '', filter_input(INPUT_POST, 'query'));
        $ids      = preg_replace('|^.*<' . $this->config->schema->id . '> <([^>]+)>.*$|', '\\1', $queryStr);
        $query    = $this->pdo->prepare("SELECT id FROM identifiers WHERE ids = ?");
        $query->execute([$ids]);
        $id       = $query->fetchColumn();

        $resTmpl = [
            'head'    => ['vars' => ['res']],
            'results' => ['bindings' => []]
        ];
        if ($id !== false) {
            $resTmpl['results']['bindings'][] = [
                'res' => [
                    'type'  => 'uri',
                    'value' => $this->getUriBase() . $id
                ]
            ];
        }
        header('Content-Type: application/sparql-results+json');
        echo json_encode($resTmpl);
    }

    private function log(string $message, int $level = self::LOGLEVEL_DEBUG): void {
        if ($this->config->logging->level <= $level) {
            error_log(date("Y-m-d H:i:s.u\t") . $message . "\n", 3, $this->config->logging->file);
        }
    }

    private function getRoles(): array {
        $roles = [];
        if (isset($_SERVER['PHP_AUTH_USER'])) {
            $roles[] = $_SERVER['PHP_AUTH_USER'];
        }
        $roles[] = 'public';
        return $roles;
    }

    private function getRole(): string {
        return $this->getRoles()[0];
    }

    private function checkRights(int $id, int $right, array $roles): void {
        $c = $this->config->accessControl;

        if ($id === self::ID_CREATE) {
            if (count(array_intersect($c->createRoles, $roles)) === 0) {
                throw new RepoException('', 403);
            }
        } else {
            $tmp   = [self::ACCESS_READ => 'read', self::ACCESS_WRITE => 'write'];
            $priv  = $tmp[$right];
            $allow = $c->default->$priv === 'allow';

            $query   = $this->pdo->prepare("SELECT value FROM metadata_view WHERE id = ? AND property = ?");
            $query->execute([$id, $c->schema->$priv]);
            $allowed = $query->fetchAll(PDO::FETCH_COLUMN);
            if (count(array_intersect($allowed, $roles)) > 0) {
                $allow = true;
            } else if (count($allowed) > 0) {
                $allow = false;
            }
            if (!$allow) {
                throw new RepoException('', 403);
            }
        }
    }

}
