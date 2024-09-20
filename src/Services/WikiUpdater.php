<?php

namespace MediaWiki\Extension\ZoteroConnector\Services;

use FormatJson;
use MediaWiki\Extension\ZoteroConnector\HookHandlers\CommentHandler;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Permissions\PermissionManager;
use Psr\Log\LoggerInterface;
use RequestContext;
use Status;
use TitleParser;
use UploadBase;
use UploadFromUrl;
use User;
use Wikimedia\ScopedCallback;
use WikitextContent;

/**
 * Class to handle all of the on-wiki updates needed - editing reference pages
 * and uploading attachments
 */
class WikiUpdater {

	private LoggerInterface $logger;
	private PermissionManager $permManager;
	private TitleParser $titleParser;
	private WikiPageFactory $wikiPageFactory;

	public function __construct(
		LoggerInterface $logger,
		PermissionManager $permManager,
		TitleParser $titleParser,
		WikiPageFactory $wikiPageFactory
	) {
		$this->logger = $logger;
		$this->permManager = $permManager;
		$this->titleParser = $titleParser;
		$this->wikiPageFactory = $wikiPageFactory;
	}

	/**
	 * Set the request user to be the maintenance script with some extra
	 * rights, and return that system user and a ScopedCallback that will
	 * restore the session and reset the permissions.
	 *
	 * @param string[] $extraPerms extra permissions to add
	 */
	public function makeRequestScope( array $extraPerms ): array {
		// TODO do we want this to be done by the acting user instead of by
		// maintenance script?
		$systemUser = User::newSystemUser(
			User::MAINTENANCE_SCRIPT_USER,
			[ 'steal' => true ]
		);
		$reqScope = RequestContext::importScopedSession( [
			'userId' => $systemUser->getId(),
			'ip' => '127.0.0.1',
			'sessionId' => '',
			'headers' => [],
		] );
		$permScope = $this->permManager->addTemporaryUserRights(
			$systemUser,
			$extraPerms
		);

		$mergedScope = new ScopedCallback(
			static function () use ( &$reqScope, &$permScope ) {
				ScopedCallback::consume( $reqScope );
				ScopedCallback::consume( $permScope );
			}
		);
		return [ $systemUser, $mergedScope ];
	}

	public function writeReferencePage(
		string $itemId,
		string $pageContent,
		?User $systemUser = null
	): Status {
		$targetTitle = $this->titleParser->parseTitle( $itemId, NS_ZOTERO_REF );
		$targetPage = $this->wikiPageFactory->newFromLinkTarget( $targetTitle );

		// Make sure $scope exists outside of the condition
		$scope = null;
		if ( $systemUser === null ) {
			[ $systemUser, $scope ] = $this->makeRequestScope( [ 'autopatrol' ] );
		}

		$status = $targetPage->doUserEditContent(
			new WikitextContent( $pageContent ),
			$systemUser,
			'/* ' . CommentHandler::AUTO_UPDATE_KEY . ' */'
		);

		return $status;
	}

	/**
	 * If we aren't going to change the file, we may still want to update the
	 * page contents
	 */
	public function updateFilePage(
		string $itemId,
		string $pageContent,
		?User $systemUser = null
	): Status {
		// Make sure $scope exists outside of the condition
		$scope = null;
		if ( $systemUser === null ) {
			[ $systemUser, $scope ] = $this->makeRequestScope(
				[ 'autopatrol' ]
			);
		}

		$targetTitle = $this->titleParser->parseTitle(
			$itemId . '.pdf',
			NS_FILE
		);
		$targetPage = $this->wikiPageFactory->newFromLinkTarget( $targetTitle );

		$status = $targetPage->doUserEditContent(
			new WikitextContent( $pageContent ),
			$systemUser,
			'/* ' . CommentHandler::AUTO_UPDATE_KEY . ' */'
		);

		if ( !$status->isOK() ) {
			// Edit failed
			return $status;
		}
		// Cannot use $status->wasRevisionCreated(), return of
		// doUserEditContent() wasn't changed to PageUpdateStatus until 1.40
		if ( $status->hasMessage( 'edit-no-change' ) ) {
			// Page already had latest
			return Status::newGood( 'zoteroconnector-upload-attachment-no-change' );
		}
		// Only page content was updated, not file
		return Status::newGood( 'zoteroconnector-attachment-page-updated' );
	}

	public function importPDFAttachment(
		string $itemId,
		string $redirLocation,
		string $pageContent,
		?User $systemUser = null
	): Status {
		// Make sure $scope exists outside of the condition
		$scope = null;
		if ( $systemUser === null ) {
			[ $systemUser, $scope ] = $this->makeRequestScope(
				[ 'autopatrol', 'upload_by_url' ]
			);
		}

		// Okay to mess with the main request since it will be reset when $scope
		// goes out of scope
		$request = RequestContext::getMain()->getRequest();
		$request->setVal( 'wpSourceType', 'url' );
		$request->setVal( 'wpUploadFileURL', $redirLocation );
		$request->setVal( 'wpDestFile', $itemId . '.pdf' );
		$upload = UploadBase::createFromRequest( $request );
		// since `wpSourceType` was set to `url`, we know that this will be an
		// instance of `UploadFromUrl` - fetchFile() for that subclass accepts
		// an argument, while the base UploadBase class doesn't
		'@phan-var UploadFromUrl $upload';
		$status = $upload->fetchFile( [
			// Use a longer timeout than the default of 25 seconds;
			// unfortunately sometimes even a minute is not long enough
			'timeout' => 2 * 60,
		] );
		if ( !$status->isGood() ) {
			$this->logger->debug(
				__METHOD__ . ' fetchfile for {item}: not good, {status}',
				[
					'item' => $itemId,
					'status' => $status->__toString(),
				]
			);
			return $status;
		}
		$errors = $upload->verifyUpload();
		if ( $errors['status'] !== UploadBase::OK ) {
			$errorCode = $upload->getVerificationErrorCode( $errors['status'] );
			if ( $errors['status'] === UploadBase::FILE_TOO_LARGE ) {
				$errors['size'] = $upload->getFileSize();
			}
			$this->logger->debug(
				__METHOD__ . ' verifyUpload for {item} had error `{code}`: {errors}',
				[
					'item' => $itemId,
					'code' => $errorCode,
					'errors' => FormatJson::encode( $errors ),
				]
			);
			return Status::newFatal( $errorCode );
		}
		// No need to check permissions
		$warnings = $upload->checkWarnings( $systemUser );

		// Don't break on duplicates - see HI1-2
		if ( isset( $warnings['duplicate'] ) ) {
			unset( $warnings['duplicate'] );
		}
		if ( isset( $warnings['duplicate-archive'] ) ) {
			unset( $warnings['duplicate-archive'] );
		}
		if ( $warnings ) {
			if ( isset( $warnings[ 'no-change' ] ) ) {
				return $this->updateFilePage(
					$itemId,
					$pageContent,
					$systemUser
				);
			}
			$this->logger->debug(
				__METHOD__ . ' checkWarnings for {item}: {warnings}',
				[
					'item' => $itemId,
					'warnings' => FormatJson::encode( $warnings ),
				]
			);
			return Status::newFatal( 'zoteroconnector-upload-attachment-warnings', $warnings );
		}
		$status = $upload->performUpload(
			'/* ' . CommentHandler::AUTO_UPLOAD_KEY . ' */',
			$pageContent,
			// watch the page?
			false,
			$systemUser,
			// edit tags
			[]
		);
		return $status;
	}

}
