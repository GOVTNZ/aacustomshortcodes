<?php

namespace GovtNZ\SilverStripe\Parsers;

use SilverStripe\View\Parsers\ShortcodeParser as BaseParser;
use SilverStripe\Dev\Debug;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\View\Parsers\HTMLValue;

/**
 * GovtNZShortcodeParser is a replacement for ShortcodeParser. It provides all
 * of shortcode parser's behaviours, but also handles certain cases.
 * Specifically, this handles the concept of a shortcode that is to be treated
 * as if it returns a block element. TinyMCE doesn't understand the shortcodes
 * so puts <p> around them, and this parser will reduce them. It also
 * understands nested shortcodes.
 */
class ShortcodeParser extends BaseParser
{
    public static $RESULT_TEXT = 'text';

    public static $RESULT_INLINE = 'inline';

    public static $RESULT_BLOCK = 'block';

    public static $RESULT_MIXED = 'mixed';

    protected $extraMetadata = [];

    protected static $debugging = false;

    protected static $block_level_elements = [
        'address',
        'article',
        'aside',
        'audio',
        'blockquote',
        'canvas',
        'dd',
        'div',
        'dl',
        'fieldset',
        'figcaption',
        'figure',
        'footer',
        'form',
        'h1',
        'h2',
        'h3',
        'h4',
        'h5',
        'h6',
        'header',
        'hgroup',
        'ol',
        'output',
        'p',
        'pre',
        'section',
        'table',
        'ul'
    ];

    public function register($shortcode, $callback, $options = null)
    {
        if (!is_callable($callback)) {
            return;
        }

        // Add the mapping of shortcode to callback
        $this->shortcodes[$shortcode] = $callback;

        // Save options, if provided. If NULL, don't record any. If provided default any missing.
        if (!$options) {
            return $this;
        }

        $defaultOptions = array(
            'hasStartAndEnd' => false,
            'expectedResult' => self::$RESULT_TEXT
        );

        $this->extraMetadata[$shortcode] = array_merge($defaultOptions, $options);

        return $this;
    }

