# Govt.NZ Shortcode Parser

[![Build Status](http://img.shields.io/travis/govtnz/aacustomshortcodes.svg?style=flat-square)](http://travis-ci.org/govtnz/aacustomshortcodes)
[![Version](http://img.shields.io/packagist/v/govtnz/aacustomshortcodes.svg?style=flat-square)](https://packagist.org/packages/govtnz/aacustomshortcodes)
[![License](http://img.shields.io/packagist/l/govtnz/aacustomshortcodes.svg?style=flat-square)](LICENSE)

# Overview

This module is a transitional implementation of short code handling. It uses the dependency
injector to replace use of SilverStripe's built-in ShortcodeParser with a custom parser.

It is considered a transitional implementation because the intention is to provide
a revised implementation for a future SilverStripe release, subject to review and refinement.
As such, the replacement implementation is completely self contained, except where
framework unit tests wouldn't work.

It is also designed to be a drop-in replacement for the standard parser and to be backward compatible with
existing framework and CMS shortcodes, and other shortcodes you may have. You can get additional
behaviours by augmenting the shortcode registration. It uses identical regexes for detecting shortcodes in
text and attributes, and passes all framework short code tests.

# Installation

You can use composer to include this module. One caveat is that the name of the module must occur before any
references to the ShortcodeParser. The CMS module, for example, registers shortcodes. Because modules
are processed in alphabetical order, this needs to be very order, otherwise the dependency injector will
fail to substitute the old parser for the new one.

You can configure shortcodes as you currently do:

	$parser = ShortcodeParser::get('default');
	$parser->register('myoldshortcode', array('SomeClass', 'handler'));

Or you can register with additional metadata:

	$parser->register('mynewshortcode', array('SomeClass', 'handler'), array(
		'expectedResult' => 'block',
		'hasStartAndEnd' => true
	));

You need to set hasStartAndEnd to true for the parser to understand that it expects the shortcode
has an end tag, and will support nesting.

# Implementation

The implementation is a subclass of ShortcodeParser, but provides it's own implementations of all
functions so it is as independent as possible.

The current ShortcodeParser implementation works by first using regexs to find the start and end
of shortcodes, substituting these for special marker elements, and then replacing these elements
with the shortcode evaluation. It does not allow for nested shortcodes, which is a major limitation
that the new implemenation corrects for.

The new implementation has two general behaviours in addition to the current implementation:

 *	Being able to parse nested short codes
 *	Being able to provide additional metadata about a shortcode, which lets it better handle
 	certain cases (below)

## Special cases

Shortcodes can be entered as text in a rich text field. TinyMCE does not understand this, and will
always wrap the shortcode with a

	<p>...</p>

This would be correct if the shortcode substitution
returns an inline element, but is not correct if the shortcode substitution is a block element, as
block elements cannot be nested in a paragraph. To work around this, when a shortcode is registered,
the extra options can be used to tell the shortcode parser that an element is expected to return
a block element. If this is the case, it will automatically remove the wrapper.

Additionally, to support the parsing of nested shortcodes, the extra options on shortcode registration
can tell the parser that a shortcode expects to have a start and end. This is required for the parser
to make sense efficiently of the shortcode nesting at run time.

Shortcodes that are not registered with extra metadata are treated as they were before. However, a shortcode 
that has a start and an end will not support elements nested within it - it will need the additional metadata.

## Parsing differences

Once the parse understands the sequence of shortcodes (open/close or single), it processes the sequence
using a stack to match opening and closing shortcodes, so that it can generate a new list of short codes to
be processed such that a child is processed before it's parent. Substitution of shortcodes is then done in
this order (bottom up replacement).
