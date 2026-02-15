<?php

namespace MediaWiki\Extension\ZoteroConnector\Services;

use FormatJson;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\ZoteroConnector\ZoteroNotFoundException;
use MediaWiki\Http\HttpRequestFactory;
use MWHttpRequest;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;
use UnexpectedValueException;

/**
 * Service to manage making our requests
 */
class ZoteroRequester {

	public const CONSTRUCTOR_OPTIONS = [
		'ZoteroConnectorAPIKey',
	];

	private const MODE_ATTACHMENTS = 'attachment';
	private const MODE_NO_ATTACHMENTS = '-attachment';

	private ServiceOptions $options;
	private LoggerInterface $logger;
	private HttpRequestFactory $httpFactory;

	/**
	 * Cache for batch updating of attachments, avoid an extra HTTP request to
	 * get the data. Keys are the item ids, values are the result of
	 * simplifyItem()
	 */
	private array $attachmentCache = [];

	public function __construct(
		ServiceOptions $options,
		LoggerInterface $logger,
		HttpRequestFactory $httpFactory
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->options = $options;
		$this->logger = $logger;
		$this->httpFactory = $httpFactory;
	}

	/**
	 * Create and run an authenticated request to $url and return the request
	 */
	private function runAuthRequest( string $url, string $caller ): MWHttpRequest {
		$req = $this->httpFactory->create( $url, [], $caller );
		$req->setHeader(
			'Zotero-API-Key',
			$this->options->get( 'ZoteroConnectorAPIKey' )
		);
		$req->execute();
		return $req;
	}

	public function preloadAttachmentData() {
		if ( count( $this->attachmentCache ) !== 0 ) {
			// Attachment imports are being run a second time to retry some
			// failures, the data here won't change though
			return;
		}
		$totalCount = 0;
		$results = $this->getItemsWithOffset( 0, self::MODE_ATTACHMENTS, $totalCount );
		while ( count( $results ) < $totalCount ) {
			$results = array_merge(
				$results,
				$this->getItemsWithOffset( count( $results ), self::MODE_ATTACHMENTS )
			);
		}
		foreach ( $results as $singleAttachment ) {
			if ( isset( $singleAttachment->key ) ) {
				$this->attachmentCache[ $singleAttachment->key ] = $singleAttachment;
			}
		}
	}

	/**
	 * For a given item ID that should be for an attachment, return an
	 * associative array with the current version number of the attachment and
	 * what item uses it
	 */
	public function getAttachmentInfo( string $itemId ): array {
		if ( isset( $this->attachmentCache[ $itemId ] ) ) {
			$item = $this->attachmentCache[ $itemId ];
		} else {
			$item = $this->getSingleItem( $itemId );
		}
		if ( !isset( $item->data )
			|| !isset( $item->data->itemType )
			|| $item->data->itemType !== 'attachment'
		) {
			$this->logger->debug(
				__METHOD__ . ': invalid attachment {item}: {raw}',
				[
					'item' => $itemId,
					'raw' => FormatJson::encode( $item ),
				]
			);
			throw new RuntimeException( __METHOD__ . " got bad JSON response" );
		}
		$data = $item->data;
		return [
			'version' => (string)( $data->version ?? '' ),
			'parentItem' => $data->parentItem ?? '',
		];
	}

	public function getAttachmentLocation( string $itemId ): ?string {
		// First HTTP request: attachment page, will report location of the
		// actual PDF - thankfully, that location URL is then publicly
		// accessible
		$req = $this->runAuthRequest(
			"https://api.zotero.org/groups/4511960/items/$itemId/file/view",
			__METHOD__
		);
		return $req->getResponseHeader( 'Location' );
	}

	private static function simplifyItem( stdClass &$item ): void {
		// Remove some unneeded details; don't unset if not already set so that
		// tests are simpler
		unset( $item->library );
		if ( isset( $item->links ) ) {
			unset( $item->links->self );
			unset( $item->links->alternate );
		}
		if ( isset( $item->meta ) ) {
			unset( $item->meta->createdByUser );
		}
		// if ( isset( $item->data ) ) {
		// 	foreach ( (array)( $item->data ) as $k => $v ) {
		// 		if ( $v === '' ) {
		// 			unset( $item->data->$k );
		// 		}
		// 	}
		// }
	}

	private function getItemsWithOffset(
		int $offset,
		// one of MODE_ATTACHMENTS or MODE_NO_ATTACHMENTS
		string $mode,
		?int &$totalCount = null
	): array {
		// Data about what items have attachments and what those keys are gets
		// extracted from the items via TemplateBuilder::getAttachment(), no
		// need to query here
		$reqUrl = "https://api.zotero.org/groups/4511960/items?itemType=$mode&limit=100";
		if ( $offset !== 0 ) {
			$reqUrl .= '&start=' . $offset;
		}
		$req = $this->runAuthRequest( $reqUrl, __METHOD__ );
		$content = $req->getContent();
		$status = FormatJson::parse( $content );
		$asJson = $status->getValue();

		if ( !is_array( $asJson ) ) {
			// API returned bad JSON?
			$this->logger->debug(
				__METHOD__ . ': non-array JSON when processing offset {offset} ({raw}): {status}',
				[
					'status' => $status->__toString(),
					'raw' => $content,
					'offset' => $offset,
				]
			);
			throw new UnexpectedValueException( "Not an array" );
		}
		foreach ( $asJson as &$item ) {
			self::simplifyItem( $item );
		}

		if ( $totalCount !== null ) {
			// Store the total count to allow for looping
			$totalCount = (int)( $req->getResponseHeader( 'Total-Results' ) ?? 0 );
		}
		return $asJson;
	}

	public function getItems(): array {
		$totalCount = 0;
		$results = $this->getItemsWithOffset( 0, self::MODE_NO_ATTACHMENTS, $totalCount );
		// TESTING: don't fetch everything
		while ( count( $results ) < $totalCount ) {
			$results = array_merge(
				$results,
				$this->getItemsWithOffset( count( $results ), self::MODE_NO_ATTACHMENTS )
			);
		}
		// Exclude items with type `note` for now
		// Also exclude `annotation`s, these are metadata for attachments and
		// not actual references
		$results = array_filter(
			$results,
			static function ( $r ) {
				$type = ( $r->data->itemType ?? '' );
				return $type !== 'note' && $type !== 'annotation';
			}
		);
		return $results;
	}

	public function getSingleItem( string $itemId ): stdClass {
		// The ID `top` means something special for Zotero; don't break
		// in the request processing
		if ( $itemId === 'top' ) {
			throw new ZoteroNotFoundException( $itemId );
		}
		$req = $this->runAuthRequest(
			'https://api.zotero.org/groups/4511960/items/' . $itemId,
			__METHOD__
		);
		$content = $req->getContent();

		if ( $content === 'Not found'
			|| $content === 'Item does not exist'
			|| $req->getStatus() === 400
		) {
			throw new ZoteroNotFoundException( $itemId );
		}
		$status = FormatJson::parse( $content );

		if ( !$status->isGood() ) {
			// API returned bad JSON?
			$this->logger->debug(
				__METHOD__ . ': invalid JSON when processing {item} ({raw}): {status}',
				[
					'item' => $itemId,
					'status' => $status->__toString(),
					'raw' => $content,
				]
			);
			throw new RuntimeException( __METHOD__ . " got bad JSON response" );
		}
		$asJson = $status->getValue();

		self::simplifyItem( $asJson );
		return $asJson;
	}
}