    /**
     * Parse shortcodes in the content. This first handles substitution of any
     * custom elements, and then delegates to the default parsing method to
     * handle short codes.
     */
    public function parse($content)
    {
        // LH 20170227 If $content contains table elements <tr> or <td> within a <script> tag, we don't parse it.
        // This avoids breaking static template fragments such as gridfieldextensions. The content we parse comes from
        // TinyMCE, and TinyMCE doesn't permit fragments of this nature.
        if ($this->contentIsFragment($content)) {
            return $content;
        }

        // Iterate over shortcodes with extra metadata, and apply any special case rules based on that metadata.
        foreach ($this->extraMetadata as $shortcode => $options) {
            switch ($options['expectedResult']) {
                case self::$RESULT_TEXT:
                case self::$RESULT_INLINE:
                    // we don't do anything if the shortcode is expected to return an inline element or just text.
                    break;

                case self::$RESULT_BLOCK:
                case self::$RESULT_MIXED:
                    $content = self::handle_blockelement_rules($content, $shortcode, $options);
                    break;

                default:
            }
        }

        // If no shortcodes defined, don't try and parse any
        if (!$this->shortcodes) {
            return $content;
        }

        // If no content, don't try and parse it
        if (!trim($content)) {
            return $content;
        }

        // DOMDocument parsing treats script tags differently. Shortcodes inside them are quoted so don't match.
        // So we first translate <script> to <xscript>, and back again at the end.
        $content = str_replace('<script', '<xscript', $content);
        $content = str_replace('</script', '</xscript', $content);

        // First we operate in text mode, replacing any shortcodes with marker elements so that later we can
        // use a proper DOM. We get back the content with marker elements, all the tags, and an ordered list of
        // opening tags that is used to process in the correct order.
        list($content, $tags, $ordered) = $this->extractTags($content);

        $htmlvalue = Injector::inst()->create(HTMLValue::class, $content);

        // Now parse the result into a DOM
        if (!$htmlvalue->isValid()) {
            if (self::$error_behavior == self::ERROR) {
                user_error('Couldn\'t decode HTML when processing short codes', E_USER_ERRROR);
            } else {
                return $content;
            }
        }

        // Replace any shortcodes that are in attributes
        $this->replaceAttributeTagsWithContent($htmlvalue);

        // Iterate over the tags in the depth-first order we've determined for the nested replacement
        // to work.
        $parentID = 0;
        $parents = array();
        foreach ($ordered as $orderedTag) {
            if (self::$debugging) {
                Debug::show('tag is ' . print_r($orderedTag, true));
            }

            if ($orderedTag['markerTag'] == 'undefined' || $orderedTag['markerTag'] == 'notranslate') {
                // these cases are handled earlier
                continue;
            }

            $shortcodeNode = $htmlvalue->query('//' . $orderedTag['markerTag'] . '[@data-tagid="' . $orderedTag['index'] . '"]');
            $shortcodeNode = $shortcodeNode[0];
            if (!$shortcodeNode) {
                continue;
            }

            $tag = $tags[$shortcodeNode->getAttribute('data-tagid')];
            $parent = $this->getParent($shortcodeNode);
            $parents[] = $parent;
            $shortcodeNode->setAttribute('data-parentid', $parentID++);

            $parent = $parents[$shortcodeNode->getAttribute('data-parentid')];
            if (self::$debugging) {
                Debug::show('node is ' . print_r($shortcodeNode, true));
            }
            if (self::$debugging) {
                Debug::show('parent is ' . print_r($parent, true));
            }

            $class = null;
            if (!empty($tag['attrs']['location'])) {
                $class = $tag['attrs']['location'];
            } else {
                if (!empty($tag['attrs']['class'])) {
                    $class = $tag['attrs']['class'];
                }
            }

            $location = self::INLINE;
            if ($class == 'left' || $class == 'right') {
                $location = self::BEFORE;
            }
            if ($class == 'center' || $class == 'leftALone') {
                $location = self::SPLIT;
            }

            if (!$parent) {
                if ($location !== self::INLINE) {
                    user_error(
                        "Parent block for shortcode couldn't be found, but location wasn't INLINE",
                        E_USER_ERROR
                    );
                }
            } else {
                $this->moveMarkerToCompliantHome($shortcodeNode, $parent, $location);
            }

            $this->replaceMarkerWithContent($shortcodeNode, $tag);

            if (self::$debugging) {
                Debug::show('end of loop content DOM is ' . $htmlvalue->getContent());
            }
        }

        $content = $htmlvalue->getContent();

        $content = str_replace('<xscript', '<script', $content);
        $content = str_replace('</xscript', '</script', $content);

        // Clean up any marker classes left over, for example, those injected into <script> tags
        $parser = $this;
        $content = preg_replace_callback(
            // Not a general-case parser; assumes that the HTML generated in replaceElementTagsWithMarkers()
            // hasn't been heavily modified
            '/<span[^>]+class="' . preg_quote(self::$marker_class) . '"[^>]+data-tagid="([^"]+)"[^>]+>/i',
            function ($matches) use ($tags, $parser) {
                $tag = $tags[$matches[1]];
                return $parser->getShortcodeReplacementText($tag);
            },
            $content
        );

        return $content;
    }

