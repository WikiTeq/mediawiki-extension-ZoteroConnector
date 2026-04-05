<?php

namespace MediaWiki\Extension\ZoteroConnector\Tests\Integration\Maintenance;

use FilesystemIterator;
use GlobIterator;
use MediaWiki\Extension\ZoteroConnector\Maintenance\ImportZoteroData;
use MediaWiki\Extension\ZoteroConnector\Services\AttachmentManager;
use MediaWiki\Extension\ZoteroConnector\Services\TemplateBuilder;
use MediaWiki\Extension\ZoteroConnector\Services\WikiUpdater;
use MediaWiki\Extension\ZoteroConnector\Services\ZoteroRequester;
use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;
use RuntimeException;
use Status;
use Title;
use User;
use WikitextContent;

/**
 * @covers \MediaWiki\Extension\ZoteroConnector\Maintenance\ImportZoteroData
 * @group Database
 */
class ImportZoteroDataTest extends MaintenanceBaseTestCase {

	/** @var string[] */
	protected $tablesUsed = [ 'page' ];

	protected function getMaintenanceClass(): string {
		return ImportZoteroData::class;
	}

	/** @dataProvider provideInvalidArgs */
	public function testInvalidArgs( array $args, string $expected ) {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( "FATAL ERROR: $expected (exit code = 1)" );
		$this->maintenance->loadWithArgv( $args );
		$this->maintenance->execute();
	}

	public static function provideInvalidArgs() {
		yield 'Invalid type' => [
			[ '--type', 'invalid' ],
			'Invalid value for `type`: should be `references`, `attachments`, '
			. 'or `both`, got: invalid',
		];
		yield 'Invalid from for type=both' => [
			[ '--type', 'both', '--from', 'foo' ],
			'--from can only be used when specifying --type as either '
			. '`references` or `attachments`',
		];
		yield 'No delete unknown refs with --from' => [
			[ '--type', 'references', '--from', 'foo', '--do-delete-unknown-refs' ],
			'--delete-unknown-refs cannot be used with --from',
		];
		yield 'No delete unknown refs with type=attachments' => [
			[ '--type', 'attachments', '--do-delete-unknown-refs' ],
			'--delete-unknown-refs cannot be used with --type=attachments',
		];
		yield 'No delete unknown refs with --item-list' => [
			[ '--item-list', 'foo,bar', '--do-delete-unknown-refs' ],
			'--delete-unknown-refs cannot be used with --item-list',
		];
	}

	public function testDryRunReferences() {
		$iterator = new GlobIterator(
			dirname( __DIR__, 2 ) . '/data/*.json',
			FilesystemIterator::CURRENT_AS_PATHNAME
		);
		$allItems = [];
		foreach ( $iterator as $dataFile ) {
			$allItems[] = json_decode( file_get_contents( $dataFile ) );
		}
		$requester = $this->createNoOpMock(
			ZoteroRequester::class,
			[ 'getItems' ]
		);
		$requester->expects( $this->once() )
			->method( 'getItems' )
			->willReturn( $allItems );
		$this->setService( 'ZoteroConnector.ZoteroRequester', $requester );

		$count = count( $allItems );
		$expectRegex = "ImportZoteroData: import-mode=DRY-RUN, PRINT-UNKNOWN type=references\n";
		$expectRegex .= "Found: $count references\n";
		$expectRegex .= "After processing: $count references, and ";
		$expectRegex .= "\d+ attachments\nIgnoring the attachments\n";
		$expectRegex .= "(References +\d+\/$count \( *\d+%\): \S+ \.\.\.DRY RUN\n)";
		$expectRegex .= '{' . $count . '}';
		$expectRegex .= "References summary:\n0 updated\n0 unchanged\n0 errors\n";
		$expectRegex .= "Attachment summary:\n0 uploaded\n";
		$expectRegex .= "0 pages updated without file changes\n0 unchanged\n0 errors\n";
		$expectRegex .= "No unknown reference pages!\n";
		$expectRegex .= "Done\n";

		$this->expectOutputRegex( "/$expectRegex/" );
		$this->maintenance->loadWithArgv( [ '--type', 'references' ] );
		$this->maintenance->execute();
	}

