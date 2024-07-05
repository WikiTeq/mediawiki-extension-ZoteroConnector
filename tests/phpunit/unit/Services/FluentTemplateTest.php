<?php

namespace MediaWiki\Extension\ZoteroConnector\Tests\Unit\Services;

use InvalidArgumentException;
use MediaWiki\Extension\ZoteroConnector\Services\FluentTemplate;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\ZoteroConnector\Services\FluentTemplate
 */
class FluentTemplateTest extends MediaWikiUnitTestCase {

	public function testEscaping() {
		$t = new FluentTemplate( 'Test' );
		$t->setParam( 'a', 'aaa{aaa' );
		$t->setParam( 'b', 'bbb}bbb' );
		$t->setParam( 'c', 'ccc[ccc' );
		$t->setParam( 'd', 'ddd]ddd' );
		$t->setParam( 'e', 'eee|eee' );

		$expected = '{{Test'
			. "\n|a = aaa&#123;aaa"
			. "\n|b = bbb&#125;bbb"
			. "\n|c = ccc&#91;ccc"
			. "\n|d = ddd&#93;ddd"
			. "\n|e = eee{{!}}eee"
			. "\n}}";
		$this->assertSame( $expected, $t->getWikitext() );
	}

	public function testHasSet() {
		$t = new FluentTemplate( "Test" );
		$this->assertFalse( $t->hasParam( 'a' ) );
		$t->setParam( 'a', 'aaa' );
		$this->assertTrue( $t->hasParam( 'a' ) );
		$this->assertSame( 'aaa', $t->getParam( 'a' ) );

		$this->assertFalse( $t->hasParam( 'b' ) );
		$t->setParam( 'b', 'bbb' );
		$this->assertTrue( $t->hasParam( 'b' ) );
		$this->assertSame( 'bbb', $t->getParam( 'b' ) );

		$this->assertFalse( $t->maybeAddParam( 'b', 'xxx' ) );
		$this->assertSame( 'bbb', $t->getParam( 'b' ) );

		$this->assertFalse( $t->hasParam( 'c' ) );
		$this->assertTrue( $t->maybeAddParam( 'c', 'ccc' ) );
		$this->assertTrue( $t->hasParam( 'c' ) );
		$this->assertSame( 'ccc', $t->getParam( 'c' ) );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Cannot get missing param d' );
		$t->getParam( 'd' );
	}

}
