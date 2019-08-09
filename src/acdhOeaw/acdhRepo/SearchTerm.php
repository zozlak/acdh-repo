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

/**
 * Description of SearchTerm
 *
 * @author zozlak
 */
class SearchTerm {

    const TYPE_NUMBER       = 'number';
    const TYPE_DATE         = 'date';
    const TYPE_DATETIME     = 'datetime';
    const TYPE_STRING       = 'string';
    const TYPE_URI          = 'uri';
    const TYPE_FTS          = 'fts';
    const COLUMN_STRING     = 'value';
    const STRING_MAX_LENGTH = 1000;

    /**
     * List of operators and data types they enforce
     * @var array
     */
    static private $operators = [
        '='  => null,
        '>'  => null,
        '<'  => null,
        '<=' => null,
        '>=' => null,
        '~'  => self::TYPE_STRING,
        '&&' => self::TYPE_FTS,
    ];
    static private $types     = [
        RDF::XSD_STRING        => 'value',
        RDF::XSD_BOOLEAN       => 'value_n',
        RDF::XSD_DECIMAL       => 'value_n',
        RDF::XSD_FLOAT         => 'value_n',
        RDF::XSD_DOUBLE        => 'value_n',
        RDF::XSD_DURATION      => 'value',
        RDF::XSD_DATE_TIME     => 'value_t',
        RDF::XSD_TIME          => 'value_t::time',
        RDF::XSD_DATE          => 'value_t::date',
        RDF::XSD_HEX_BINARY    => 'value',
        RDF::XSD_BASE64_BINARY => 'value',
        RDF::XSD_ANY_URI       => 'ids',
        self::TYPE_URI         => 'ids',
        self::TYPE_DATE        => 'value_t::date',
        self::TYPE_DATETIME    => 'value_t',
        self::TYPE_NUMBER      => 'value_n',
        self::TYPE_STRING      => 'value',
        self::TYPE_FTS         => 'segments',
    ];
    public $property;
    public $operator;
    public $type;
    public $value;
    public $language;

    public function __construct($key) {
        $this->property = $_POST['property'][$key] ?? null;
        $this->operator = $_POST['operator'][$key] ?? '=';
        $this->type     = $_POST['type'][$key] ?? null;
        $this->value    = $_POST['value'][$key] ?? null;
        $this->language = $_POST['language'][$key] ?? null;

        if (!in_array($this->operator, array_keys(self::$operators))) {
            throw new RepoException('Unknown operator ' . $this->operator, 400);
        }
        if (!in_array($this->type, array_keys(self::$types)) && $this->type !== null) {
            throw new RepoException('Unknown type ' . $this->type, 400);
        }
    }

    public function getSqlQuery(): array {
        $query = $param = [];
        
        if (!empty($this->property)) {
            $query[] = 'property = ?';
            $param[] = $this->property;
        }

        if (!empty($this->language)) {
            $query[] = 'lang = ?';
            $param[] = $this->language;
        }

        if (!empty($this->value)) {
            $type = self::$operators[$this->operator];
            // if type not enforced by the operator, try the provided one
            if ($type === null) {
                $type = $this->type;
            }
            // if type not enforced by the operator and not provided, guess it
            if ($type === null) {
                if (is_numeric($this->value)) {
                    $type = self::TYPE_NUMBER;
                } elseif (preg_match(Metadata::DATETIME_REGEX, $this->value)) {
                    $type = self::TYPE_DATETIME;
                } else {
                    $type = self::TYPE_STRING;
                }
            }
            switch ($type) {
                case self::TYPE_FTS:
                    $placeholder = "websearch_to_tsquery('simple', ?)";
                    break;
                default:
                    $placeholder = '?';
            }

            $column = self::$types[$type];
            // string values stored in the database can be to long to be indexed, 
            // therefore the index is set only on `substring(value, 1, self::STRING_MAX_LENGTH)`
            // and to benefit from it the predicate must strictly follow the index definition
            if ($column === self::COLUMN_STRING && strlen($this->value) < self::STRING_MAX_LENGTH) {
                $column = "substring(" . $column . ", 1, " . self::STRING_MAX_LENGTH . ")";
            }

            $query[] = $column . ' ' . $this->operator . ' ' . $placeholder;
            $param[] = $this->value;
        }
        return [implode(" AND ", $query), $param];
    }

}
