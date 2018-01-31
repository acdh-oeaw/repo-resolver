<?php

/*
 * The MIT License
 *
 * Copyright 2018 Austrian Centre for Digital Humanities.
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

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Exception\RequestException;

/**
 * Description of Proxy
 *
 * @author zozlak
 */
class Proxy {

    /**
     * Response headers not to be forwarded to the client.
     * (https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers#hbh plus host header)
     * @var array
     */
    static $skipResponseHeaders = [
        'connection', 'keep-alive', 'proxy-authenticate', 'proxy-authorization',
        'te', 'trailer', 'transfer-encoding', 'upgrade', 'host'
    ];
    static $skipForwardHeaders  = ['host'];

    public function proxy(string $url): Response {
        $headers = $this->getForwardHeaders();

        $method = strtoupper(filter_input(INPUT_SERVER, 'REQUEST_METHOD'));
        $input  = null;
        if ($method !== 'TRACE' && (isset($headers['content-type']) || isset($headers['content-length']))) {
            $input = fopen('php://input', 'r');
        }

        $options               = array();
        $output                = fopen('php://output', 'w');
        $options['sink']       = $output;
        $options['on_headers'] = function(Response $r) {
            $this->handleResponseHeaders($r);
        };
        $options['verify']          = false;
        $options['allow_redirects'] = true;
        $client                     = new Client($options);

        $request = new Request($method, $url, $headers, $input);
        try {
            $response = $client->send($request);
        } catch (RequestException $e) {
            if (!$e->hasResponse()) {
                throw $e; // if there is no response we can't properly return from function
            }
            $response = $e->getResponse();

            if ($input) {
                fclose($input);
                $input = null;
            }
        } finally {
            if ($input) {
                fclose($input);
            }
            fclose($output);
        }
    }

    /**
     * 
     * @param Response $response
     */
    public function handleResponseHeaders(Response $response) {
        $status = $response->getStatusCode();
        if (in_array($status, array(401, 403))) {
            if (!isset($_SERVER['PHP_AUTH_USER'])) {
                header('HTTP/1.1 401 Unauthorized');
                header('WWW-Authenticate: Basic realm="resolver"');
            }
        } else {
            header('HTTP/1.1 ' . $response->getStatusCode() . ' ' . $response->getReasonPhrase());
            foreach ($response->getHeaders() as $name => $values) {
                if (in_array(strtolower($name), self::$skipResponseHeaders)) {
                    continue;
                }
                foreach ($values as $value) {
                    header(sprintf('%s: %s', $name, $value), false);
                }
            }
        }
    }

    private function getForwardHeaders(): array {
        $headers = array();
        foreach ($_SERVER as $k => $v) {
            if (substr($k, 0, 5) !== 'HTTP_') {
                continue;
            }
            $k = str_replace('_', '-', strtolower(substr($k, 5)));
            if (!in_array($k, self::$skipForwardHeaders)) {
                $headers[$k] = $v;
            }
        }

        $contentType = filter_input(\INPUT_SERVER, 'CONTENT_TYPE');
        if ($contentType !== null) {
            $headers['content-type'] = $contentType;
        }

        $contentLength = filter_input(\INPUT_SERVER, 'CONTENT_LENGTH');
        if ($contentLength !== null) {
            $headers['content-length'] = $contentLength;
        }

        $cookies = array();
        foreach ($_COOKIE as $k => $v) {
            $cookies[] = $k . '=' . $v;
        }
        if (count($cookies) > 0) {
            $headers['cookie'] = implode('; ', $cookies);
        }

        return $headers;
    }

}
