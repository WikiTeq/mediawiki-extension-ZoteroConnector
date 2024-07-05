<?php

namespace MediaWiki\Extension\ZoteroConnector\Tests\Unit\Services;

use DirectoryIterator;
use FormatJson;
use MediaWiki\Extension\ZoteroConnector\Services\TemplateBuilder;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\ZoteroConnector\Services\TemplateBuilder
 */
class TemplateBuilderTest extends MediaWikiUnitTestCase {

	/** @dataProvider provideTestCases */
	public function testSourceTemplate( string $testFile, string $expectFile ) {
		$testData = file_get_contents( $testFile );
		$status = FormatJson::parse( $testData );
		$this->assertStatusGood( $status );
		$testDataJson = $status->getValue();

		$expectedTemplates = file_get_contents( $expectFile );
		$actualTemplates = TemplateBuilder::getSource( $testDataJson );

		// Trim to avoid any potential issues with newlines
		$this->assertSame(
			trim( $expectedTemplates ),
			trim( $actualTemplates )
		);
	}

	public function provideTestCases() {
		$dirIter = new DirectoryIterator( __DIR__ . '/../../data' );
		foreach ( $dirIter as $file ) {
			if ( $file->isDot() || $file->getExtension() !== 'json' ) {
				continue;
			}
			// JSON data are .json, the expected results are .txt
			$testFile = $file->getPath() . "/" . $file->getFilename();
			$expectFile = substr( $testFile, 0, -4 ) . 'txt';

			yield $file->getFilename() => [ $testFile, $expectFile ];
		}
	}
}
