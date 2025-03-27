<?php

namespace MediaWiki\Extension\ZoteroConnector\Tests\Integration\HookHandlers;

use MediaWiki\Extension\ZoteroConnector\HookHandlers\CommentHandler;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\ZoteroConnector\HookHandlers\CommentHandler
 */
class CommentHandlerTest extends MediaWikiIntegrationTestCase {

	/** @dataProvider provideCases */
	public function testHookApplied( string $comment, string $expected ) {
		// SMW complains when the language code changes, turn off that path
		$this->clearHook( 'CanonicalNamespaces' );

		$this->setContentLang( 'qqx' );
		$commentFormatter = $this->getServiceContainer()->getCommentFormatter();
		$formatted = $commentFormatter->format( $comment );
		$this->assertSame( $expected, $formatted );
	}

	public static function provideCases() {
		yield 'Auto comment (upload)' => [
			'/* ' . CommentHandler::AUTO_UPLOAD_KEY . ' */',
			'(' . CommentHandler::AUTO_UPLOAD_KEY . ')',
		];
		yield 'Auto comment (update)' => [
			'/* ' . CommentHandler::AUTO_UPDATE_KEY . ' */',
			'(' . CommentHandler::AUTO_UPDATE_KEY . ')',
		];
		yield 'Normal comment' => [ 'foo', 'foo' ];
	}

}
