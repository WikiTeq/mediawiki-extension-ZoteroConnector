<?php

namespace MediaWiki\Extension\ZoteroConnector\Special;

use FormSpecialPage;
use HTMLForm;
use LogicException;
use MediaWiki\Extension\ZoteroConnector\Services\AttachmentManager;
use MediaWiki\Extension\ZoteroConnector\Services\TemplateBuilder;
use MediaWiki\Extension\ZoteroConnector\Services\WikiUpdater;
use MediaWiki\Extension\ZoteroConnector\Services\ZoteroRequester;
use MediaWiki\Extension\ZoteroConnector\ZoteroNotFoundException;
use Message;
use Status;
use stdClass;
use TitleFormatter;

class SpecialZoteroImporter extends FormSpecialPage {

	private AttachmentManager $attachmentManager;
	private TitleFormatter $titleFormatter;
	private WikiUpdater $wikiUpdater;
	private ZoteroRequester $zoteroRequester;

	/** Potentially pre-loaded from the request */
	private ?string $importType = null;
	private ?string $importKey = null;

	/**
	 * Messages that should be added to the success page
	 * @var Message[]
	 */
	private array $successMessages = [];

	public function __construct(
		AttachmentManager $attachmentManager,
		TitleFormatter $titleFormatter,
		WikiUpdater $wikiUpdater,
		ZoteroRequester $zoteroRequester
	) {
		parent::__construct( 'ZoteroImporter', 'zotero-import' );
		$this->attachmentManager = $attachmentManager;
		$this->titleFormatter = $titleFormatter;
		$this->wikiUpdater = $wikiUpdater;
		$this->zoteroRequester = $zoteroRequester;
	}

	/** @inheritDoc */
	public function setParameter( $subpage ) {
		// Allow using the subpage to pre-load the parameters, if specified,
		// but without any errors if not
		if ( $subpage ) {
			$params = explode( '/', $subpage );
			if ( count( $params ) === 2 ) {
				if ( $params[0] === 'reference' || $params[0] === 'attachment' ) {
					$this->importType = $params[0];
				}
				$this->importKey = $params[1];
			}
		}
		return parent::setParameter( $subpage );
	}

	/** @inheritDoc */
	public function getFormFields(): array {
		$fields = [];
		$fields['type'] = [
			'type' => 'radio',
			'label-message' => 'zoteroconnector-import-type-label',
			'options-messages' => [
				'zoteroconnector-import-type-reference' => 'reference',
				'zoteroconnector-import-type-attachment' => 'attachment',
			],
			'flatlist' => true,
			'default' => 'reference',
		];
		$fields['key'] = [
			'type' => 'text',
			'label-message' => 'zoteroconnector-import-key-label',
			'required' => true,
			'validation-callback' => static function ( $value, $allData, $form ) {
				if ( $value === 'top' ) {
					return $form->msg( 'zoteroconnector-import-error-top' )->parse();
				}
				return true;
			},
		];
		// Defaults from the subpage
		if ( $this->importType ) {
			$fields['type']['default'] = $this->importType;
		}
		if ( $this->importKey ) {
			$fields['key']['default'] = $this->importKey;
		}
		return $fields;
	}

	/** @inheritDoc */
	protected function alterForm( HTMLForm $form ) {
		$form->setSubmitTextMsg( 'zoteroconnector-import-submit' );
	}

	/** @inheritDoc */
	public function onSubmit( array $data ) {
		if ( $data['type'] === 'reference' ) {
			return $this->doImportReference( $data['key'] );
		} elseif ( $data['type'] === 'attachment' ) {
			return $this->doImportAttachment( $data['key'] );
		} else {
			throw new LogicException(
				"Impossible value for type: " . $data['type']
			);
		}
	}

	/** @inheritDoc */
	public function onSuccess() {
		foreach ( $this->successMessages as $m ) {
			// Match OutputPage::addWikiMsg()
			$this->getOutput()->addHTML( $m->parseAsBlock() );
		}
	}

	/** @inheritDoc */
	protected function getDisplayFormat(): string {
		return 'ooui';
	}

	/**
	 * Return value compatible with return of onSubmit()
	 * @return array|Status|bool
	 */
	private function doImportReference( string $itemId ) {
		try {
			$item = $this->zoteroRequester->getSingleItem( $itemId );
		} catch ( ZoteroNotFoundException $e ) {
			return [
				[ 'zoteroconnector-import-not-found', $itemId ],
			];
		}

		// You can accidentally use /reference/{id} to import attachments, but
		// not the other way around
		if ( ( $item->data->itemType ?? '' ) === 'attachment' ) {
			$this->successMessages[] = $this->msg(
				'zoteroconnector-import-reference-is-attachment',
				$itemId
			);
			return $this->doImportAttachment( $itemId );
		}

		$res1 = $this->writeReferencePage( $itemId, $item );
		if ( $res1 !== true ) {
			// Got an error
			return $res1;
		}

		$attachment = TemplateBuilder::getAttachment( $item );
		if ( $attachment ) {
			// Attachment automatically gets imported
			return $this->doImportAttachment( $attachment );
		}

		return true;
	}

	/**
	 * Return value compatible with return of onSubmit()
	 * @return Status|bool
	 */
	private function writeReferencePage( string $itemId, stdClass $item ) {
		$template = TemplateBuilder::getSource( $item );
		$status = $this->wikiUpdater->writeReferencePage( $itemId, $template );
		$refPageLink = $this->titleFormatter->formatTitle(
			NS_ZOTERO_REF,
			$itemId
		);
		if ( $status->hasMessage( 'edit-no-change' ) ) {
			$this->successMessages[] = $this->msg(
				'zoteroconnector-import-reference-no-change',
				$refPageLink
			);
			return true;
		} elseif ( $status->isGood() ) {
			$this->successMessages[] = $this->msg(
				'zoteroconnector-import-reference-successful',
				$refPageLink
			);
			return true;
		} else {
			return $status;
		}
	}

	/**
	 * Return value compatible with return of onSubmit()
	 * @return Status|bool
	 */
	private function doImportAttachment( string $attachmentId ) {
		$uploadData = $this->attachmentManager->getUploadData( $attachmentId );
		// If we could not find the file, return an error
		if ( $uploadData['location'] === null ) {
			return Status::newFatal( 'zoteroconnector-no-redirect' );
		}
		// We might only need to update the page content
		if ( $uploadData['location'] === false ) {
			$status = $this->wikiUpdater->updateFilePage(
				$attachmentId,
				$uploadData['pageContent']
			);
		} else {
			$status = $this->wikiUpdater->importPDFAttachment(
				$attachmentId,
				$uploadData['location'],
				$uploadData['pageContent']
			);
		}
		if ( $status->isGood() ) {
			$val = $status->getValue();
			if ( $val === 'zoteroconnector-upload-attachment-no-change'
				|| $val === 'zoteroconnector-attachment-page-updated'
			) {
				$this->successMessages[] = $this->msg(
					$val,
					$attachmentId
				);
			} else {
				$this->successMessages[] = $this->msg(
					'zoteroconnector-upload-attachment-successful',
					$attachmentId
				);
			}
			return true;
		}
		return $status;
	}
}