	public function testItemList() {
		$iterator = new GlobIterator(
			dirname( __DIR__, 2 ) . '/data/*.json',
			FilesystemIterator::CURRENT_AS_PATHNAME
		);
		$itemIds = [];
		$returnMap = [];
		foreach ( $iterator as $dataFile ) {
			$itemId = explode( ' ', basename( $dataFile, '.json' ) )[1];
			$itemIds[] = $itemId;
			$returnMap[] = [ $itemId, json_decode( file_get_contents( $dataFile ) ) ];
		}
		$requester = $this->createNoOpMock(
			ZoteroRequester::class,
			[ 'getSingleItem' ]
		);
		$requester->expects( $this->exactly( count( $returnMap ) ) )
			->method( 'getSingleItem' )
			->willReturnMap( $returnMap );
		$this->setService( 'ZoteroConnector.ZoteroRequester', $requester );

		$count = count( $returnMap );
		$expectRegex = "ImportZoteroData: import-mode=DRY-RUN type=references\n";
		$expectRegex .= "Manual list: " . implode( ',', $itemIds ) . "\n";
		$expectRegex .= "After processing: $count references, and ";
		$expectRegex .= "\d+ attachments\nIgnoring the attachments\n";
		$expectRegex .= "(References +\d+\/$count \( *\d+%\): \S+ \.\.\.DRY RUN\n)";
		$expectRegex .= '{' . $count . '}';
		$expectRegex .= "References summary:\n0 updated\n0 unchanged\n0 errors\n";
		$expectRegex .= "Attachment summary:\n0 uploaded\n";
		$expectRegex .= "0 pages updated without file changes\n0 unchanged\n0 errors\n";
		$expectRegex .= "Done\n";

		$this->expectOutputRegex( "/$expectRegex/" );
		$this->maintenance->loadWithArgv( [
			'--type',
			'references',
			'--item-list',
			implode( ',', $itemIds )
		] );
		$this->maintenance->execute();
	}

	public function testDoImportReferences() {
		// Table should start empty
		$this->assertSelect(
			'page',
			'page_id',
			[ 'page_namespace' => NS_ZOTERO_REF ],
			[]
		);
		$iterator = new GlobIterator(
			dirname( __DIR__, 2 ) . '/data/*.json',
			FilesystemIterator::CURRENT_AS_PATHNAME
		);
		$allItems = [];
		foreach ( $iterator as $dataFile ) {
			$allItems[] = json_decode( file_get_contents( $dataFile ) );
		}
		$requester = $this->createNoOpMock(
			ZoteroRequester::class,
			[ 'getItems' ]
		);
		$requester->expects( $this->once() )
			->method( 'getItems' )
			->willReturn( $allItems );
		$this->setService( 'ZoteroConnector.ZoteroRequester', $requester );

		$count = count( $allItems );
		$expectRegex = "ImportZoteroData: import-mode=DO-IMPORT, PRINT-UNKNOWN type=references\n";
		$expectRegex .= "Found: $count references\n";
		$expectRegex .= "After processing: $count references, and ";
		$expectRegex .= "\d+ attachments\nIgnoring the attachments\n";
		$expectRegex .= "(References +\d+\/$count \( *\d+%\): \S+ \.\.\.updated\n)";
		$expectRegex .= '{' . $count . '}';
		$expectRegex .= "References summary:\n$count updated\n0 unchanged\n0 errors\n";
		$expectRegex .= "Attachment summary:\n0 uploaded\n";
		$expectRegex .= "0 pages updated without file changes\n0 unchanged\n0 errors\n";
		$expectRegex .= "No unknown reference pages!\n";
		$expectRegex .= "Done\n";

		$this->expectOutputRegex( "/$expectRegex/" );
		$this->maintenance->loadWithArgv( [ '--type', 'references', '--do-import' ] );
		$this->maintenance->execute();

		$iterator = new GlobIterator(
			dirname( __DIR__, 2 ) . '/data/*.txt',
			FilesystemIterator::CURRENT_AS_PATHNAME
		);
		$wikiPageFactory = $this->getServiceContainer()->getWikiPageFactory();
		foreach ( $iterator as $expectFile ) {
			$testName = basename( $expectFile, '.txt' );
			$pageName = explode( ' ', $testName )[1];

			$page = $wikiPageFactory->newFromTitle(
				Title::makeTitle( NS_ZOTERO_REF, $pageName )
			);
			$this->assertTrue( $page->exists(), "$pageName should exist" );
			$content = $page->getContent();
			$this->assertInstanceOf(
				WikitextContent::class,
				$content,
				"$pageName should be wikitext"
			);
			$this->assertSame(
				trim( $content->getText() ),
				trim( file_get_contents( $expectFile ) ),
				"$pageName text should match"
			);
		}

		// Table should have exactly the number of imported pages
		$this->assertSelect(
			'page',
			'COUNT(*)',
			[ 'page_namespace' => NS_ZOTERO_REF ],
			[ [ $count ] ]
		);
	}

