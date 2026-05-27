<?php

declare(strict_types=1);

namespace App\Http\Controllers;

/**
 * Base controller.
 *
 * In Laravel 12 the default base controller is a minimal abstract class. We keep
 * it intentionally empty: this app's controllers do not rely on the legacy
 * AuthorizesRequests / ValidatesRequests traits (validation is handled by Form
 * Requests, and API-key auth is handled by middleware), so there is nothing to
 * add here.
 */
abstract class Controller
{
}
