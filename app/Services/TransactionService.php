<?php

namespace App\Services;

use App\Models\{Transaction, DetailTransaction, HistoryStock, Product, HistoryReceipt};
use Illuminate\Support\Facades\DB;
use Exception;
use App\Helpers\TableResolver;

class TransactionService{
    public function create(array $data, int $companyId, $user)
    {
        return DB::transaction(function () use ($data, $companyId, $user) {


            $trxTable = TableResolver::trx($companyId);
            $detailTable = TableResolver::detail($companyId);

            $products = $this->validateAndLockStock($data['details']);
            $transactionNumber = $this->generateTrxNumber($companyId);
            //  insert transaksi
            $transactionId = DB::table($trxTable)->insertGetId([
                'transaction_number' => $transactionNumber,
                'transaction_date' => $data['transaction_date'],
                'subtotal' => $data['subtotal'],
                'transaction_discount' => $data['transaction_discount'] ?? 0,
                'transaction_tax' => $data['transaction_tax'] ?? 0,
                'total_transaction' => $data['total_transaction'],
                'name_other_cost' => $data['name_other_cost'] ?? null,
                'other_cost' => $data['other_cost'] ?? 0,
                'total_payment' => $data['total_payment'],
                'change_amount' => $data['change_amount'] ?? 0,
                'transaction_description' => $data['transaction_description'] ?? null,
                'total_profit' => $data['total_profit'],
                'company_id' => $companyId,
                'customer_id' => $data['customer_id'] ?? null,
                'user_id' => $user->id,
                'company_payment_method_id' => $data['company_payment_method_id'],
                'created_at' => now(),
                'updated_at' => now()
            ]);

            $detailsData = [];
            $historyStockData = [];
            $receiptData = [];

            foreach ($data['details'] as $item) {

                $product = $products[$item['product_id']];

                $initialStock = $product->product_stock;
                $finalStock = $initialStock - $item['quantity'];

                $product->product_stock = $finalStock;

                $detailsData[] = [
                    'transaction_id' => $transactionId,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['selling_price'],
                    'cost_price' => $item['cost_price'],
                    'subtotal' => $item['subtotal'],
                    'item_profit' => $item['subtotal'] - ($item['cost_price'] * $item['quantity']),
                    'created_at' => now(),
                    'updated_at' => now()
                ];

                $receiptData[] = [
                    'namaProduk' => $product->product_name ?? 'Produk Dihapus',
                    'jumlah' => $item['quantity'],
                    'hargaSatuan' => $item['selling_price'],
                    'diskon' => $item['discount'] ?? 0,
                    'subtotal' => $item['subtotal'],
                ];

                $historyStockData[] = [
                    'history_stock_date' => now(),
                    'product_id' => $item['product_id'],
                    'user_id' => $user->id,
                    'initial_stock' => $initialStock,
                    'stock_in' => 0,
                    'stock_out' => $item['quantity'],
                    'final_stock' => $finalStock,
                    'transaction_id' => $transactionId,
                    'stock_description' => "Penjualan - Transaksi",
                    'created_at' => now(),
                    'updated_at' => now()
                ];
            }

            DB::table($detailTable)->insert($detailsData);

            HistoryStock::insert($historyStockData);

            foreach ($products as $product) {
                $product->save();
            }

            $dt = \Carbon\Carbon::parse($data['transaction_date']);
            HistoryReceipt::create([
                'kode_transaksi'    => $transactionNumber,
                'tanggal'           => $dt->toDateString(),
                'waktu'             => $dt->toTimeString(),
                'pelanggan'         => $data['customer_name'] ?? "Pelanggan Umum", 
                'kasir'             => $user->full_name ?? $user->email,
                'metode_pembayaran' => $data['payment_method_name'] ?? '-', 
                'total_penjualan'   => $data['total_transaction'],
                'diskon'            => $data['transaction_discount'] ?? 0,
                'biaya_lain'        => $data['other_cost'] ?? 0,
                'bayar'             => $data['total_payment'],
                'kembalian'         => $data['change_amount'] ?? 0,
                'catatan'           => $data['transaction_description'] ?? '-',
                'products'          => $receiptData, 
            ]);
            return [
                'id' => $transactionId,
                'transaction_number' => $data['transaction_number'] ?? null
            ];
        });
    }
    private function validateAndLockStock($details) {
        $productIds = collect($details)->pluck('product_id');

        $products = Product::whereIn('id', $productIds)
        ->lockForUpdate()
        ->get()
        ->keyBy('id');

        foreach ($details as $item) {
            $product = $products[$item['product_id']] ?? null;

            if (!$product) {
                throw new Exception("Produk tidak ditemukan");
            }
            if ($product->product_stock < $item['quantity']) {
                throw new Exception("Stok {$product->product_name} tidak cukup!");
            }
        }
        return $products;
    }
    


    private function generateTrxNumber($companyId) {
        return "TRX-" . now()->format('YmdHis') . "-" . strtoupper(bin2hex(random_bytes(2)));
    }
}