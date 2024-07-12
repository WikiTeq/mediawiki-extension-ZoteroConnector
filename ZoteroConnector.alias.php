<?php

/** Aliases for the ZoteroConnector extension */

$specialPageAliases = [];

/** English (English) */
$specialPageAliases['en'] = [
	'ZoteroImporter' => [ 'ZoteroImporter' ],
];

$magicWords = [];

/** English (English) */
$magicWords['en'] = [
	// These magic words are case sensitive, as indicated by the '1' - they
	// should only be set via the automated imports
	'ZOTERO_FILE_VERSION' => [ '1', 'ZOTERO_FILE_VERSION' ],
	'ZOTERO_REFERENCE_TITLE' => [ '1', 'ZOTERO_REFERENCE_TITLE' ],
];
