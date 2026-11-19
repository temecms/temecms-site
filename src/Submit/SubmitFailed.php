<?php

/**
 * Thrown inside the Submit form's handler when GitHub refuses a step or
 * answers with something it can't read; the visitor is told the pull
 * request couldn't be opened.
 *
 * @package TemecmsSite
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Temecms\Site\Submit;

use RuntimeException;

/**
 * A pull request that couldn't be opened.
 *
 * @since 1.1.0
 */
final class SubmitFailed extends RuntimeException {}
