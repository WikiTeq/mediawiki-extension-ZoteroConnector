<?php

namespace MediaWiki\Extension\ZoteroConnector\Services;

use stdClass;

// To figure out:
// `note` is like attachment (JKEHNHWN is a note on 4PMNHLSD)

/* Utility to build template invocations */
class TemplateBuilder {

	/**
	 * Parameters common to all of the {{Cite *}} templates, key is the name
	 * of the field in Zotero and value is the name of the field in the template
	 */
	private const CITATION_PARAMS = [
		// General
		'title' => 'title',
		'date' => 'date',
		'publisher' => 'publisher',
		'place' => 'place',

		// External identifiers
		'url' => 'url',
		'ISBN' => 'ISBN',
		'ISSN' => 'ISSN',
		'DOI' => 'DOI',

		// Access
		'accessDate' => 'access-date',

		// Journal articles
		'volume' => 'volume',
		'issue' => 'issue',
		'pages' => 'pages',
		'journalAbbreviation' => 'journalAbbreviation',
		// TODO we should add some way of showing abstracts...
		// 'abstractNote' => 'abstractNote',

		// Sections of a book get handled specially later, since we need
		// `bookTitle` to be set as the template `title` and `title` to be
		// set as the template `chapter`

		// Magazines, journals, etc. - name of the journal or magazine,
		'publicationTitle' => 'work',
		'seriesTitle' => 'work',
		'proceedingsTitle' => 'work',

		// Thesis - university can be publisher if not set separately
		'university' => 'publisher',
		// Report - institution can be publisher if not set separately
		'institution' => 'publisher',
	];

	/**
	 * Citation templates - map of the Zotero `itemType` to the name of the
	 * on-wiki template to use
	 */
	private const CITATION_TEMPLATES = [
		'book' => 'Cite book',
		'bookSection' => 'Cite book',
		'conferencePaper' => 'Cite conference',
		'document' => 'Cite document',
		'journalArticle' => 'Cite journal',
		'magazineArticle' => 'Cite magazine',
		'newspaperArticle' => 'Cite news',
		'report' => 'Cite report',
		'thesis' => 'Cite thesis',
	];

	// For formatting dates
	private const MONTH_NAMES = [
		'January',
		'February',
		'March',
		'April',
		'May',
		'June',
		'July',
		'August',
		'September',
		'October',
		'November',
		'December',
	];

	/**
	 * Ensure that dates are formatted properly - lots of the sources use
	 * dates like `1981-05`, which the citation templates don't like, convert
	 * such dates to `{Month} {Year}`
	 *
	 * @param string $original
	 */
	private static function normalizeDate( string $original ): string {
		$matches = [];
		if ( !preg_match( '/^(\d{4})-(\d\d)$/', $original, $matches ) ) {
			return $original;
		}
		$monthNum = intval( $matches[2] );
		if ( $monthNum === 0 || $monthNum > 12 ) {
			// Broken month, don't change things
			return $original;
		}
		$monthName = self::MONTH_NAMES[ $monthNum - 1 ];
		return $monthName . ' ' . $matches[1];
	}

	/**
	 * Add the details of a creator to the template, generally for
	 * authors but supports other roles too; the array passed by reference
	 * checks the number of each role already used, to identify the next number
	 * to use
	 *
	 * @param FluentTemplate $template
	 * @param stdClass $creatorObj
	 * @param int[] &$creatorTypesUsed
	 */
	private static function addCreator(
		FluentTemplate $template,
		stdClass $creatorObj,
		array &$creatorTypesUsed
	): void {
		$type = $creatorObj->creatorType ?? 'author';
		if ( !isset( $creatorTypesUsed[ $type ] ) ) {
			$creatorTypesUsed[ $type ] = 1;
		} else {
			$creatorTypesUsed[ $type ]++;
		}
		$nextNum = $creatorTypesUsed[ $type ];

		if ( isset( $creatorObj->name ) ) {
			// here we need authorN, not just N, and do not want a - afterwards
			$template->setParam( "$type$nextNum", $creatorObj->name );
		}
		// For authors, we want `last1`, `first1`, etc.
		// For other roles, like editor, we want `editor1-last`, `editor1-first`
		$creatorPrefix = ( $type === 'author' ? '' : "$type-" );
		$result = '';
		if ( isset( $creatorObj->lastName ) ) {
			if ( $type === 'author' ) {
				$template->setParam( "last$nextNum", $creatorObj->lastName );
			} else {
				$template->setParam( "$type$nextNum-last", $creatorObj->lastName );
			}
		}
		if ( isset( $creatorObj->firstName ) ) {
			if ( $type === 'author' ) {
				$template->setParam( "first$nextNum", $creatorObj->firstName );
			} else {
				$template->setParam( "$type$nextNum-first", $creatorObj->firstName );
			}
		}
	}

