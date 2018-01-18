<?php

/*
 * The MIT License
 *
 * Copyright 2017 zozlak.
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

namespace acdhOeaw\resolver;

use Exception;
use RuntimeException;
use GuzzleHttp\Psr7\Request;
use acdhOeaw\fedora\Fedora;
use acdhOeaw\fedora\FedoraResource;
use acdhOeaw\fedora\exceptions\NotFound;
use acdhOeaw\fedora\exceptions\AmbiguousMatch;
use acdhOeaw\util\RepoConfig as RC;

/**
 * Description of Resolver
 *
 * @author zozlak
 */
class Resolver {

    const TYPE_PROP      = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type';
    const SUB_CLASS_PROP = 'http://www.w3.org/2000/01/rdf-schema#subClassOf';

    static public $debug = false;

    public function resolve() {
        if (filter_input(INPUT_SERVER, 'HTTP_X_FORWARDED_HOST')) {
            $host = explode(',', filter_input(INPUT_SERVER, 'HTTP_X_FORWARDED_HOST'));
            $host = trim($host[0]);
        } else {
            $host = filter_input(INPUT_SERVER, 'HTTP_HOST');
        }

        $resId    = 'https://' . $host . filter_input(\INPUT_SERVER, 'REDIRECT_URL');
        $res      = $this->findResource($resId);
        $dissServ = $res->getDissServices();
        $accept = $this->parseAccept();
        foreach ($accept as $mime) {
            if (isset($dissServ[$mime])) {
                $request = $dissServ[$mime]->getRequest($res);
                break;
            }
        }

        if ($request === null) {
            $defaultServ = RC::get('defaultDissService');
            if ($defaultServ && isset($dissServ[$defaultServ])) {
                $request = $dissServ[$defaultServ]->getRequest($res);
            } else {
                $request = new Request('GET', $res->getUri(true));
            }
        }

        $this->redirect($request->getUri());
        return;
    }

    private function findResource(string $resId): FedoraResource {
        RC::set('fedoraUser', RC::get('resolverUser'));
        RC::set('fedoraPswd', RC::get('resolverPswd'));
        $fedoraApiUrls = RC::get('resolverFedoraApiUrl');
        array_unshift($fedoraApiUrls, RC::get('fedoraApiUrl'));
        $sparqlUrls    = RC::get('resolverSpaqrlUrl');
        array_unshift($sparqlUrls, RC::get('sparqlUrl'));
        foreach (array_combine($fedoraApiUrls, $sparqlUrls) as $fedoraApiUrl => $sparqlUrl) {
            RC::set('fedoraApiUrl', $fedoraApiUrl);
            RC::set('sparqlUrl', $sparqlUrl);

            $fedora = new Fedora();
            try {
                $res = $fedora->getResourceById($resId);
                return $res;
            } catch (NotFound $e) {
                
            } catch (AmbiguousMatch $e) {
                throw new RuntimeException('Internal Server Error - many resources with the given URI', 500);
            } catch (Exception $e) {
                throw new RuntimeException('Internal Server Error', 500);
            }
        }
        throw new RuntimeException('Not Found', 404);
    }

    private function parseAccept(): array {
        $accept       = array();
        $acceptHeader = trim(filter_input(\INPUT_SERVER, 'HTTP_ACCEPT'));
        if ($acceptHeader != '') {
            $tmp = explode(',', $acceptHeader);
            foreach ($tmp as $i) {
                $i    = explode(';', $i);
                $i[0] = trim($i[0]);
                if (count($i) >= 2) {
                    $accept[$i[0]] = floatval(preg_replace('|[^.0-9]|', '', $i[1]));
                } else {
                    $accept[$i[0]] = 1;
                }
            }
            arsort($accept);
            $accept = array_keys($accept);
        }
        $format = filter_input(\INPUT_GET, 'format');
        if ($format) {
            array_unshift($accept, $format);
        }
        return $accept;
    }

    private function redirect(string $location) {
        if (self::$debug) {
            echo 'Location: ' . $location . "\n";
        } else {
            header('Location: ' . $location);
        }
    }

//    private function useDissService(DisseminationService $service) {
//        switch ($this->config->get('mode')) {
//            case 'POST':
//                break;
//            case 'GET':
//                break;
//            default:
//                $this->redirect($service->getUrl());
//        }
//    }
//
//    private function aaa() {
//        $options               = array();
//        $options['sink']       = $output;
//        $options['on_headers'] = function(Response $r) {
//            $this->filterHeaders($r);
//        };
//        $options['verify'] = false;
//        $client            = new Client($options);
//
//        $output = fopen('php://output', 'w');
//    }
}
