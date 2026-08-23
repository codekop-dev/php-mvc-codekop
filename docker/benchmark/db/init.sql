-- Seed data for the benchmark DB container (docker/benchmark/docker-compose.yml
-- and run-matrix.sh --with-db). This is an ISOLATED benchmark database, not
-- the real `estock` database used by app/Config/Database.php locally — it
-- only exists to give the /api/db/transaksi benchmark endpoint a realistic
-- table to query without ever touching real production data.
--
-- Table structure mirrors the real `penjualan` (sales transaction) table so
-- the query shape/index usage is representative.

CREATE TABLE IF NOT EXISTS `penjualan` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `no_trx` varchar(255) DEFAULT NULL,
  `id_user` int(11) NOT NULL,
  `id_pelanggan` int(11) NOT NULL,
  `jumlah` decimal(12,2) NOT NULL,
  `beli` int(11) DEFAULT NULL,
  `total` int(11) NOT NULL,
  `pajak_persen` decimal(11,2) NOT NULL DEFAULT 0.00,
  `pajak_rp` decimal(14,2) NOT NULL DEFAULT 0.00,
  `bayar` int(11) NOT NULL,
  `status_bayar` varchar(255) DEFAULT 'Lunas',
  `tanggal_input` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `periode` varchar(255) DEFAULT NULL,
  `status_order` varchar(255) DEFAULT 'Selesai',
  `keterangan` text DEFAULT NULL,
  `metode_pembayaran` varchar(255) DEFAULT 'Tunai',
  `source` varchar(25) NOT NULL DEFAULT 'kasir',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_penjualan_notrx` (`no_trx`),
  KEY `idx_penjualan_tanggal` (`tanggal_input`),
  KEY `idx_penjualan_pelanggan_tanggal` (`id_pelanggan`,`tanggal_input`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 20,000 synthetic rows: enough that ORDER BY id DESC LIMIT N and the
-- pelanggan/tanggal index actually do real work, without taking long to
-- seed on container start.
DELIMITER $$
CREATE PROCEDURE seed_penjualan()
BEGIN
  DECLARE i INT DEFAULT 1;
  DECLARE batch_start INT DEFAULT 0;
  WHILE i <= 20000 DO
    START TRANSACTION;
    SET batch_start = i;
    WHILE i < batch_start + 500 AND i <= 20000 DO
      INSERT INTO `penjualan`
        (`no_trx`, `id_user`, `id_pelanggan`, `jumlah`, `beli`, `total`,
         `pajak_persen`, `pajak_rp`, `bayar`, `status_bayar`, `tanggal_input`,
         `created_at`, `periode`, `status_order`, `keterangan`,
         `metode_pembayaran`, `source`)
      VALUES (
        CONCAT('TRX-BENCH-', LPAD(i, 8, '0')),
        1 + (i % 5),
        i % 200,
        1.00 + (i % 6),
        10000 + (i % 50) * 1000,
        (10000 + (i % 50) * 1000) * (1 + (i % 6)),
        0.00, 0.00,
        (10000 + (i % 50) * 1000) * (1 + (i % 6)),
        IF(i % 17 = 0, 'Piutang', 'Lunas'),
        DATE_FORMAT(DATE_SUB(NOW(), INTERVAL (20000 - i) MINUTE), '%Y-%m-%d'),
        DATE_SUB(NOW(), INTERVAL (20000 - i) MINUTE),
        DATE_FORMAT(DATE_SUB(NOW(), INTERVAL (20000 - i) MINUTE), '%Y-%m'),
        'Selesai',
        NULL,
        IF(i % 3 = 0, 'Transfer', 'Tunai'),
        IF(i % 10 = 0, 'web', 'kasir')
      );
      SET i = i + 1;
    END WHILE;
    COMMIT;
  END WHILE;
END$$
DELIMITER ;

CALL seed_penjualan();
DROP PROCEDURE seed_penjualan;