	public function testDryRunAttachments() {
		$iterator = new GlobIterator(
			dirname( __DIR__, 2 ) . '/data/*.json',
			FilesystemIterator::CURRENT_AS_PATHNAME
		);
		$allItems = [];
		$attachmentCount = 0;
		foreach ( $iterator as $dataFile ) {
			$item = json_decode( file_get_contents( $dataFile ) );
			$allItems[] = $item;
			if ( isset( $item->links->attachment ) ) {
				$attachmentCount++;
			}
		}
		$requester = $this->createNoOpMock(
			ZoteroRequester::class,
			[ 'getItems', 'preloadAttachmentData', 'getAttachmentInfo', 'getAttachmentLocation' ]
		);
		$requester->expects( $this->once() )
			->method( 'getItems' )
			->willReturn( $allItems );
		$requester->expects( $this->once() )->method( 'preloadAttachmentData' );
		$requester->expects( $this->exactly( $attachmentCount ) )
			->method( 'getAttachmentInfo' )
			->willReturn( [ 'parentItem' => 'foo', 'version' => 'bar', 'makePublic' => false ] );
		$requester->expects( $this->exactly( $attachmentCount ) )
			->method( 'getAttachmentLocation' )
			->willReturn( 'MOCK' );
		$this->setService( 'ZoteroConnector.ZoteroRequester', $requester );

		$count = count( $allItems );
		$expectRegex = "ImportZoteroData: import-mode=DRY-RUN, PRINT-UNKNOWN type=attachments\n";
		$expectRegex .= "Found: $count references\n";
		$expectRegex .= "After processing: $count references, and ";
		$expectRegex .= "$attachmentCount attachments\nIgnoring the references\n";
		$expectRegex .= "(Attachments +\d+\/$attachmentCount \( *\d+%\): \S+ \.\.\.found redirect, DRY RUN\n)";
		$expectRegex .= '{' . $attachmentCount . '}';
		$expectRegex .= "References summary:\n0 updated\n0 unchanged\n0 errors\n";
		$expectRegex .= "Attachment summary:\n0 uploaded\n";
		$expectRegex .= "0 pages updated without file changes\n0 unchanged\n0 errors\n";
		$expectRegex .= "No unknown reference pages!\n";
		$expectRegex .= "Done\n";

		$this->expectOutputRegex( "/$expectRegex/" );
		$this->maintenance->loadWithArgv( [ '--type', 'attachments' ] );
		$this->maintenance->execute();
	}

