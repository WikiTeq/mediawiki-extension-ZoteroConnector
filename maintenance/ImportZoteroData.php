<?php

namespace MediaWiki\Extension\ZoteroConnector\Maintenance;

use Exception;
use Maintenance;
use MediaWiki\Extension\ZoteroConnector\HookHandlers\CommentHandler;
use MediaWiki\Extension\ZoteroConnector\Services\AttachmentManager;
use MediaWiki\Extension\ZoteroConnector\Services\TemplateBuilder;
use MediaWiki\Extension\ZoteroConnector\Services\WikiUpdater;
use MediaWiki\Extension\ZoteroConnector\Services\ZoteroRequester;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\DeletePageFactory;
use MediaWiki\Page\WikiPageFactory;
use MessageSpecifier;
use Status;
use stdClass;
use User;
use WikiPage;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

class ImportZoteroData extends Maintenance {

	private AttachmentManager $manager;
	private DeletePageFactory $deletePageFactory;
	private WikiPageFactory $wikiPageFactory;
	private WikiUpdater $updater;
	private ZoteroRequester $requester;

	public function __construct() {
		parent::__construct();
		$this->requireExtension( 'ZoteroConnector' );

		$this->addDescription( 'Import Zotero data to the wiki' );

		$this->addOption(
			'item-list',
			'Comma-separated of items to import (their attachments will be imported too)',
			false,
			true
		);

		$this->addOption(
			'type',
			'One of `references`, `attachments`, or `both` for what to create',
			false,
			true
		);
		$this->addOption(
			'from',
			'Key to start with, only valid if `type` is not `both`',
			false,
			true
		);
		$this->addOption(
			'no-reupload',
			'Avoid uploading attachments if there is already a file by that name'
		);
		// Used so that we can skip updates in the common case of the generated
		// content not changing
		$this->addOption(
			'do-attachment-page-update',
			'Avoid updating attachments pages if no upload is performed'
		);

		$this->addOption(
			'do-import',
			'Really do the import (default: dry run)'
		);

		// Cannot be used with
		// * --from
		// * --type=attachments
		// * --item-list
		$this->addOption(
			'do-delete-unknown-refs',
			'Delete any pages in the Zotero Reference namespace that do not '
				. 'correspond to entries in Zotero (default: report)'
		);
	}

	private function initServices() {
		$services = MediaWikiServices::getInstance();
		$this->manager = $services->getService( 'ZoteroConnector.AttachmentManager' );
		$this->deletePageFactory = $services->getDeletePageFactory();
		$this->wikiPageFactory = $services->getWikiPageFactory();
		$this->updater = $services->getService( 'ZoteroConnector.WikiUpdater' );
		$this->requester = $services->getService( 'ZoteroConnector.ZoteroRequester' );
	}

