<?php

namespace GovtNZ\SilverStripe\Tests\Parsers;

use SilverStripe\Dev\SapphireTest;

/**
 * Unit tests for GOVT NZ custom shortcode parser, which derives from shortcode parser but extends it
 * with extra behaviour.
 *
 */
class GovtNZShortcodeParserTest extends SapphireTest {

	protected $arguments, $contents, $tagName, $parser;
	protected $extra = array();

	public function setUp() {
		// ShortcodeParser::get('test')->register('test_shortcode', array($this, 'shortcodeSaver'));

		$this->parser = ShortcodeParser::get('test');

		$this->parser->register(
			'testblocknested',
			function ($arguments, $content = null, $parser = null, $tagName) {
				return "<div>" . $content . "</div>";
			},
			array(
				'hasStartAndEnd' => true,
				'expectedResult' => GovtNZShortcodeParser::$RESULT_BLOCK
			)
		);
		$this->parser->register(
			'testblocksecond',
			function ($arguments, $content = null, $parser = null, $tagName) {
				return "<div>#2" . $content . "</div>";
			},
			array(
				'hasStartAndEnd' => true,
				'expectedResult' => GovtNZShortcodeParser::$RESULT_BLOCK
			)
		);
		$this->parser->register(
			'testinlinesingle',
			function ($arguments, $content = null, $parser = null, $tagName) {
				return "<span>testinlinesingle</span>";
			},
			array(
				'hasStartAndEnd' => false,
				'expectedResult' => GovtNZShortcodeParser::$RESULT_INLINE
			)
		);
		$this->parser->register(
			'legacy',
			function ($arguments, $content = null, $parser = null, $tagName) {
				return "<span>legacy</span>";
			}
		);

		parent::setUp();
	}

	public function testStrippingNestedBlockShortcode() {
		// Check no substitution case, with <p> tags
		$this->assertEquals(
			'<p>no shortcode</p>',
			$this->parser->parse('<p>no shortcode</p>')
		);

		// // Check no substitution case, without <p> tags
		$this->assertEquals(
			'no shortcode',
			$this->parser->parse('no shortcode')
		);

		// Check minimal nested block shortcode. This is not the typical case (see next).
		$this->assertEquals(
			'<div>test</div>',
			$this->parser->parse('[testblocknested]test[/testblocknested]')
		);

		// Test typical scenario with <p>...</p> around start and end shortcodes.
		$this->assertEquals(
			"<div>\ntest</div>",
			$this->parser->parse("<p>[testblocknested]</p>\ntest<p>[/testblocknested]</p>")
		);

		// Test typical scenario with <p>...</p> around start and end shortcodes. With param.
		$this->assertEquals(
			"<div>\ntest</div>",
			$this->parser->parse("<p>[testblocknested title=\"foo\"]</p>\ntest<p>[/testblocknested]</p>")
		);
	}

	public function testNesting() {
		$this->assertEquals(
			'<div>before<div>#2foo</div></div>',
			$this->parser->parse('[testblocknested]before[testblocksecond]foo[/testblocksecond][/testblocknested]')
		);
		$this->assertEquals(
			'<div>before<div>#2foo</div>after</div>',
			$this->parser->parse('[testblocknested]before[testblocksecond]foo[/testblocksecond]after[/testblocknested]')
		);
		$this->assertEquals(
			'<div><div>#2foo</div>after</div>',
			$this->parser->parse('[testblocknested][testblocksecond]foo[/testblocksecond]after[/testblocknested]')
		);
		$this->assertEquals(
			'<div>before<span>testinlinesingle</span>after</div>',
			$this->parser->parse('[testblocknested]before[testinlinesingle]after[/testblocknested]')
		);
		$this->assertEquals(
			'<div>before<span>testinlinesingle</span>after1<div>#2foo</div>after2</div>',
			$this->parser->parse('[testblocknested]before[testinlinesingle]after1[testblocksecond]foo[/testblocksecond]after2[/testblocknested]')
		);
		$this->assertEquals(
			'<div>before<span>legacy</span>after1<div>#2foo</div>after2</div>',
			$this->parser->parse('[testblocknested]before[legacy]after1[testblocksecond]foo[/testblocksecond]after2[/testblocknested]')
		);
	}

	public function testInlineSingleShortcode() {
		$this->assertEquals(
			"<p><span>testinlinesingle</span></p>",
			$this->parser->parse("<p>[testinlinesingle]</p>")
		);
	}
}