	public function testAttachmentRetry() {
		// We will mock the following:
		// AAA111 - successful upload on first attempt
		// BBB111 - page updated only, on first attempt
		// CCC111 - page unchanged (null edit), on first attempt
		// DDD111 - fail with null redirect first time, then succeed
		// EEE111 - fail with time out first time, then succeed
		// FFF111 - fail with file too big first time, not tried again
		// GGG111 - fail with time out first and second time
		$data = [ 'location' => 'not-null', 'pageContent' => 'not-null' ];
		$firstUploadData = [
			'AAA111' => $data,
			'BBB111' => [ 'location' => false, 'pageContent' => 'real-edit' ],
			'CCC111' => [ 'location' => false, 'pageContent' => 'null-edit' ],
			'DDD111' => [ 'location' => null ],
			'EEE111' => $data,
			'FFF111' => $data,
			'GGG111' => $data,
		];
		$secondUploadData = [
			'DDD111' => $data,
			'EEE111' => $data,
			'GGG111' => $data,
		];
		$manager = $this->createNoOpMock(
			AttachmentManager::class,
			[ 'preloadAttachmentVersions', 'getUploadData' ]
		);
		$manager->expects( $this->exactly( 2 ) )
			->method( 'preloadAttachmentVersions' )
			->withConsecutive(
				// All requested the first time:
				[ array_keys( $firstUploadData ) ],
				// Failures other than file too big requested a second time,
				[ array_keys( $secondUploadData ) ]
			);
		$manager->expects( $this->exactly( count( $firstUploadData ) + count( $secondUploadData ) ) )
			->method( 'getUploadData' )
			->willReturnCallback(
				function ( $key ) use ( &$firstUploadData, &$secondUploadData ) {
					if ( isset( $firstUploadData[$key] ) ) {
						$data = $firstUploadData[$key];
						unset( $firstUploadData[$key] );
						return $data;
					}
					$this->assertArrayHasKey( $key, $secondUploadData );
					$data = $secondUploadData[$key];
					unset( $secondUploadData[$key] );
					return $data;
				}
			);
		$this->setService( 'ZoteroConnector.AttachmentManager', $manager );

		$allItems = array_map(
			static function ( $key ) {
				return (object)[
					'key' => $key,
					'data' => (object)[ 'itemType' => 'attachment' ],
				];
			},
			// Pass the keys to construct the data with
			array_keys( $firstUploadData ),
		);
		$requester = $this->createNoOpMock(
			ZoteroRequester::class,
			[ 'getItems', 'preloadAttachmentData' ]
		);
		$requester->expects( $this->once() )
			->method( 'getItems' )
			->willReturn( $allItems );
		$requester->expects( $this->exactly( 2 ) )->method( 'preloadAttachmentData' );
		$this->setService( 'ZoteroConnector.ZoteroRequester', $requester );

		$updater = $this->createNoOpMock(
			WikiUpdater::class,
			[ 'makeRequestScope', 'updateFilePage', 'importPDFAttachment' ]
		);
		$updater->expects( $this->once() )
			->method( 'makeRequestScope' )
			->willReturn( [ $this->createNoOpMock( User::class ), 'does not matter' ] );
		$updater->expects( $this->exactly( 2 ) )
			->method( 'updateFilePage' )
			->willReturnCallback(
				function ( $key, $content, $user ) {
					if ( $content === 'real-edit' ) {
						return Status::newGood( 'zoteroconnector-attachment-page-updated' );
					} elseif ( $content === 'null-edit' ) {
						return Status::newGood( 'zoteroconnector-upload-attachment-no-change' );
					} else {
						$this->fail( "Bad content for $key: $content" );
					}
				}
			);
		$importResult1 = [
			'AAA111' => Status::newGood(),
			'EEE111' => Status::newFatal( 'http-timed-out' ),
			'FFF111' => Status::newFatal( 'file-too-large' ),
			'GGG111' => Status::newFatal( 'http-timed-out' ),
		];
		$importResult2 = [
			'DDD111' => Status::newGood(),
			'EEE111' => Status::newGood(),
			'GGG111' => Status::newFatal( 'http-timed-out' ),
		];
		$updater->expects( $this->exactly( 7 ) )
			->method( 'importPDFAttachment' )
			->willReturnCallback(
				function ( $key, $loc, $content, $user ) use ( &$importResult1, &$importResult2 ) {
					if ( isset( $importResult1[$key] ) ) {
						$data = $importResult1[$key];
						unset( $importResult1[$key] );
						return $data;
					}
					$this->assertArrayHasKey( $key, $importResult2 );
					$data = $importResult2[$key];
					unset( $importResult2[$key] );
					return $data;
				}
			);
		$this->setService( 'ZoteroConnector.WikiUpdater', $updater );

		$count = count( $allItems );
		$expectStr = "ImportZoteroData: import-mode=DO-IMPORT, PRINT-UNKNOWN type=attachments\n";
		$expectStr .= "Found: 7 references\n";
		$expectStr .= "After processing: 0 references, and 7 attachments\n";
		$expectStr .= "Ignoring the references\n";
		$expectStr .= "Attachments 1/7 ( 14%): AAA111 ...uploaded\n";
		$expectStr .= "Would sleep for a second...\n";
		$expectStr .= "Attachments 2/7 ( 29%): BBB111 ...page updated, no file change\n";
		$expectStr .= "Would sleep for a second...\n";
		$expectStr .= "Attachments 3/7 ( 43%): CCC111 ...no change\n";
		$expectStr .= "Would sleep for a second...\n";
		$expectStr .= "Attachments 4/7 ( 57%): DDD111 ...\n";
		$expectStr .= "DDD111 - got null redirect\n";
		$expectStr .= "Attachments 5/7 ( 71%): EEE111 ...\n";
		$expectStr .= "EEE111 - http timed out\n";
		$expectStr .= "Would sleep for a second...\n";
		$expectStr .= "Attachments 6/7 ( 86%): FFF111 ...\n";
		$expectStr .= "FFF111 - failed to upload:\n";
		$expectStr .= Status::newFatal( 'file-too-large' )->__toString() . "\n";
		$expectStr .= "Would sleep for a second...\n";
		$expectStr .= "Attachments 7/7 (100%): GGG111 ...\n";
		$expectStr .= "GGG111 - http timed out\n";
		$expectStr .= "Would sleep for a second...\n";
		$expectStr .= "References summary:\n0 updated\n0 unchanged\n0 errors\n";
		$expectStr .= "Attachment summary:\n1 uploaded\n";
		$expectStr .= "1 pages updated without file changes\n1 unchanged\n4 errors\n";
		$expectStr .= "DDD111: null-redirect\n";
		$expectStr .= "EEE111: http-timed-out\n";
		$expectStr .= "FFF111: file-too-large\n";
		$expectStr .= "GGG111: http-timed-out\n";
		$expectStr .= "Retrying 3 errors not caused by file size: DDD111, EEE111, GGG111\n";
		$expectStr .= "Attachments 1/3 ( 33%): DDD111 ...uploaded\n";
		$expectStr .= "Would sleep for a second...\n";
		$expectStr .= "Attachments 2/3 ( 67%): EEE111 ...uploaded\n";
		$expectStr .= "Would sleep for a second...\n";
		$expectStr .= "Attachments 3/3 (100%): GGG111 ...\nGGG111 - http timed out\n";
		$expectStr .= "Would sleep for a second...\n";
		$expectStr .= "Attachment retry summary:\n2 uploaded\n";
		$expectStr .= "0 pages updated without file changes\n0 unchanged\n1 errors\n";
		$expectStr .= "GGG111: http-timed-out\n";
		$expectStr .= "No unknown reference pages!\n";
		$expectStr .= "Done\n";

		$this->expectOutputString( $expectStr );
		$this->maintenance->loadWithArgv( [ '--type', 'attachments', '--do-import', '--do-attachment-page-update' ] );
		$this->maintenance->execute();
	}

