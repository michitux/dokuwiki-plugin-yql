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

/**
 * The YQL syntax plugin
 */
class syntax_plugin_yql extends DokuWiki_Syntax_Plugin {
    /**
     * Syntax Type
     * @return string The type
     */
    public function getType() {
        return 'substition';
    }

    /**
     * Paragraph Type
     *
     * Defines how this syntax is handled regarding paragraphs:
     * 'block'  - Open paragraphs need to be closed before plugin output
     * @return string The paragraph type
     * @see Doku_Handler_Block
     */
    public function getPType() {
        return 'block';
    }

    /**
     * @return int The sort order
     */
    public function getSort() {
        return 120;
    }


    /**
     * Connect the plugin to the parser modes
     *
     * @param string $mode The current mode
     */
    public function connectTo($mode) {
        $this->Lexer->addSpecialPattern('<YQL.*?>.*?<\/YQL>',$mode,'plugin_yql');
    }

    /**
     * Handler to prepare matched data for the rendering process
     *
     * @param   string       $match   The text matched by the patterns
     * @param   int          $state   The lexer state for the match
     * @param   int          $pos     The character position of the matched text
     * @param   Doku_Handler $handler Reference to the Doku_Handler object
     * @return  array Return an array with all data you want to use in render
     */
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
                        if ($pos % 2 == 0) { // the start and every second part is pure character data
                            $data['format'][] = $part;
                        } else { // this is the stuff inside %% %%
                            if (strpos($part, '|') !== FALSE) { // is this a link?
                                list($link, $title) = explode('|', $part, 2);
                                $data['format'][] = array($link => $title);
                            } else { // if not just store the name, we'll recognize that again because of the position
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
        // set default values
        if (!isset($data['refresh'])) $data['refresh'] = 14400;
        if (!isset($data['format'])) $data['format'] = array('', array('link' => 'title'), '');
        if (!isset($data['item_name'])) $data['item_name'] = 'item';

        return $data;
    }

    /**
     * Handles the actual output creation.
     *
     * @param   $format   string        output format being rendered
     * @param   $renderer Doku_Renderer reference to the current renderer object
     * @param   $data     array         data created by handler()
     * @return  boolean                 rendered correctly?
     */
    public function render($format, Doku_Renderer $renderer, $data) {
        $refresh = $data['referesh'];
        $format  = $data['format'];
        $item_name = $data['item_name'];
        $query   = $data['query'];
        extract($data);

        // Don't fetch the data for rendering metadata
        // But still do it for all other modes in order to support different renderers
        if ($format == 'metadata') {
            /** @var $renderer Doku_Renderer_metadata */
            $renderer->meta['date']['valid']['age'] =
                isset($renderer->meta['date']['valid']['age']) ?
                    min($renderer->meta['date']['valid']['age'],$refresh) :
                    $refresh;
            return false;
        }

        // execute the YQL query

        $yql_base_url = "http://query.yahooapis.com/v1/public/yql";
        $yql_query_url = $yql_base_url . "?q=" . urlencode($query);
        $yql_query_url .= "&format=json";
        $client = new DokuHTTPClient();
        $result = $client->sendRequest($yql_query_url);

        if ($result === false) {
            $this->render_error($renderer, 'YQL: Error: the request to the server failed: '.$client->error);
            return true;
        }

        $json_parser = new JSON();
        $json_result = $json_parser->decode($client->resp_body);

        // catch YQL errors
        if (isset($json_result->error)) {
            $this->render_error($renderer, 'YQL: YQL Error: '.$json_result->error->description);
            return true;
        }

        if (is_null($json_result->query->results)) {
            $this->render_error($renderer, 'YQL: Unknown error: there is neither an error nor results in the YQL result.');
            return true;
        }

        if (!isset($json_result->query->results->$item_name)) {
            $this->render_error($renderer, 'YQL: Error: The item name '.$item_name.' doesn\'t exist in the results');
            return true;
        }

        $renderer->listu_open();
        foreach ($json_result->query->results->$item_name as $item) {
            $renderer->listitem_open(1);
            $renderer->listcontent_open();
            foreach ($format as $pos => $val) {
                if ($pos % 2 == 0) { // outside %% %%, just character data
                    $renderer->cdata($val);
                } else { // inside %% %%, either links or other fields
                    if (is_array($val)) { // arrays are links
                        foreach ($val as $link => $title) {
                            // check if there is a link at all and if the title isn't an instance of stdClass (can't be casted to string)
                            if (!isset($item->$link)) {
                                $this->render_error($renderer, 'YQL: Error: The given attribute '.$link.' doesn\'t exist');
                                continue;
                            }

                            if (!isset($item->$title)) {
                                $this->render_error($renderer, 'YQL: Error: The given attribute '.$title.' doesn\'t exist');
                                continue;
                            }

                            if ($item->$title instanceof stdClass) {
                                $this->render_error($renderer, 'YQL: Error: The given attribute '.$title.' is not a simple string but an object');
                                continue;
                            }

                            // links can be objects, then they should have an attribute "href" which contains the actual url
                            if ($item->$link instanceof stdClass && !isset($item->$link->href)) {
                                $this->render_error($renderer, 'YQL: Error: The given attribute '.$link.' is not a simple string but also doesn\'t have a href attribute as link objects have.');
                                continue;
                            }

                            if ($item->$link instanceof stdClass) {
                                $renderer->externallink($item->$link->href, (string)$item->$title);
                            } else {
                                $renderer->externallink($item->$link, (string)$item->$title);
                            }
                        }
                    } else { // just a field
                        // test if the value really exists and if isn't a stdClass (can't be casted to string)
                        if (!isset($item->$val)) {
                            $this->render_error($renderer, 'YQL: Error: The given attribute '.$val.' doesn\'t exist');
                            continue;
                        }

                        if ($item->$val instanceof stdClass) {
                            $this->render_error($renderer, 'YQL: Error: The given attribute '.$val.' is not a simple string but an object');
                            continue;
                        }

                        $renderer->cdata((string)$item->$val);
                    }
                }
            }
            $renderer->listcontent_close();
            $renderer->listitem_close();
        }
        $renderer->listu_close();

        return true;
    }

    /**
     * Helper function for displaying error messages. Currently just adds a paragraph with emphasis and the error message in it
     */
    private function render_error(Doku_Renderer $renderer, $error) {
        $renderer->p_open();
        $renderer->emphasis_open();
        $renderer->cdata($error);
        $renderer->emphasis_close();
        $renderer->p_close();
    }
}

// vim:ts=4:sw=4:et:
