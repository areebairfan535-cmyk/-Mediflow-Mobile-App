<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;

/**
 * GET /api/v1/health — liveness probe for deploys and the admin panel.
 * Public on purpose, so it deliberately reveals nothing beyond up/down.
 */
final class HealthController extends Controller
{
    public function index(Request $request): never
    {
        $databaseOk = true;
        try {
            Database::selectOne('SELECT 1 AS ok');
        } catch (\Throwable) {
            $databaseOk = false;
        }

        $this->ok([
            'status'   => $databaseOk ? 'ok' : 'degraded',
            'service'  => $GLOBALS['__config']['name'] ?? 'MediFlow',
            'database' => $databaseOk ? 'connected' : 'unavailable',
            'time'     => gmdate('c'),
        ]);
    }
}
