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
use PDOStatement;
use zozlak\RdfConstants as RDF;
use acdhOeaw\acdhRepo\RestController as RC;

/**
 * Description of Search
 *
 * @author zozlak
 */
class Search {

    static private $highlightParam = [
        'StartSel', 'StopSel', 'MaxWords', 'MinWords',
        'ShortWord', 'HighlightAll', 'MaxFragments', 'FragmentDelimiter'
    ];
    private $pdo;

    public function post(): void {
        $this->pdo = new PDO(RC::$config->dbConnStr->guest);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        if (isset($_POST['sql'])) {
            $query = $this->searchBySql();
        } else {
            $query = $this->searchByParam();
        }

        $meta   = new Metadata();
        $meta->loadFromDbQuery($query);
        $format = $meta->outputHeaders();
        $meta->outputRdf($format);
    }

    public function get(): void {
        foreach ($_GET as $k => $v) {
            $_POST[$k] = $v;
        }
        $this->post();
    }

    public function options($code = 200) {
        http_response_code($code);
        header('Allow: OPTIONS, HEAD, GET, POST');
    }

    private function searchByParam(): PDOStatement {
        $_POST['sql']      = '';
        $_POST['sqlParam'] = [];
        $many              = isset($_POST['property'][1]);
        for ($n = 0; isset($_POST['property'][$n]) || isset($_POST['value'][$n]) || isset($_POST['language'][$n]); $n++) {
            $term = new SearchTerm($n);
            list($queryTmp, $paramTmp) = $term->getSqlQuery();
            if (empty($_POST['sql'])) {
                $_POST['sql'] = ($many ? "(" : "") . $queryTmp . ($many ? ") t$n" : "");
            } else {
                $_POST['sql'] .= " JOIN ($queryTmp) t$n USING (id) ";
            }
            $_POST['sqlParam'] = array_merge($_POST['sqlParam'], $paramTmp);
        }

        return $this->searchBySql();
    }

    private function searchBySql(): PDOStatement {
        list($authQuery, $authParam) = RC::$auth->getMetadataAuthQuery();
        list($pagingQuery, $pagingParam) = $this->getPagingQuery();
        list($ftsQuery, $ftsParam) = $this->getFtsQuery();

        $mode       = filter_input(\INPUT_SERVER, RC::getHttpHeaderName('metadataReadMode')) ?? RC::$config->rest->defaultMetadataSearchMode;
        $parentProp = filter_input(\INPUT_SERVER, RC::getHttpHeaderName('metadataParentProperty')) ?? RC::$config->schema->parent;
        switch (strtolower($mode)) {
            case Metadata::LOAD_RESOURCE:
                $metaQuery = "
                    SELECT id, property, type, lang, value
                    FROM metadata JOIN ids USING (id)
                  UNION
                    SELECT id, null, 'ID' AS type, null, ids AS VALUE 
                    FROM identifiers JOIN ids USING (id)
                  UNION
                    SELECT id, property, 'REL' AS type, null, target_id::text AS value
                    FROM relations JOIN ids USING (id)
                ";
                $metaParam = [];
                break;
            case Metadata::LOAD_NEIGHBORS:
                $metaQuery = "SELECT (get_neighbors_metadata(id, ?)).* FROM ids";
                $metaParam = [$parentProp];
                break;
            case Metadata::LOAD_RELATIVES:
                $metaQuery = "SELECT (get_relatives_metadata(id, ?)).* FROM ids";
                $metaParam = [$parentProp];
                break;
            default:
                throw new RepoException('Wrong metadata read mode value ' . $mode, 400);
        }

        $query       = "
            WITH ids AS (
                SELECT id FROM (" . $_POST['sql'] . ") t1 " . $authQuery . " $pagingQuery
            )
            $metaQuery
            UNION
            SELECT id, ?::text AS property, ?::text AS type, ''::text AS lang, ?::text AS value FROM ids
            $ftsQuery
        ";
        $userParam   = $_POST['sqlParam'] ?? [];
        $schemaParam = [RC::$config->schema->searchMatch, RDF::XSD_BOOLEAN, 'true'];
        $param       = array_merge($userParam, $authParam, $pagingParam, $metaParam, $schemaParam, $ftsParam);
        $this->logQuery($query, $param);

        $query = $this->pdo->prepare($query);
        try {
            $query->execute($param);
        } catch (PDOException $e) {
            throw new RepoException('Bad query', 400, $e);
        }
        return $query;
    }

    /**
     * Prepares an SQL query adding a full text search query results as 
     * metadata graph edges.
     * @return array
     */
    private function getFtsQuery(): array {
        $query = '';
        $param = [];
        if (isset($_POST['ftsQuery'])) {
            $search = $_POST['ftsQuery'];

            $options = '';
            foreach (self::$highlightParam as $i) {
                if (isset($_POST['fts' . $i])) {
                    $options .= " ,$i=" . $_POST['fts' . $i];
                }
            }
            $options = substr($options, 2);

            $where      = '';
            $whereParam = [];
            if (isset($_POST['ftsProperty'])) {
                $where        = "WHERE property = ?";
                $whereParam[] = $_POST['ftsProperty'];
            }

            $query = "
              UNION
                SELECT id, ? AS property, ? AS type, '' AS lang, ts_headline('simple', raw, websearch_to_tsquery('simple', ?), ?) AS value 
                FROM full_text_search JOIN ids USING (id)
                $where
            ";
            $prop  = RC::$config->schema->searchFts;
            $type  = RDF::XSD_STRING;
            $param = array_merge([$prop, $type, $search, $options], $whereParam);
        }
        return [$query, $param];
    }

    private function getPagingQuery(): array {
        $query = '';
        $param = [];
        if (isset($_POST['limit'])) {
            $query   .= ' LIMIT ?';
            $param[] = $_POST['limit'];
        }
        if (isset($_POST['offset'])) {
            $query   .= ' OFFSET ?';
            $param[] = $_POST['offset'];
        }
        return [$query, $param];
    }

    private function logQuery(string $query, array $param): void {
        $msg = "\tSearch query:\n";
        while(($pos = strpos($query, '?')) !== false) {
            $msg .= substr($query, 0, $pos) . RC::$pdo->quote(array_shift($param));
            $query = substr($query, $pos + 1);
        }
        $msg .= $query;
        RC::$log->debug("\tSearch query:\n" . $msg);
    }
}
