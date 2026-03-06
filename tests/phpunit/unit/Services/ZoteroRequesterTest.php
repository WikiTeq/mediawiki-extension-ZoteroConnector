<?php

namespace MediaWiki\Extension\ZoteroConnector\Tests\Unit\Services;

use FormatJson;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\ZoteroConnector\Services\ZoteroRequester;
use MediaWiki\Extension\ZoteroConnector\ZoteroNotFoundException;
use MediaWiki\Http\HttpRequestFactory;
use MediaWikiUnitTestCase;
use MWHttpRequest;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * TODO test getItems() but have that return stdClass[] instead of a string
 *
 * @covers \MediaWiki\Extension\ZoteroConnector\Services\ZoteroRequester
 */
class ZoteroRequesterTest extends MediaWikiUnitTestCase {

	private const API_KEY = 'DummyAPIKey';

	private function makeRequester(
		HttpRequestFactory $httpRequestFactory,
		?LoggerInterface $logger = null
	): ZoteroRequester {
		$options = new ServiceOptions(
			ZoteroRequester::CONSTRUCTOR_OPTIONS,
			[ 'ZoteroConnectorAPIKey' => self::API_KEY ]
		);
		return new ZoteroRequester(
			$options,
			$logger ?? new NullLogger(),
			$httpRequestFactory
		);
	}

	/** @dataProvider provideValidLocations */
	public function testGetAttachmentLocation( ?string $attachmentLocation ) {
		$itemId = 'ITEM-ID-GOES-HERE';

		$req = $this->createNoOpMock(
			MWHttpRequest::class,
			[ 'setHeader', 'execute', 'getResponseHeader' ]
		);
		// $executed used to enforce that execute is called after header is
		// set and before response is used
		$executed = false;

		$req->expects( $this->once() )
			->method( 'setHeader' )
			->with( 'Zotero-API-Key', self::API_KEY )
			->willReturnCallback(
				function () use ( &$executed ) {
					$this->assertFalse( $executed );
				}
			);
		$req->expects( $this->once() )
			->method( 'execute' )
			->willReturnCallback(
				static function () use ( &$executed ) {
					$executed = true;
				}
			);
		$req->expects( $this->once() )
			->method( 'getResponseHeader' )
			->with( 'Location' )
			->willReturnCallback(
				function () use ( &$executed, $attachmentLocation ) {
					$this->assertTrue( $executed );
					return $attachmentLocation;
				}
			);
		$httpRequestFactory = $this->createNoOpMock(
			HttpRequestFactory::class,
			[ 'create' ]
		);
		$httpRequestFactory->expects( $this->once() )
			->method( 'create' )
			->with(
				"https://api.zotero.org/groups/4511960/items/$itemId/file/view",
				[],
				ZoteroRequester::class . '::getAttachmentLocation'
			)
			->willReturn( $req );

		$requester = $this->makeRequester( $httpRequestFactory );
		$this->assertSame(
			$attachmentLocation,
			$requester->getAttachmentLocation( $itemId )
		);
	}

	public static function provideValidLocations() {
		yield 'Missing redirect' => [ null ];
		yield 'Valid redirect' => [ 'ATTACHMENT-LOCATION-GOES-HERE' ];
	}

	public function testSingleItemException_top() {
		$requester = $this->makeRequester(
			$this->createNoOpMock( HttpRequestFactory::class )
		);
		$this->expectException( ZoteroNotFoundException::class );
		$this->expectExceptionMessage( "Not found: top" );
		$requester->getSingleItem( 'top' );
	}

	/** @dataProvider provideSingleItemException */
	public function testSingleItemException( $result ) {
		$itemId = 'ITEM-ID-GOES-HERE';

		$req = $this->createNoOpMock(
			MWHttpRequest::class,
			[ 'setHeader', 'execute', 'getContent', 'getStatus' ]
		);
		// $executed used to enforce that execute is called after header is
		// set and before response is used
		$executed = false;

		$req->expects( $this->once() )
			->method( 'setHeader' )
			->with( 'Zotero-API-Key', self::API_KEY )
			->willReturnCallback(
				function () use ( &$executed ) {
					$this->assertFalse( $executed );
				}
			);
		$req->expects( $this->once() )
			->method( 'execute' )
			->willReturnCallback(
				static function () use ( &$executed ) {
					$executed = true;
				}
			);
		$req->expects( $this->once() )
			->method( 'getContent' )
			->willReturnCallback(
				function () use ( &$executed, $result ) {
					$this->assertTrue( $executed );
					return (string)$result;
				}
			);
		if ( $result === 400 ) {
			$req->expects( $this->once() )
				->method( 'getStatus' )
				->willReturn( 400 );
		} else {
			$req->expects( $this->never() )->method( 'getStatus' );
		}

		$httpRequestFactory = $this->createNoOpMock(
			HttpRequestFactory::class,
			[ 'create' ]
		);
		$httpRequestFactory->expects( $this->once() )
			->method( 'create' )
			->with(
				"https://api.zotero.org/groups/4511960/items/$itemId",
				[],
				ZoteroRequester::class . '::getSingleItem'
			)
			->willReturn( $req );

		$requester = $this->makeRequester( $httpRequestFactory );
		$this->expectException( ZoteroNotFoundException::class );
		$this->expectExceptionMessage( "Not found: $itemId" );
		$requester->getSingleItem( $itemId );
	}

