<?php

namespace App\Services\Billing;

use RuntimeException;

/**
 * Something is wrong with a store credential **on this server** — a path that
 * is not set, a file that is not there, a key OpenSSL will not load.
 *
 * Deliberately distinct from a store refusing a request. This one is always our
 * fault and always fixable here; a 401 from Apple might be either, and the two
 * want different sentences in front of the person reading the output.
 */
class StoreCredentialException extends RuntimeException {}
