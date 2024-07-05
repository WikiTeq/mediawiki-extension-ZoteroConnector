<?php

/* Service wiring */
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\ZoteroConnector\Services\AttachmentManager;
use MediaWiki\Extension\ZoteroConnector\Services\WikiUpdater;
use MediaWiki\Extension\ZoteroConnector\Services\ZoteroRequester;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;

return [
	'ZoteroConnector.AttachmentManager' => static function (
		MediaWikiServices $services
	): AttachmentManager {
		return new AttachmentManager(
			$services->getLinkBatchFactory(),
			$services->getPageStore(),
			$services->getPageProps(),
			$services->getService( 'ZoteroConnector.ZoteroRequester' )
		);
	},
	'ZoteroConnector.WikiUpdater' => static function (
		MediaWikiServices $services
	): WikiUpdater {
		return new WikiUpdater(
			LoggerFactory::getInstance( 'ZoteroConnector' ),
			$services->getPermissionManager(),
			$services->getTitleParser(),
			$services->getWikiPageFactory()
		);
	},
	'ZoteroConnector.ZoteroRequester' => static function (
		MediaWikiServices $services
	): ZoteroRequester {
		return new ZoteroRequester(
			new ServiceOptions(
				ZoteroRequester::CONSTRUCTOR_OPTIONS,
				$services->getMainConfig()
			),
			LoggerFactory::getInstance( 'ZoteroConnector' ),
			$services->getHttpRequestFactory()
		);
	},
];
