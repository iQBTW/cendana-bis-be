<?php

namespace App\Services;

use App\Models\BusinessHealth;
use App\Models\BusinessHealthIndicator;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Financial;
use App\Models\HealthIndicator;
use App\Models\HealthRecommendationDictionary;
use App\Models\HistoryStock;
use App\Models\Product;
use App\Helpers\TableResolver;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BusinessHealthService
{
    // MAIN: generateReport
    public function generateReport(Company $company)
    {
        return DB::transaction(function () use ($company) {

            $companyStart = Carbon::parse($company->created_at)->startOfDay();
            $now = Carbon::now();

            // Hitung period
            $diffMonths = $companyStart->diffInMonths($now);
            $maxPeriod = floor($diffMonths / 3);

            // Kalau belum sampai 3 bulan skip
            if ($maxPeriod < 1) {
                return null;
            }

            $reports = [];

            // Loop semua period yang valid
            for ($periodIndex = 1; $periodIndex <= $maxPeriod; $periodIndex++) {

                $startDate = $companyStart->copy()
                    ->addMonths(($periodIndex - 1) * 3)
                    ->startOfDay();

                $endDate = $startDate->copy()
                    ->addMonths(3)
                    ->subSecond();

                // Skip kalau period belum selesai (tidak partial data)
                if ($endDate->gt($now)) {
                    continue;
                }

                // Cek apakah sudah pernah dibuat
                $exists = BusinessHealth::where('company_id', $company->id)
                    ->where('period_index', $periodIndex)
                    ->exists();

                if ($exists) {
                    continue;
                }

                // Generate report code (UNIQUE & konsisten)
                $reportCode = "REP-{$company->id}-P{$periodIndex}";

                // Ambil raw data
                $rawFinancials = $this->getRawFinancialData(
                    $company->id,
                    $startDate,
                    $endDate
                );

                // Skip kalau tidak ada transaksi sama sekali
                if ($rawFinancials['transactions_count'] == 0) {
                    continue;
                }

                $report = BusinessHealth::create([
                    'company_id' => $company->id,
                    'period_index' => $periodIndex,
                    'report_code' => $reportCode,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'total_sales' => $rawFinancials['sales'],
                    'total_profit' => $rawFinancials['profit'],
                    'total_expense' => $rawFinancials['expense'],
                    'total_hpp' => $rawFinancials['hpp'],
                    'net_profit' => $rawFinancials['net_profit'],
                    'total_transactions' => $rawFinancials['transactions_count'],
                    'profit_margin' => $rawFinancials['margin'],
                    'health_score' => 0,
                    'health_category' => '-',
                    'health_status' => '-',
                    'narrative' => '-',
                ]);

                // Reset indikator
                $totalHealthScore = 0;
                $indicators = HealthIndicator::all()->keyBy('indicator_code');

                $s1Data = $this->calculateS1DataDiscipline($company->id, $startDate, $endDate);
                $totalHealthScore += $this->saveIndicator($report, $indicators['S1'], $s1Data);

                $s2Data = $this->calculateS2RevenueStability($company, $startDate, $endDate);
                $totalHealthScore += $this->saveIndicator($report, $indicators['S2'], $s2Data);

                $s3Data = $this->calculateS3Profitability($company, $startDate, $endDate);
                $totalHealthScore += $this->saveIndicator($report, $indicators['S3'], $s3Data);

                $s4Data = $this->calculateS4InventoryEfficiency($company, $startDate, $endDate);
                $totalHealthScore += $this->saveIndicator($report, $indicators['S4'], $s4Data);

                $s5Data = $this->calculateS5CashFlow($company, $startDate, $endDate);
                $totalHealthScore += $this->saveIndicator($report, $indicators['S5'], $s5Data);

                $s6Data = $this->calculateS6Behavioral($company, $startDate, $endDate);
                $totalHealthScore += $this->saveIndicator($report, $indicators['S6'], $s6Data);

                $statusData = $this->determineStatus($totalHealthScore);

                $report->update([
                    'health_score'    => round($totalHealthScore, 2),
                    'health_category' => $statusData['category'],
                    'health_status'   => $statusData['status'],
                    'narrative'       => $statusData['narrative'], // fallback statis sementara
                ]);

                // ── Step 1: Simpan rekomendasi terstruktur dari dictionary ──────────
                $this->mapRecommendations($report);

                // ── Step 2: Generate narasi personal dengan AI (OpenAI) ─────────────
                // Reload agar recommendations ter-load setelah mapRecommendations
                $report->load('recommendations', 'indicators.healthIndicator');
                $aiNarrative = $this->generateAiNarrative($report, $company->store_name ?? 'Bisnis Anda');
                $report->update(['narrative' => $aiNarrative]);
                $reports[] = $report->fresh(['indicators.healthIndicator', 'recommendations']);
            }
            return $reports;
        });
    }

    // AI NARRATIVE GENERATOR

    /**
     * Panggil OpenAI API (gpt-5.4-mini) untuk menghasilkan narasi bisnis yang
     * personal dan kontekstual, berdasarkan data health report + PDF knowledge.
     *
     * Fallback ke narasi statis jika API gagal atau key tidak dikonfigurasi.
     */
    private function generateAiNarrative(BusinessHealth $report, string $storeName): string
    {
        $apiKey = config('services.openai.key');

        if (empty($apiKey)) {
            Log::warning('[BusinessHealth AI] OPENAI_API_KEY tidak dikonfigurasi, menggunakan narasi fallback.');
            return $report->narrative;
        }

        $systemPrompt = $this->buildAiSystemPrompt();
        $userPrompt   = $this->buildAiUserPrompt($report, $storeName);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type'  => 'application/json',
            ])
            ->timeout(30)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model'       => 'gpt-5.4-mini',
                'messages'    => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user',   'content' => $userPrompt],
                ],
                'max_tokens'  => 600,
                'temperature' => 0.75,
            ]);

            if ($response->successful()) {
                $narrative = $response->json('choices.0.message.content');
                if (!empty(trim($narrative))) {
                    return trim($narrative);
                }
            }

            Log::warning('[BusinessHealth AI] Respons OpenAI tidak valid.', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

        } catch (\Exception $e) {
            Log::error('[BusinessHealth AI] Gagal memanggil OpenAI API: ' . $e->getMessage());
        }

        // Fallback: kembalikan narasi statis yang sudah ada
        return $report->narrative;
    }

 /**
     * System prompt: berisi pengetahuan dari PDF "Variasi Rekomendasi"
     * dan instruksi cara AI berperilaku (menggunakan bahasa awam).
     */
    private function buildAiSystemPrompt(): string
    {
        return <<<PROMPT
Kamu adalah teman diskusi dan penasihat bisnis dari "Auro". Tugasmu adalah memberikan evaluasi kesehatan bisnis untuk pemilik UMKM dengan gaya bahasa yang sangat ramah, suportif, empatik, dan menggunakan bahasa awam (hindari istilah bisnis yang terlalu berat atau teknis).

TUGAS UTAMAMU:
Tulis narasi ringkasan (3-4 paragraf, maksimal 500 kata) tentang kondisi bisnis mereka. Buat mereka merasa dipahami, bukan sedang dihakimi.

PAHAMI 6 INDIKATOR BISNIS INI (Terjemahkan ke bahasa sehari-hari saat menulis):
- S1 (Disiplin Data) = Seberapa rajin dan rapi mereka mencatat pemasukan & pengeluaran.
- S2 (Stabilitas Pendapatan) = Apakah jualan mereka stabil, naik, atau malah naik-turun tidak menentu.
- S3 (Profitabilitas) = Apakah keuntungannya cukup untuk usaha, atau untungnya terlalu tipis (bahkan tekor).
- S4 (Efisiensi Inventaris) = Apakah barang dagangan perputarannya cepat, atau banyak barang numpuk di gudang/toko.
- S5 (Cash Flow) = Kondisi uang kas atau modal putar harian. Apakah perputaran uangnya lancar atau sering macet.
- S6 (Behavioral/Branding) = Kedekatan dengan pelanggan dan seberapa tertib mereka mengurus operasional toko.

TINGKAT KEPARAHAN (Jangan sebutkan kata "Kritis" atau "Ringan", tapi sampaikan maknanya):
- RINGAN (≥ 70): Masalah kecil, ibarat kerikil, masih gampang dibereskan.
- SEDANG (50–69): Mulai terasa mengganggu, butuh perhatian khusus agar tidak makin parah.
- KRITIS (< 50): Harus segera diperbaiki sekarang juga karena bisa membuat bisnis kehabisan nafas/modal.

ATURAN MENULIS (SANGAT PENTING):
1. Buka dengan sapaan hangat yang mengapresiasi kerja keras mereka mengurus bisnis.
2. Puji dulu apa yang sudah bagus dari bisnis mereka.
3. Bahas 2-3 masalah utama secara lembut. Jangan menakut-nakuti, tapi berikan semangat bahwa ini bisa diperbaiki.
4. JANGAN gunakan bullet points (titik-titik). Tulis mengalir seperti orang sedang bercerita.
5. JANGAN sebut angka skor (misal: "Skor S1 Anda 40"). Ubah jadi kalimat: "Kami perhatikan pencatatan keuangan di toko masih sering terlewat ya..."
6. Tutup dengan kalimat penyemangat yang disesuaikan dengan status mereka (misal: tetap semangat menstabilkan bisnis, atau siap-siap buka cabang/scale up).
PROMPT;
    }

    /**
     * User prompt: berisi data spesifik dari laporan bisnis.
     */
    private function buildAiUserPrompt(BusinessHealth $report, string $storeName): string
    {
        // Ringkas data indikator
        $indicatorLines = $report->indicators->map(function ($ind) {
            $severity = $this->getSeverityLabel($ind->indicator_score);
            $name     = $ind->healthIndicator->indicator_name ?? $ind->healthIndicator->indicator_code;
            return "- Kondisi {$name}: Skor {$ind->indicator_score}/100 (Status: {$severity})";
        })->implode("\n");

        // Kondisi yang terpicu
        $conditions = $report->recommendations->pluck('condition_name')->unique()->implode(', ');

        // Format angka ke rupiah
        $formatRp = fn($val) => 'Rp ' . number_format($val, 0, ',', '.');

        return <<<PROMPT
Tolong tuliskan narasi evaluasi bisnis untuk toko di bawah ini menggunakan bahasa awam yang santai tapi profesional.

INFORMASI TOKO:
- Nama Toko: {$storeName}
- Periode Laporan: {$report->period_range}
- Skor Kesehatan Keseluruhan: {$report->health_score}/100
- Kategori Kondisi: {$report->health_category}
- Status Bisnis Saat Ini: {$report->health_status}

DATA KEUANGAN (Gunakan angka ini jika perlu memberi contoh ke mereka):
- Total Uang Masuk (Omzet): {$formatRp($report->total_sales)}
- Laba Kotor (Sebelum dipotong biaya operasional): {$formatRp($report->total_profit)}
- Total Pengeluaran (Biaya-biaya): {$formatRp($report->total_expense)}
- Laba Bersih (Untung bersih yang dibawa pulang): {$formatRp($report->net_profit)}
- Persentase Keuntungan (Margin): {$report->profit_margin}%
- Jumlah Transaksi/Nota: {$report->total_transactions} kali

DETAIL KONDISI (JANGAN sebutkan skornya, gunakan sebagai panduanmu saja):
{$indicatorLines}

MASALAH UTAMA YANG TERDETEKSI:
{$conditions}

Instruksi: Ceritakan kondisi bisnis mereka berdasarkan data di atas. Fokus pada apa yang paling menghambat mereka saat ini, dan berikan pandangan yang membangun!
PROMPT;
    }
    /**
     * Tentukan label keparahan berdasarkan skor.
     */
    private function getSeverityLabel(float $score): string
    {
        if ($score >= 80) return 'Baik';
        if ($score >= 70) return 'Ringan';
        if ($score >= 50) return 'Perlu Perhatian';
        return 'Kritis';
    }

    // UPDATED: mapRecommendations — 3-level severity selection

    private function mapRecommendations(BusinessHealth $report): void
    {
        $report->recommendations()->detach();

        $score = $report->health_score;

        // Global recommendation berdasarkan total skor 
        if ($score >= 80) {
            $globalSeverity = $this->scoreToSeverity($score, 'global_high');
            $globalRec = HealthRecommendationDictionary::where('trigger_indicator', 'Global')
                ->where('condition_name', 'Business Health Tinggi')
                ->where('severity_level', $globalSeverity)
                ->first();
            if ($globalRec) {
                $this->attachRecommendation($report, $globalRec);
            }
        }

        // Per-indikator recommendation
        $allIndicators = $report->indicators()->with('healthIndicator')->get();

        $weakIndicators = $allIndicators
            ->filter(fn($ind) => $ind->indicator_score < 80)
            ->sortByDesc(function ($ind) {
                return (100 - $ind->indicator_score) * ($ind->healthIndicator->weight / 100);
            });

        foreach ($weakIndicators as $weak) {
            $triggerCode = $weak->healthIndicator->indicator_code;
            $indScore    = $weak->indicator_score;
            $severity    = $this->scoreToSeverity($indScore);

            // Coba cari rekomendasi dengan severity yang tepat
            $rec = HealthRecommendationDictionary::where('trigger_indicator', $triggerCode)
                ->where('severity_level', $severity)
                ->first();

            // Fallback: kalau severity tertentu tidak ada, ambil yang tersedia
            if (!$rec) {
                $rec = HealthRecommendationDictionary::where('trigger_indicator', $triggerCode)
                    ->orderByRaw("CASE severity_level
                        WHEN 'KRITIS' THEN 1
                        WHEN 'SEDANG' THEN 2
                        WHEN 'RINGAN' THEN 3
                        END")
                    ->first();
            }

            if ($rec) {
                $this->attachRecommendation($report, $rec);
            }
        }
    }

    /**
     * Konversi skor indikator ke severity level.
     *
     * @param float  $score
     * @param string $context  'default' | 'global_high' (untuk skor global ≥ 80)
     */
    private function scoreToSeverity(float $score, string $context = 'default'): string
    {
        if ($context === 'global_high') {
            // Skor global ≥ 80: bedakan level tingkat urgensi ekspansi
            if ($score >= 90) return 'KRITIS';
            if ($score >= 85) return 'SEDANG';
            return 'RINGAN';
        }

        // Per-indikator lemah (skor < 80)
        if ($score < 50) return 'KRITIS';
        if ($score < 70) return 'SEDANG';
        return 'RINGAN';
    }

    private function attachRecommendation(BusinessHealth $report, $rec): void
    {
        $report->recommendations()->syncWithoutDetaching([$rec->id]);
    }


    private function calculateS1DataDiscipline($companyId, $startDate, $endDate): array
    {
        $trxTable = TableResolver::trx($companyId);
        $detailTable = TableResolver::detail($companyId);

        // Sub Indikator 1: Hitung hari ada transaksi / total hari operasional x 100
        $totalOperationalDays = $startDate->diffInDays($endDate) + 1;
        $daysTransactions = DB::table($trxTable)
            ->where('company_id', $companyId)
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->select(DB::raw('DATE(transaction_date) as date'))
            ->distinct()
            ->count();
        $scorePengisianHarian = $totalOperationalDays > 0
            ? ($daysTransactions / $totalOperationalDays) * 100 : 0;

        // Sub Indikator 2: Transaksi lengkap / Total transaksi x 100
        $totalTransactions = DB::table($trxTable)
            ->where('company_id', $companyId)
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->count();

        $completeTransactions = DB::table($trxTable . ' as t')
            ->join($detailTable . ' as dt', 't.id', '=', 'dt.transaction_id')
            ->where('t.company_id', $companyId)
            ->whereBetween('t.transaction_date', [$startDate, $endDate])
            ->whereNotNull('dt.cost_price')
            ->whereNotNull('dt.quantity')
            ->whereNotNull('dt.product_id')
            ->distinct('t.id')
            ->count('t.id');
        $scoreKelengkapan = $totalTransactions > 0
            ? ($completeTransactions / $totalTransactions) * 100 : 0;

        // Sub Indikator 3: Minggu dengan data biaya / Total minggu x 100 [cite: 2]
        $totalWeeks = ceil($totalOperationalDays / 7);

        $expenseDates = Financial::where('company_id', $companyId)
            ->where('financials_type', 'outcome')
            ->whereBetween('financials_date', [$startDate, $endDate])
            ->pluck('financials_date');

        $hppDates = DB::table($trxTable . ' as t')
            ->join($detailTable . ' as dt', 't.id', '=', 'dt.transaction_id')
            ->where('t.company_id', $companyId)
            ->whereBetween('t.transaction_date', [$startDate, $endDate])
            ->whereNotNull('dt.cost_price')
            ->pluck('t.transaction_date');

        $allCostDates = $expenseDates->concat($hppDates);
        $uniqueWeeksWithCost = $allCostDates->map(function ($date) {
            return Carbon::parse($date)->format('Y-W'); // Format: 2024-23
        })->unique()->count();
        $scoreKonsistensiHpp = $totalWeeks > 0
            ? ($uniqueWeeksWithCost / $totalWeeks) * 100 : 0;

        // Kalkulasi skor akhir S1
        $scorePengisianHarian = min(100, $scorePengisianHarian);
        $scoreKelengkapan     = min(100, $scoreKelengkapan);
        $scoreKonsistensiHpp  = min(100, $scoreKonsistensiHpp);
        $indicatorScore = ($scorePengisianHarian * 0.40) + ($scoreKelengkapan * 0.35) + ($scoreKonsistensiHpp * 0.25);

        return [
            'indicator_score' => round($indicatorScore,2),
            'sub_indicators_data' => [
                'tingkat_pengisian_harian' => round($scorePengisianHarian,2),
                'kelengkapan_data' => round($scoreKelengkapan,2),
                'konsistensi_input_hpp' => round($scoreKonsistensiHpp,2)
            ]
        ];
    }

    private function calculateS2RevenueStability($company, $startDate, $endDate): array
    {
        $trxTable = TableResolver::trx($company->id);
        $detailTable = TableResolver::detail($company->id);

        // Sub Indikator 1: Omset 3 bulan terakhir vs omset 3 bulan sebelumnya
        $currentSales = DB::table($trxTable)
            ->where('company_id', $company->id)
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->sum('total_transaction') ?? 0;

        $previousStart = $startDate->copy()->subMonths(3);
        $previousEnd = $startDate->copy()->subDay()->endOfDay();

        $previousSales = DB::table($trxTable)
            ->where('company_id', $company->id)
            ->whereBetween('transaction_date', [$previousStart, $previousEnd])
            ->sum('total_transaction') ?? 0;

        if ($company->created_at >= $previousStart){
            $scoreTren = 100;
        }else{
            $scoreTren = $previousSales > 0
            ? ($currentSales / $previousSales) * 100 : 0;
        }


        // Sub Indikator 2: Hitung Standar Deviasi & Rata-rata untuk CV
        $dailySalesQuery = DB::table($trxTable)
            ->where('company_id', $company->id)
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->select(DB::raw('DATE(transaction_date) as date'), DB::raw('SUM(total_transaction) as total'))
            ->groupBy('date')
            ->pluck('total', 'date')->toArray();

        $period = CarbonPeriod::create($startDate, $endDate);
        $dailySales = [];

        foreach ($period as $date) {
            $dateString = $date->format('Y-m-d');
            $dailySales[] = $dailySalesQuery[$dateString] ?? 0;
        }

        $totalDays = count($dailySales);
        $mean = $totalDays > 0 ? array_sum($dailySales) / $totalDays : 0;

        $scoreKonsistensi = 0;
        if ($mean > 0) {
            // Hitung Varians (Kuadrat selisih)
            $varianceSum = 0;
            foreach ($dailySales as $sales) {
                $varianceSum += pow($sales - $mean, 2);
            }
            $variance = $varianceSum / $totalDays;
            $stdDev = sqrt($variance);
            $cvPercentage = ($stdDev / $mean) * 100;
            $scoreKonsistensi = max(0, 100 - ($cvPercentage * 0.5));
        }

        // Sub Indikator 3: Diversifikasi Produk/Kategori
        $categorySales = DB::table($detailTable . ' as dt')
            ->join($trxTable . ' as t', 'dt.transaction_id', '=', 't.id')
            ->join('products as p', 'dt.product_id', '=', 'p.id')
            ->where('t.company_id', $company->id)
            ->whereBetween('t.transaction_date', [$startDate, $endDate])
            ->select('p.category_id', DB::raw('SUM(dt.subtotal) as total'))
            ->groupBy('p.category_id')
            ->pluck('total', 'category_id');

        $validCategoriesCount = 0;
        if ($currentSales > 0){
            foreach ($categorySales as $catTotal){
                $percantage = ($catTotal / $currentSales) * 100;

                if ($percantage >= 5){
                    $validCategoriesCount++;
                }
            }
        }
        $scoreDiversifikasi = min(100, $validCategoriesCount * 20);

        // Kalkulasi skor akhir S2
        $scoreTren          = min(100, $scoreTren);
        $scoreKonsistensi   = min(100, $scoreKonsistensi);
        $scoreDiversifikasi = min(100, $scoreDiversifikasi);
        $indicatorScore = ($scoreTren * 0.50) + ($scoreKonsistensi * 0.30) + ($scoreDiversifikasi * 0.20);

        return [
            'indicator_score' => round($indicatorScore,2),
            'sub_indicators_data' => [
                'tren_pendapatan' => round($scoreTren,2),
                'konsistensi_pendapatan' => round($scoreKonsistensi,2),
                'diversifikasi_produk' => round($scoreDiversifikasi,2)
            ]
        ];
    }

    private function calculateS3Profitability($company, $startDate, $endDate): array
    {
        $trxTable = TableResolver::trx($company->id);
        $detailTable = TableResolver::detail($company->id);

        // Sub Indikator 1: Margin Laba Kotor
        $currentSales = DB::table($trxTable)
            ->where('company_id', $company->id)
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->sum('total_transaction') ?? 0;

        $currentHpp = DB::table($detailTable . ' as dt')
            ->join($trxTable . ' as t', 'dt.transaction_id', '=', 't.id')
            ->where('t.company_id', $company->id)
            ->whereBetween('t.transaction_date', [$startDate, $endDate])
            ->select(DB::raw('SUM(dt.cost_price * dt.quantity) as total_cost'))
            ->first()->total_cost ?? 0;

        $marginLabaKotor =0 ;
        if ($currentSales > 0){
            $marginLabaKotor = (($currentSales - $currentHpp) / $currentSales) * 100;
        }
        $scoreMargin = max(0, min(100, $marginLabaKotor));

        // Sub Indikator 2: Konsistensi Laba Positif
        $totalMonths = 3;
        $transactionsData = DB::table($trxTable)
            ->where('company_id', $company->id)
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->select('transaction_date', 'total_profit')
            ->get();

        $monthlyProfits = $transactionsData->groupBy(function($val){
            return Carbon::parse($val->transaction_date)->format('Y-m');
        });

        $positiveMonthsCount = 0;
        foreach ($monthlyProfits as $month => $transactionInMonth) {
            if ($transactionInMonth->sum('total_profit') > 0) {
                $positiveMonthsCount++;
            }
        }
        $scoreKonsistensiLaba = ($positiveMonthsCount / $totalMonths) * 100;
        $scoreKonsistensiLaba = min(100, $scoreKonsistensiLaba);

        // Sub Indikator 3: Tren Margin Laba
        $previousStart = $startDate->copy()->subMonths(3);
        $previousEnd = $startDate->copy()->subDay()->endOfDay();

        $previousSales = DB::table($trxTable)
            ->where('company_id', $company->id)
            ->whereBetween('transaction_date', [$previousStart, $previousEnd])
            ->sum('total_transaction') ?? 0;

        $previousHpp = DB::table($detailTable . ' as dt')
            ->join($trxTable . ' as t', 'dt.transaction_id', '=', 't.id')
            ->where('t.company_id', $company->id)
            ->whereBetween('t.transaction_date', [$previousStart, $previousEnd])
            ->select(DB::raw('SUM(dt.cost_price * dt.quantity) as total_hpp'))
            ->first()->total_hpp ?? 0;

        $prevMarginLabaKotor = 0;
        if ($previousSales > 0){
            $prevMarginLabaKotor = (($previousSales - $previousHpp) / $previousSales) * 100;
        }

        if ($company->created_at >= $previousStart){
            $scoreTrenMargin = 100;
        }elseif ($prevMarginLabaKotor <= 0 && $marginLabaKotor > 0){
            $scoreTrenMargin = 100;
        }elseif ($prevMarginLabaKotor > 0 ){
            $scoreTrenMargin = ($marginLabaKotor / $prevMarginLabaKotor) * 100;
        }else{
            $scoreTrenMargin = 0;
        }

        $scoreTrenMargin = min(100, $scoreTrenMargin);

        //Kalkulasi skor akhir S3
        $indicatorScore = ($scoreMargin * 0.40)
                    + ($scoreKonsistensiLaba * 0.35)
                    + ($scoreTrenMargin * 0.25);
        return [
            'indicator_score' => round($indicatorScore,2),
            'sub_indicators_data' => [
                'margin_laba_kotor' => round($scoreMargin,2),
                'konsistensi_laba_positif' => round($scoreKonsistensiLaba,2),
                'tren_margin_laba' => round($scoreTrenMargin,2)
            ]
        ];
    }

    private function calculateS4InventoryEfficiency($company, $startDate, $endDate): array
    {
        $totalProducts = Product::where('company_id', $company->id)->count();

        if ($totalProducts == 0) {
            return [
                'indicator_score' => 0,
                'sub_indicators_data' => [
                    'inventory_turnover' => 0,
                    'tingkat_produk_mati' => 0,
                    'akurasi_stok' => 0
                ]
            ];
        }

        // Sub Indikator 1: Inventory Turnover Ratio
        $totalStockout = HistoryStock::wherehas('product', function ($query) use ($company) {
            $query->where('company_id', $company->id);
        })
        ->whereBetween('history_stock_date', [$startDate, $endDate])
        ->sum('stock_out');

        $averageStock = HistoryStock::wherehas('product', function ($query) use ($company) {
            $query->where('company_id', $company->id);
        })
        ->whereBetween('history_stock_date', [$startDate, $endDate])
        ->avg('final_stock') ?? 0;

        $turnoverRate = 0;
        if ($averageStock > 0) {
            $turnoverRate = $totalStockout / $averageStock;
        } elseif ($totalStockout > 0) {
            $turnoverRate = 1;
        }

        $scoreTurnover = min(100, $turnoverRate * 20);

        // Sub Indikator 2: Tingkat Produk Mati
        $productsWithSales = HistoryStock::wherehas('product', function ($query) use ($company) {
            $query->where('company_id', $company->id);
            })
            ->whereBetween('history_stock_date', [$startDate, $endDate])
            ->where('stock_out', '>', 0)
            ->distinct('product_id')
            ->count('product_id');

        $deadProducts = $totalProducts - $productsWithSales;
        $deadStockPercentage = ($deadProducts / $totalProducts) * 100;

        $scoreDeadStock = max(0, 100 - $deadStockPercentage);

        // Sub Indikator 3: Akurasi Stok
        $negativeStockEvent = HistoryStock::wherehas('product', function ($query) use ($company) {
            $query->where('company_id', $company->id);
            })
            ->whereBetween('history_stock_date', [$startDate, $endDate])
            ->where('final_stock', '<', 0)
            ->count();

        $scoreAccuracy = max(0, 100 -($negativeStockEvent * 5));

        // Kalkulasi skor akhir S4
        $indicatorScore = ($scoreTurnover * 0.40)
                    + ($scoreDeadStock * 0.40)
                    + ($scoreAccuracy * 0.20);
        return [
            'indicator_score' => round($indicatorScore, 2),
            'sub_indicators_data' => [
                'inventory_turnover' => round($scoreTurnover, 2),
                'tingkat_produk_mati' => round($scoreDeadStock, 2),
                'akurasi_stok' => round($scoreAccuracy, 2)
            ]
        ];
    }

    private function calculateS5CashFlow($company, $startDate, $endDate): array
    {
        $trxTable = TableResolver::trx($company->id);

        // Sub Indikator 1: Rasio Pengeluaran terhadap Pendapatan
        $totalSales = DB::table($trxTable)
            ->where('company_id', $company->id)
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->sum('total_transaction') ?? 0;

        $totalExpense = Financial::where('company_id', $company->id)
            ->where('financials_type', 'outcome')
            ->whereBetween('financials_date', [$startDate, $endDate])
            ->sum('nominal') ?? 0;

        $expenseRatio = 0;
        if ($totalSales > 0) {
            $expenseRatio = ($totalExpense / $totalSales) * 100;
        } elseif ($totalExpense > 0) {
            $expenseRatio = 100;
        }

        $scoreRasioPengeluaran = max(0, 100 - $expenseRatio);

        // Sub Indikator 2: Konsistensi Arus Kas Positif
        $totalMonths = 3;
        $monthlySales = DB::table($trxTable)
            ->where('company_id', $company->id)
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->select(DB::raw('DATE_FORMAT(transaction_date, "%Y-%m") as month'), DB::raw('SUM(total_transaction) as total'))
            ->groupBy('month')
            ->pluck('total', 'month');

        $monthlyExpenses = Financial::where('company_id', $company->id)
            ->where('financials_type', 'outcome')
            ->whereBetween('financials_date', [$startDate, $endDate])
            ->select(DB::raw('DATE_FORMAT(financials_date, "%Y-%m") as month'), DB::raw('SUM(nominal) as total'))
            ->groupBy('month')
            ->pluck('total', 'month');

        $allMonths = $monthlySales->keys()->merge($monthlyExpenses->keys())->unique();

        $positiveCashFlowMonthsCount = 0;
        foreach ($allMonths as $month) {
            $income = $monthlySales[$month] ?? 0;
            $expense = $monthlyExpenses[$month] ?? 0;

            // Cash Flow = Pemasukan - Pengeluaran
            if (($income - $expense) > 0) {
                $positiveCashFlowMonthsCount++;
            }
        }

        $scoreCashFlow = ($positiveCashFlowMonthsCount / $totalMonths) * 100;
        $scoreCashFlow = min(100, $scoreCashFlow);

        // Sub Indikator 3: Ketepatan pembayaran wajib
        $scoreKetepatanBayar = 100; // Masih Belum ada kolom yang cocok

        // Kalkulasi skor akhir S5
        $indicatorScore = ($scoreRasioPengeluaran * 0.40)
                    + ($scoreCashFlow * 0.35)
                    + ($scoreKetepatanBayar * 0.25);
        return [
            'indicator_score' => round($indicatorScore, 2),
            'sub_indicators_data' => [
                'rasio_pengeluaran' => round($scoreRasioPengeluaran, 2),
                'arus_kas_positif' => round($scoreCashFlow, 2),
                'ketepatan_pembayaran' => round($scoreKetepatanBayar, 2)
            ]
        ];
    }

    private function calculateS6Behavioral($company, $startDate, $endDate): array
    {
        $trxTable = TableResolver::trx($company->id);
        $detailTable = TableResolver::detail($company->id);

        // Sub indikator 1: Pencatatan pengeluaran oprasional
        $totalTransactions = DB::table($trxTable)
            ->where('company_id', $company->id)
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->count();

        $totalExpensesCount = Financial::where('company_id', $company->id)
            ->where('financials_type', 'outcome')
            ->whereBetween('financials_date', [$startDate, $endDate])
            ->count();

        $scoreExpenseRecording = 0;
        if ($totalTransactions > 0) {
            // Rasio 5%
            $scoreExpenseRecording = min(100, ($totalExpensesCount / ($totalTransactions * 0.05)) * 100);
        }
        // Sub indikator 2: Ketepatan input data transaksi
        $transactions = DB::table($trxTable . ' as t')
            ->leftJoin(DB::raw('(SELECT transaction_id, SUM(subtotal) as total_details_subtotal FROM ' . $detailTable . ' GROUP BY transaction_id) as dt'), 't.id', '=', 'dt.transaction_id')
            ->where('t.company_id', $company->id)
            ->whereBetween('t.transaction_date', [$startDate, $endDate])
            ->select('t.*', 'dt.total_details_subtotal')
            ->get();

        $totalTransactions = $transactions->count();
        $validTransactionsCount = 0;

        if ($totalTransactions > 0) {
            foreach ($transactions as $trx) {
                $isValid = true;

                if (Carbon::parse($trx->created_at)->diffInMinutes(Carbon::parse($trx->updated_at)) > 10) {
                    $isValid = false;
                }

                if (abs($trx->subtotal - $trx->total_details_subtotal) > 1) {
                    $isValid = false;
                }

                if ($trx->subtotal > 0 && ($trx->transaction_discount / $trx->subtotal) > 0.8) {
                    $isValid = false;
                }

                if ($isValid) {
                    $validTransactionsCount++;
                }
            }
            $scoreInputTransaksi = ($validTransactionsCount / $totalTransactions) * 100;
        } else {
            $scoreInputTransaksi = 100;
        }

        // Sub indikator 3: Kelengkapan data pelanggan
        $customers = Customer::where('company_id', $company->id)->get();
        $totalCustomers = $customers->count();

        $completeCustomers = 0;
        if ($totalCustomers > 0) {
            foreach ($customers as $customer) {
                if (!empty($customer->customer_email) && !empty($customer->customer_phone)) {
                    $completeCustomers++;
                }
            }
            $scoreCustomerData = ($completeCustomers / $totalCustomers) * 100;
        } else {
            $scoreCustomerData = 50;
        }
        // Kalkulasi skor akhir S6
        $indicatorScore = ($scoreExpenseRecording * 0.35)
                    + ($scoreInputTransaksi * 0.30)
                    + ($scoreCustomerData * 0.35);
        return [
            'indicator_score' => round($indicatorScore, 2),
            'sub_indicators_data' => [
                'pencatatan_pengeluaran_operasional' => round($scoreExpenseRecording, 2),
                'ketepatan_input_transaksi' => round($scoreInputTransaksi, 2),
                'kelengkapan_data_pelanggan' => round($scoreCustomerData, 2)
            ]
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    private function saveIndicator(BusinessHealth $report, HealthIndicator $indicatorMaster, array $calculatedData): float
    {
        $contributionPoint = $calculatedData['indicator_score'] * ($indicatorMaster->weight / 100);
        $report->indicators()->create([
            'health_indicator_id' => $indicatorMaster->id,
            'indicator_score'     => $calculatedData['indicator_score'],
            'contribution_point'  => $contributionPoint,
            'sub_indicators_data' => $calculatedData['sub_indicators_data'],
        ]);
        return $contributionPoint;
    }

    private function determineStatus(float $score): array
    {
        if ($score >= 80) {
            return [
                'category' => 'LAYAK SCALE',
                'status'   => 'Bisnis Sehat',
                'narrative'=> 'Selamat! Bisnis Anda berada dalam kondisi sehat. Operasional dan keuangan sudah berjalan dengan baik.',
            ];
        } elseif ($score >= 60) {
            return [
                'category' => 'PERLU PENGUATAN',
                'status'   => 'Bisnis Berkembang',
                'narrative'=> 'Bisnis Anda sudah berjalan cukup baik, namun masih ada beberapa area yang perlu diperbaiki agar bisnis bisa tumbuh lebih stabil.',
            ];
        } else {
            return [
                'category' => 'FASE STABILISASI',
                'status'   => 'Bisnis Rentan',
                'narrative'=> 'Bisnis Anda masih perlu memperkuat fondasi operasional dan pengelolaan keuangan.',
            ];
        }
    }

    private function getRawFinancialData($companyId, $startDate, $endDate): array
    {
        $trxTable = TableResolver::trx($companyId);
        $detailTable = TableResolver::detail($companyId);

        $totalSales = DB::table($trxTable)
                ->where('company_id', $companyId)
                ->whereBetween('transaction_date', [$startDate, $endDate])
                ->sum('total_transaction') ?? 0;

        $totalTransactions = DB::table($trxTable)
            ->where('company_id', $companyId)
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->count();

        $totalHPP = DB::table($detailTable . ' as dt')
            ->join($trxTable . ' as t', 'dt.transaction_id', '=', 't.id')
            ->where('t.company_id', $companyId)
            ->whereBetween('t.transaction_date', [$startDate, $endDate])
            ->select(DB::raw('SUM(dt.cost_price * dt.quantity) as total_cost'))
            ->first()->total_cost ?? 0;

        $totalExpense = Financial::where('company_id', $companyId)
            ->where('financials_type', 'outcome')
            ->whereBetween('financials_date', [$startDate, $endDate])
            ->sum('nominal') ?? 0;

        $totalProfit = DB::table($trxTable)
            ->where('company_id', $companyId)
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->sum('total_profit') ?? 0;

        // $expenseRatio = 0;
        $expenseRatio = $totalSales > 0 ? round(($totalExpense / $totalSales) * 100, 2) : 0;
        $netProfit = $totalProfit - $totalExpense;
        $profitMargin = $totalSales > 0 ? round(($netProfit / $totalSales) * 100, 2) : 0;

        return [
            'sales' => $totalSales,
            'profit' => $totalProfit,
            'expense' => $totalExpense,
            'hpp' => $totalHPP,
            'net_profit' => $netProfit,
            'margin' => $profitMargin,
            'transactions_count' => $totalTransactions
        ];
    }
}