	public static function provideSingleItemException() {
		yield 'Not found' => [ 'Not found' ];
		yield 'Does not exist' => [ 'Item does not exist' ];
		yield '400 status' => [ 400 ];
	}

	private function makeSuccessfulRequest( string $data ): MWHttpRequest {
		$req = $this->createNoOpMock(
			MWHttpRequest::class,
			[ 'setHeader', 'execute', 'getContent', 'getStatus' ]
		);
		// $executed used to enforce that execute is called after header is
		// set and before response is used
		$executed = false;

		$req->expects( $this->once() )
			->method( 'setHeader' )
			->with( 'Zotero-API-Key', self::API_KEY )
			->willReturnCallback(
				function () use ( &$executed ) {
					$this->assertFalse( $executed );
				}
			);
		$req->expects( $this->once() )
			->method( 'execute' )
			->willReturnCallback(
				static function () use ( &$executed ) {
					$executed = true;
				}
			);
		$req->expects( $this->once() )
			->method( 'getContent' )
			->willReturnCallback(
				function () use ( &$executed, $data ) {
					$this->assertTrue( $executed );
					return $data;
				}
			);
		$req->expects( $this->once() )->method( 'getStatus' )->willReturn( 200 );

		return $req;
	}

	public function testSingleItemSuccess() {
		$itemId = 'ITEM-ID-GOES-HERE';
		$itemData = (object)[ 'Foo' => 'Bar' ];

		$req = $this->makeSuccessfulRequest( FormatJson::encode( $itemData ) );

		$httpRequestFactory = $this->createNoOpMock(
			HttpRequestFactory::class,
			[ 'create' ]
		);
		$httpRequestFactory->expects( $this->once() )
			->method( 'create' )
			->with(
				"https://api.zotero.org/groups/4511960/items/$itemId",
				[],
				ZoteroRequester::class . '::getSingleItem'
			)
			->willReturn( $req );

		$requester = $this->makeRequester( $httpRequestFactory );
		$data = $requester->getSingleItem( $itemId );
		$this->assertEquals(
			$data,
			$itemData
		);
	}

	public function testGetAttachmentInfoUncachedMissing() {
		$itemId = 'ITEM-ID-GOES-HERE';
		$itemData = (object)[ 'data' => [ 'itemType' => 'attachment' ] ];

		$req = $this->makeSuccessfulRequest( FormatJson::encode( $itemData ) );

		$httpRequestFactory = $this->createNoOpMock(
			HttpRequestFactory::class,
			[ 'create' ]
		);
		$httpRequestFactory->expects( $this->once() )
			->method( 'create' )
			->with(
				"https://api.zotero.org/groups/4511960/items/$itemId",
				[],
				ZoteroRequester::class . '::getSingleItem'
			)
			->willReturn( $req );

		$requester = $this->makeRequester( $httpRequestFactory );
		$data = $requester->getAttachmentInfo( $itemId );
		$this->assertSame(
			[ 'version' => '', 'parentItem' => '' ],
			$data
		);
	}

	public function testGetAttachmentInfoUncachedPresent() {
		$itemId = 'ITEM-ID-GOES-HERE';
		$itemData = (object)[
			'data' => [
				'itemType' => 'attachment',
				'version' => 123,
				'parentItem' => 'abc'
			]
		];

		$req = $this->makeSuccessfulRequest( FormatJson::encode( $itemData ) );

		$httpRequestFactory = $this->createNoOpMock(
			HttpRequestFactory::class,
			[ 'create' ]
		);
		$httpRequestFactory->expects( $this->once() )
			->method( 'create' )
			->with(
				"https://api.zotero.org/groups/4511960/items/$itemId",
				[],
				ZoteroRequester::class . '::getSingleItem'
			)
			->willReturn( $req );

		$requester = $this->makeRequester( $httpRequestFactory );
		$data = $requester->getAttachmentInfo( $itemId );
		$this->assertSame(
			[ 'version' => '123', 'parentItem' => 'abc' ],
			$data
		);
	}
}
