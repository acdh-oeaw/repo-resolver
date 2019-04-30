<?php

/*
 * The MIT License
 *
 * Copyright 2017 Austrian Centre for Digital Humanities.
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
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Exception\RequestException;
use acdhOeaw\fedora\Fedora;
use acdhOeaw\fedora\FedoraResource;
use acdhOeaw\fedora\exceptions\NotFound;
use acdhOeaw\fedora\exceptions\AmbiguousMatch;
use acdhOeaw\util\RepoConfig as RC;

/**
 * Resolves an URI being defined as an identifier of a repository object to
 * the proper dissemination method.
 *
 * @author zozlak
 */
class Resolver {

    const TYPE_PROP      = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type';
    const SUB_CLASS_PROP = 'http://www.w3.org/2000/01/rdf-schema#subClassOf';

    static public $debug = false;

    /**
     * Performs the resolution
     */
    public function resolve() {
        /* @var $service \acdhOeaw\fedora\dissemination\Service */
        $service = null;
        if (filter_input(\INPUT_SERVER, 'HTTP_X_FORWARDED_HOST')) {
            $host = explode(',', filter_input(\INPUT_SERVER, 'HTTP_X_FORWARDED_HOST'));
            $host = trim($host[0]);
        } else {
            $host = filter_input(\INPUT_SERVER, 'HTTP_HOST');
        }

        $resId    = 'https://' . $host . filter_input(\INPUT_SERVER, 'REDIRECT_URL');
        $extResId = filter_input(\INPUT_GET, 'id');
        if (!empty($extResId)) {
            $resId = $extResId;
        }
        $res      = $this->findResource($resId);
        $dissServ = $res->getDissServices();
        $accept   = $this->parseAccept();
        foreach ($accept as $mime) {
            if (isset($dissServ[$mime])) {
                $service = $dissServ[$mime];
                break;
            }
        }

        if ($service === null) {
            $defaultServ = RC::get('defaultDissService');
            if ($defaultServ && isset($dissServ[$defaultServ])) {
                $service = $dissServ[$defaultServ];
            }
        }

        if ($service === null) {
            $request = new Request('GET', $res->getUri(true));
            $this->redirect($request->getUri());
        } elseif (!$service->getRevProxy()) {
            $request = $service->getRequest($res);
            $this->redirect($request->getUri());
        } else {
            try {
                // It's the only thing we can check for sure cause other resources 
                // might be encoded in the diss service request in a way the resolver
                // doesn't understand.
                // In the "reverse proxy to separated location having full repo access
                // rights" scenario it creates problem of "resource injection"
                // attacks when a dissemination service parameter (which access rights
                // aren't checked by the resolver) will be manipulated to get access
                // to it.
                $this->checkAccessRights($res->getUri(true));

                $request = $service->getRequest($res);
                Proxy::proxy($request);
            } catch (AccessRightsException $e) {
                
            }
        }
    }

    /**
     * Finds a repository resource which corresponds to a given URI
     * @param string $resId URI to be mapped to a repository resource
     * @return FedoraResource
     * @throws RuntimeException
     */
    private function findResource(string $resId): FedoraResource {
        RC::set('fedoraUser', RC::get('resolverUser'));
        RC::set('fedoraPswd', RC::get('resolverPswd'));
        $fedoraApiUrls = RC::get('resolverFedoraApiUrl');
        array_unshift($fedoraApiUrls, RC::get('fedoraApiUrl'));
        $sparqlUrls    = RC::get('resolverSparqlUrl');
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
            } catch (RequestException $e) {
                // simply skip sparql endpoints which don't work
            } catch (\EasyRdf\Exception $e) {
                // simply skip sparql endpoints which don't work
            } catch (Exception $e) {
                throw new RuntimeException('Internal Server Error', 500);
            }
        }
        throw new RuntimeException('Not Found', 404);
    }

    /**
     * Parses the client request accept header
     * @return array
     */
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

    /**
     * Checks if a client is able to access a given URI.
     * @param string $uri URI to be checked
     * @throws AccessRightsException
     * @throws RequestException
     */
    private function checkAccessRights(string $uri) {
        $headers = Proxy::getForwardHeaders();
        $request = new Request('HEAD', $uri, $headers);
        $options = [
            'verify'          => false,
            'allow_redirects' => true,
        ];
        $client  = new Client($options);
        try {
            $client->send($request);
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                $code = $e->getResponse()->getStatusCode();
                if ($code === 401) {
                    header('HTTP/1.1 401 Unauthorized');
                    header('WWW-Authenticate: Basic realm="resolver"');
                    echo "Authentication required\n";
                    throw AccessRightsException($e->getMessage(), $code);
                } elseif ($code === 403) {
                    header('HTTP/1.1 403 Forbidden');
                    echo "Access denied\n";
                    throw AccessRightsException($e->getMessage(), $code);
                }
            }
            throw $e;
        }
    }

    private function redirect(string $url) {
        if (self::$debug) {
            echo 'Location: ' . $url . "\n";
        } else {
            header('Location: ' . $url);
        }
    }

}
