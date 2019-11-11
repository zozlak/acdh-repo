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

namespace acdhOeaw\acdhRepo\handler;

use EasyRdf\Resource;
use EasyRdf\Literal;
use acdhOeaw\acdhRepo\RestController as RC;

/**
 * Description of MetadataManager
 *
 * @author zozlak
 */
class MetadataManager {

    static public function manage(Resource $meta, ?string $path): Resource {
        foreach (RC::$config->metadataManager->fixed as $p => $vs) {
            foreach ($vs as $v) {
                self::addMetaValue($meta, $p, $v);
            }
        }
        foreach (RC::$config->metadataManager->default as $p => $vs) {
            if (count($meta->all($p)) === 0) {
                foreach ($vs as $v) {
                    self::addMetaValue($meta, $p, $v);
                }
            }
        }
        foreach (RC::$config->metadataManager->forbidden as $p) {
            $meta->delete($p);
            $meta->deleteResource($p);
        }
        foreach (RC::$config->metadataManager->copying as $sp => $tp) {
            foreach ($meta->all($sp) as $v) {
                $meta->add($tp, $v);
            }
        }
        return $meta;
    }

    static private function addMetaValue(Resource $meta, string $p, object $v): void {
        if (isset($v->uri)) {
            $meta->addResource($p, $v->uri);
        } else {
            $literal = new Literal($v->value, $v->lang ?? null, $v->type ?? null);
            $meta->addLiteral($p, $literal);
        }
    }

}
