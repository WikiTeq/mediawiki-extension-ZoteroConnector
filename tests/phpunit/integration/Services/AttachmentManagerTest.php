<?php

namespace MediaWiki\Extension\ZoteroConnector\Tests\Integration\Services;

use FormatJson;
use MediaWiki\Extension\ZoteroConnector\HookHandlers\ParserHandler;
use MediaWiki\Page\PageIdentity;
use MediaWikiIntegrationTestCase;
use MockHttpTrait;
use PageProps;

/**
 * @covers \MediaWiki\Extension\ZoteroConnector\Services\AttachmentManager
 * @group Database
 */
class AttachmentManagerTest extends MediaWikiIntegrationTestCase {
	use MockHttpTrait;

	protected function setUp(): void {
		parent::setUp();

		$pageProps = $this->createNoOpMock( PageProps::class, [ 'getProperties' ] );
		$pageProps->method( 'getProperties' )
			->willReturnCallback(
				function ( $file, $prop ) {
					wfDebug( 'getProperties called' );
					$this->assertInstanceOf( PageIdentity::class, $file );
					$this->assertSame( NS_FILE, $file->getNamespace() );
					$this->assertSame( ParserHandler::FILE_VERSION_PROP_NAME, $prop );
					if ( $file->getDBkey() === 'Existing123.pdf' ) {
						return [ $file->getId() => '123' ];
					}
					return [];
				}
			);
		$this->setService( 'PageProps', $pageProps );
	}

	public function testNewFile() {
		$this->installMockHttp( [
			// ZoteroRequester::getAttachmentInfo() calls ::getSingleItem()
			$this->makeFakeHttpRequest(
				FormatJson::encode( [
					'data' => [
						'itemType' => 'attachment',
						'version' => 123,
						'parentItem' => 'PARENT987',
					]
				] )
			),
			// And since the version is not already the latest
			$this->makeFakeHttpRequest(
				'',
				302,
				[ 'Location' => 'dummy location for download' ]
			),
		] );
		$result = $this->getServiceContainer()
			->getService( 'ZoteroConnector.AttachmentManager' )
			->getUploadData( 'NEWFILE123' );
		$this->assertSame(
			[
				'pageContent' => "{{ZoteroFile\n" .
					"|parentItem = PARENT987\n" .
					"|version = 123\n" .
					'}}',
				'location' => 'dummy location for download',
			],
			$result
		);
	}

	public function testUnchangedFile() {
		$this->getExistingTestPage( 'File:Existing123.pdf' );
		$this->installMockHttp( [
			// ZoteroRequester::getAttachmentInfo() calls ::getSingleItem()
			$this->makeFakeHttpRequest(
				FormatJson::encode( [
					'data' => [
						'itemType' => 'attachment',
						'version' => '123',
						'parentItem' => 'PARENT987',
					]
				] )
			),
		] );
		$result = $this->getServiceContainer()
			->getService( 'ZoteroConnector.AttachmentManager' )
			->getUploadData( 'Existing123' );
		$this->assertSame(
			[
				'pageContent' => "{{ZoteroFile\n" .
					"|parentItem = PARENT987\n" .
					"|version = 123\n" .
					'}}',
				'location' => false,
			],
			$result
		);
	}

	public function testChangedFile() {
		$this->getExistingTestPage( 'File:Existing123.pdf' );
		$this->installMockHttp( [
			// ZoteroRequester::getAttachmentInfo() calls ::getSingleItem()
			$this->makeFakeHttpRequest(
				FormatJson::encode( [
					'data' => [
						'itemType' => 'attachment',
						'version' => '456',
						'parentItem' => 'PARENT987',
					]
				] )
			),
			// And since the version is not already the latest
			$this->makeFakeHttpRequest(
				'',
				302,
				[ 'Location' => 'dummy location for download' ]
			),
		] );
		$result = $this->getServiceContainer()
			->getService( 'ZoteroConnector.AttachmentManager' )
			->getUploadData( 'Existing123' );
		$this->assertSame(
			[
				'pageContent' => "{{ZoteroFile\n" .
					"|parentItem = PARENT987\n" .
					"|version = 456\n" .
					'}}',
				'location' => 'dummy location for download',
			],
			$result
		);
	}

	public function testMissingLocation() {
		$this->installMockHttp( [
			// ZoteroRequester::getAttachmentInfo() calls ::getSingleItem()
			$this->makeFakeHttpRequest(
				FormatJson::encode( [
					'data' => [
						'itemType' => 'attachment',
						'version' => 123,
						'parentItem' => 'PARENT987',
					]
				] )
			),
			// Missing location header
			$this->makeFakeHttpRequest(
				'',
				302,
				[]
			),
		] );
		$result = $this->getServiceContainer()
			->getService( 'ZoteroConnector.AttachmentManager' )
			->getUploadData( 'NEWFILE123' );
		$this->assertSame(
			[
				'pageContent' => "{{ZoteroFile\n" .
					"|parentItem = PARENT987\n" .
					"|version = 123\n" .
					'}}',
				'location' => null,
			],
			$result
		);
	}

}
