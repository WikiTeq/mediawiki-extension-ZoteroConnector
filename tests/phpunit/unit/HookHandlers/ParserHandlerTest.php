<?php

namespace MediaWiki\Extension\ZoteroConnector\Tests\Unit\HookHandlers;

use MediaWiki\Extension\ZoteroConnector\HookHandlers\ParserHandler;
use MediaWiki\Extension\ZoteroConnector\Services\AttachmentManager;
use MediaWikiUnitTestCase;
use Message;
use Parser;
use ParserOutput;
use RequestContext;
use Title;

/**
 * @covers \MediaWiki\Extension\ZoteroConnector\HookHandlers\ParserHandler
 */
class ParserHandlerTest extends MediaWikiUnitTestCase {

	public function testHooksRegistered() {
		$hooks = new ParserHandler(
			$this->createNoOpMock( AttachmentManager::class )
		);
		$parser = $this->createNoOpMock( Parser::class, [ 'setFunctionHook' ] );
		$parser->expects( $this->exactly( 2 ) )
			->method( 'setFunctionHook' )
			->withConsecutive(
				[
					ParserHandler::FILE_VERSION_HOOK_NAME,
					[ $hooks, 'setZoteroVersion' ],
				],
				[
					ParserHandler::REFERENCE_TITLE_HOOK_NAME,
					[ $hooks, 'setReferenceTitle' ]
				]
			);
		$hooks->onParserFirstCallInit( $parser );
	}

	private function testVersionForParser(
		Parser $parser,
		string $version
	) {
		// Test is done via the expectations of the configured parser
		$hooks = new ParserHandler(
			$this->createNoOpMock( AttachmentManager::class )
		);
		$this->assertSame( '', $hooks->setZoteroVersion( $parser, $version ) );
	}

	public function testVersionSet() {
		// No page set
		$p1 = $this->createNoOpMock( Parser::class, [ 'getPage' ] );
		$p1->expects( $this->once() )->method( 'getPage' )->willReturn( null );
		$this->testVersionForParser( $p1, '' );

		// Wrong namespace
		$t2 = $this->createNoOpMock( Title::class, [ 'getNamespace' ] );
		$t2->expects( $this->once() )->method( 'getNamespace' )->willReturn( NS_MAIN );
		$p2 = $this->createNoOpMock( Parser::class, [ 'getPage', 'addTrackingCategory' ] );
		$p2->expects( $this->once() )->method( 'getPage' )->willReturn( $t2 );
		$p2->expects( $this->once() )->method( 'addTrackingCategory' )
			->with( ParserHandler::NONFILE_TRACKING_CAT_NAME );
		$this->testVersionForParser( $p2, '' );

		// Used for the next three cases, no need to have a different title each
		// time
		$filePage = $this->createNoOpMock( Title::class, [ 'getNamespace' ] );
		$filePage->expects( $this->exactly( 3 ) )->method( 'getNamespace' )
			->willReturn( NS_FILE );

		// Empty version and invalid version are treated the same
		$p3 = $this->createNoOpMock( Parser::class, [ 'getPage', 'addTrackingCategory' ] );
		$p3->expects( $this->exactly( 2 ) )->method( 'getPage' )->willReturn( $filePage );
		$p3->expects( $this->exactly( 2 ) )->method( 'addTrackingCategory' )
			->with( ParserHandler::INVALID_VERSION_TRACKING_CAT_NAME );
		$this->testVersionForParser( $p3, '' );
		$this->testVersionForParser( $p3, 'abc' );

		// Success
		$output = $this->createNoOpMock( ParserOutput::class, [ 'setPageProperty' ] );
		$output->expects( $this->once() )
			->method( 'setPageProperty' )
			->with( ParserHandler::FILE_VERSION_PROP_NAME, 12345 );
		$p4 = $this->createNoOpMock( Parser::class, [ 'getPage', 'getOutput' ] );
		$p4->expects( $this->once() )->method( 'getPage' )->willReturn( $filePage );
		$p4->expects( $this->once() )->method( 'getOutput' )->willReturn( $output );
		$this->testVersionForParser( $p4, '12345' );
	}