	public static function getAttachment( stdClass $itemObj ): ?string {
		if ( !isset( $itemObj->links->attachment->href ) ) {
			return null;
		}
		$attachment = $itemObj->links->attachment;
		if ( !isset( $attachment->attachmentType ) ) {
			// Missing type, skip for now
			return null;
		}
		if ( $attachment->attachmentType !== 'application/pdf' ) {
			// Unknown type, skip for now
			return null;
		}
		$lastSlashPos = strrpos( $attachment->href, '/' );
		return substr( $attachment->href, $lastSlashPos + 1 );
	}

	private static function getCitationTemplate( stdClass $itemObj ): string {
		$type = $itemObj->data->itemType ?? null;
		if ( $type === null ) {
			// just default to books
			return 'Cite book';
		}
		// Default to book if unknown
		return self::CITATION_TEMPLATES[ $type ] ?? 'Cite book';
	}

	/**
	 * Set up {{ZoteroDetails}} with data that is not in the citation itself
	 * but still important
	 */
	private static function getZoteroTemplate( stdClass $itemObj ): string {
		// Template noting the extra Zotero details, so that we can store them
		// in a structured way (and also the title, for use in parser functions)
		$template = new FluentTemplate( 'ZoteroDetails' );
		$data = $itemObj->data;
		// for `title` here for book chapters we want to store the chapter name,
		// so no need for fancy magic
		$params = [ 'key', 'itemType', 'title', 'version', 'abstractNote' ];
		foreach ( $params as $p ) {
			if ( isset( $data->$p ) && $data->$p !== '' ) {
				$template->setParam( $p, $data->$p );
			}
		}
		$attachmentId = self::getAttachment( $itemObj );
		if ( $attachmentId ) {
			$template->setParam( 'attachment', $attachmentId );
		}
		return $template->getWikitext();
	}

	public static function getSource( stdClass $itemObj ): string {
		$data = $itemObj->data;
		$template = new FluentTemplate( self::getCitationTemplate( $itemObj ) );

		// Some citation parameters can be drawn from *multiple* Zotero
		// parameters, e.g. for a thesis if the publisher is not set but the
		// University is, use the university. Use maybeAddParam() to not
		// override a prior value
		foreach ( self::CITATION_PARAMS as $p => $k ) {
			if ( isset( $data->$p ) && $data->$p !== '' ) {
				$template->maybeAddParam( $k, $data->$p );
			}
		}

		// Extra handling for book sections
		if ( isset( $data->itemType ) && $data->itemType === 'bookSection' ) {
			// The Zotero 'bookTitle' should be the actual title, and the
			// Zotero 'title' should be the chapter title
			if ( isset( $data->bookTitle ) && $data->bookTitle !== '' ) {
				$template->setParam( 'title', $data->bookTitle );
			}
			if ( isset( $data->title ) && $data->title !== '' ) {
				$template->setParam( 'chapter', $data->title );
			}
		}

		if ( $template->hasParam( 'date' ) ) {
			$template->setParam(
				'date',
				self::normalizeDate( $template->getParam( 'date' ) )
			);
		}

		if ( isset( $data->creators ) ) {
			$creatorTypesUsed = [];
			foreach ( $data->creators as $creator ) {
				self::addCreator( $template, $creator, $creatorTypesUsed );
			}
		}
		$template = $template->getWikitext();
		return $template . "\n" . self::getZoteroTemplate( $itemObj );
	}
}
