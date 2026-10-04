<?php
declare(strict_types=1);

namespace OCA\ImmichSearch\Service;

/** Error with a message that is safe to show to the user; code is the HTTP status to answer with. */
class ImmichException extends \RuntimeException {
}
