# PETABAHAN Order & Finance Service (`petabahan-svc-order-finance`)

Layanan Inti Pemesanan, Finansial, Komisi Split 50:50, Deposit Agen, dan Rekonsiliasi Transaksi PETABAHAN v2.0 (Fase 4 Wave 2).

---

## 1. Fitur Utama

- **15-State `OrderStateMachine`:**
  Transisi pesanan terkontrol ketat (DRAFT, BOOKING_REQUESTED, ACCEPTED, REJECTED, EXPIRED, PAYMENT_PROOF_UPLOADED, PAID_DP, SETTLEMENT_PROOF_UPLOADED, PAID_FULL, CANCELLED, READY_FOR_PICKUP, COURIER_REQUESTED, IN_DELIVERY, DISPUTED, COMPLETED). Pelanggaran transisi mengembalikan HTTP `409 Conflict` (`INVALID_ORDER_STATE`).
- **Komisi Flat Bertingkat Split 50:50 (`CommissionCalculator`):**
  - `< 350.000`: Rp 7.000 (Merchant Rp 3.500, Agen Rp 3.500)
  - `350.000 - 500.000`: Rp 10.000 (Merchant Rp 5.000, Agen Rp 5.000)
  - `500.001 - 1.000.000`: Rp 13.000 (Merchant Rp 6.500, Agen Rp 6.500)
  - `> 1.000.000`: Rp 15.000 (Merchant Rp 7.500, Agen Rp 7.500)
- **Aturan Pembayaran DP Min 50% (`PaymentService`):**
  - Minimum DP = `ceil(subtotal_materials * 0.5) + merchant_fee`.
  - Nominal DP di bawah 50% ditolak dengan HTTP `422 PAYMENT_BELOW_MIN_DP`.
- **Append-Only Deposit Ledger (`DepositLedgerService`):**
  - Tabel mutasi `deposit_transactions` strictly append-only (hanya INSERT).
  - Kolom `balance_after` selalu diverifikasi rantainya.
  - Idempotensi deterministik (`idempotency_key = "commission:{order_id}"` & `"topup:{topup_id}"`).
  - Saldo deposit kurang dari komisi saat konfirmasi bayar langsung memicu rollback atomik & HTTP `422 INSUFFICIENT_DEPOSIT`.
- **Private Storage Bukti Transfer:**
  - Bukti pembayaran dan bukti top-up disimpan pada private storage disk.
  - Akses gambar melalui Signed URL sementara (10 menit).
- **Protokol Saga & Anti-Deadlock Locking:**
  - Urutan lock di DB: `orders` -> `order_payments` -> `deposit_wallets` -> `deposit_topups`.
  - Urutan lock di Katalog: `stock_levels (ORDER BY product_id ASC FOR UPDATE)`.
  - Kompensasi otomatis jika stok gagal dikunci (`StockLockFailed`): auto-refund komisi via ledger + cancel order.
- **Skrip Rekonsiliasi Otomatis (`php artisan petabahan:reconcile`):**
  - Invarian 1: $\Delta_{wallet} = \text{wallet.balance} - (\sum \text{credit} - \sum \text{debit}) = 0$.
  - Invarian 2: Rantai `balance_after` konsisten berurutan.
  - Invarian 3: Konsistensi 1:1 debit komisi pesanan berbayar & net 0 kompensasi Saga.
  - Invarian 4: Konsistensi reservasi stok.
  - Exit code `0` jika seluruh invarian bersih, exit code `1` jika ada selisih.

---

## 2. Menjalankan Layanan & Pengujian

### Menjalankan Pengujian Otomatis
```bash
./vendor/bin/pest
```

### Menjalankan Skrip Rekonsiliasi
```bash
php artisan petabahan:reconcile
```

### Menjalankan Pemeriksaan Kedaluwarsa Pesanan (Scheduler Safety Net)
```bash
php artisan petabahan:expire-orders
```
