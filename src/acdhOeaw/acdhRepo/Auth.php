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

use EasyRdf\Graph;
use EasyRdf\Resource;
use acdhOeaw\acdhRepo\RestController as RC;
use zozlak\auth\AuthController;
use zozlak\auth\usersDb\PdoDb;
use zozlak\auth\authMethod\TrustedHeader;
use zozlak\auth\authMethod\HttpBasic;
use zozlak\auth\authMethod\Guest;

/**
 * Description of Auth
 *
 * @author zozlak
 */
class Auth {

    const DEFAULT_ALLOW = 'allow';
    const DEFAULT_DENY  = 'deny';

    private $controller;

    public function __construct() {
        $db               = new PdoDb(RC::$config->dbConnStr->admin, 'users', 'user_id');
        $this->controller = new AuthController($db);

        //TODO make it config-driven
        $header = new TrustedHeader('HTTP_EPPN');
        $this->controller->addMethod($header);

        $guest = new Guest(RC::$config->accessControl->publicRole);
        $this->controller->addMethod($guest);

        $this->controller->authenticate();
    }

    public function checkCreateRights(): void {
        $roles     = RC::$auth->getUserRoles();
        $isAdmin   = count(array_intersect($roles, RC::$config->accessControl->adminRoles)) > 0;
        $isCreator = !in_array($this->controller->getUserName(), RC::$config->accessControl->createRoles);
        if (!$isAdmin && !$isCreator) {
            throw new RepoException('Resource creation denied', 403);
        }
    }

    public function checkAccessRights(int $resId, string $privilege,
                                      bool $metadataRead) {
        if ($metadataRead && !RC::$config->accessControl->enforceOnMetadata) {
            return;
        }
        $roles = RC::$auth->getUserRoles();
        if (count(array_intersect($roles, RC::$config->accessControl->adminRoles)) > 0) {
            return;
        }
        $query   = RC::$pdo->prepare("SELECT json_agg(value) AS val FROM metadata WHERE id = ? AND property = ?");
        $query->execute([$resId, RC::$config->accessControl->schema->$privilege]);
        $allowed = $query->fetchColumn();
        $allowed = json_decode($allowed) ?? [];
        $default = RC::$config->accessControl->default->$privilege;
        if (count(array_intersect($roles, $allowed)) === 0 && $default !== Auth::DEFAULT_ALLOW) {
            RC::$log->debug(['roles' => $roles, 'allowed' => $allowed]);
            throw new RepoException('Forbidden', 403);
        }
    }

    public function getCreateRights(): Resource {
        $c     = RC::$config->accessControl;
        $graph = new Graph();
        $meta  = $graph->newBNode();

        $role = $this->getUserName();
        foreach ($c->creatorRights as $i) {
            $meta->addLiteral($c->schema->$i, $role);
        }

        foreach ($c->default as $privilege => $roles) {
            foreach ($roles as $role) {
                $prop = $c->schema->$privilege;
                $meta->addLiteral($prop, $role);
            }
        }

        return $meta;
    }

    /**
     * Returns (if needed according to the config) an SQL query returning a list
     * of resource ids the current user can read.
     * @return array
     */
    public function getMetadataAuthQuery(): array {
        $c = RC::$config->accessControl;
        if ($c->enforceOnMetadata) {
            return [
                " JOIN (SELECT * from get_allowed_resources(?, ?)) maq USING (id) ",
                [$c->schema->read, json_encode($this->getUserRoles())],
            ];
        } else {
            return ['', []];
        }
    }

    public function getUserName(): string {
        return $this->controller->getUserName();
    }

    public function getUserRoles(): array {
        return array_merge(
            [$this->controller->getUserName()],
            $this->controller->getUserData()->groups ?? []
        );
    }

}