	public function testAttachmentExistenceFilter() {
		$iterator = new GlobIterator(
			dirname( __DIR__, 2 ) . '/data/*.json',
			FilesystemIterator::CURRENT_AS_PATHNAME
		);
		$allItems = [];
		$attachmentIds = [];
		foreach ( $iterator as $dataFile ) {
			$item = json_decode( file_get_contents( $dataFile ) );
			$allItems[] = $item;
			$attachment = TemplateBuilder::getAttachment( $item );
			if ( $attachment ) {
				$attachmentIds[] = $attachment;
			}
		}
		$attachmentCount = count( $attachmentIds );
		foreach ( array_slice( $attachmentIds, 0, 3 ) as $id ) {
			$this->getExistingTestPage( "File:$id.pdf" );
		}
		$processCount = $attachmentCount - 3;
		$requester = $this->createNoOpMock(
			ZoteroRequester::class,
			[ 'getItems', 'preloadAttachmentData', 'getAttachmentInfo', 'getAttachmentLocation' ]
		);
		$requester->expects( $this->once() )
			->method( 'getItems' )
			->willReturn( $allItems );
		$requester->expects( $this->once() )->method( 'preloadAttachmentData' );
		$requester->expects( $this->exactly( $processCount ) )
			->method( 'getAttachmentInfo' )
			->willReturn( [ 'parentItem' => 'foo', 'version' => 'bar', 'makePublic' => false ] );
		$requester->expects( $this->exactly( $processCount ) )
			->method( 'getAttachmentLocation' )
			->willReturn( 'MOCK' );
		$this->setService( 'ZoteroConnector.ZoteroRequester', $requester );

		$count = count( $allItems );
		$expectRegex = "ImportZoteroData: import-mode=DRY-RUN, PRINT-UNKNOWN type=attachments\n";
		$expectRegex .= "Found: $count references\n";
		$expectRegex .= "After processing: $count references, and ";
		$expectRegex .= "$attachmentCount attachments\nIgnoring the references\n";
		$expectRegex .= "Excluding existing files: $processCount attachments left\n";
		$expectRegex .= "(Attachments +\d+\/$processCount \( *\d+%\): \S+ \.\.\.found redirect, DRY RUN\n)";
		$expectRegex .= '{' . $processCount . '}';
		$expectRegex .= "References summary:\n0 updated\n0 unchanged\n0 errors\n";
		$expectRegex .= "Attachment summary:\n0 uploaded\n";
		$expectRegex .= "0 pages updated without file changes\n0 unchanged\n0 errors\n";
		$expectRegex .= "No unknown reference pages!\n";
		$expectRegex .= "Done\n";

		$this->expectOutputRegex( "/$expectRegex/" );
		$this->maintenance->loadWithArgv( [
			'--type',
			'attachments',
			'--no-reupload',
		] );
		$this->maintenance->execute();
	}