	public function execute() {
		$this->initServices();
		$type = $this->getOption( 'type', 'both' );
		if ( !in_array( $type, [ 'references', 'attachments', 'both' ], true ) ) {
			$this->fatalError(
				'Invalid value for `type`: should be `references`, `attachments`,' .
				' or `both`, got: ' . $type
			);
		}
		$from = $this->getOption( 'from', null );
		if ( $type === 'both' && $from !== null ) {
			$this->fatalError(
				'--from can only be used when specifying --type as either ' .
				'`references` or `attachments`'
			);
		}
		$deleteUnknown = $this->hasOption( 'do-delete-unknown-refs' );
		if ( $deleteUnknown ) {
			if ( $from !== null ) {
				$this->fatalError(
					'--delete-unknown-refs cannot be used with --from'
				);
			}
			if ( $type === 'attachments' ) {
				$this->fatalError(
					'--delete-unknown-refs cannot be used with --type=attachments'
				);
			}
		}
		$dryRun = !$this->hasOption( 'do-import' );

		$mode = $dryRun ? 'DRY-RUN' : 'DO-IMPORT';
		if ( !$this->hasOption( 'item-list' ) ) {
			// Won't even be printed when there is an item-list since we don't
			// fetch everything
			$mode .= $deleteUnknown ? ', DELETE-UNKNOWN' : ', PRINT-UNKNOWN';
		}
		$this->output( "ImportZoteroData: mode=$mode type=$type\n" );

		if ( $this->hasOption( 'item-list' ) ) {
			if ( $deleteUnknown ) {
				$this->fatalError(
					'--delete-unknown-refs cannot be used with --item-list'
				);
			}
			$itemList = $this->getOption( 'item-list' );
			$this->output( "Manual list: $itemList\n" );
			$itemIds = explode( ',', $itemList );
			$allItems = array_map(
				function ( $itemId ) {
					return $this->requester->getSingleItem( $itemId );
				},
				$itemIds
			);
		} else {
			$allItems = $this->requester->getItems();

			$this->output( 'Found: ' . count( $allItems ) . " references\n" );
		}

		// Sort so that we can specify where to start from
		usort(
			$allItems,
			static function ( $a, $b ) {
				return strcmp( $a->key, $b->key );
			}
		);

		[ $referencePages, $attachmentIds ] = $this->extractItems( $allItems );
		$this->output(
			'After processing: ' . count( $referencePages ) . ' references, and ' .
			count( $attachmentIds ) . " attachments\n"
		);
		
		$allKnownReferences = array_keys( $referencePages );

		$this->filterReferences( $referencePages );
		$this->filterAttachments( $attachmentIds );

		$this->doAllImports( $referencePages, $attachmentIds );

		if ( !$this->hasOption( 'item-list' ) ) {
			// Either delete or just print all unknown references
			$this->handleUnknownReferences( $allKnownReferences, $deleteUnknown );
		}

		$this->output( "Done\n" );
	}

	/**
	 * Given an array with the JSON responses for non-attachment items, identify
	 * the keys of the attachments and return
	 *   - one array is an associative array, with keys as item IDs and values
	 *     as wikitext that should be used for their pages
	 *   - the second array contains just the IDs for the attachments
	 */
	private function extractItems( array $allItems ): array {
		$referencePages = [];
		$attachmentIds = [];

		foreach ( $allItems as $item ) {
			// Might be an attachment if using --item-list
			if ( ( $item->data->itemType ?? null ) === 'attachment' ) {
				$attachmentIds[] = $item->key;
				continue;
			}
			$referencePages[ $item->key ] = TemplateBuilder::getSource(
				$item
			);
			$attachment = TemplateBuilder::getAttachment( $item );
			if ( $attachment ) {
				$attachmentIds[] = $attachment;
			}
		}
		usort( $attachmentIds, "strcmp" );
		return [ $referencePages, $attachmentIds ];
	}

	/**
	 * Potentially reduce the set of references pages that gets imported
	 */
	private function filterReferences( array &$referencePages ) {
		if ( $this->getOption( 'type' ) === 'attachments' ) {
			$this->output( "Ignoring the references\n" );
			$referencePages = [];
			return;
		}
		if ( $this->getOption( 'type' ) === 'both' ) {
			// Cannot use --from
			return;
		}
		// Must be --type=references
		$from = $this->getOption( 'from' );
		if ( $from !== null ) {
			$this->output( "Starting from: $from: " );
			$referencePages = array_filter(
				$referencePages,
				static function ( $key ) use ( $from ) {
					return strcmp( $key, $from ) >= 0;
				},
				ARRAY_FILTER_USE_KEY
			);
			$count = count( $referencePages );
			$this->output( "$count references left\n" );
		}
	}

