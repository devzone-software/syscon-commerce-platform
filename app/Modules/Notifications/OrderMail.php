<?php

namespace App\Modules\Notifications;

use App\Modules\Invoices\InvoiceFiles;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Correo al cliente sobre su pedido; el texto depende del tipo de aviso. */
class OrderMail extends Mailable
{
    public function __construct(public string $kind, public array $order, public ?object $invoice, private ?string $pdf) {}

    public function envelope(): Envelope
    {
        $short = strtoupper(substr($this->order['id'], 0, 8));

        return new Envelope(subject: match ($this->kind) {
            'ORDER_CREATED' => "Recibimos tu pedido $short",
            'PAYMENT_CONFIRMED' => "Pago confirmado del pedido $short",
            default => InvoiceFiles::TITLES[$this->invoice->type].' '.$this->invoice->serial_number." · pedido $short",
        });
    }

    public function content(): Content
    {
        $web = rtrim((string) config('commerce.web_url'), '/');

        return new Content(view: 'mail.order', with: [
            'kind' => $this->kind, 'order' => $this->order, 'invoice' => $this->invoice,
            'title' => $this->invoice ? InvoiceFiles::TITLES[$this->invoice->type] : null,
            'warning' => $this->invoice ? InvoiceFiles::warning($this->invoice) : null,
            'instructions' => $this->order['status'] === 'PAYMENT_PENDING' ? trim((string) config('commerce.payments.manual_instructions')) : '',
            'link' => $web !== '' ? $web.'/pedido/'.$this->order['id'] : null,
        ]);
    }

    public function attachments(): array
    {
        return $this->pdf === null ? [] : [Attachment::fromData(fn () => $this->pdf, InvoiceFiles::filename($this->invoice, 'pdf'))->withMime('application/pdf')];
    }
}
