<?php

namespace MediaWiki\Extension\ZoteroConnector\Services;

use MediaWiki\Cache\LinkBatchFactory;
use MediaWiki\Extension\ZoteroConnector\HookHandlers\ParserHandler;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Page\PageLookup;
use PageProps;

/**
 * Class to keep track of versions of attachments, wrapping around the PageProps
 * and PageLookup services
 */
class AttachmentManager {

	private LinkBatchFactory $linkBatchFactory;
	private PageLookup $pageLookup;
	private PageProps $pageProps;
	private ZoteroRequester $zoteroRequester;

	/**
	 * Keys are attachment ids (with .pdf), values are page props versions as
	 * STRINGS, or null if not set
	 */
	private array $versionCache = [];

	public function __construct(
		LinkBatchFactory $linkBatchFactory,
		PageLookup $pageLookup,
		PageProps $pageProps,
		ZoteroRequester $zoteroRequester
	) {
		$this->linkBatchFactory = $linkBatchFactory;
		$this->pageLookup = $pageLookup;
		$this->pageProps = $pageProps;
		$this->zoteroRequester = $zoteroRequester;
	}

	/**
	 * Load the version property into a cache so we don't need an individual
	 * query for each attachment, for use when mass-importing. Not just relying
	 * on the PageProps service's cache since we want to be able to check the
	 * version based on attachment id (page name), not page id, to avoid that
	 * lookup too.
	 *
	 * @param string[] $attachmentIds
	 */
	public function preloadAttachmentVersions( array $attachmentIds ) {
		// Use a link batch to look up the page identities together
		$batch = $this->linkBatchFactory->newLinkBatch();
		foreach ( $attachmentIds as $id ) {
			$batch->add( NS_FILE, $id . '.pdf' );
		}
		$batch->setCaller( __METHOD__ );

		$pages = $batch->getPageIdentities();
		$this->pageProps->ensureCacheSize( count( $pages ) );
		$props = $this->pageProps->getProperties( $pages, ParserHandler::FILE_VERSION_PROP_NAME );

		// properties get indexed by page id, not attachment id, in the result
		// $props, but we want to cache by attachment id (with .pdf to simplify)
		foreach ( $pages as $page ) {
			// fallback to null for pages that have no current version; when
			// using the cache be sure to use isset() rather than array_key_exists()
			$this->versionCache[ $page->getDBkey() ] = $props[ $page->getId() ] ?? null;
		}
	}

	/**
	 * For a given page (assumed to be in NS_FILE), try and determine the
	 * version currently uploaded to the wiki - returns null if not uploaded or
	 * uploaded but not set
	 */
	public function getAttachmentVersion( PageIdentity $file ): ?string {
		if ( isset( $this->versionCache[ $file->getDBkey() ] ) ) {
			return $this->versionCache[ $file->getDBkey() ];
		}
		$props = $this->pageProps->getProperties(
			$file,
			ParserHandler::FILE_VERSION_PROP_NAME
		);
		$result = $props[ $file->getId() ] ?? null;
		$this->versionCache[ $file->getDBkey() ] = $result;
		return $result;
	}

	/**
	 * For a given attachment ID, determine if we already have the latest data,
	 * or need to upload a new version. Return an associative array with the
	 * - 'location': `false` if no update is needed, `null` if an update is
	 *   needed but the location could not be found, or the string location
	 * - 'pageContent': content the page should have, which should include a
	 *   direct or indirect setting of the version via the parser function
	 */
	public function getUploadData( string $itemId ): array {
		$fileName = $itemId . '.pdf';
		if ( isset( $this->versionCache[ $fileName ] ) ) {
			$currVersion = $this->versionCache[ $fileName ];
		} else {
			$page = $this->pageLookup->getPageByName( NS_FILE, $fileName );
			$currVersion = ( $page === null ? null : $this->getAttachmentVersion( $page ) );
		}

		$latestInfo = $this->zoteroRequester->getAttachmentInfo( $itemId );

		$template = new FluentTemplate( 'ZoteroFile' );
		$template->setParam( 'parentItem', $latestInfo['parentItem'] );
		$template->setParam( 'version', $latestInfo['version'] );
		$pageContent = $template->getWikitext();

		if ( $latestInfo['makePublic'] ) {
			$pageContent .= "\n__MAKE_FILE_PUBLIC__";
		}

		if ( $latestInfo['version'] === $currVersion ) {
			// No need to fetch a new version
			$location = false;
		} else {
			$location = $this->zoteroRequester->getAttachmentLocation( $itemId );
		}

		return [
			'pageContent' => $pageContent,
			'location' => $location,
		];
	}

}
