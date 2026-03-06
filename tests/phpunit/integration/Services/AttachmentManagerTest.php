<?php

namespace MediaWiki\Extension\ZoteroConnector\Tests\Integration\Services;

use FormatJson;
use MediaWiki\Extension\ZoteroConnector\HookHandlers\ParserHandler;
use MediaWiki\Extension\ZoteroConnector\Services\ZoteroRequester;
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

	public static function provideVisibilityCases() {
		yield 'Should be public' => [
			[ 'key' => 'PARENT987',
				'data' => [
					'tags' => [
						[ 'tag' => ZoteroRequester::TAG_FOR_PUBLIC_ATTACHMENT ]
					]
				]
			],
			true
		];
		yield 'Should be private' => [
			[ 'key' => 'PARENT987', ],
			false
		];
	}

	/** @dataProvider provideVisibilityCases */
	public function testNewFile( array $parentResponse, bool $shouldBePublic ) {
		$this->installMockHttp( [
			// ZoteroRequester::getAttachmentInfo() calls ::getSingleItem()
			$this->makeFakeHttpRequest(
				FormatJson::encode( [
					'key' => 'NEWFILE123',
					'data' => [
						'itemType' => 'attachment',
						'version' => 123,
						'parentItem' => 'PARENT987',
					]
				] )
			),
			// Parent info gets queried for visibility check
			$this->makeFakeHttpRequest(
				FormatJson::encode( $parentResponse )
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
					'}}' . ( $shouldBePublic ? "\n__MAKE_FILE_PUBLIC__" : '' ),
				'location' => 'dummy location for download',
			],
			$result
		);
	}

	/** @dataProvider provideVisibilityCases */
	public function testUnchangedFile( array $parentResponse, bool $shouldBePublic ) {
		$this->getExistingTestPage( 'File:Existing123.pdf' );
		$this->installMockHttp( [
			// ZoteroRequester::getAttachmentInfo() calls ::getSingleItem()
			$this->makeFakeHttpRequest(
				FormatJson::encode( [
					'key' => 'Existing123',
					'data' => [
						'itemType' => 'attachment',
						'version' => '123',
						'parentItem' => 'PARENT987',
					]
				] )
			),
			// Parent info gets queried for visibility check
			$this->makeFakeHttpRequest(
				FormatJson::encode( $parentResponse )
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
					'}}' . ( $shouldBePublic ? "\n__MAKE_FILE_PUBLIC__" : '' ),
				'location' => false,
			],
			$result
		);
	}

	/** @dataProvider provideVisibilityCases */
	public function testChangedFile( array $parentResponse, bool $shouldBePublic ) {
		$this->getExistingTestPage( 'File:Existing123.pdf' );
		$this->installMockHttp( [
			// ZoteroRequester::getAttachmentInfo() calls ::getSingleItem()
			$this->makeFakeHttpRequest(
				FormatJson::encode( [
					'key' => 'Existing123',
					'data' => [
						'itemType' => 'attachment',
						'version' => '456',
						'parentItem' => 'PARENT987',
					]
				] )
			),
			// Parent info gets queried for visibility check
			$this->makeFakeHttpRequest(
				FormatJson::encode( $parentResponse )
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
					'}}' . ( $shouldBePublic ? "\n__MAKE_FILE_PUBLIC__" : '' ),
				'location' => 'dummy location for download',
			],
			$result
		);
	}

	/** @dataProvider provideVisibilityCases */
	public function testMissingLocation( array $parentResponse, bool $shouldBePublic ) {
		$this->installMockHttp( [
			// ZoteroRequester::getAttachmentInfo() calls ::getSingleItem()
			$this->makeFakeHttpRequest(
				FormatJson::encode( [
					'key' => 'NEWFILE123',
					'data' => [
						'itemType' => 'attachment',
						'version' => 123,
						'parentItem' => 'PARENT987',
					]
				] )
			),
			// Parent info gets queried for visibility check
			$this->makeFakeHttpRequest(
				FormatJson::encode( $parentResponse )
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
					'}}' . ( $shouldBePublic ? "\n__MAKE_FILE_PUBLIC__" : '' ),
				'location' => null,
			],
			$result
		);
	}

}
