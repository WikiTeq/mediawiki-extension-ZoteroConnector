<?php

namespace MediaWiki\Extension\ZoteroConnector\Maintenance;

use Maintenance;
use MediaWiki\Extension\ZoteroConnector\Services\TemplateBuilder;
use MediaWiki\MediaWikiServices;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

class DebugZoteroTypes extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->requireExtension( 'ZoteroConnector' );

		$this->addDescription( 'Identify Zotero item types and an item for each' );
	}

	public function execute() {
		$services = MediaWikiServices::getInstance();
		$requester = $services->getService( 'ZoteroConnector.ZoteroRequester' );

		$this->output( "Requesting items from zotero...\n" );
		$allItems = $requester->getItems();
		$this->output( 'Found: ' . count( $allItems ) . " items\n" );

		// Sort so that we have consistent results
		usort(
			$allItems,
			static function ( $a, $b ) {
				return strcmp( $a->key, $b->key );
			}
		);

		$typeMap = [];
		$counts = [];
		foreach ( $allItems as $item ) {
			$typeMap[ $item->data->itemType ] ??= $item->key;
			$counts[ $item->data->itemType ] ??= 0;
			$counts[ $item->data->itemType ] += 1;
		}
		ksort( $typeMap );

		$maxTypeLen = 1 + max( array_map( 'strlen', array_keys( $typeMap ) ) );
		$maxValLen = 1 + max( array_map( 'strlen', array_values( $typeMap ) ) );
		$maxCountLen = 1 + max( array_map( 'strlen', array_values( $counts ) ) );
		$this->output( 'Found: ' . count( $typeMap ) . " types of items:\n" );
		foreach ( $typeMap as $type => $example ) {
			$this->output(
				'| '
				. str_pad( $type, $maxTypeLen )
				. '| used: '
				. str_pad( $counts[ $type ], $maxCountLen )
				. '| eg: '
				. str_pad( $example, $maxValLen )
				. '| '
				. ( TemplateBuilder::CITATION_TEMPLATES[ $type ] ?? '**FALLBACK**' )
				. "\n"
			);
		}
	}

}

$maintClass = DebugZoteroTypes::class;
require_once RUN_MAINTENANCE_IF_MAIN;
