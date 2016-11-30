# Overview

This module is a transitional implementation of short code handling. It uses the dependency
injector to replace use of SilverStripe's built-in ShortcodeParser with a custom parser.

It is considered a transitional implementation because the intention is to provide
a revised implementation for a future SilverStripe release, subject to review and refinement.
As such, the replacement implementation is completely self contained, except where
framework unit tests wouldn't work.

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
always wrap the shortcode with a <p>...</p>. This would be correct if the shortcode substitution
returns an inline element, but is not correct if the shortcode substitution is a block element, as
block elements cannot be nested in a paragraph. To work around this, when a shortcode is registered,
the extra options can be used to tell the shortcode parser that an element is expected to return
a block element. If this is the case, it will automatically remove the <p>...</p> wrapper.

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