	/**
	 * Potentially reduce the set of attachment files that gets imported
	 */
	private function filterAttachments( array &$attachmentIds ) {
		if ( $this->getOption( 'type' ) === 'references' ) {
			$this->output( "Ignoring the attachments\n" );
			$attachmentIds = [];
			return;
		}

		if ( $this->getOption( 'type' ) === 'attachments'
			&& $this->getOption( 'from' ) !== null
		) {
			$from = $this->getOption( 'from' );
			$this->output( "Starting from: $from: " );
			$attachmentIds = array_filter(
				$attachmentIds,
				static function ( $key ) use ( $from ) {
					return strcmp( $key, $from ) >= 0;
				}
			);
			$attachmentIds = array_values( $attachmentIds );
			$count = count( $attachmentIds );
			$this->output( "$count attachments left\n" );
		}

		if ( !$this->hasOption( 'no-reupload' ) ) {
			return;
		}

		$this->output( 'Excluding existing files: ' );
		$attachmentFiles = array_map(
			static fn ( $f ) => ucfirst( $f ) . ".pdf",
			$attachmentIds
		);

		// Just use a DB query, its simpler than individually checking the
		// different titles. Select all those that exist, and then exclude
		// those
		$existingFiles = $this->getDb( DB_REPLICA )->newSelectQueryBuilder()
			->select( 'page_title' )
			->from( 'page' )
			->where( [
				'page_namespace' => NS_FILE,
				'page_title' => $attachmentFiles,
			] )
			->caller( __METHOD__ )
			->fetchFieldValues();
		$existingFiles = array_flip( $existingFiles );
		$attachmentIds = array_filter(
			$attachmentIds,
			static function ( $id ) use ( $existingFiles ) {
				return !array_key_exists( ucfirst( $id ) . '.pdf', $existingFiles );
			}
		);
		$attachmentIds = array_values( $attachmentIds );
		$count = count( $attachmentIds );
		$this->output( "$count attachments left\n" );
	}

	/**
	 * Actually do all of the imports for reference pages
	 *
	 * @param array<string,string> $referencePages
	 * @param string[] $attachmentIds
	 */
	private function doAllImports( array $referencePages, array $attachmentIds ) {
		// Use a single scope the entire time to simplify things
		[ $sysUser, $scope ] = $this->updater->makeRequestScope(
			[ 'autopatrol', 'upload_by_url' ]
		);
		$referenceSummary = $this->importReferences( $referencePages, $sysUser );
		$uploadSummary = $this->importAttachments( $attachmentIds, $sysUser );

		$this->output( "References summary:\n" );
		$this->output( $referenceSummary['updated'] . " updated\n" );
		$this->output( $referenceSummary['no-change'] . " unchanged\n" );
		$this->output( count( $referenceSummary['errors'] ) . " errors\n" );
		if ( $referenceSummary['errors'] ) {
			$this->output(
				implode( ', ', $referenceSummary['errors'] ) . "\n"
			);
		}

		$this->output( "Attachment summary:\n" );
		$this->output( $uploadSummary['uploaded'] . " uploaded\n" );
		$this->output( $uploadSummary['page-updated'] . " pages updated without file changes\n" );
		$this->output( $uploadSummary['no-change'] . " unchanged\n" );
		$this->output( count( $uploadSummary['errors'] ) . " errors\n" );
		if ( $uploadSummary['errors'] ) {
			foreach ( $uploadSummary['errors'] as $id => $error ) {
				$this->output( "$id: $error\n" );
			}
		}
	}

	/**
	 * Actually do all of the imports for reference pages
	 *
	 * @param array<string,string> $referencePages
	 * @param User $sysUser
	 * @return array with summary details
	 */
	private function importReferences(
		array $referencePages,
		User $sysUser
	): array {
		$dryRun = !$this->hasOption( 'do-import' );
		$summary = [ 'no-change' => 0, 'updated' => 0, 'errors' => [] ];
		$itemCount = 0;
		$totalCount = count( $referencePages );
		foreach ( $referencePages as $itemId => $pageText ) {
			$itemCount++;
			$this->logProgress( 'References', $itemCount, $totalCount, $itemId );
			if ( $dryRun ) {
				$this->output( "DRY RUN\n" );
				continue;
			}
			$status = $this->updater->writeReferencePage(
				$itemId,
				$pageText,
				$sysUser
			);
			if ( $status->hasMessage( 'edit-no-change' ) ) {
				$this->output( "no change\n" );
				$summary['no-change']++;
			} elseif ( $status->isGood() ) {
				$summary['updated']++;
				$this->output( "updated\n" );
			} else {
				// Clean up unterminated line
				$this->output( "\n" );
				$this->error( "$itemId - failed to update page:" );
				$this->error( $status->__toString() );
				$summary['errors'][] = $itemId;
			}
		}
		return $summary;
	}

