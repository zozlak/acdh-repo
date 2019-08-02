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

use BadMethodCallException;
use DateTime;
use RuntimeException;
use EasyRdf\Format;
use EasyRdf\Graph;
use EasyRdf\Literal;
use zozlak\HttpAccept;
use acdhOeaw\acdhRepo\RestController as RC;

/**
 * Description of Metadata
 *
 * @author zozlak
 */
class Metadata {

    const LOAD_RESOURCE  = 1;
    const LOAD_NEIGHBORS = 2;
    const LOAD_RELATIVES = 3;

    static public function getAcceptedFormats(): string {
        return Format::getHttpAcceptHeader();
    }

    private $id;
    private $graph;

    public function __construct(int $id = null) {
        $this->id = $id;
    }

    public function load(int $mode = self::LOAD_NEIGHBORS): void {
        $this->graph = new Graph();
        $baseUrl     = RC::getBaseUrl();

        switch ($mode) {
            case self::LOAD_RESOURCE:
                $query = "SELECT * FROM metadata_view WHERE id = ?";
                $param = [$this->id];
                break;
            case self::LOAD_NEIGHBORS:
                $query = "SELECT * FROM get_neighbors_metadata(?, ?)";
                $param = [$this->id, RC::$config->schema->parent];
                break;
            case self::LOAD_RELATIVES:
                $query = "SELECT * FROM get_relatives_metadata(?, ?)";
                $param = [$this->id, RC::$config->schema->parent];
                break;
            default:
                throw new BadMethodCallException();
        }
        list($authQuery, $authParam) = RC::$auth->getMetadataAuthQuery();
        $query = RC::$pdo->prepare($query . $authQuery);
        $query->execute(array_merge($param, $authParam));

        while ($triple = $query->fetchObject()) {
            $triple->id = $baseUrl . $triple->id;
            $resource   = $this->graph->resource($triple->id);
            switch ($triple->type) {
                case 'ID':
                    $resource->addResource(RC::$config->schema->id, $triple->value);
                    break;
                case 'URI':
                    $resource->addResource($triple->property, $baseUrl . $triple->value);
                    break;
                default:
                    $literal = new Literal($triple->value, !empty($triple->lang) ? $triple->lang : null, $triple->type);
                    $resource->add($triple->property, $literal);
            }
        }
    }

    /**
     * Updates system-managed metadata, e.g. who and when lastly modified a resource
     * @return void
     */
    public function updateSystemMetadata(): void {
        //TODO add support for RC::$config->metadataManagment and change into preparation of an EasyRdf graph being then written into the database

        $queryD = RC::$pdo->prepare("DELETE FROM metadata WHERE (id, property) = (?, ?)");
        $queryV = RC::$pdo->prepare("
            INSERT INTO metadata (id, property, type, lang, value_n, value_t, value) 
            VALUES (?, ?, ?, '', ?, ?, ?)
        ");
        $queryS = RC::$pdo->prepare("
            INSERT INTO metadata (id, property, type, lang, text, textraw) 
            VALUES (?, ?, 'http://www.w3.org/2001/XMLSchema#string', '', to_tsvector(?), ?)
        ");

        foreach (RC::$config->schema->modificationDate as $i) {
            $queryD->execute([$this->id, $i]);
            $date = (new DateTime())->format('Y-m-d h:i:s');
            $type = 'http://www.w3.org/2001/XMLSchema#dateTime';
            $queryV->execute([$this->id, $i, $type, null, $date, $date]);
        }
        foreach (RC::$config->schema->modificationUser as $i) {
            $queryD->execute([$this->id, $i]);
            $user = RC::$auth->getUserName();
            $queryS->execute([$this->id, $i, $user, $user]);
        }
    }

    /**
     * @return void
     */
    public function serialise(): void {
        $this->load();
        $format = $this->negotiateFormat();
        header('Content-Type: ' . $format);
        echo $this->graph->serialise($format);
    }

    private function negotiateFormat(): string {
        try {
            $format = HttpAccept::getBestMatch(RC::$config->rest->metadataFormats)->getFullType();
        } catch (RuntimeException $e) {
            $format = RC::$config->rest->defaultMetadataFormat;
        }
        return $format;
    }

}
