<?php

namespace MediaWiki\Extension\ZoteroConnector\Tests\Unit\HookHandlers;

use Language;
use MediaWiki\Extension\ZoteroConnector\HookHandlers\CommentHandler;
use MediaWikiUnitTestCase;
use Wikimedia\Message\IMessageFormatterFactory;
use Wikimedia\Message\ITextFormatter;
use Wikimedia\Message\MessageValue;
use Wikimedia\TestingAccessWrapper;

/**
 * @covers \MediaWiki\Extension\ZoteroConnector\HookHandlers\CommentHandler
 */
class CommentHandlerTest extends MediaWikiUnitTestCase {

	public function testFactory() {
		$lang = $this->createNoOpMock( Language::class, [ 'getCode' ] );
		$lang->expects( $this->once() )
			->method( 'getCode' )
			->willReturn( 'FOO' );

		$formatter = $this->createNoOpMock( ITextFormatter::class );

		$formatterFactory = $this->createNoOpMock(
			IMessageFormatterFactory::class,
			[ 'getTextFormatter' ]
		);
		$formatterFactory->expects( $this->once() )
			->method( 'getTextFormatter' )
			->with( 'FOO' )
			->willReturn( $formatter );

		$hooks = CommentHandler::create( $formatterFactory, $lang );
		$this->assertInstanceOf( CommentHandler::class, $hooks );
		$this->assertSame(
			$formatter,
			TestingAccessWrapper::newFromObject( $hooks )->textFormatter,
			'Formatter was stored'
		);
	}

	/** @dataProvider provideCases */
	public function testDoFormat( $comment, $auto, $expectResult ) {
		if ( $auto === '' ) {
			$formatter = $this->createNoOpMock( ITextFormatter::class );
		} else {
			$formatter = $this->createNoOpMock( ITextFormatter::class, [ 'format' ] );
			$formatter->expects( $this->once() )
				->method( 'format' )
				->with( $this->callback(
					static function ( $v ) use ( $auto ) {
						return ( $v instanceof MessageValue )
							&& ( $v->getKey() === $auto )
							&& ( $v->getParams() === [] );
					}
				) )
				->willReturn( 'FORMATTED-COMMENT' );
		}

		$hooks = new CommentHandler( $formatter );
		$hooks->onFormatAutocomments(
			$comment,
			false,
			$auto,
			true,
			null,
			true,
			''
		);
		$this->assertSame( $expectResult, $comment );
	}

	public static function provideCases() {
		// comment, auto match, expected result
		yield 'Not replaced' => [ 'NOTTHEKEY', '', 'NOTTHEKEY' ];
		yield 'Upload' => [ '', CommentHandler::AUTO_UPLOAD_KEY, 'FORMATTED-COMMENT' ];
		yield 'Update' => [ '', CommentHandler::AUTO_UPDATE_KEY, 'FORMATTED-COMMENT' ];
	}

}