	/**
	 * Actually do all of the imports for attachments
	 *
	 * @param string[] $attachmentIds
	 * @param User $sysUser
	 * @return array with summary details
	 */
	private function importAttachments(
		array $attachmentIds,
		User $sysUser
	): array {
		if ( $attachmentIds === [] ) {
			// Nothing to do, don't break on core's bad typehint
			return [ 'no-change' => 0, 'uploaded' => 0, 'page-updated' => 0, 'errors' => [] ];
		}
		$this->manager->preloadAttachmentVersions( $attachmentIds );
		$this->requester->preloadAttachmentData();

		$dryRun = !$this->hasOption( 'do-import' );
		$updateContent = $this->hasOption( 'do-attachment-page-update' );
		$summary = [ 'no-change' => 0, 'uploaded' => 0, 'page-updated' => 0, 'errors' => [] ];
		$itemCount = 0;
		$totalCount = count( $attachmentIds );
		foreach ( $attachmentIds as $attachment ) {
			$itemCount++;
			$this->logProgress( 'Attachments', $itemCount, $totalCount, $attachment );
			$uploadData = $this->manager->getUploadData( $attachment );
			if ( $uploadData['location'] === null ) {
				// Clean up unterminated line
				$this->output( "\n" );
				$this->error( "$attachment - got null redirect" );
				$summary['errors'][$attachment] = 'null-redirect';
				continue;
			}
			if ( $dryRun ) {
				$this->output( "found redirect, DRY RUN\n" );
				continue;
			}
			// We might only need to update the page content
			if ( $uploadData['location'] === false ) {
				if ( $updateContent ) {
					$status = $this->updater->updateFilePage(
						$attachment,
						$uploadData['pageContent'],
						$sysUser
					);
				} else {
					// so that we can reuse the existing logic below
					$status = Status::newGood( 'zoteroconnector-upload-attachment-no-change' );
				}
			} else {
				$status = $this->updater->importPDFAttachment(
					$attachment,
					$uploadData['location'],
					$uploadData['pageContent'],
					$sysUser
				);
			}
			if ( $status->isGood() ) {
				if ( $status->getValue() === 'zoteroconnector-upload-attachment-no-change' ) {
					$this->output( "no change\n" );
					$summary['no-change']++;
				} elseif ( $status->getValue() === 'zoteroconnector-attachment-page-updated' ) {
					$this->output( "page updated, no file change\n" );
					$summary['page-updated']++;
				} else {
					$this->output( "uploaded\n" );
					$summary['uploaded']++;
				}
			} else {
				// Clean up unterminated line
				$this->output( "\n" );
				if ( $status->hasMessage( 'http-timed-out' ) ) {
					$this->error( "$attachment - http timed out" );
				} else {
					$this->error( "$attachment - failed to upload:" );
					$this->error( $status->__toString() );
				}
				$summary['errors'][$attachment] = implode(
					',',
					array_map(
						static function ( $err ) {
							if ( $err['message'] instanceof MessageSpecifier ) {
								return $err['message']->getKey();
							}
							return $err['message'];
						},
						$status->getErrors()
					)
				);
			}
		}
		return $summary;
	}

