<?php

namespace App\Mail;

use App\Models\Organization;
use App\Services\Usage\UsageMeter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BudgetThresholdReached extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  float  $threshold  Share of the monthly budget reached, e.g. 0.8.
     */
    public function __construct(public Organization $organization, public float $threshold) {}

    public function envelope(): Envelope
    {
        $percent = (int) round($this->threshold * 100);

        return new Envelope(
            subject: $this->threshold >= 1
                ? "{$this->organization->name} reached its monthly AI budget"
                : "{$this->organization->name} used {$percent}% of its monthly AI budget",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.budget-threshold-reached',
            with: [
                'organizationName' => $this->organization->name,
                'percent' => (int) round($this->threshold * 100),
                'reached' => $this->threshold >= 1,
                'spent' => number_format(app(UsageMeter::class)->monthToDateMicros($this->organization) / 1_000_000, 2),
                'budget' => number_format((int) $this->organization->monthly_budget_cents / 100, 2),
                'url' => route('settings.usage'),
            ],
        );
    }
}
