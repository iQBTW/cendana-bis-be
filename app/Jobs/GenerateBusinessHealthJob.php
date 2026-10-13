<?php
namespace App\Jobs;

use App\Models\Company;
use App\Services\BusinessHealthService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateBusinessHealthJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $backoff = 60;
    /**
     * Create a new job instance.
     */
    public function __construct(public int $companyId) {

    }

    /**
     * Execute the job.
     */
    public function handle(BusinessHealthService $service)
    {
        $company = Company::find($this->companyId);

        if (! $company) {
            return;
        }
        $service->generateReport($company);
    }
}
