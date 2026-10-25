<?php

namespace App\Mail;

use App\Models\Transfer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TransferReceipt extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Transfer $transfer) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Transfer receipt');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.receipt', with: [
            'amount' => number_format($this->transfer->amount / 100, 2),
            'currency' => $this->transfer->currency,
            'reference' => $this->transfer->id,
        ]);
    }
}
