<?php

declare(strict_types=1);

defined('BASEPATH') || exit('No direct script access allowed');

/**
 * Remove stale native PHP file sessions.
 *
 * This helper is optional. Enable session.cleanup.enabled in Config.php to
 * run it automatically when a session starts, or call this function manually
 * from an application's dashboard/maintenance flow.
 */
function cleanup_stale_session_files(): void
{
    $sessionPath = defined('SESSION_PATH') ? (string) SESSION_PATH : (string) session_save_path();
    if ($sessionPath === '') {
        $sessionPath = (string) ini_get('session.save_path');
    }
    if (str_contains($sessionPath, ';')) {
        [, $sessionPath] = explode(';', $sessionPath, 2);
    }
    $sessionPath = rtrim($sessionPath, DIRECTORY_SEPARATOR);
    if ($sessionPath === '' || !is_dir($sessionPath) || !is_readable($sessionPath)) {
        return;
    }

    $maxLifetime = (int) ini_get('session.gc_maxlifetime');
    $cutoff = time() - max(1, $maxLifetime > 0 ? $maxLifetime : 1440);
    $currentSessionId = session_status() === PHP_SESSION_ACTIVE ? session_id() : '';

    try {
        $files = new FilesystemIterator($sessionPath, FilesystemIterator::SKIP_DOTS);
        foreach ($files as $file) {
            if (!$file->isFile() || !str_starts_with($file->getFilename(), 'sess_')) {
                continue;
            }
            if ($currentSessionId !== '' && $file->getFilename() === 'sess_' . $currentSessionId) {
                continue;
            }

            $handle = @fopen($file->getPathname(), 'c');
            if ($handle === false) {
                continue;
            }
            $locked = @flock($handle, LOCK_EX | LOCK_NB);
            if (!$locked) {
                fclose($handle);
                continue;
            }

            $lastModified = @filemtime($file->getPathname());
            if ($lastModified !== false && $lastModified < $cutoff) {
                @unlink($file->getPathname());
            }
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    } catch (Throwable) {
        // Cleanup must never interrupt an application's normal request.
    }
}