    /**
     * @param $content
     * @return boolean
     * Returns TRUE if the content contains table fragments within a script tag and without an enclosing table.
     * Written specifically for gridfieldextensions' GridFieldAddNewInlineRow, but will work for other static template
     * instances. This is a nasty hack, but we have two conflicting dynamics at work; the injection of script blocks
     * into the page by modules such as gridfieldextensions, and the need to parse content for shortcodes. In loading
     * content into a DOM and extracting it again, we lose orphaned table elements, which breaks template fragments
     * that rely on this.
     */
    protected function contentIsFragment($content)
    {
        $pos = strpos($content, '<script');

        if ($pos !== false) {
            $content = substr($content, $pos);
            $pos = strpos($content, '</script>');

            if ($pos !== false) {
                $content = substr($content, 0, $pos + 9);
                $pos = strpos($content, '<table');

                if ($pos === false) {
                    $pos = strpos($content, '<tr') || strpos($content, '<td');

                    if ($pos !== false) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Look through a string that contains shortcode tags and pull out the locations and details
     * of those tags
     *
     * @param string $content
     * @return array - The list of tags found. When using an open/close pair, only one item will be in the array,
     * with "content" set to the text between the tags
     */
    public function extractTags($content)
    {
        $tags = array();
        $tagIndex = 0;

        // Step 1: perform basic regex scan of individual tags
        if (preg_match_all(static::tagrx(), $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            for ($i = 0; $i < count($matches); $i++) {
                $match = $matches[$i];

                // Ignore any elements
                if (empty($match['open'][0]) && empty($match['close'][0])) {
                    continue;
                }

                // Pull the attributes out into a key/value hash
                $attrs = array();

                if (!empty($match['attrs'][0])) {
                    preg_match_all(static::attrrx(), $match['attrs'][0], $attrmatches, PREG_SET_ORDER);

                    foreach ($attrmatches as $attr) {
                        list($whole, $name, $value) = array_values(array_filter($attr));
                        $attrs[$name] = $value;
                    }
                }

                // And store the indexes, tag details, etc
                $tag = array(
                    'text' => $match[0][0],
                    's' => $match[0][1],
                    'e' => $match[0][1] + strlen($match[0][0]),
                    'open' => isset($match['open'][0]) ? $match['open'][0] : null,
                    'close' => isset($match['close'][0]) ? $match['close'][0] : null,
                    'attrs' => $attrs,
                    'content' => '',
                    'escaped' => !empty($match['oesc'][0]) || !empty($match['cesc1'][0]) || !empty($match['cesc2'][0]),
                    'oesc' => !empty($match['oesc'][0]),
                    'cesc1' => !empty($match['cesc1'][0]),
                    'cesc2' => !empty($match['cesc2'][0]),
                    'index' => $tagIndex++
                );

                // populate info about whether the tag is expected to have a matching end or not, and
                // also what element should be used based on what it returns.

                if ($tag['open']) {
                    $tagName = $tag['open'];
                } else {
                    $tagName = $tag['close'];
                }

                if (isset($this->extraMetadata[$tagName])) {
                    $registered = true;
                    $conf = $this->extraMetadata[$tagName];
                    $hasStartAndEnd = false;
                    if (isset($conf['hasStartAndEnd']) && $conf['hasStartAndEnd']) {
                        $hasStartAndEnd = true;
                    }

                    if (isset($conf['expectedResult']) && $conf['expectedResult'] == self::$RESULT_BLOCK) {
                        $markerTag = 'div';
                    } else {
                        $markerTag = 'span';
                    }
                } else {
                    // no config, assume it's inline and doesn't have a closing tag
                    $registered = false;
                    $hasStartAndEnd = false;

                    // ignore the tag if it is not registered at all. We flag it's markerTag as 'undefined', which
                    // tells a later step not to convert it to markup, so it is left alone. Unless it is numeric,
                    // in which case it's treated more like a literal.
                    if (!$this->registered($tagName)) {
                        if (is_numeric($tagName)) {
                            $markerTag = 'span';
                        } else {
                            $markerTag = 'undefined';
                        }
                    } else {
                        $markerTag = 'span';
                    }
                }

                $tag['registered'] = $registered;
                $tag['hasStartAndEnd'] = $hasStartAndEnd;
                $tag['markerTag'] = $markerTag;

                $tags[] = $tag;
            }
        }

        // Once we've got the raw tags, look for the special case of a tag  without extended metadata whose start
        // is followed by a close tag that matches. In this case we set hasStartAndEnd so the nested
        // parser expects this. Also check for completely unregistered shortcodes, which will have a markerTag of
        // 'undefined'. Short code rule for unregistered shortcodes are that single shortcodes with no close get
        // replaced with nothing, but shortcodes that have a closing shortcode.
        foreach ($tags as $i => &$tag) {
            if (!$tag['registered']) {
                if ($i < count($tags) - 1 &&                // there is a next tag
                    $tags[$i + 1]['close'] == $tag['open']) {    // it's a close and it matches this open
                    $tag['hasStartAndEnd'] = true;

                    if ($tag['markerTag'] == 'undefined') {
                        // mark both the start and end tag to be left alone.
                        $tags[$i]['markerTag'] = 'notranslate';
                        $tags[$i + 1]['markerTag'] = 'notranslate';
                    }
                }
            }
        }

        if (self::$debugging) {
            Debug::show('extract tags step 1:' . print_r($tags, true));
        }

        // Step 2: (Alternate) From $tags, which identifies where the start and end of shortcodes are (except
        // those in attributes), generate a partially ordered list of tags deepest first, so that we can
        // process it in order.
        list($newContent, $orderedTags) = $this->generateDepthFirstOrderedTags($content, $tags);
        if (self::$debugging) {
            Debug::show('extract tags step 2 tags:' . print_r($tags, true));
        }
        if (self::$debugging) {
            Debug::show('extract tags step 2 prior content:' . print_r($content, true));
        }
        if (self::$debugging) {
            Debug::show('extract tags step 2 new content:' . print_r($newContent, true));
        }
        if (self::$debugging) {
            Debug::show('extract tags step 2 ordered tags:' . print_r($orderedTags, true));
        }
        return array(
            $newContent,
            array_values($tags),
            $orderedTags
        );
    }

    /**
     * A helper function to assist with error handling. Given an error message, figure out what to do based
     * on self::$error_behaviour, as follows:
     *    self::STRIP:        returns an empty string to substitute.
     *    self::WARN:            returns the error message to substitute.
     *    self::LEAVE:        returns FALSE
     *    self::ERROR:        generates a user error with the message.
     *
     * @param string $error
     * @return mixed -  if a string is returned, it is for substituting.
     *                    if FALSE is returned, it indicates the errornous shortcode should not be substituted.
     */
    protected function handleError($error, &$tag)
    {
        switch (self::$error_behavior) {
            case self::STRIP:
                $tag['markerTag'] = 'literal';
                $tag['content'] = '';
                break;

            case self::WARN:
                $tag['markerTag'] = 'literal';
                $tag['content'] = '<strong class="warning">' . $error . '</strong>';
                break;

            case self::LEAVE:
                $tag['markerTag'] = 'literal';
                $tag['content'] = $tag['text'];
                break;

            case self::ERROR:
                user_error($error, E_USER_ERROR);
                break;
        }
    }

    /**
     * Given the content containing shortcodes, and the starting and ending locations of shortcodes
     * (excluding shortcodes in attributes), do two things:
     * - generate an ordered list of tags (excludes the closing tags) such that scanned order of
     *   tags is preserved, but where a tag is nested, the child is in the ordered list before the parent.
     * - replace shortcodes (open and close) with an HTML marker for later parsing. Generates
     *   div elements for shortcodes registered as returning block elements, span for others (differs from
     *   framework which always uses img as the marker.)
     * Unlike the framework's shortcode handling, which has no metadata available to guide parsing,
     * this uses configuration information such as whether a shortcode has an opening and a closing element,
     * to guide parsing.
     *
     * @param string $content Original content being scanned
     * @return array - An array of two elements:
     *            - the modified content with shortcodes replaced, and secondly
     *            - the ordered list of tag openings
     */
    protected function generateDepthFirstOrderedTags($content, &$tags)
    {
        $result = array();
        $orderedTags = array();
        $stack = array();

        // The approach is to iterate over the tags in order. For a shortcode that doesn't have an end,
        // we just add it to the ordered list. For tags that have a start and end, we push the start on
        // the stack. When we see a closing tag, we expect that to match the top of stack. We only add the
        // tag to the ordered list when it is removed from the stack, so that nested tags will get processed
        // first. The only exception is legacy tags that are not registered, where we don't know if the
        // tag has an end or not. In this case, we look ahead one tag to see if it has a matching closing tag,
        // and if so treat as such. This only works because the legacy shortcodes never expected to work nested
        // anyway.
        // No foreach, as we need the index for lookahead.
        for ($i = 0; $i < count($tags); $i++) {
            $tag = $tags[$i];

            if ($tag['open']) {
                if ($tag['hasStartAndEnd']) {
                    // it's an open of a tag with start and end, so push on the stack
                    $stack[] = $tag;
                } else {
                    // write directly to orderedTags
                    $orderedTags[] = $tag;
                }
            } else {
                if ($tag['close']) {
                    if (count($stack) == 0) {
                        // if an end tag appears by itself, it's an error unless it's not registered, where we'll ignore it.
                        if ($this->registered($tag['close'])) {
                            $this->handleError(
                                "didn't expect close of " . $tag['close'],
                                $tags[$i]
                            ); // $tags[$i] rather than $tag, because we change it.
                            $orderedTags[] = $tag;
                        }
                    } else {
                        $stackTop = $stack[count($stack) - 1];
                        if ($stackTop['open'] != $tag['close']) {
                            $this->handleError(
                                "mismatching end shortcode, expected " . $stackTop['open'] . " but got " . $tag['close'],
                                $tags[$i]
                            );
                        } else {
                            // the close matches what's on the stack, so pop it off and add it to orderedtags.
                            $orderedTags[] = $stackTop;
                            array_pop($stack);
                        }
                    }
                }
            }
        }

        $markerClass = self::$marker_class;

        // Now substitute the shortcodes with their corresponding markup. Doing it in reverse order allows us to
        // use the start and end already calculated.
        foreach (array_reverse($tags) as $tag) {
            $markerTag = $tag['markerTag'];
            if ($markerTag == 'undefined') {
                // replace the tag based on error behaviour.
                switch (self::$error_behavior) {
                    case self::STRIP:
                        $c = '';
                        break;
                    case self::WARN:
                        $c = '<strong class="warning">' . $tag['text'] . '</strong>';
                        break;
                    case self::LEAVE:
                        // ignore this tag, and translate nothing. 2 indicates the loop, not the switch.
                        continue 2;
                        break;
                    case self::ERROR:
                        user_error('Unknown shortcode tag ' . $tag['open'], E_USER_ERRROR);
                        break;
                }
                $content = substr($content, 0, $tag['s']) .
                    $c .
                    substr($content, $tag['e']);
            } else {
                if ($markerTag == 'notranslate') {
                    // leave it alone
                } else {
                    if ($markerTag == 'literal') {
                        $content = substr($content, 0, $tag['s']) .
                            $tag['content'] .
                            substr($content, $tag['e']);
                    } else {
                        if ($tag['escaped']) {
                            // strip one set of [ and ]. Work out exactly the bits we want.
                            $start = $tag['s'];
                            $end = $tag['e'];
                            if ($tag['oesc']) {
                                $start += 1;
                            }
                            if ($tag['cesc1'] || $tag['cesc2']) {
                                $end -= 1;
                            }
                            $content = substr($content, 0, $tag['s']) .
                                substr($content, $start, $end - $start) .
                                substr($content, $tag['e']);
                        } else {
                            if ($tag['close']) {
                                // it's always a close div
                                $content = substr($content, 0, $tag['s']) .
                                    '</' . $markerTag . '>' .
                                    substr($content, $tag['e']);
                            } else {
                                if ($tag['open']) {
                                    if ($tag['hasStartAndEnd']) {
                                        $content = substr($content, 0, $tag['s']) .
                                            '<' . $markerTag . ' class="' . $markerClass . '" data-tagid="' . $tag['index'] . '">' .
                                            substr($content, $tag['e']);
                                    } else {
                                        $content = substr($content, 0, $tag['s']) .
                                            '<' . $markerTag . ' class="' . $markerClass . '" data-tagid="' . $tag['index'] . '">' .
                                            '</' . $markerTag . '>' .
                                            substr($content, $tag['e']);
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        $result[] = $content;
        $result[] = $orderedTags;

        return $result;
    }

    protected function getParent($node)
    {
        $parent = $node;
        do {
            $parent = $parent->parentNode;
        } while ($parent instanceof DOMElement &&
        !in_array(strtolower($parent->tagName), self::$block_level_elements));

        return $parent;
    }

    /**
     * Given a node with represents a shortcode marker and some information about the shortcode, call the
     * sha1(str)ortcode handler & replace the marker with the actual content
     *
     * @param DOMElement $node
     * @param array      $tag
     */
    protected function replaceMarkerWithContent($node, $tag)
    {
        $content = $this->getShortcodeNodeReplacementText($node, $tag);
        if (self::$debugging) {
            Debug::show('replacing marker content with ' . print_r($content, true));
        }
        if ($content) {
            $parsed = Injector::inst()->create('HTMLValue', $content);
            $body = $parsed->getBody();
            if ($body) {
                $this->insertListAfter($body->childNodes, $node);
            }
        }

        $this->removeNode($node);
    }

    /**
     * Return the text to insert in place of a shortcode.
     * Behaviour in the case of missing shortcodes depends on the setting of self::$error_behavior.
     * @param $tag A map containing the the following keys:
     *  - 'open': The name of the tag
     *  - 'attrs': Attributes of the tag
     *  - 'content': Content of the tag
     * @param $extra Extra-meta data
     * @param $isHTMLAllowed A boolean indicating whether it's okay to insert HTML tags into the result
     */
    function getShortcodeNodeReplacementText($node, $tag, $extra = array(), $isHTMLAllowed = true)
    {
        // Get the contents of this node as HTML, which is what we'll pass to the shortcode handler.
        $content = $this->getInnerHTML($node, $tag);
        if (self::$debugging) {
            Debug::show('content from node is ' . print_r($content, true));
        }
        $content = $this->callShortcode($tag['open'], $tag['attrs'], $content, $extra);

        // Missing tag
        if ($content === false) {
            if (self::$error_behavior == self::ERROR) {
                user_error('Unknown shortcode tag ' . $tag['open'], E_USER_ERRROR);
            } else {
                if (self::$error_behavior == self::WARN && $isHTMLAllowed) {
                    $content = '<strong class="warning">' . $tag['text'] . '</strong>';
                } else {
                    if (self::$error_behavior == self::STRIP) {
                        return '';
                    } else {
                        return $tag['text'];
                    }
                }
            }
        }

        return $content;
    }

    protected function getInnerHTML($node)
    {
        $content = '';
        foreach ($node->childNodes as $child) {
            $content .= $child->ownerDocument->saveHTML($child);
        }
        return $content;
    }

    /**
     * Replaces the shortcode tags extracted by extractTags with HTML element "markers", so that
     * we can parse the resulting string as HTML and easily mutate the shortcodes in the DOM
     *
     * @param string $content - The HTML string with [tag] style shortcodes embedded
     * @param array  $tags    - The tags extracted by extractTags
     * @return string - The HTML string with [tag] style shortcodes replaced by markers
     */
    protected function replaceTagsWithText($content, $tags, $generator)
    {
        // The string with tags replaced with markers
        $str = '';
        // The start index of the next tag, remembered as we step backwards through the list
        $li = null;

        $i = count($tags);
        while ($i--) {
            if ($li === null) {
                $tail = substr($content, $tags[$i]['e']);
            } else {
                $tail = substr($content, $tags[$i]['e'], $li - $tags[$i]['e']);
            }

            if ($tags[$i]['escaped']) {
                $str = substr($content, $tags[$i]['s'] + 1, $tags[$i]['e'] - $tags[$i]['s'] - 2) . $tail . $str;
            } else {
                $str = $generator($i, $tags[$i]) . $tail . $str;
            }

            $li = $tags[$i]['s'];
        }

        if (self::$debugging) {
            Debug::show('after replacing: ' . print_r(substr($content, 0, $tags[0]['s']) . $str, true));
        }

        return substr($content, 0, $tags[0]['s']) . $str;
    }

    /**
     * Replace the shortcodes in attribute values with the calculated content
     *
     * We don't use markers with attributes because there's no point, it's easier to do all the matching
     * in-DOM after the XML parse
     *
     * @param DOMDocument $doc
     */
    protected function replaceAttributeTagsWithContent($htmlvalue)
    {
        $attributes = $htmlvalue->query('//@*[contains(.,"[")][contains(.,"]")]');
        $parser = $this;

        for ($i = 0; $i < $attributes->length; $i++) {
            $node = $attributes->item($i);
            list($newContent, $tags) = $this->extractTags($node->nodeValue);
            $extra = array('node' => $node, 'element' => $node->ownerElement);

            if ($tags) {
                $node->nodeValue = $this->replaceTagsWithText(
                    $node->nodeValue,
                    $tags,
                    function ($idx, $tag) use ($parser, $extra) {
                        return $parser->getShortcodeReplacementText($tag, $extra, false);
                    }
                );
            }
        }
    }

    protected static function handle_blockelement_rules($content, $shortcode, $options)
    {
        if ($options['hasStartAndEnd']) {
            // there is a start and an end shortcode. In this case, tinyMCE will have
            // put a <p>...</p> around each, so stripe these.
            $startPattern = '/<p>(\[' . $shortcode . '.*?\])<\/p>/';
            $content = preg_replace($startPattern, '$1', $content);

            $endPattern = '/<p>(\[\/' . $shortcode . '\])<\/p>/';
            $content = preg_replace($endPattern, '$1', $content);
        } else {
            $pattern = '/<p>(\[' . $shortcode . '.*?\])<\/p>/';
            $content = preg_replace($pattern, '$1', $content);
        }

        return $content;
    }

    /**
     * Given a node with represents a shortcode marker and a location string, mutates the DOM to put the
     * marker in the compliant location
     *
     * For shortcodes inserted BEFORE, that location is just before the block container that
     * the marker is in
     *
     * For shortcodes inserted AFTER, that location is just after the block container that
     * the marker is in
     *
     * For shortcodes inserted SPLIT, that location is where the marker is, but the DOM
     * is split around it up to the block container the marker is in - for instance,
     *
     *   <p>A<span>B<marker />C</span>D</p>
     *
     * becomes
     *
     *   <p>A<span>B</span></p><marker /><p><span>C</span>D</p>
     *
     * For shortcodes inserted INLINE, no modification is needed (but in that case the shortcode handler needs to
     * generate only inline blocks)
     *
     * @param DOMElement $node
     * @param integer    $location - self::BEFORE, self::SPLIT or self::INLINE
     */
    protected function moveMarkerToCompliantHome($node, $parent, $location)
    {
        // Move before block parent
        if ($location == self::BEFORE) {
            if (isset($parent->parentNode)) {
                $parent->parentNode->insertBefore($node, $parent);
            }
        } else {
            if ($location == self::AFTER) {
                // Move after block parent
                $this->insertAfter($node, $parent);
            } // Split parent at node
            else {
                if ($location == self::SPLIT) {
                    $at = $node;
                    $splitee = $node->parentNode;

                    while ($splitee !== $parent->parentNode) {
                        $spliter = $splitee->cloneNode(false);

                        $this->insertAfter($spliter, $splitee);

                        while ($at->nextSibling) {
                            $spliter->appendChild($at->nextSibling);
                        }

                        $at = $splitee;
                        $splitee = $splitee->parentNode;
                    }

                    $this->insertAfter($node, $parent);
                } // Do nothing
                else {
                    if ($location == self::INLINE) {
                        // if(in_array(strtolower($node->tagName), self::$block_level_elements)) {
                        //  user_error(
                        //      'Requested to insert block tag '.$node->tagName.
                        //      ' inline - probably this will break HTML compliance',
                        //      E_USER_WARNING
                        //  );
                        // }
                        // NOP
                    } else {
                        user_error('Unknown value for $location argument ' . $location, E_USER_ERROR);
                    }
                }
            }
        }
    }

    /**
     * Return the text to insert in place of a shoprtcode.
     * Behaviour in the case of missing shortcodes depends on the setting of self::$error_behavior.
     * @param $tag A map containing the the following keys:
     *  - 'open': The name of the tag
     *  - 'attrs': Attributes of the tag
     *  - 'content': Content of the tag
     * @param $extra Extra-meta data
     * @param $isHTMLAllowed A boolean indicating whether it's okay to insert HTML tags into the result
     */
    function getShortcodeReplacementText($tag, $extra = array(), $isHTMLAllowed = true)
    {
        $content = $this->callShortcode($tag['open'], $tag['attrs'], $tag['content'], $extra);

        // Missing tag
        if ($content === false) {
            if (self::$error_behavior == self::ERROR) {
                user_error('Unknown shortcode tag ' . $tag['open'], E_USER_ERRROR);
            } else {
                if (self::$error_behavior == self::WARN && $isHTMLAllowed) {
                    $content = '<strong class="warning">' . $tag['text'] . '</strong>';
                } else {
                    if (self::$error_behavior == self::STRIP) {
                        return '';
                    } else {
                        return $tag['text'];
                    }
                }
            }
        }

        return $content;
    }
}
