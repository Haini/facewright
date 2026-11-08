<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Service;

/** A call to the ExApp's /cluster endpoints failed: transport, a status other than 200, or a 409 a sync did not cure. */
class ExAppClusteringException extends \RuntimeException {
}
