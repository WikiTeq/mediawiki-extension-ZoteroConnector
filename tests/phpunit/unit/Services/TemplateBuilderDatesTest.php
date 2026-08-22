<?php

namespace MediaWiki\Extension\ZoteroConnector\Tests\Unit\Services;

use FormatJson;
use MediaWiki\Extension\ZoteroConnector\Services\TemplateBuilder;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\ZoteroConnector\Services\TemplateBuilder::getSource
 */
class TemplateBuilderDatesTest extends MediaWikiUnitTestCase {

	private function getSourceForBillDate( string $date ): string {
		// Minimal bill-type item; bills are the only type that gets
		// DateTimeImmutable-based date reformatting (for {{Cite act}})
		$item = (object)[
			'key' => 'TESTBILL',
			'data' => (object)[
				'key' => 'TESTBILL',
				'version' => 1,
				'itemType' => 'bill',
				'title' => 'A bill',
				'creators' => [],
				'tags' => [],
				'date' => $date,
			],
		];
		return TemplateBuilder::getSource( $item );
	}

	private function getBillDateParam( string $date ): ?string {
		$source = $this->getSourceForBillDate( $date );
		return preg_match( '/^\|date = (.*)$/m', $source, $m ) ? $m[1] : null;
	}

	public static function provideBillDates() {
		yield 'Full ISO date is reformatted' => [
			'2023-09-11',
			'11 September 2023'
		];
		yield 'Year-month stays normalized month name' => [
			'2023-09',
			'September 2023'
		];
		yield 'Bare year becomes first of January' => [
			'1996',
			'1 January 1996'
		];
		yield 'ISO timestamp with timezone is reformatted' => [
			'2023-08-22T10:00:00Z',
			'22 August 2023'
		];
	}

	/** @dataProvider provideBillDates */
	public function testValidBillDatesAreReformatted( string $date, string $expected ) {
		$this->assertSame(
			$expected,
			$this->getBillDateParam( $date )
		);
	}

	public static function provideUnparseableBillDates() {
		// Zotero allows free-form dates; none of these may throw or be
		// silently reinterpreted (e.g. `n.d.` parses like "next day")
		yield 'No date marker' => [ 'n.d.' ];
		yield 'No date marker without dots' => [ 'ND' ];
		yield 'Approximate date' => [ 'circa 1950' ];
		yield 'Season' => [ 'Fall 2023' ];
		yield 'Century' => [ '19th century' ];
		yield 'Trailing garbage after valid prefix' => [ '2023-09-11junk' ];
		yield 'Invalid month' => [ '2023-13' ];
		yield 'Empty-ish text' => [ 'unknown' ];
	}

	/** @dataProvider provideUnparseableBillDates */
	public function testUnparseableBillDatesAreKeptAsIs( string $date ) {
		$this->assertSame( $date, $this->getBillDateParam( $date ) );
	}

	public function testUnparseableBillDateDoesNotThrow() {
		$source = $this->getSourceForBillDate( 'n.d.' );
		$this->assertStringContainsString( '|date = n.d.', $source );
	}

	public function testSourceTemplateRegressionStillPasses() {
		$data = file_get_contents(
			dirname( __DIR__, 2 ) . '/data/regression MOCK123.json'
		);
		$status = FormatJson::parse( $data );
		$this->assertStatusGood( $status );
		// Just make sure a non-bill item with a normal date still works
		$source = TemplateBuilder::getSource( $status->getValue() );
		$this->assertStringContainsString( '|date = 2026-03-04', $source );
	}

}
