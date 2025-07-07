<?php

namespace MediaWiki\Extension\ZoteroConnector\Tests\Integration\Maintenance;

use Exception;
use FilesystemIterator;
use GlobIterator;
use MediaWiki\Extension\ZoteroConnector\Maintenance\ImportZoteroData;
use MediaWiki\Extension\ZoteroConnector\Services\TemplateBuilder;
use MediaWiki\Extension\ZoteroConnector\Services\ZoteroRequester;
use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;
use Title;
use WikitextContent;

/**
 * @covers \MediaWiki\Extension\ZoteroConnector\Maintenance\ImportZoteroData
 * @group database
 */
class ImportZoteroDataTest extends MaintenanceBaseTestCase {

	protected function getMaintenanceClass(): string {
		return ImportZoteroData::class;
	}

	public function testValidateType() {
		$this->expectException( Exception::class );
		$this->expectExceptionMessage(
			'FATAL ERROR: Invalid value for `type`: should be `references`, '
			. '`attachments`,  or `both`, got: invalid (exit code = 1)'
		);
		$this->maintenance->loadWithArgv( [ '--type', 'invalid' ] );
		$this->maintenance->execute();
	}

	public function testValidateFrom() {
		$this->expectException( Exception::class );
		$this->expectExceptionMessage(
			'FATAL ERROR: --from can only be used when specifying --type as '
			. 'either `references` or `attachments`'
		);
		$this->maintenance->loadWithArgv( [ '--type', 'both', '--from', 'foo' ] );
		$this->maintenance->execute();
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
		$expectRegex = "ImportZoteroData: mode=DRY-RUN type=references\n";
		$expectRegex .= "Found: $count references\n";
		$expectRegex .= "After processing: $count references, and ";
		$expectRegex .= "\d+ attachments\nIgnoring the attachments\n";
		$expectRegex .= "(References +\d+\/$count \( *\d+%\): \S+ \.\.\.DRY RUN\n)";
		$expectRegex .= '{' . $count . '}';
		$expectRegex .= "References summary:\n0 updated\n0 unchanged\n0 errors\n";
		$expectRegex .= "Attachment summary:\n0 uploaded\n";
		$expectRegex .= "0 pages updated without file changes\n0 unchanged\n0 errors\n";
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
		$expectRegex = "Manual list: " . implode( ',', $itemIds ) . "\n";
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
		$expectRegex = "ImportZoteroData: mode=DO-IMPORT type=references\n";
		$expectRegex .= "Found: $count references\n";
		$expectRegex .= "After processing: $count references, and ";
		$expectRegex .= "\d+ attachments\nIgnoring the attachments\n";
		$expectRegex .= "(References +\d+\/$count \( *\d+%\): \S+ \.\.\.updated\n)";
		$expectRegex .= '{' . $count . '}';
		$expectRegex .= "References summary:\n$count updated\n0 unchanged\n0 errors\n";
		$expectRegex .= "Attachment summary:\n0 uploaded\n";
		$expectRegex .= "0 pages updated without file changes\n0 unchanged\n0 errors\n";
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
			->willReturn( [ 'parentItem' => 'foo', 'version' => 'bar' ] );
		$requester->expects( $this->exactly( $attachmentCount ) )
			->method( 'getAttachmentLocation' )
			->willReturn( 'MOCK' );
		$this->setService( 'ZoteroConnector.ZoteroRequester', $requester );

		$count = count( $allItems );
		$expectRegex = "ImportZoteroData: mode=DRY-RUN type=attachments\n";
		$expectRegex .= "Found: $count references\n";
		$expectRegex .= "After processing: $count references, and ";
		$expectRegex .= "$attachmentCount attachments\nIgnoring the references\n";
		$expectRegex .= "(Attachments +\d+\/$attachmentCount \( *\d+%\): \S+ \.\.\.found redirect, DRY RUN\n)";
		$expectRegex .= '{' . $attachmentCount . '}';
		$expectRegex .= "References summary:\n0 updated\n0 unchanged\n0 errors\n";
		$expectRegex .= "Attachment summary:\n0 uploaded\n";
		$expectRegex .= "0 pages updated without file changes\n0 unchanged\n0 errors\n";
		$expectRegex .= "Done\n";

		$this->expectOutputRegex( "/$expectRegex/" );
		$this->maintenance->loadWithArgv( [ '--type', 'attachments' ] );
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
			->willReturn( [ 'parentItem' => 'foo', 'version' => 'bar' ] );
		$requester->expects( $this->exactly( $processCount ) )
			->method( 'getAttachmentLocation' )
			->willReturn( 'MOCK' );
		$this->setService( 'ZoteroConnector.ZoteroRequester', $requester );

		$count = count( $allItems );
		$expectRegex = "ImportZoteroData: mode=DRY-RUN type=attachments\n";
		$expectRegex .= "Found: $count references\n";
		$expectRegex .= "After processing: $count references, and ";
		$expectRegex .= "$attachmentCount attachments\nIgnoring the references\n";
		$expectRegex .= "Excluding existing files: $processCount attachments left\n";
		$expectRegex .= "(Attachments +\d+\/$processCount \( *\d+%\): \S+ \.\.\.found redirect, DRY RUN\n)";
		$expectRegex .= '{' . $processCount . '}';
		$expectRegex .= "References summary:\n0 updated\n0 unchanged\n0 errors\n";
		$expectRegex .= "Attachment summary:\n0 uploaded\n";
		$expectRegex .= "0 pages updated without file changes\n0 unchanged\n0 errors\n";
		$expectRegex .= "Done\n";

		$this->expectOutputRegex( "/$expectRegex/" );
		$this->maintenance->loadWithArgv( [
			'--type',
			'attachments',
			'--no-reupload',
		] );
		$this->maintenance->execute();
	}

}
