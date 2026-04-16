<?php

namespace MediaWiki\Extension\ZoteroConnector\Maintenance;

use CommentStore;
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
use RuntimeException;
use User;
use Wikimedia\Rdbms\IResultWrapper;
use WikiPage;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

class ImportZoteroData extends Maintenance {

	private AttachmentManager $manager;
	private CommentStore $commentStore;
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

		// Cannot be used with
		// * --from
		// * --type=references
		// * --item-list
		$this->addOption(
			'do-delete-unknown-attachments',
			'Delete any attachments in the file namespace that were previously '
				. 'imported by ZoteroConnector but do not correspond to entries '
				. 'in Zotero (default: report)'
		);
	}

	private function initServices() {
		$services = MediaWikiServices::getInstance();
		$this->manager = $services->getService( 'ZoteroConnector.AttachmentManager' );
		$this->commentStore = $services->getCommentStore();
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
		$deleteUnknownRefs = $this->hasOption( 'do-delete-unknown-refs' );
		$deleteUnknownAttachments = $this->hasOption( 'do-delete-unknown-attachments' );
		if ( $deleteUnknownRefs ) {
			if ( $from !== null ) {
				$this->fatalError(
					'--do-delete-unknown-refs cannot be used with --from'
				);
			}
			if ( $type === 'attachments' ) {
				$this->fatalError(
					'--do-delete-unknown-refs cannot be used with --type=attachments'
				);
			}
		}
		if ( $deleteUnknownAttachments ) {
			if ( $from !== null ) {
				$this->fatalError(
					'--do-delete-unknown-attachments cannot be used with --from'
				);
			}
			if ( $type === 'references' ) {
				$this->fatalError(
					'--do-delete-unknown-attachments cannot be used with --type=references'
				);
			}
		}
		$dryRun = !$this->hasOption( 'do-import' );

		$mode = $dryRun ? 'DRY-RUN' : 'DO-IMPORT';
		if ( !$this->hasOption( 'item-list' ) ) {
			// Won't even be printed when there is an item-list since we don't
			// fetch everything
			$mode .= $deleteUnknownRefs ? ', DELETE-UNKNOWN-REFS' : ', PRINT-UNKNOWN-REFS';
			$mode .= $deleteUnknownAttachments
				? ', DELETE-UNKNOWN-ATTACHMENTS'
				: ', PRINT-UNKNOWN-ATTACHMENTS';
		}
		$this->output( "ImportZoteroData: import-mode=$mode type=$type\n" );

		if ( $this->hasOption( 'item-list' ) ) {
			if ( $deleteUnknownRefs ) {
				$this->fatalError(
					'--do-delete-unknown-refs cannot be used with --item-list'
				);
			}
			if ( $deleteUnknownAttachments ) {
				$this->fatalError(
					'--do-delete-unknown-attachments cannot be used with --item-list'
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
		// Make a copy since filterAttachments() will modify the array
		$allKnownAttachments = array_values( $attachmentIds );

		$this->filterReferences( $referencePages );
		$this->filterAttachments( $attachmentIds );

		$this->doAllImports( $referencePages, $attachmentIds );

		if ( !$this->hasOption( 'item-list' ) ) {
			// Either delete or just print all unknown references
			$this->handleUnknownReferences( $allKnownReferences, $deleteUnknownRefs );
			// Either delete or just print all unknown attachments
			$this->handleUnknownAttachments( $allKnownAttachments, $deleteUnknownAttachments );
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
		if ( $uploadSummary['retry'] !== [] ) {
			$count = count( $uploadSummary['retry'] );
			$this->output(
				"Retrying $count errors not caused by file size: " . implode( ', ', $uploadSummary['retry'] ) . "\n"
			);
			$retrySummary = $this->importAttachments( $uploadSummary['retry'], $sysUser );
			$this->output( "Attachment retry summary:\n" );
			$this->output( $retrySummary['uploaded'] . " uploaded\n" );
			$this->output( $retrySummary['page-updated'] . " pages updated without file changes\n" );
			$this->output( $retrySummary['no-change'] . " unchanged\n" );
			$this->output( count( $retrySummary['errors'] ) . " errors\n" );
			if ( $retrySummary['errors'] ) {
				foreach ( $retrySummary['errors'] as $id => $error ) {
					$this->output( "$id: $error\n" );
				}
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
			return [ 'no-change' => 0, 'uploaded' => 0, 'page-updated' => 0, 'errors' => [], 'retry' => [] ];
		}
		$this->manager->preloadAttachmentVersions( $attachmentIds );
		$this->requester->preloadAttachmentData();

		$dryRun = !$this->hasOption( 'do-import' );
		$summary = [ 'no-change' => 0, 'uploaded' => 0, 'page-updated' => 0, 'errors' => [], 'retry' => [] ];
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
				$summary['retry'][] = $attachment;
				continue;
			}
			if ( $dryRun ) {
				$this->output( "found redirect, DRY RUN\n" );
				continue;
			}
			// We might only need to update the page content
			if ( $uploadData['location'] === false ) {
				$status = $this->updater->updateFilePage(
					$attachment,
					$uploadData['pageContent'],
					$sysUser
				);
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
				if ( !$status->hasMessage( 'file-too-large' ) ) {
					$summary['retry'][] = $attachment;
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
			// Only pause if we tried to actually do an upload or page edit,
			// should not be needed for null redirects or dry-run imports
			$this->addPause();
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

		$this->doDeletePages(
			$unknown,
			NS_ZOTERO_REF,
			'Zotero reference',
			'Reference deletions'
		);
	}

	/**
	 * Given a list of rows suitable to use in WikiPageFactory::newFromRow(),
	 * confirm that they are in the given namespace and delete them
	 */
	private function doDeletePages(
		IResultWrapper $rows,
		int $namespace,
		string $namespaceText,
		string $progressType
	) {
		$totalCount = count( $rows );
		$summary = [ 'already-deleted' => 0, 'deleted' => 0, 'errors' => [] ];
		$itemCount = 0;

		[ $sysUser, $scope ] = $this->updater->makeRequestScope(
			[ 'delete' ]
		);
		foreach ( $rows as $row ) {
			$itemCount++;
			$this->logProgress( $progressType, $itemCount, $totalCount, $row->page_title );

			// Make extra sure we don't delete anything unexpected
			if ( $row->page_namespace !== (string)$namespace ) {
				throw new RuntimeException( "Wrong row namespace: " . $row->page_namespace );
			}
			$page = $this->wikiPageFactory->newFromRow( $row );
			if ( $page->getNamespace() !== $namespace ) {
				throw new RuntimeException( "Wrong page namespace: " . $page->getNamespace() );
			}

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
				$pageTitleText = $row->page_title;
				$this->error( "$namespaceText:$pageTitleText - failed to delete:" );
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
		$this->output( "$progressType summary:\n" );
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
	 * Given the list of known attachments, identify all pages in the file
	 * namespace that were originally uploaded by this extension and then
	 * either delete them or just report about them
	 *
	 * @param string[] $knownAttachments array of keys
	 */
	private function handleUnknownAttachments(
		array $knownAttachments,
		bool $deleteUnknown
	) {
		// Attachments are PDFs, titles start with a capital letter
		$knownAttachments = array_map(
			static fn ( $id ) => ucfirst( $id ) . '.pdf',
			$knownAttachments
		);
		$db = $this->getDb( DB_REPLICA );
		// Find all pages
		// * in the file namespace
		// * that are not known attachments
		// * where the oldest revision was created by User::MAINTENANCE_SCRIPT_USER
		// * and the oldest revision had the edit summary `/* zoteroconnector-auto-upload */`
		$uploader = User::newSystemUser(
			User::MAINTENANCE_SCRIPT_USER,
			[ 'steal' => true ]
		);
		$uploadActor = $uploader->getActorId();

		$comment = '/* ' . CommentHandler::AUTO_UPLOAD_KEY . ' */';

		// Doing this with two queries
		// First: all pages in the file namespace that are not known attachments
		$possibleFiles = $db->newSelectQueryBuilder()
			->select( [ 'page_namespace', 'page_title', 'page_id' ] )
			->from( 'page' )
			->where( [
				'page_namespace' => NS_FILE,
				'page_title NOT IN (' . $db->makeList( $knownAttachments ) . ')'
			] )
			->caller( __METHOD__ )
			->fetchResultSet();
		$possibleFilesCount = count( $possibleFiles );
		if ( $possibleFilesCount === 0 ) {
			$this->output( "No unknown attachments!\n" );
			return;
		}

		$filePageIds = [];
		foreach ( $possibleFiles as $row ) {
			$filePageIds[] = (int)$row->page_id;
		}
		// Second, limit based on oldest revision being creation by this
		// extension
		// Need a subquery to get the oldest revision for each page before
		// we do the filtering. We could try to combine the comment query with
		// this but it is easier to just do them separately
		$oldestRevs = $db->newSelectQueryBuilder()
			->select( [ 'rev_page', 'min_rev_id' => 'MIN(rev_id)' ] )
			->from( 'revision' )
			->where( [
				'rev_page IN (' . $db->makeList( $filePageIds ) . ')'
			] )
			->groupBy( 'rev_page' );
		$queryInfo = WikiPage::getQueryInfo();
		$commentJoinInfo = $this->commentStore->getJoin( 'rev_comment' );
		$unknownAttachments = $db->newSelectQueryBuilder()
			->select( $queryInfo['fields'] )
			->fields( $commentJoinInfo['fields'] )
			->from( 'page' )
			->tables( $commentJoinInfo['tables'] )
			->join(
				$oldestRevs,
				'oldest_revs',
				[ 'page_id = oldest_revs.rev_page' ]
			)
			->join(
				'revision',
				null,
				[ 'rev_id = oldest_revs.min_rev_id' ]
			)
			->joinConds( $commentJoinInfo['joins'] )
			->where( [
				'rev_actor' => $uploadActor,
				'rev_comment_text' => $comment
			] )
			->caller( __METHOD__ )
			->fetchResultSet();
		$unknownCount = count( $unknownAttachments );
		if ( $unknownCount === 0 ) {
			$this->output( "No unknown attachments!\n" );
			return;
		}
		$this->output( "There are $unknownCount unknown attachments: " );
		$files = [];
		foreach ( $unknownAttachments as $row ) {
			$files[] = 'File:' . $row->page_title;
		}
		$this->output( implode( ', ', $files ) );
		$this->output( "\n" );
		if ( !$deleteUnknown ) {
			$this->output(
				"...would delete the $unknownCount files, but deletion not requested\n"
			);
			return;
		}
		$this->output( "...deleting the $unknownCount files\n" );

		$this->doDeletePages(
			$unknownAttachments,
			NS_FILE,
			'File',
			'Attachment deletions'
		);
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
		// Once support for earlier version of MediaWiki is dropped this
		// override can be removed, until then ensure consistent handling in
		// different versions so that we can easily test.
		if ( !defined( 'MW_PHPUNIT_TEST' ) ) {
			parent::fatalError( $msg, $exitCode );
		} else {
			throw new RuntimeException( "FATAL ERROR: $msg (exit code = $exitCode)" );
		}
	}

	/**
	 * @codeCoverageIgnore
	 * @inheritDoc
	 */
	protected function error( $err, $die = 0 ) {
		// Parent method will use
		// `fwrite( STDERR, $err . "\n" );` outside of tests
		// but `print $err;` in tests, add an extra line ending for readability
		// in tests
		if ( defined( 'MW_PHPUNIT_TEST' ) ) {
			print( $err . "\n" );
		} else {
			parent::error( $err, $die );
		}
	}

	/**
	 * In tests, this just reports the script would sleep, no need to slow down
	 * the test execution. In production, this sleeps for a second, and doesn't
	 * print anything because we don't need to spam the logs
	 */
	private function addPause() {
		if ( defined( 'MW_PHPUNIT_TEST' ) ) {
			$this->output( "Would sleep for a second...\n" );
		} else {
			sleep( 1 );
		}
	}

}

$maintClass = ImportZoteroData::class;
require_once RUN_MAINTENANCE_IF_MAIN;