	public function testDryRunDeletion() {
		// Table should start empty
		$this->assertSelect(
			'page',
			'page_id',
			[ 'page_namespace' => NS_ZOTERO_REF ],
			[]
		);
		$this->getExistingTestPage( 'Zotero reference:Foo' );
		$this->getExistingTestPage( 'Zotero reference:Bar' );
		// Now just those two pages
		$this->assertSelect(
			'page',
			[ 'page_namespace', 'page_title' ],
			[ 'page_namespace' => NS_ZOTERO_REF ],
			[
				[ (string)NS_ZOTERO_REF, 'Bar' ],
				[ (string)NS_ZOTERO_REF, 'Foo' ],
			]
		);

		$requester = $this->createNoOpMock(
			ZoteroRequester::class,
			[ 'getItems' ]
		);
		$requester->expects( $this->once() )
			->method( 'getItems' )
			->willReturn( [] );
		$this->setService( 'ZoteroConnector.ZoteroRequester', $requester );

		$expectStr = "ImportZoteroData: import-mode=DRY-RUN, PRINT-UNKNOWN type=both\n";
		$expectStr .= "Found: 0 references\n";
		$expectStr .= "After processing: 0 references, and 0 attachments\n";
		$expectStr .= "References summary:\n0 updated\n0 unchanged\n0 errors\n";
		$expectStr .= "Attachment summary:\n0 uploaded\n";
		$expectStr .= "0 pages updated without file changes\n0 unchanged\n0 errors\n";
		$expectStr .= "There are 2 unknown reference pages: Zotero_reference:Bar, Zotero_reference:Foo\n";
		$expectStr .= "...would delete the 2 pages, but deletion not requested\n";
		$expectStr .= "Done\n";

		$this->expectOutputString( $expectStr );
		$this->maintenance->loadWithArgv( [] );
		$this->maintenance->execute();

		// Pages not deleted
		$this->assertSelect(
			'page',
			[ 'page_namespace', 'page_title' ],
			[ 'page_namespace' => NS_ZOTERO_REF ],
			[
				[ (string)NS_ZOTERO_REF, 'Bar' ],
				[ (string)NS_ZOTERO_REF, 'Foo' ],
			]
		);
	}