	/**
	 * Given the list of known references, identify all pages in the zotero
	 * reference namespace that are unknown and then either delete them or
	 * just report about them
	 *
	 * @param string[] $knownReferences array of keys
	 */
	private function handleUnknownReferences(
		array $knownReferences,
		bool $deleteUnknown
	) {
		// Titles start with a capital letter
		$knownReferences = array_map(
			'ucfirst',
			$knownReferences
		);
		$db = $this->getDb( DB_REPLICA );
		$queryInfo = WikiPage::getQueryInfo();
		$unknown = $db->newSelectQueryBuilder()
			->select( $queryInfo['fields'] )
			->from( 'page' )
			->where( [
				'page_namespace' => NS_ZOTERO_REF,
				'page_title NOT IN (' . $db->makeList( $knownReferences ) . ')'
			] )
			->caller( __METHOD__ )
			->fetchResultSet();
		$unknownCount = count( $unknown );
		if ( $unknownCount === 0 ) {
			$this->output( "No unknown reference pages!\n" );
			return;
		}
		$this->output( "There are $unknownCount unknown reference pages: " );
		$pages = [];
		foreach ( $unknown as $row ) {
			$pages[] = 'Zotero_reference:' . $row->page_title;
		}
		$this->output( implode( ', ', $pages ) );
		$this->output( "\n" );
		if ( !$deleteUnknown ) {
			$this->output(
				"...would delete the $unknownCount pages, but deletion not requested\n"
			);
			return;
		}
		$this->output( "...deleting the $unknownCount pages\n" );

		$summary = [ 'already-deleted' => 0, 'deleted' => 0, 'errors' => [] ];
		$itemCount = 0;

		[ $sysUser, $scope ] = $this->updater->makeRequestScope(
			[ 'delete' ]
		);
		foreach ( $unknown as $row ) {
			$itemCount++;
			$this->logProgress( 'Deletions', $itemCount, $unknownCount, $row->page_title );

			assert( $row->page_namespace === NS_ZOTERO_REF );
			$page = $this->wikiPageFactory->newFromRow( $row );
			assert( $page->getNamespace() === NS_ZOTERO_REF );

			$deletePage = $this->deletePageFactory->newDeletePage(
				$page,
				$sysUser
			);
			$status = $deletePage->forceImmediate( true )
				// bypass any permission checks, we still add the `delete`
				// permission just in case
				->deleteUnsafe( '/* ' . CommentHandler::AUTO_DELETE_KEY . ' */' );

			if ( $status->hasMessage( 'cannotdelete' ) ) {
				$this->output( "already deleted\n" );
				$summary['already-deleted']++;
			} elseif ( $status->isGood() ) {
				$summary['deleted']++;
				$this->output( "deleted\n" );
			} else {
				// Clean up unterminated line
				$this->output( "\n" );
				$this->error( "Zotero reference:$pageTitleText - failed to delete:" );
				$this->error( $status->__toString() );
				$summary['errors'][$pageTitleText] = implode(
					',',
					array_map(
						static function ( $err ) {
							if ( $err['message'] instanceof MessageSpecifier ) {
								return $err['message']->getKey();
							}
							return $err['message'];
						},
						$status->getErrors()
					)
				);
			}
		}
		$this->output( "Deletion summary:\n" );
		$this->output( $summary['deleted'] . " deleted\n" );
		$this->output( $summary['already-deleted'] . " were already deleted\n" );
		$this->output( count( $summary['errors'] ) . " errors\n" );
		if ( $summary['errors'] ) {
			foreach ( $summary['errors'] as $page => $error ) {
				$this->output( "$page: $error\n" );
			}
		}
	}

	/**
	 * Progress logs to help those running the script monitor progress
	 */
	private function logProgress(
		string $type,
		int $curr,
		int $total,
		string $item
	) {
		$percent = str_pad(
			(string)( round( ( $curr / $total ) * 100 ) ),
			3,
			" ",
			STR_PAD_LEFT
		);
		$curr = str_pad( (string)$curr, strlen( (string)$total ), " ", STR_PAD_LEFT );
		echo "$type $curr/$total ($percent%): $item ...";
	}

	/**
	 * @codeCoverageIgnore
	 * @inheritDoc
	 * @return never
	 */
	protected function fatalError( $msg, $exitCode = 1 ) {
		// Until 1.43 fatalError() would call exit() unconditionally, making it
		// impossible to test fatalError() calls, see T272241
		if ( version_compare( MW_VERSION, '1.43', '>=' )
			|| !defined( 'MW_PHPUNIT_TEST' )
		) {
			parent::fatalError( $msg, $exitCode );
		} else {
			throw new Exception( "FATAL ERROR: $msg (exit code = $exitCode)" );
		}
	}
}

$maintClass = ImportZoteroData::class;
require_once RUN_MAINTENANCE_IF_MAIN;