	public function testInfoAction_nonFile() {
		$hooks = new ParserHandler(
			$this->createNoOpMock( AttachmentManager::class )
		);
		$title = $this->createNoOpMock( Title::class, [ 'inNamespace' ] );
		$title->expects( $this->once() )->method( 'inNamespace' )
			->with( NS_FILE )
			->willReturn( false );
		$ctx = new RequestContext();
		$ctx->setTitle( $title );
		$info = [];
		$hooks->onInfoAction( $ctx, $info );
		$this->assertSame( [], $info );
	}

	public function testInfoAction_noVersion() {
		$title = $this->createNoOpMock( Title::class, [ 'inNamespace' ] );
		$title->expects( $this->once() )->method( 'inNamespace' )
			->with( NS_FILE )
			->willReturn( true );
		$manager = $this->createNoOpMock(
			AttachmentManager::class,
			[ 'getAttachmentVersion' ]
		);
		$manager->expects( $this->once() )
			->method( 'getAttachmentVersion' )
			->with( $title )
			->willReturn( null );
		$hooks = new ParserHandler( $manager );
		$ctx = new RequestContext();

		$ctx->setTitle( $title );
		$info = [];
		$hooks->onInfoAction( $ctx, $info );
		$this->assertSame( [], $info );
	}

	public function testInfoAction_withVersion() {
		$title = $this->createNoOpMock( Title::class, [ 'inNamespace' ] );
		$title->expects( $this->once() )->method( 'inNamespace' )
			->with( NS_FILE )
			->willReturn( true );
		$manager = $this->createNoOpMock(
			AttachmentManager::class,
			[ 'getAttachmentVersion' ]
		);
		$manager->expects( $this->once() )
			->method( 'getAttachmentVersion' )
			->with( $title )
			->willReturn( '98765' );
		$hooks = new ParserHandler( $manager );

		$msg = $this->createNoOpMock( Message::class );

		$ctx = $this->createNoOpMock( RequestContext::class, [ 'getTitle', 'msg' ] );
		$ctx->expects( $this->once() )->method( 'getTitle' )->willReturn( $title );
		$ctx->expects( $this->once() )
			->method( 'msg' )
			->with( 'zotero-file-current-version-info' )
			->willReturn( $msg );

		$info = [];
		$hooks->onInfoAction( $ctx, $info );
		$this->assertSame(
			[ 'header-properties' => [
				[ $msg, '98765' ],
			] ],
			$info
		);
	}

	private function testTitleForParser(
		Parser $parser,
		string $title
	) {
		// Test is done via the expectations of the configured parser
		$hooks = new ParserHandler(
			$this->createNoOpMock( AttachmentManager::class )
		);
		$this->assertSame( '', $hooks->setReferenceTitle( $parser, $title ) );
	}

	public function testTitleSet() {
		// No page set
		$p1 = $this->createNoOpMock( Parser::class, [ 'getPage' ] );
		$p1->expects( $this->once() )->method( 'getPage' )->willReturn( null );
		$this->testTitleForParser( $p1, '' );

		// Wrong namespace
		$t2 = $this->createNoOpMock( Title::class, [ 'getNamespace' ] );
		$t2->expects( $this->once() )->method( 'getNamespace' )->willReturn( NS_MAIN );
		$p2 = $this->createNoOpMock( Parser::class, [ 'getPage', 'addTrackingCategory' ] );
		$p2->expects( $this->once() )->method( 'getPage' )->willReturn( $t2 );
		$p2->expects( $this->once() )->method( 'addTrackingCategory' )
			->with( ParserHandler::NONREFERENCE_TRACKING_CAT_NAME );
		$this->testTitleForParser( $p2, '' );

		// Success
		$t3 = $this->createNoOpMock( Title::class, [ 'getNamespace' ] );
		$t3->expects( $this->once() )->method( 'getNamespace' )->willReturn( NS_ZOTERO_REF );
		$output = $this->createNoOpMock( ParserOutput::class, [ 'setDisplayTitle' ] );
		$output->expects( $this->once() )
			->method( 'setDisplayTitle' )
			->with( 'abc&lt;123&gt;xyz' );
		$p3 = $this->createNoOpMock( Parser::class, [ 'getPage', 'getOutput' ] );
		$p3->expects( $this->once() )->method( 'getPage' )->willReturn( $t3 );
		$p3->expects( $this->once() )->method( 'getOutput' )->willReturn( $output );
		$this->testTitleForParser( $p3, 'abc<123>xyz' );
	}

}
