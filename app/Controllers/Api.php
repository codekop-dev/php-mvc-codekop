<?php

declare(strict_types=1);

defined('BASEPATH') || exit('No direct script access allowed');

use System\Controller;
use System\Response;

final class Api extends Controller
{
    public function health(): Response
    {
        return Response::json([
            'success' => true,
            'data' => [
                'name' => 'Codekop PHP MVC',
                'version' => '1.0.0',
                'php' => PHP_VERSION,
                'timezone' => date_default_timezone_get(),
            ],
        ]);
    }

    public function echo(): Response
    {
        return Response::json([
            'success' => true,
            'data' => $this->request->json(),
        ]);
    }

    public function route(): Response
    {
        return Response::json([
            'success' => true,
            'data' => [
                'parameters' => $this->request->routeParameters(),
                'query' => $this->request->query(),
            ],
        ]);
    }

    /**
     * Read-only sample query against a transaction table, used by the
     * benchmark harness (docker/benchmark/) to measure a real DB-backed
     * request instead of only stateless JSON endpoints.
     */
    public function transaksi(): Response
    {
        if (!$this->db instanceof \PDO) {
            return Response::json(['success' => false, 'message' => 'Database not configured'], 500);
        }

        $limit = (int) ($this->request->query('limit', 20));
        $limit = max(1, min($limit, 100));

        $statement = $this->db->prepare(
            'SELECT id, no_trx, total, status_bayar, metode_pembayaran, created_at
             FROM penjualan
             ORDER BY id DESC
             LIMIT ' . $limit,
        );
        $statement->execute();

        return Response::json([
            'success' => true,
            'data' => $statement->fetchAll(),
        ]);
    }
}