	public function testDoDeletionNoImport() {
		// Table should start empty
		$this->assertSelect(
			'page',
			'page_id',
			[ 'page_namespace' => NS_ZOTERO_REF ],
			[]
		);
		$this->getExistingTestPage( 'Zotero reference:Foo' );
		$this->getExistingTestPage( 'Zotero reference:Bar' );
		// Now just those two pages
		$this->assertSelect(
			'page',
			[ 'page_namespace', 'page_title' ],
			[ 'page_namespace' => NS_ZOTERO_REF ],
			[
				[ (string)NS_ZOTERO_REF, 'Bar' ],
				[ (string)NS_ZOTERO_REF, 'Foo' ],
			]
		);

		$iterator = new GlobIterator(
			dirname( __DIR__, 2 ) . '/data/*.json',
			FilesystemIterator::CURRENT_AS_PATHNAME
		);
		$allItems = [];
		$itemId = null;
		foreach ( $iterator as $dataFile ) {
			$allItems[] = json_decode( file_get_contents( $dataFile ) );
			$itemId = explode( ' ', basename( $dataFile, '.json' ) )[1];
		}
		$this->assertNotNull( $itemId );
		// Add a single page that already exists (but wrong content)
		$this->getExistingTestPage( 'Zotero reference:' . $itemId );
		$this->assertSelect(
			'page',
			'COUNT(*)',
			[ 'page_namespace' => NS_ZOTERO_REF ],
			// Not matching the exact titles because order will depend on what
			// is in the data and it doesn't really matter
			[ [ 3 ] ]
		);
		$requester = $this->createNoOpMock(
			ZoteroRequester::class,
			[ 'getItems' ]
		);
		$requester->expects( $this->once() )
			->method( 'getItems' )
			->willReturn( $allItems );
		$this->setService( 'ZoteroConnector.ZoteroRequester', $requester );

		$count = count( $allItems );
		$expectRegex = "ImportZoteroData: import-mode=DRY-RUN, DELETE-UNKNOWN type=references\n";
		$expectRegex .= "Found: $count references\n";
		$expectRegex .= "After processing: $count references, and ";
		$expectRegex .= "\d+ attachments\nIgnoring the attachments\n";
		$expectRegex .= "(References +\d+\/$count \( *\d+%\): \S+ \.\.\.DRY RUN\n)";
		$expectRegex .= '{' . $count . '}';
		$expectRegex .= "References summary:\n0 updated\n0 unchanged\n0 errors\n";
		$expectRegex .= "Attachment summary:\n0 uploaded\n";
		$expectRegex .= "0 pages updated without file changes\n0 unchanged\n0 errors\n";
		$expectRegex .= "There are 2 unknown reference pages: Zotero_reference:Bar, Zotero_reference:Foo\n";
		$expectRegex .= "\.\.\.deleting the 2 pages\n";
		$expectRegex .= "Deletions 1\/2 \( 50%\): Bar \.\.\.deleted\n";
		$expectRegex .= "Deletions 2\/2 \(100%\): Foo \.\.\.deleted\n";
		$expectRegex .= "Deletion summary:\n2 deleted\n0 were already deleted\n0 errors\n";
		$expectRegex .= "Done\n";

		$this->expectOutputRegex( "/$expectRegex/" );
		$this->maintenance->loadWithArgv( [
			'--type', 'references', '--do-delete-unknown-refs'
		] );
		$this->maintenance->execute();

		// The one real page wasn't deleted, even though it wasn't updated
		$this->assertSelect(
			'page',
			[ 'page_namespace', 'page_title' ],
			[ 'page_namespace' => NS_ZOTERO_REF ],
			[
				[ (string)NS_ZOTERO_REF, $itemId ],
			]
		);
	}

