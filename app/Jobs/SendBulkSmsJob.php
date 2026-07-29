<?php

namespace App\Jobs;

use App\Models\SmsLog;
use App\Services\SmsService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendBulkSmsJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public array $recipient;
    public string $message;

    /**
     * Create a new job instance.
     */
    public function __construct(array $recipient, string $message)
    {
        $this->recipient = $recipient;
        $this->message = $message;
    }

    /**
     * Execute the job.
     */
    public function handle(SmsService $smsService): void
    {
        // If this job is part of a batch that was cancelled, don't process it
        if ($this->batch() && $this->batch()->cancelled()) {
            return;
        }

        try {
            $smsResponse = $smsService->sendSms(
                $this->recipient['phone'],
                $this->message,
                null,
                $this->recipient['model'] ?? null
            );

            // The SmsService already logs to SmsLog, but we can add additional logging if needed
            Log::info('SMS job completed', [
                'phone' => $this->recipient['phone'],
                'name' => $this->recipient['name'] ?? 'Unknown',
                'status' => $smsResponse['status'] ?? 'unknown',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send SMS in job', [
                'phone' => $this->recipient['phone'],
                'name' => $this->recipient['name'] ?? 'Unknown',
                'error' => $e->getMessage(),
            ]);

            // Create log entry for failed SMS
            try {
                SmsLog::create([
                    'phone' => $this->recipient['phone'],
                    'message' => $this->message,
                    'status' => 'error',
                    'response' => json_encode(['error' => $e->getMessage()]),
                    'provider' => $smsService->getActiveProvider(),
                ]);
            } catch (\Exception $logException) {
                Log::error('Failed to create SMS log entry', [
                    'error' => $logException->getMessage(),
                ]);
            }

            // Re-throw to mark job as failed
            throw $e;
        }
    }
}

