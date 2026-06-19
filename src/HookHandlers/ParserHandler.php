<?php

namespace MediaWiki\Extension\ZoteroConnector\HookHandlers;

use MediaWiki\Extension\ZoteroConnector\Services\AttachmentManager;
use MediaWiki\Hook\InfoActionHook;
use MediaWiki\Hook\ParserFirstCallInitHook;
use Parser;

class ParserHandler implements InfoActionHook, ParserFirstCallInitHook {

	public const FILE_VERSION_HOOK_NAME = 'ZOTERO_FILE_VERSION';
	public const NONFILE_TRACKING_CAT_NAME = 'zotero-non-file-with-version';
	public const INVALID_VERSION_TRACKING_CAT_NAME = 'zotero-file-with-invalid-version';
	public const FILE_VERSION_PROP_NAME = 'zotero-file-current-version';

	public const REFERENCE_TITLE_HOOK_NAME = 'ZOTERO_REFERENCE_TITLE';
	public const NONREFERENCE_TRACKING_CAT_NAME = 'zotero-non-reference-with-title';

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
			self::FILE_VERSION_HOOK_NAME,
			[ $this, 'setZoteroVersion' ]
		);
		$parser->setFunctionHook(
			self::REFERENCE_TITLE_HOOK_NAME,
			[ $this, 'setReferenceTitle' ]
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
			$parser->addTrackingCategory( self::INVALID_VERSION_TRACKING_CAT_NAME );
			return '';
		}
		$parser->getOutput()->setPageProperty(
			self::FILE_VERSION_PROP_NAME,
			$version
		);
		return '';
	}

	/**
	 * Handler for the magic word `#ZOTERO_REFERENCE_TITLE`. The arguments here
	 * are the parser and then the arguments to the magic word, i.e. the title
	 * to use.
	 */
	public function setReferenceTitle(
		Parser $parser,
		string $title
	): string {
		$page = $parser->getPage();
		if ( $page === null ) {
			// No page?
			return '';
		}
		// Only works in the reference namespace, this is for overriding the
		// display title with arbitrary data. Note that even though only the
		// extension bot should be editing the reference namespace itself,
		// transcluded templates might try and change things (and in fact, that
		// is how the title will probably be set). Thus, we will allow
		// arbitrary text (so that the title can be shown when it is unrelated
		// to the item key) EXCEPT that we escape the HTML so that a malicious
		// actor cannot use this as an attack vector
		if ( $page->getNamespace() !== NS_ZOTERO_REF ) {
			$parser->addTrackingCategory( self::NONREFERENCE_TRACKING_CAT_NAME );
			return '';
		}
		$parser->getOutput()->setDisplayTitle(
			// Default flags except no escaping quotes
			htmlspecialchars(
				$title,
				ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_HTML401
			)
		);
		return '';
	}

}