	public function testDoDeletionAndImportRefs() {
		// Table should start empty
		$this->assertSelect(
			'page',
			'page_id',
			[ 'page_namespace' => NS_ZOTERO_REF ],
			[]
		);
		$this->getExistingTestPage( 'Zotero reference:Foo' );
		$this->getExistingTestPage( 'Zotero reference:Bar' );
		// Now just those two pages
		$this->assertSelect(
			'page',
			[ 'page_namespace', 'page_title' ],
			[ 'page_namespace' => NS_ZOTERO_REF ],
			[
				[ (string)NS_ZOTERO_REF, 'Bar' ],
				[ (string)NS_ZOTERO_REF, 'Foo' ],
			]
		);

		$iterator = new GlobIterator(
			dirname( __DIR__, 2 ) . '/data/*.json',
			FilesystemIterator::CURRENT_AS_PATHNAME
		);
		$allItems = [];
		$itemId = null;
		foreach ( $iterator as $dataFile ) {
			$allItems[] = json_decode( file_get_contents( $dataFile ) );
			$itemId = explode( ' ', basename( $dataFile, '.json' ) )[1];
		}
		$this->assertNotNull( $itemId );
		// Add a single page that already exists (but wrong content)
		$this->getExistingTestPage( 'Zotero reference:' . $itemId );
		$this->assertSelect(
			'page',
			'COUNT(*)',
			[ 'page_namespace' => NS_ZOTERO_REF ],
			// Not matching the exact titles because order will depend on what
			// is in the data and it doesn't really matter
			[ [ 3 ] ]
		);
		$requester = $this->createNoOpMock(
			ZoteroRequester::class,
			[ 'getItems' ]
		);
		$requester->expects( $this->once() )
			->method( 'getItems' )
			->willReturn( $allItems );
		$this->setService( 'ZoteroConnector.ZoteroRequester', $requester );

		$count = count( $allItems );
		$expectRegex = "ImportZoteroData: import-mode=DO-IMPORT, DELETE-UNKNOWN type=references\n";
		$expectRegex .= "Found: $count references\n";
		$expectRegex .= "After processing: $count references, and ";
		$expectRegex .= "\d+ attachments\nIgnoring the attachments\n";
		$expectRegex .= "(References +\d+\/$count \( *\d+%\): \S+ \.\.\.updated\n)";
		$expectRegex .= '{' . $count . '}';
		$expectRegex .= "References summary:\n$count updated\n0 unchanged\n0 errors\n";
		$expectRegex .= "Attachment summary:\n0 uploaded\n";
		$expectRegex .= "0 pages updated without file changes\n0 unchanged\n0 errors\n";
		$expectRegex .= "There are 2 unknown reference pages: Zotero_reference:Bar, Zotero_reference:Foo\n";
		$expectRegex .= "\.\.\.deleting the 2 pages\n";
		$expectRegex .= "Deletions 1\/2 \( 50%\): Bar \.\.\.deleted\n";
		$expectRegex .= "Deletions 2\/2 \(100%\): Foo \.\.\.deleted\n";
		$expectRegex .= "Deletion summary:\n2 deleted\n0 were already deleted\n0 errors\n";
		$expectRegex .= "Done\n";

		$this->expectOutputRegex( "/$expectRegex/" );
		$this->maintenance->loadWithArgv( [
			'--type', 'references', '--do-delete-unknown-refs', '--do-import'
		] );
		$this->maintenance->execute();

		// Unknown pages were deleted, known page kept, other created
		$this->assertSelect(
			'page',
			'COUNT(*)',
			[ 'page_namespace' => NS_ZOTERO_REF ],
			[ [ $count ] ]
		);
	}

}
