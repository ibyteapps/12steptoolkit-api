<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A check that neither passed nor failed.
 *
 * Thrown by the diagnostic commands for the answer that is correct *today* and
 * still something somebody has to come back to: a notification URL that is not
 * configured yet, a product Play knows about and this config does not, a key
 * that works but is readable by every user on the box.
 *
 * It exists because folding those into a pass loses them and folding them into
 * a failure makes the exit code useless as a monitor. Cautions are counted
 * separately and never change the exit code.
 */
class CheckCaution extends RuntimeException {}
