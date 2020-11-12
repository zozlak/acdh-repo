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

use ErrorException;
use PDO;
use Throwable;
use Composer\Autoload\ClassLoader;
use zozlak\logging\Log as Log;
use acdhOeaw\acdhRepo\Transaction;
use acdhOeaw\acdhRepoLib\exception\RepoLibException;

/**
 * Description of RestController
 *
 * @author zozlak
 */
class RestController {

    const ID_CREATE    = 0;
    const ACCESS_READ  = 1;
    const ACCESS_WRITE = 2;
    const CORS_ORIGIN  = '__origin__';

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
    static public $config;

    /**
     *
     * @var \zozlak\logging\Log
     */
    static public $log;

    /**
     *
     * @var \PDO
     */
    static public $pdo;

    /**
     *
     * @var \acdhOeaw\acdhRepo\Transaction
     */
    static public $transaction;

    /**
     *
     * @var \acdhOeaw\acdhRepo\Resource
     */
    static public $resource;

    /**
     *
     * @var \acdhOeaw\acdhRepo\Auth 
     */
    static public $auth;

    /**
     *
     * @var \acdhOeaw\acdhRepo\HandlersController
     */
    static public $handlersCtl;

    static public function init(string $configFile, ClassLoader $loader): void {
        set_error_handler(function($errno, $errstr, $errfile, $errline, $errcontext) {
            if (0 === error_reporting()) {
                return false;
            }
            throw new ErrorException($errstr, 500, $errno, $errfile, $errline);
        });

        self::$config = json_decode(json_encode(yaml_parse_file($configFile)));
        self::$log    = new Log(self::$config->rest->logging->file, self::$config->rest->logging->level);

        try {
            self::$log->info("------------------------------");
            self::$log->info(filter_input(INPUT_SERVER, 'REQUEST_METHOD') . " " . filter_input(INPUT_SERVER, 'REQUEST_URI'));

            self::$pdo = new PDO(self::$config->dbConnStr->admin);
            self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$pdo->query("SET application_name TO rest");

            self::$transaction = new Transaction();

            self::$auth = new Auth();

            self::$handlersCtl = new HandlersController(self::$config->rest->handlers, $loader);        
        } catch (Throwable $e) {
            http_response_code(500);
            self::$log->error($e);
        }
    }

    static public function handleRequest(): void {
        try {
            if (!empty(self::$config->rest->cors ?? '')) {
                $origin = self::$config->rest->cors;
                if ($origin === self::CORS_ORIGIN) {
                    $origin = filter_input(INPUT_SERVER, 'HTTP_ORIGIN') ?? '*';
                    header('Vary: origin');
                }
                header("Access-Control-Allow-Origin: $origin");
                header('Access-Control-Allow-Headers: Accept, Content-Type');
                header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, HEAD, OPTIONS');
                header('Cache-Control: no-cache');
            }
            
            self::$pdo->beginTransaction();

            $method   = filter_input(INPUT_SERVER, 'REQUEST_METHOD');
            $method   = ucfirst(strtolower($method));
            $path     = substr(filter_input(INPUT_SERVER, 'REQUEST_URI'), strlen(self::$config->rest->pathBase));
            $queryPos = strpos($path, '?');
            if ($queryPos !== false) {
                $path = substr($path, 0, $queryPos);
            }

            if ($path === 'describe') {
                header('Content-Type: text/vnd.yaml');
                echo yaml_emit(json_decode(json_encode([
                    'rest'   => [
                        'headers'  => self::$config->rest->headers,
                        'urlBase'  => self::$config->rest->urlBase,
                        'pathBase' => self::$config->rest->pathBase
                    ],
                    'schema' => self::$config->schema
                        ]), true));
            } elseif ($path === 'transaction') {
                self::$log->info("Transaction->$method()");
                if (method_exists(self::$transaction, $method)) {
                    self::$transaction->$method();
                } else {
                    self::$transaction->options(405);
                }
            } elseif ($path === 'search') {
                $search = new Search();
                if (method_exists($search, $method)) {
                    $search->$method();
                } else {
                    $search->options(405);
                }
            } elseif (preg_match('>^([0-9]+/?)?(metadata|tombstone)?$>', $path)) {
                $collection = $suffix     = '';
                $id         = null;
                if (is_numeric(substr($path, 0, 1))) {
                    $id = (int) $path; // PHP is very permissive when casting to numbers
                } else {
                    $collection = 'Collection';
                }
                $matches = null;
                if (preg_match('>metadata|tombstone$>', $path, $matches)) {
                    $suffix = ucfirst($matches[0]);
                }

                self::$resource = new Resource($id);
                $methodFull     = $method . $collection . $suffix;
                self::$log->info("Resource($id)->$methodFull()");
                if (method_exists(self::$resource, $methodFull)) {
                    self::$resource->$methodFull();
                } else {
                    $methodOptions = 'options' . $collection . $suffix;
                    self::$resource->$methodOptions(405);
                }
            } else {
                throw new RepoException('Not Found', 404);
            }

            self::$transaction->prolongAndRelease(); // to avoid deadlocks in the next line
            self::$pdo->commit();
        } catch (RepoLibException $e) {
            self::$log->error($e);
            if (self::$config->transactionController->enforceCompleteness && self::$transaction->getId() !== null) {
                self::$log->info('aborting transaction ' . self::$transaction->getId() . " due to enforce completeness");
                self::$transaction->delete();
            }
            http_response_code($e->getCode());
            echo $e->getMessage();
        } catch (Throwable $e) {
            self::$log->error($e);
            if (self::$config->transactionController->enforceCompleteness && self::$transaction->getId() !== null) {
                self::$log->info('aborting transaction ' . self::$transaction->getId() . " due to enforce completeness");
                self::$transaction->delete();
            }
            http_response_code(500);
        } finally {
            self::$log->info("Return code " . http_response_code());
            self::$log->debug("Memory usage " . round(memory_get_peak_usage(true) / 1024 / 1024) . " MB");
        }
    }

    static public function getBaseUrl(): string {
        return self::$config->rest->urlBase . self::$config->rest->pathBase;
    }

    static public function getHttpHeaderName(string $purpose): ?string {
        return 'HTTP_' . str_replace('-', '_', self::$config->rest->headers->$purpose) ?? null;
    }

}
