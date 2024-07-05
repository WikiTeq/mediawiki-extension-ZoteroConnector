<?php

namespace MediaWiki\Extension\ZoteroConnector\HookHandlers;

use MediaWiki\Extension\ZoteroConnector\Services\AttachmentManager;
use MediaWiki\Hook\InfoActionHook;
use MediaWiki\Hook\ParserFirstCallInitHook;
use Parser;

class ParserHandler implements InfoActionHook, ParserFirstcallInitHook {

	public const PARSER_HOOK_NAME = 'ZOTERO_FILE_VERSION';
	public const NONFILE_TRACKING_CAT_NAME = 'zotero-non-file-with-version';
	public const INVALID_TRACKING_CAT_NAME = 'zotero-file-with-invalid-version';
	public const PAGE_PROP_NAME = 'zotero-file-current-version';

	private AttachmentManager $attachmentManager;

	public function __construct(
		AttachmentManager $attachmentManager
	) {
		$this->attachmentManager = $attachmentManager;
	}

	/**
	 * This hook is called when the parser initialises for the first time. See
	 * documentation in core for details.
	 *
	 * @inheritDoc
	 */
	public function onParserFirstCallInit( $parser ) {
		$parser->setFunctionHook(
			self::PARSER_HOOK_NAME,
			[ $this, 'setZoteroVersion' ]
		);
	}

	/**
	 * This hook is called to add extra information to action=info details. See
	 * documentation in core for details.
	 *
	 * @inheritDoc
	 */
	public function onInfoAction( $context, &$pageInfo ) {
		$title = $context->getTitle();
		if ( !$title->inNamespace( NS_FILE ) ) {
			// Shouldn't be set
			return;
		}
		$version = $this->attachmentManager->getAttachmentVersion( $title );
		if ( $version ) {
			$pageInfo['header-properties'][] = [
				$context->msg( 'zotero-file-current-version-info' ),
				$version
			];
		}
	}

	/**
	 * Handler for the magic word `#ZOTERO_VERSION`. The arguments here are the
	 * parser and then the arguments to the magic word, i.e. the version to set.
	 */
	public function setZoteroVersion(
		Parser $parser,
		string $version
	): string {
		$page = $parser->getPage();
		if ( $page === null ) {
			// No page?
			return '';
		}
		// Only works in the file namespace, this is for tracking file versions
		// to avoid expensive attempts to upload attachments that haven't
		// changed - if its the wrong namespace, add a tracking category for
		// cleanup but don't do anything else
		if ( $page->getNamespace() !== NS_FILE ) {
			$parser->addTrackingCategory( self::NONFILE_TRACKING_CAT_NAME );
			return '';
		}
		if ( $version === '' || !ctype_digit( $version ) ) {
			$parser->addTrackingCategory( self::INVALID_TRACKING_CAT_NAME );
			return '';
		}
		$parser->getOutput()->setPageProperty(
			self::PAGE_PROP_NAME,
			(int)$version
		);
		return '';
	}

}
