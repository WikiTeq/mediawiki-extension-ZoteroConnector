<?php

namespace MediaWiki\Extension\ZoteroConnector;

use RuntimeException;
use Throwable;

class ZoteroNotFoundException extends RuntimeException {

	public function __construct(
		string $itemKey,
		int $code = 0,
		Throwable $previous = null
	) {
		parent::__construct( "Not found: $itemKey", $code, $previous );
	}

}
