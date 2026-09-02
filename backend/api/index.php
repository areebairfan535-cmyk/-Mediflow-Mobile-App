<?php

declare(strict_types=1);

/**
 * Vercel entry point.
 *
 * The vercel-php runtime invokes a PHP file directly rather than serving a
 * document root, so every request is routed here (see ../vercel.json) and
 * handed straight to the real front controller.
 *
 * public/index.php resolves its own paths with dirname(__DIR__), which still
 * points at the backend root from inside public/, so nothing else changes.
 */

require dirname(__DIR__) . '/public/index.php';
