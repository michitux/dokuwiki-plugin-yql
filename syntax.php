<?php
/**
 * DokuWiki Plugin yql (Syntax Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Michael Hamann <michael@content-space.de>
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();

if (!defined('DOKU_LF')) define('DOKU_LF', "\n");
if (!defined('DOKU_TAB')) define('DOKU_TAB', "\t");
if (!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');

require_once DOKU_PLUGIN.'syntax.php';

class syntax_plugin_yql extends DokuWiki_Syntax_Plugin {
    public function getType() {
        return 'substition';
    }

    public function getPType() {
        return 'block';
    }

    public function getSort() {
        return 120;
    }


    public function connectTo($mode) {
        $this->Lexer->addSpecialPattern('<YQL.*?>.*?<\/YQL>',$mode,'plugin_yql');
    }

    public function handle($match, $state, $pos, &$handler){
        $data = array();
        preg_match('/<YQL ?(.*)>(.*)<\/YQL>/ms', $match, $components);

        if ($components[1]) { // parse parameters
            preg_match_all('/\s*(\S+)="([^"]*)"\s*/', $components[1], $params, PREG_SET_ORDER);
            foreach ($params as $param) {
                array_shift($param);
                list($key, $value) = $param;
                switch ($key) {
                case 'refresh':
                    $data['refresh'] = (int)$value;
                    break;
                case 'format':
                    $parts = explode('%%', $value);
                    foreach ($parts as $pos => $part) {
                        if ($pos % 2 == 0) {
                            $data['format'][] = $part;
                        } else {
                            if (strpos($part, '|') !== FALSE) {
                                list($link, $title) = explode('|', $part, 2);
                                $data['format'][] = array($link => $title);
                            } else {
                                $data['format'][] = $part;
                            }
                        }
                    }
                    break;
                case 'item_name':
                    $data['item_name'] = $value;
                    break;
                }
            }
        }

        $data['query'] = $components[2];
        if (!isset($data['refresh'])) $data['refresh'] = 14400;
        if (!isset($data['format'])) $data['format'] = array('', array('link' => 'title'), '');
        if (!isset($data['item_name'])) $data['item_name'] = 'item';

        return $data;
    }

    public function render($mode, &$renderer, $data) {
        extract($data);

        $renderer->meta['date']['valid']['age'] =
            isset($renderer->meta['date']['valid']['age']) ?
            min($renderer->meta['date']['valid']['age'],$params['refresh']) :
            $params['refresh'];

        // Don't fetch the data for rendering metadata
        if ($mode == 'metadata') return;

        // execute the YQL query

        $yql_base_url = "http://query.yahooapis.com/v1/public/yql";
        $yql_query_url = $yql_base_url . "?q=" . urlencode($query);
        $yql_query_url .= "&format=json";
        $client = new DokuHTTPClient();
        $result = $client->get($yql_query_url);
        if ($result !== false) {
            $json_parser = new JSON();
            $json_result = $json_parser->decode($result);
            if (!is_null($json_result->query->results)) {
                $renderer->listu_open();
                foreach ($json_result->query->results->$item_name as $item) {
                    $renderer->listitem_open(1);
                    $renderer->listcontent_open();
                    foreach ($format as $pos => $val) {
                        if ($pos % 2 == 0) {
                            $renderer->cdata($val);
                        } else {
                            if (is_array($val)) {
                                foreach ($val as $link => $title) {
                                    if (isset($item->$link, $item->$title) && !is_a($item->$title, 'stdClass')) {
                                        if (is_a($item->$link, 'stdClass') && isset($item->$link->href)) {
                                            $renderer->externallink($item->$link->href, (string)$item->$title);
                                        } else if (!is_a($item->$link, 'stdClass')) {
                                            $renderer->externallink($item->$link, (string)$item->$title);
                                        }
                                    }
                                }
                            } else {
                                if (isset($item->$val) && !is_a($item->$val, 'stdClass')) {
                                    $renderer->cdata((string)$item->$val);
                                }
                            }
                        }
                    }
                    $renderer->listcontent_close();
                    $renderer->listitem_close();
                }
                $renderer->listu_close();
            }
        }

        return true;
    }
}

// vim:ts=4:sw=4:et:
