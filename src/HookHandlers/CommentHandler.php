<?php

namespace MediaWiki\Extension\ZoteroConnector\HookHandlers;

use Language;
use MediaWiki\Hook\FormatAutocommentsHook;
use Wikimedia\Message\IMessageFormatterFactory;
use Wikimedia\Message\ITextFormatter;
use Wikimedia\Message\MessageValue;

class CommentHandler implements FormatAutocommentsHook {

	public const AUTO_UPLOAD_KEY = 'zoteroconnector-auto-upload';
	public const AUTO_UPDATE_KEY = 'zoteroconnector-auto-update';
	public const AUTO_DELETE_KEY = 'zoteroconnector-auto-delete';

	private ITextFormatter $textFormatter;

	public function __construct( ITextFormatter $textFormatter ) {
		$this->textFormatter = $textFormatter;
	}

	/**
	 * Static constructor for use by ObjectFactory since the text formatter is
	 * not a service itself
	 */
	public static function create(
		IMessageFormatterFactory $messageFormatterFactory,
		Language $contentLanguage
	): self {
		return new self(
			$messageFormatterFactory->getTextFormatter(
				$contentLanguage->getCode()
			)
		);
	}

	/**
	 * This hook is called when an autocomment is formatted by the Linker. See
	 * documentation in core for details.
	 *
	 * @inheritDoc
	 */
	public function onFormatAutocomments(
		&$comment,
		$pre,
		$auto,
		$post,
		$title,
		$local,
		$wikiId
	) {
		if ( $auto === self::AUTO_UPLOAD_KEY ||
			$auto === self::AUTO_UPDATE_KEY ||
			$auto === self::AUTO_DELETE_KEY
		) {
			$comment = $this->textFormatter->format(
				new MessageValue( $auto )
			);
		}
		return true;
	}
}
